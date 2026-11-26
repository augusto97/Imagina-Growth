<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Actions;

use App\Domain\Campaigns\Audience\AudienceQuery;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Support\CommercialCalendar;
use App\Domain\Segments\Models\Segment;
use App\Domain\Services\Verticals\VerticalCatalog;
use App\Domain\Templates\Library\InstallLibraryTemplate;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * «La campaña lista» de un momento del calendario comercial (rediseño v2 §11.5): crea, en borrador, la campaña que
 * sugiere el calendario para el tipo de negocio y la vertical del espacio de trabajo, con su plantilla de la galería
 * ya con los textos del momento, su grupo de clientes (o todos los contactos; el consentimiento se revisa al enviar)
 * y programada para el día sugerido a las 10:00 (nunca sale sin que la persona la revise y la apruebe). Idempotente por
 * momento y año: la segunda vez devuelve la misma (`settings.calendar_campaigns`), salvo que se haya cancelado.
 */
final class CreateCalendarCampaign
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly InstallLibraryTemplate $install,
        private readonly RecordAuditLog $audit,
    ) {}

    /** @return array{campaign: Campaign, created: bool} */
    public function handle(Workspace $workspace, string $key, ?int $year, ?string $userId, ?CarbonImmutable $today = null): array
    {
        return $this->current->run($workspace, fn (): array => $this->inWorkspace($workspace, $key, $year, $userId, $today));
    }

    /** @return array{campaign: Campaign, created: bool} */
    private function inWorkspace(Workspace $workspace, string $key, ?int $year, ?string $userId, ?CarbonImmutable $today): array
    {
        $tz = (string) ($workspace->timezone ?: 'America/Bogota');
        $today ??= CarbonImmutable::now($tz)->startOfDay();
        $profile = VerticalCatalog::forWorkspace($workspace);
        $moment = null;
        foreach (CommercialCalendar::upcoming((string) $workspace->country, $profile['business_type'], $profile['vertical'], $today, 400) as $m) {
            if ($m['key'] === $key && ($year === null || $m['year'] === $year)) {
                $moment = $m;

                break;
            }
        }
        if ($moment === null) {
            throw ValidationException::withMessages(['moment' => __('Ese momento del calendario no aplica a tu negocio o ya pasó.')]);
        }
        $ref = $key.':'.$moment['year'];
        $lock = Cache::lock('igr:calendar-campaign:'.$workspace->id.':'.$ref, 15);
        $lock->block(10);
        try {
            return $this->create($workspace, $moment, $ref, $userId, $tz, $today);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $moment
     * @return array{campaign: Campaign, created: bool}
     */
    private function create(Workspace $workspace, array $moment, string $ref, ?string $userId, string $tz, CarbonImmutable $today): array
    {
        $workspace->refresh();
        $known = (array) ($workspace->setting('calendar_campaigns') ?? []);
        $existing = isset($known[$ref]) && is_string($known[$ref]) ? Campaign::query()->find($known[$ref]) : null;
        if ($existing !== null && $existing->status !== Campaign::CANCELLED) {
            return ['campaign' => $existing, 'created' => false];
        }
        /** @var array{for: string, template: string, channel: string, segment: string|null, copy: array<string, string>} $suggested */
        $suggested = $moment['campaign'];
        $name = mb_substr($moment['name'].' '.$moment['year'], 0, 160);
        $template = $suggested['channel'] === 'email'
            ? TemplateLibrary::install($workspace, $suggested['template'], null, $userId, true, $suggested['copy'], $name.' · '.__('correo'))
            : $this->install->handle($workspace, $suggested['template'], null, $userId)['template'];
        $segment = $suggested['segment'] !== null ? Segment::query()->where('system_key', $suggested['segment'])->first() : null;
        $send = CarbonImmutable::parse((string) $moment['starts_communicating'], $tz)->setTime(10, 0);
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'channel' => $suggested['channel'],
            'audience' => AudienceQuery::normalize($segment !== null ? ['segments_include' => [$segment->publicId()]] : ['all_contacts' => true]),
            'template_id' => $template->id,
            'send_mode' => $send->greaterThan($today->setTimezone($tz)->setTime(10, 0)) ? 'scheduled' : 'now',
            'send_at' => $send->greaterThan($today->setTimezone($tz)->setTime(10, 0)) ? $send->utc() : null,
            'created_by' => $userId,
        ]);
        $known[$ref] = $campaign->id;
        $settings = (array) ($workspace->settings ?? []);
        $settings['calendar_campaigns'] = $known;
        $workspace->forceFill(['settings' => $settings])->save();
        $this->audit->handle('campaign.created', $campaign, ['name' => $campaign->name, 'channel' => $campaign->channel, 'source' => 'calendar', 'moment' => $ref], $workspace->id, null, $userId);

        return ['campaign' => $campaign, 'created' => true];
    }
}
