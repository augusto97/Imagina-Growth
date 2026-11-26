<?php

declare(strict_types=1);

namespace App\Domain\Home\Queries;

use App\Analytics\Semantic\MetricQueryRunner;
use App\Domain\Agency\Queries\MoneyGenerated;
use App\Domain\Services\Leads\Assistant\TodayList;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * La página de entrada de cada sección (docs/diseno/rediseno-v2.md §3): las cifras que importan y lo que conviene hacer
 * primero, en una sola llamada (`GET sections/{section}`). Solo números y claves: los textos los pone la SPA
 * (`sectionEntry.<sección>` en sus textos), así cada pantalla puede ajustar cómo lo dice sin tocar el servidor.
 *
 * Cada cifra es un conteo o una suma del espacio de trabajo (sin datos personales) y solo se calcula si el rol puede
 * ver lo que cuenta; el dinero generado sale de la misma caché que el Inicio (`home:money:<mes>`). `next` es lo primero
 * que conviene hacer, con su ruta relativa a /w/:ws y los números para su texto.
 *
 * @phpstan-type Figure array{key: string, value: float|int, unit: string, to: string|null}
 * @phpstan-type Next array{kind: string, to: string, params: array<string, int|string>}
 */
final class SectionSummary
{
    public const SECTIONS = ['customers', 'conversations', 'messages', 'onsite', 'results', 'settings'];

    /** Lo que suma cada sección del dinero generado (MoneyGenerated::MODULES). */
    private const MONEY = [
        'conversations' => ['inbox', 'ai_agent'],
        'messages' => ['flows', 'campaigns', 'messages', 'recovered_carts'],
        'onsite' => ['widgets', 'referrals', 'affiliates', 'quotes'],
    ];

    public function __construct(private readonly MoneyGenerated $money) {}

    /**
     * @return array{section: string, business_type: string, currency: string, month: array{from: string, to: string}, figures: list<Figure>, next: Next|null}
     */
    public function handle(Workspace $workspace, string $section): array
    {
        $ctx = StrategyContext::for($workspace);
        $tz = (string) ($workspace->timezone ?: 'America/Bogota');
        $now = CarbonImmutable::now($tz);
        $from = $now->startOfMonth()->utc();
        $to = $now->utc();
        [$figures, $next] = match ($section) {
            'customers' => $this->customers($workspace, $ctx, $from, $to),
            'conversations' => $this->conversations($workspace, $ctx, $from, $to),
            'messages' => $this->messages($workspace, $ctx, $from, $to),
            'onsite' => $this->onsite($workspace, $ctx, $from, $to),
            'results' => $this->results($workspace, $ctx),
            default => $this->settings($workspace, $ctx),
        };

        return [
            'section' => $section,
            'business_type' => $ctx->businessType(),
            'currency' => (string) ($workspace->currency ?: 'COP'),
            'month' => ['from' => $now->startOfMonth()->toDateString(), 'to' => $now->toDateString()],
            'figures' => array_values(array_filter($figures)),
            'next' => $next,
        ];
    }

    /**
     * Tienda: personas, clientes, nuevos del mes y en riesgo. Sin tienda: contactos abiertos, por atender hoy, citas
     * de la semana y ganados del mes.
     *
     * @return array{0: list<Figure|null>, 1: Next|null}
     */
    private function customers(Workspace $workspace, StrategyContext $ctx, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ws = $workspace->id;
        $type = $ctx->businessType();
        $figures = [];
        $next = null;
        if ($type !== 'services' && Gate::allows('ws.viewAssignedContacts')) {
            $contacts = fn () => DB::table('contacts')->where('workspace_id', $ws)->whereNull('deleted_at')->whereNull('merged_into_id');
            $people = $contacts()->count();
            $atRisk = $contacts()->whereIn('rfm_segment', ['at_risk', 'cant_lose'])->count();
            $figures[] = self::figure('people', $people, 'count', 'contactos');
            $figures[] = self::figure('customers', $contacts()->where('orders_count', '>', 0)->count(), 'count', 'contactos');
            $figures[] = self::figure('new_customers', $contacts()->whereBetween('first_order_at', [$from, $to])->count(), 'count', 'contactos');
            $figures[] = self::figure('at_risk', $atRisk, 'count', 'estrategias/winback');
            $next = match (true) {
                $people === 0 && ! $ctx->hasSite() => self::next('connect_site', 'sitios?conectar=1'),
                $people === 0 => self::next('import_contacts', 'contactos/importar'),
                $atRisk > 0 => self::next('win_back', 'estrategias/winback', ['count' => $atRisk]),
                default => self::next('create_group', 'segmentos/nuevo'),
            };
        }
        if ($type !== 'store' && $ctx->module('services') && Gate::allows('ws.useLeads')) {
            $open = DB::table('leads')->where('workspace_id', $ws)->whereNull('won_at')->whereNull('lost_at')->count();
            $counts = $open > 0 ? app(TodayList::class)->counts($workspace, null) : [];
            $today = (int) ($counts['reply'] ?? 0) + (int) ($counts['first_contact'] ?? 0) + (int) ($counts['task'] ?? 0) + (int) ($counts['next_step'] ?? 0);
            $figures[] = self::figure('open_leads', $open, 'count', 'leads');
            $figures[] = self::figure('today', $today, 'count', 'hoy');
            if ($ctx->module('bookings')) {
                $figures[] = self::figure('appointments_week', DB::table('appointments')->where('workspace_id', $ws)
                    ->whereBetween('starts_at', [CarbonImmutable::now(), CarbonImmutable::now()->addDays(7)])
                    ->whereNotIn('status', ['cancelled', 'no_show'])->count(), 'count', 'agenda');
            }
            $figures[] = self::figure('won_month', DB::table('leads')->where('workspace_id', $ws)->whereBetween('won_at', [$from, $to])->count(), 'count', 'leads');
            $people = DB::table('contacts')->where('workspace_id', $ws)->whereNull('deleted_at')->whereNull('merged_into_id')->exists();
            $next = match (true) {
                $today > 0 => self::next('attend_today', 'hoy', ['count' => $today]),
                $open === 0 && ! $people && ! $ctx->hasSite() => self::next('connect_site', 'sitios?conectar=1'),
                $open === 0 => self::next('first_lead', 'leads'),
                default => $next ?? self::next('review_leads', 'leads', ['count' => $open]),
            };
        }

        return [$figures, $next];
    }

    /**
     * Esperando respuesta, abiertas, sin asignar y resueltas del mes, con lo que generaron.
     *
     * @return array{0: list<Figure|null>, 1: Next|null}
     */
    private function conversations(Workspace $workspace, StrategyContext $ctx, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Gate::allows('ws.useInbox')) {
            return [[], null];
        }
        $ws = $workspace->id;
        $open = fn () => DB::table('conversations')->where('workspace_id', $ws)->where('status', '!=', 'closed');
        $waiting = $open()->whereNotNull('awaiting_since')->count();
        $unassigned = $open()->whereNull('assignee_id')->count();
        $figures = [
            self::figure('waiting', $waiting, 'count', 'bandeja'),
            self::figure('open', $open()->count(), 'count', 'bandeja'),
            self::figure('unassigned', $unassigned, 'count', 'bandeja'),
            $this->moneyFigure($workspace, 'conversations'),
        ];
        $hasChannel = $ctx->hasChannel('whatsapp', 'messenger', 'instagram');
        $next = match (true) {
            ! $hasChannel => self::next('connect_channel', 'canales'),
            $waiting > 0 => self::next('reply', 'bandeja', ['count' => $waiting]),
            $unassigned > 0 => self::next('assign', 'bandeja', ['count' => $unassigned]),
            default => self::next('agent', 'bandeja/agente'),
        };

        return [$figures, $next];
    }

    /**
     * Automatizaciones prendidas, campañas y mensajes del mes y el dinero que generaron.
     *
     * @return array{0: list<Figure|null>, 1: Next|null}
     */
    private function messages(Workspace $workspace, StrategyContext $ctx, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Gate::allows('ws.viewDashboards')) {
            return [[], null];
        }
        $ws = $workspace->id;
        $active = DB::table('flows')->where('workspace_id', $ws)->where('status', 'active')->count();
        $figures = [
            self::figure('active_flows', $active, 'count', 'flujos'),
            self::figure('campaigns_month', DB::table('campaigns')->where('workspace_id', $ws)->whereBetween('started_at', [$from, $to])->count(), 'count', 'campanas'),
            self::figure('sent_month', DB::table('messages')->where('workspace_id', $ws)->where('direction', 'outbound')->whereBetween('created_at', [$from, $to])->count(), 'count', null),
            $this->moneyFigure($workspace, 'messages'),
        ];
        $next = match (true) {
            ! $ctx->hasChannel('whatsapp', 'email') => self::next('connect_channel', 'canales'),
            $active === 0 => self::next('first_automation', 'estrategias'),
            default => self::next('campaign', 'campanas'),
        };

        return [$figures, $next];
    }

    /**
     * Lo que se muestra en la web: formularios prendidos, contactos captados y reseñas del mes, y lo que generó.
     *
     * @return array{0: list<Figure|null>, 1: Next|null}
     */
    private function onsite(Workspace $workspace, StrategyContext $ctx, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Gate::allows('ws.viewDashboards')) {
            return [[], null];
        }
        $ws = $workspace->id;
        $widgets = DB::table('onsite_widgets')->where('workspace_id', $ws)->where('status', 'active')->count();
        $captured = DB::table('widget_captures')->where('workspace_id', $ws)->whereBetween('captured_at', [$from, $to])->count()
            + DB::table('landing_submissions')->where('workspace_id', $ws)->whereBetween('created_at', [$from, $to])->count();
        if ($ctx->businessType() === 'services') {
            // Sin tienda se capta también sin web: la página de captura y el WhatsApp con QR van primero.
            $pages = DB::table('landing_pages')->where('workspace_id', $ws)->where('status', 'published')->count();
            $figures = [
                self::figure('captured_month', $captured, 'count', null),
                self::figure('landing_pages', $pages, 'count', 'paginas-de-captura'),
                self::figure('active_widgets', $widgets, 'count', 'en-el-sitio'),
                $this->moneyFigure($workspace, 'onsite'),
            ];
            $next = match (true) {
                $pages === 0 => self::next('first_landing', 'paginas-de-captura'),
                $ctx->hasSite() && $widgets === 0 => self::next('first_contact_form', 'en-el-sitio'),
                default => self::next('landing_results', 'paginas-de-captura'),
            };

            return [$figures, $next];
        }
        $figures = [
            self::figure('active_widgets', $widgets, 'count', 'en-el-sitio'),
            self::figure('captured_month', $captured, 'count', null),
            self::figure('reviews_month', DB::table('reviews')->where('workspace_id', $ws)->where('status', 'published')->whereBetween('published_at', [$from, $to])->count(), 'count', 'resenas'),
            $this->moneyFigure($workspace, 'onsite'),
        ];
        $next = match (true) {
            ! $ctx->hasSite() => self::next('connect_site', 'sitios?conectar=1'),
            $widgets === 0 => self::next('first_widget', 'estrategias/welcome_popup'),
            default => self::next('widgets', 'en-el-sitio'),
        };

        return [$figures, $next];
    }

    /**
     * El dinero generado del mes, lo que vendió el negocio (o el valor ganado sin tienda), su parte y las
     * recomendaciones abiertas.
     *
     * @return array{0: list<Figure|null>, 1: Next|null}
     */
    private function results(Workspace $workspace, StrategyContext $ctx): array
    {
        if (! Gate::allows('ws.viewDashboards')) {
            return [[], null];
        }
        $money = $this->month($workspace);
        $insights = DB::table('insights')->where('workspace_id', $workspace->id)->whereIn('status', ['new', 'seen'])->count();
        $services = $ctx->businessType() === 'services';
        $figures = [
            self::figure('money_month', (float) $money['total'], 'money', null),
            self::figure($services ? 'won_value' : 'sales_month', (float) $money['store_total'], 'money', 'analitica'),
            self::figure('share', (float) $money['share_pct'], 'percent', null),
            self::figure('open_insights', $insights, 'count', 'hallazgos'),
        ];
        $next = match (true) {
            ! $ctx->hasSite() => self::next('connect_site', 'sitios?conectar=1'),
            $insights > 0 => self::next('insights', 'hallazgos', ['count' => $insights]),
            default => self::next('dashboards', 'analitica'),
        };

        return [$figures, $next];
    }

    /**
     * Lo conectado (la web y los canales) y el equipo, con lo que falta primero.
     *
     * @return array{0: list<Figure|null>, 1: Next|null}
     */
    private function settings(Workspace $workspace, StrategyContext $ctx): array
    {
        $ws = $workspace->id;
        $channels = count(array_filter($ctx->channels(), fn (string $status) => $status === 'active'));
        $members = DB::table('workspace_user')->where('workspace_id', $ws)->count();
        $pending = $ctx->pendingSiteId() !== null;
        $figures = [
            self::figure('sites', $ctx->connectedSites()->count(), 'count', 'sitios'),
            self::figure('channels', $channels, 'count', 'canales'),
            self::figure('members', $members, 'count', 'equipo'),
        ];
        $next = match (true) {
            ! $ctx->hasSite() && Gate::allows('ws.manageSites') => self::next($pending ? 'finish_site' : 'connect_site', $pending ? 'sitios' : 'sitios?conectar=1'),
            $channels === 0 && Gate::allows('ws.manageWorkspace') => self::next('connect_channel', 'canales'),
            $members <= 1 && Gate::allows('ws.manageMembers') => self::next('invite', 'equipo?invitar=1'),
            default => null,
        };

        return [$figures, $next];
    }

    /** @return Figure|null el dinero que generó lo de la sección este mes */
    private function moneyFigure(Workspace $workspace, string $section): ?array
    {
        if (! Gate::allows('ws.viewDashboards')) {
            return null;
        }
        $modules = collect($this->month($workspace)['modules'])->keyBy('key');
        $total = 0.0;
        foreach (self::MONEY[$section] as $module) {
            $total += (float) ($modules[$module]['revenue'] ?? 0);
        }

        return self::figure('money', round($total, 2), 'money', 'analitica');
    }

    /** @return array{total: string, store_total: string, share_pct: float, modules: list<array{key: string, revenue: string, orders: int}>} */
    private function month(Workspace $workspace): array
    {
        [$from, $to] = MoneyGenerated::month($workspace);

        // La misma llave que el Inicio: entrar a una sección después del Inicio no vuelve a calcularlo.
        return MetricQueryRunner::remember($workspace, 'home:money:'.$from->toDateString(), fn () => $this->money->forWorkspace($workspace, $from, $to));
    }

    /** @return Figure */
    private static function figure(string $key, float|int $value, string $unit, ?string $to): array
    {
        return ['key' => $key, 'value' => $value, 'unit' => $unit, 'to' => $to];
    }

    /**
     * @param  array<string, int|string>  $params
     * @return Next
     */
    private static function next(string $kind, string $to, array $params = []): array
    {
        return ['kind' => $kind, 'to' => $to, 'params' => $params];
    }
}
