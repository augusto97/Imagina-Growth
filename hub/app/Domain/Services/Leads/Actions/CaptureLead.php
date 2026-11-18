<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Actions;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Services\Leads\Jobs\QualifyLeadWithAi;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Services\Leads\Support\LeadDefaults;
use App\Domain\Services\Leads\Support\LeadEvents;
use App\Domain\Services\Leads\Support\LeadScorer;
use App\Domain\Services\Leads\Support\LeadWriter;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;

/**
 * Entrada de leads (M18): formularios del sitio, WhatsApp con intención de
 * compra, cotizador, agenda, API y altas manuales.
 *
 * - Idempotente por (origen, referencia): reintentar la misma entrada devuelve
 *   el mismo lead.
 * - Un lead abierto por contacto: si ya tiene uno, se actualiza (actividad,
 *   señales, servicio y valor si faltaban) y no se duplica.
 * - Un lead perdido hace menos de 30 días se reabre (vuelve a la primera
 *   etapa); uno perdido hace más o uno ganado deja crear uno nuevo.
 * - El lead nuevo entra en la primera etapa del pipeline por defecto, con su
 *   fuente, puntaje (señales del perfil y de la navegación de 30 días), vendedor
 *   asignado, `lead.created` y el aviso al vendedor si llega caliente (o con cada
 *   lead nuevo si está activado). El mensaje se califica con IA en la cola `ai`.
 *
 * Devuelve el ID interno del lead, o null si el módulo de servicios no está en
 * el plan o el contacto no existe.
 */
final class CaptureLead implements LeadIntake
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly Entitlements $entitlements,
        private readonly LeadScorer $scorer,
        private readonly LeadWriter $writer,
        private readonly AssignLead $assign,
        private readonly AlertSeller $alerts,
        private readonly LeadEvents $events,
    ) {}

    public function capture(LeadIntakeInput $input): ?string
    {
        $workspace = Workspace::query()->find($input->workspaceId);
        if ($workspace === null || ! $this->entitlements->allows($workspace, 'services')) {
            return null;
        }

        return $this->current->run($workspace, fn (): ?string => $this->inWorkspace($workspace, $input));
    }

    private function inWorkspace(Workspace $workspace, LeadIntakeInput $input): ?string
    {
        $contact = Contact::query()->find($input->contactId);
        if ($contact === null) {
            return null;
        }
        if ($input->originRef !== null) {
            $existing = Lead::query()->where('origin', $input->origin)->where('origin_ref', $input->originRef)->first();
            if ($existing !== null) {
                return $existing->id;
            }
        }
        $settings = LeadSettings::for($workspace->id);
        $open = Lead::query()->where('contact_id', $contact->id)->whereNull('won_at')->whereNull('lost_at')->orderByDesc('created_at')->first();
        if ($open !== null) {
            if ($this->firstTime($workspace, $input)) {
                $this->update($workspace, $open, $settings, $input);
            }

            return $open->id;
        }
        $lost = Lead::query()->where('contact_id', $contact->id)->whereNotNull('lost_at')
            ->where('lost_at', '>=', now()->subDays(LeadDefaults::REOPEN_LOST_DAYS))->orderByDesc('lost_at')->first();
        if ($lost !== null) {
            if ($this->firstTime($workspace, $input)) {
                $this->reopen($workspace, $lost, $settings, $input);
            }

            return $lost->id;
        }

        return $this->create($workspace, $contact, $settings, $input)->id;
    }

    private function create(Workspace $workspace, Contact $contact, LeadSettings $settings, LeadIntakeInput $input): Lead
    {
        $pipeline = Pipelines::default($workspace->id);
        $lead = new Lead([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'contact_id' => $contact->id,
            'site_id' => $input->siteId,
            'stage' => Pipelines::firstOpen($pipeline),
            'service' => self::service($input->service),
            'value_ws' => self::value($input->value),
            'source' => $input->source !== [] ? $input->source : null,
            'origin' => $input->origin,
            'origin_ref' => $input->originRef !== null ? mb_substr($input->originRef, 0, 64) : null,
            'score' => 0,
            'temperature' => 'cold',
            'score_reasons' => [],
            'last_activity_at' => now(),
        ]);
        $this->originSignal($lead, $settings, $input->origin);
        $this->scorer->history($lead, $contact, $settings, $workspace);
        $this->scorer->recompute($lead, $settings);
        $lead->owner_id = $this->assign->handle($lead, $settings);
        $lead->save();
        Cache::put(RedisKey::for($workspace->id, 'leads:any'), true, 300);
        LeadScorer::mirror($lead);
        LeadActivity::log($lead, 'created', 'system', array_filter([
            'origin' => $input->origin, 'score' => $lead->score, 'temperature' => $lead->temperature,
            'channel' => $lead->source['channel'] ?? null, 'owner_id' => $lead->owner_id,
        ], fn ($v) => $v !== null));

        $this->events->created($workspace, $lead);
        if ($lead->temperature === 'hot') {
            $this->alerts->handle($workspace, $lead, $settings, 'hot');
        } else {
            $this->alerts->handle($workspace, $lead, $settings, 'new');
        }
        $this->qualify($workspace, $lead, $settings, $input);

        return $lead;
    }

    private function update(Workspace $workspace, Lead $lead, LeadSettings $settings, LeadIntakeInput $input): void
    {
        $lead->last_activity_at = now();
        $lead->service ??= self::service($input->service);
        $lead->value_ws ??= self::value($input->value);
        $lead->source = self::mergeSource($lead->source, $input->source);
        $this->originSignal($lead, $settings, $input->origin);
        LeadActivity::log($lead, 'intake', 'system', ['origin' => $input->origin]);
        $this->writer->save($workspace, $lead, $settings);
        $this->qualify($workspace, $lead, $settings, $input);
    }

    private function reopen(Workspace $workspace, Lead $lead, LeadSettings $settings, LeadIntakeInput $input): void
    {
        $from = $lead->stage;
        $lead->stage = Pipelines::firstOpen(Pipelines::for($lead));
        $lead->lost_at = null;
        $lead->lost_reason = null;
        $lead->last_activity_at = now();
        $lead->service ??= self::service($input->service);
        $lead->source = self::mergeSource($lead->source, $input->source);
        $this->originSignal($lead, $settings, $input->origin);
        $seq = LeadActivity::query()->where('lead_id', $lead->id)->where('kind', 'stage')->count() + 1;
        LeadActivity::log($lead, 'stage', 'system', ['from' => $from, 'to' => $lead->stage, 'reopened' => true, 'origin' => $input->origin]);
        $this->writer->save($workspace, $lead, $settings);
        $this->events->stageChanged($workspace, $lead, $from, $lead->stage, 'system', $seq);
        $this->qualify($workspace, $lead, $settings, $input);
    }

    private function originSignal(Lead $lead, LeadSettings $settings, string $origin): void
    {
        match ($origin) {
            'form', 'quote', 'booking' => $this->scorer->signal($lead, $settings, 'form', ['origin' => $origin]),
            'whatsapp' => $this->scorer->signal($lead, $settings, 'whatsapp_click', ['origin' => 'whatsapp']),
            default => null,
        };
    }

    private function qualify(Workspace $workspace, Lead $lead, LeadSettings $settings, LeadIntakeInput $input): void
    {
        $message = trim((string) $input->message);
        if ($message !== '' && $settings->ai_qualify) {
            QualifyLeadWithAi::dispatch($workspace->id, $lead->id, mb_substr($message, 0, 3000))->afterCommit();
        }
    }

    /** La misma entrada (origen y referencia) solo actualiza el lead una vez. */
    private function firstTime(Workspace $workspace, LeadIntakeInput $input): bool
    {
        if ($input->originRef === null) {
            return true;
        }

        return Cache::add(RedisKey::for($workspace->id, 'lead:intake:'.$input->origin.':'.sha1($input->originRef)), 1, now()->addDays(30));
    }

    /**
     * Agrega a la fuente lo que faltaba (p. ej. un gclid que llegó después).
     *
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $new
     * @return array<string, mixed>|null
     */
    private static function mergeSource(?array $current, array $new): ?array
    {
        if ($current === null || $current === []) {
            return $new !== [] ? $new : null;
        }

        return $current + $new;
    }

    private static function service(?string $service): ?string
    {
        return $service !== null && trim($service) !== '' ? mb_substr(trim($service), 0, 160) : null;
    }

    private static function value(?string $value): ?string
    {
        return $value !== null && is_numeric($value) && (float) $value >= 0 ? number_format((float) $value, 2, '.', '') : null;
    }
}
