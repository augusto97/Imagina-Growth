<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Listeners;

use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Services\Leads\Support\LeadFacts;
use App\Domain\Services\Leads\Support\LeadScorer;
use App\Domain\Services\Leads\Support\LeadSource;
use App\Domain\Services\Leads\Support\LeadWriter;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Events\EventIngested;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;

/**
 * Escucha la ingesta en vivo para leads (M18):
 *
 * - `form.submitted` de una persona identificada → LeadIntake (origen `form`,
 *   referencia = event_id, servicio y presupuesto de los campos mapeados, fuente
 *   del contexto del evento y el mensaje para la calificación con IA).
 * - `page.viewed` de una página de precios o servicios y visitas en días
 *   distintos, y `cta.clicked` de WhatsApp o llamada → señales del puntaje del
 *   lead abierto del contacto.
 *
 * La importación histórica no publica EventIngested; los eventos del hub
 * (`lead.*`) se ignoran.
 */
final class LeadSignalsListener
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly LeadIntake $intake,
        private readonly LeadScorer $scorer,
        private readonly LeadWriter $writer,
    ) {}

    public function handle(EventIngested $event): void
    {
        if ($event->contactId === null || ! in_array($event->event, ['form.submitted', 'page.viewed', 'cta.clicked'], true)) {
            return;
        }
        $type = $event->envelope['source']['type'] ?? null;
        if ($type !== 'browser' && $type !== 'server') {
            return;
        }
        if ($event->event === 'form.submitted') {
            $this->form($event);

            return;
        }
        $workspace = Workspace::query()->find($event->workspaceId);
        if ($workspace === null) {
            return;
        }
        $this->current->run($workspace, fn () => $this->signal($workspace, $event));
    }

    private function form(EventIngested $event): void
    {
        $props = is_array($event->envelope['properties'] ?? null) ? $event->envelope['properties'] : [];
        $fields = is_array($props['fields'] ?? null) ? $props['fields'] : [];
        $str = fn (mixed $v): ?string => is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
        $site = $event->siteId !== '' ? Site::withoutWorkspaceScope()->find($event->siteId) : null;
        $budget = self::amount($str($fields['budget'] ?? null));

        $this->intake->capture(new LeadIntakeInput(
            workspaceId: $event->workspaceId,
            contactId: (string) $event->contactId,
            origin: 'form',
            originRef: $event->eventId,
            service: $str($fields['service'] ?? null),
            value: $budget,
            source: LeadSource::fromEnvelope($event->envelope, $site !== null ? (parse_url((string) $site->url, PHP_URL_HOST) ?: null) : null),
            siteId: $event->siteId !== '' ? $event->siteId : null,
            message: $str($fields['message'] ?? null),
        ));
    }

    /**
     * Presupuesto escrito por la persona a número (pesos):
     * - «$2.500.000», «1,250,000.50»: punto o coma seguidos de dos cifras al final son decimales; los demás, miles.
     * - «1,5 millones», «800 mil», «3M», «500k»: la palabra multiplica (la coma o el punto ahí es decimal).
     * - Rangos de un `<select>` como «2 a 8 millones» o «entre 500 mil y 1 millón»: el punto medio (la
     *   palabra del final vale para los dos extremos). «Menos de 2 millones» o «Más de 8 millones»: ese número.
     * - Sin cifras («no sé»): null.
     */
    public static function amount(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $text = mb_strtolower($raw);
        if (preg_match_all('/(\d[\d.,]*)\s*(millones|millón|millon|mill|mil|m\b|k\b)?/u', $text, $m, PREG_SET_ORDER) === 0) {
            return null;
        }
        $parts = [];
        foreach (array_slice($m, 0, 2) as $match) {
            $parts[] = ['number' => rtrim($match[1], '.,'), 'unit' => $match[2] ?? ''];
        }
        // «2 a 8 millones»: la unidad del último número vale para los anteriores que no la tienen.
        $last = $parts[count($parts) - 1]['unit'];
        $values = [];
        foreach ($parts as $part) {
            $unit = $part['unit'] !== '' ? $part['unit'] : $last;
            $factor = match (true) {
                in_array($unit, ['millones', 'millón', 'millon', 'mill', 'm'], true) => 1_000_000,
                in_array($unit, ['mil', 'k'], true) => 1_000,
                default => 1,
            };
            $number = self::number($part['number'], $factor > 1);
            if ($number === null) {
                return null;
            }
            $values[] = $number * $factor;
        }
        $value = count($values) === 2 ? ($values[0] + $values[1]) / 2 : $values[0];
        if ($value <= 0 || $value >= 1e13) {
            return null;
        }

        return number_format($value, 2, '.', '');
    }

    /** Una cifra con separadores. Con palabra («1,5 millones») la coma o el punto es decimal. */
    private static function number(string $digits, bool $scaled): ?float
    {
        if ($scaled && preg_match('/^(\d+)[.,](\d{1,3})$/', $digits, $m) === 1 && strlen($m[2]) < 3) {
            return (float) ($m[1].'.'.$m[2]);
        }
        $decimals = '';
        if (preg_match('/[.,](\d{2})$/', $digits, $m) === 1) {
            $decimals = '.'.$m[1];
            $digits = substr($digits, 0, -3);
        }
        $integer = (string) preg_replace('/\D/', '', $digits);
        if ($integer === '' || strlen($integer) > 13) {
            return null;
        }

        return (float) ($integer.$decimals);
    }

    private function signal(Workspace $workspace, EventIngested $event): void
    {
        $any = Cache::remember(RedisKey::for($workspace->id, 'leads:any'), 300, fn () => Lead::query()->whereNull('won_at')->whereNull('lost_at')->exists());
        if (! $any) {
            return;
        }
        $lead = Lead::query()->where('contact_id', $event->contactId)->whereNull('won_at')->whereNull('lost_at')->orderByDesc('created_at')->first();
        if ($lead === null) {
            return;
        }
        $settings = LeadSettings::for($workspace->id);
        $changed = false;
        if ($event->event === 'cta.clicked') {
            $kind = $event->envelope['properties']['kind'] ?? null;
            if ($kind === 'whatsapp' || $kind === 'phone') {
                $changed = $this->scorer->signal($lead, $settings, 'whatsapp_click', ['kind' => $kind]);
            }
        } else {
            $path = $event->envelope['context']['page']['path'] ?? null;
            if (LeadScorer::isPricingPath(is_string($path) ? $path : null, (array) $settings->scoringRules()['pricing_paths'])) {
                $changed = $this->scorer->signal($lead, $settings, 'pricing_page', ['views' => LeadFacts::pricingViews($lead) + 1]);
            }
            $days = $this->visitDays($workspace, $lead);
            if ($days >= 2) {
                $changed = $this->scorer->signal($lead, $settings, 'recurring_visit', ['days' => $days]) || $changed;
            }
        }
        if ($changed) {
            $lead->last_activity_at = now();
            $this->writer->save($workspace, $lead, $settings);
        }
    }

    /** Días distintos con visitas en los últimos 7 (en Redis, por lead). */
    private function visitDays(Workspace $workspace, Lead $lead): int
    {
        $key = RedisKey::for($workspace->id, "lead:{$lead->id}:days");
        $today = now()->toDateString();
        $limit = now()->subDays(6)->toDateString();
        /** @var list<string> $days */
        $days = array_values(array_filter((array) Cache::get($key, []), fn ($d) => is_string($d) && $d >= $limit));
        if (! in_array($today, $days, true)) {
            $days[] = $today;
            Cache::put($key, $days, now()->addDays(8));
        }

        return count(array_unique($days));
    }
}
