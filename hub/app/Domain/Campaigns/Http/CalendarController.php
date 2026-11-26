<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Http;

use App\Domain\Campaigns\Actions\CreateCalendarCampaign;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Support\CommercialCalendar;
use App\Domain\Segments\Models\Segment;
use App\Domain\Services\Verticals\VerticalCatalog;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Workspaces\Models\User;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Calendario comercial del espacio de trabajo (rediseño v2 §11.5): los próximos momentos de su país que aplican a su
 * tipo de negocio y vertical, cada uno con la campaña sugerida (plantilla de la galería, canal, grupo de clientes,
 * cuándo enviarla y desde cuándo se avisa) y, si ya la creó, su ID. `POST calendar/{clave}/campaign` la deja lista en
 * borrador (CreateCalendarCampaign, idempotente).
 */
final class CalendarController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET /api/w/{ws}/calendar?days=180 */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $data = $request->validate(['days' => ['nullable', 'integer', 'between:7,400']]);
        $ws = $this->current->get();
        $tz = (string) ($ws->timezone ?: 'America/Bogota');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $profile = VerticalCatalog::forWorkspace($ws);
        $country = in_array($ws->country, CommercialCalendar::COUNTRIES, true) ? (string) $ws->country : 'CO';
        $created = (array) ($ws->setting('calendar_campaigns') ?? []);
        $campaigns = Campaign::query()->whereIn('id', array_values(array_filter($created, 'is_string')))->get(['id', 'status'])->keyBy('id');
        $segments = Segment::query()->whereNotNull('system_key')->pluck('name', 'system_key');

        return response()->json([
            'country' => $country,
            'business_type' => $profile['business_type'],
            'vertical' => $profile['vertical'],
            'data' => array_map(function (array $m) use ($today, $created, $campaigns, $segments): array {
                $id = $created[$m['key'].':'.$m['year']] ?? null;
                $campaign = is_string($id) ? $campaigns->get($id) : null;
                $entry = TemplateLibrary::entry($m['campaign']['template']);

                return [
                    'key' => $m['key'],
                    'year' => $m['year'],
                    'name' => $m['name'],
                    'date' => $m['date'],
                    'ends' => $m['ends'],
                    'send_on' => $m['starts_communicating'],
                    'notice_from' => $m['notice_from'],
                    'days_left' => (int) $today->diffInDays(CarbonImmutable::parse($m['date'], $today->getTimezone()), false),
                    'ideas' => $m['ideas'],
                    'overridden' => $m['overridden'],
                    'campaign' => [
                        'for' => $m['campaign']['for'],
                        'channel' => $m['campaign']['channel'],
                        'template_key' => $m['campaign']['template'],
                        'template_name' => $entry['name'] ?? null,
                        'subject' => $m['campaign']['copy']['subject'] ?? null,
                        'segment_key' => $m['campaign']['segment'],
                        'segment_name' => $m['campaign']['segment'] !== null ? ($segments[$m['campaign']['segment']] ?? null) : null,
                    ],
                    'campaign_id' => $campaign !== null && $campaign->status !== Campaign::CANCELLED ? PrefixedId::encode(Campaign::ID_PREFIX, $campaign->id) : null,
                ];
            }, CommercialCalendar::upcoming($country, $profile['business_type'], $profile['vertical'], $today, (int) ($data['days'] ?? 180))),
        ]);
    }

    /** POST /api/w/{ws}/calendar/{key}/campaign {year?} */
    public function campaign(Request $request, string $key, CreateCalendarCampaign $create): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2020,2100']]);
        if (! in_array($key, CommercialCalendar::keys(), true)) {
            abort(404);
        }
        /** @var User $user */
        $user = $request->user();
        $result = $create->handle($this->current->get(), $key, isset($data['year']) ? (int) $data['year'] : null, $user->id);

        return response()->json(CampaignPresenter::make($result['campaign']) + ['created' => $result['created']], $result['created'] ? 201 : 200);
    }
}
