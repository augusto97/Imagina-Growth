<?php

declare(strict_types=1);

namespace App\Analytics\Http;

use App\Analytics\Dashboards\ChannelsDashboard;
use App\Analytics\Dashboards\CouponsDashboard;
use App\Analytics\Dashboards\CustomersDashboard;
use App\Analytics\Dashboards\Dashboard;
use App\Analytics\Dashboards\FunnelDashboard;
use App\Analytics\Dashboards\GeographyDashboard;
use App\Analytics\Dashboards\HdyhauDashboard;
use App\Analytics\Dashboards\MessagingDashboard;
use App\Analytics\Dashboards\OverviewDashboard;
use App\Analytics\Dashboards\ProductsDashboard;
use App\Analytics\Dashboards\ProfitabilityDashboard;
use App\Analytics\Dashboards\SearchesDashboard;
use App\Analytics\Dashboards\ServicesDashboard;
use App\Analytics\Semantic\Catalog;
use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\MetricQuery;
use App\Analytics\Semantic\MetricQueryRunner;
use App\Analytics\Semantic\Period;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Tableros de M05 y consultas de la capa semántica. */
final class AnalyticsController
{
    /** @var array<string, class-string<Dashboard>> */
    public const DASHBOARDS = [
        'overview' => OverviewDashboard::class,
        'funnel' => FunnelDashboard::class,
        'channels' => ChannelsDashboard::class,
        'customers' => CustomersDashboard::class,
        'products' => ProductsDashboard::class,
        'searches' => SearchesDashboard::class,
        'geography' => GeographyDashboard::class,
        'coupons' => CouponsDashboard::class,
        'messaging' => MessagingDashboard::class,
        'profitability' => ProfitabilityDashboard::class,
        'services' => ServicesDashboard::class,
        'hdyhau' => HdyhauDashboard::class,
    ];

    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET /api/w/{ws}/analytics/{dashboard} */
    public function dashboard(Request $request, string $dashboard): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $class = self::DASHBOARDS[$dashboard] ?? abort(404);
        $workspace = $this->current->get();
        $period = $this->period($request);
        $filters = Filters::fromRequest($request);
        $key = 'dash:'.$dashboard.':'.md5((string) json_encode([$period->toArray(), $filters->toArray()]));

        $started = microtime(true);
        $data = MetricQueryRunner::remember($workspace, $key, fn () => app($class)->handle($workspace, $period, $filters));
        if ($dashboard === 'overview') {
            $data['insights'] = OverviewDashboard::topInsights();
        }

        return response()->json([
            'dashboard' => $dashboard,
            'period' => $period->toArray(),
            'filters' => $filters->toArray(),
            'currency' => $workspace->currency,
            'data' => $data,
            'took_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    /** GET /api/w/{ws}/analytics/metrics — catálogo con las definiciones («¿Cómo se calcula?»). */
    public function metrics(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json(['metrics' => Catalog::metrics(), 'dimensions' => array_map(fn ($d) => $d['label'], Catalog::DIMENSIONS)]);
    }

    /** GET /api/w/{ws}/analytics/query?metrics=revenue,orders&dimensions=date — consulta estructurada (la usará la IA de la Fase 4). */
    public function query(Request $request, MetricQueryRunner $runner): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $split = fn (string $key) => array_values(array_filter(explode(',', (string) $request->query($key, ''))));
        try {
            $query = new MetricQuery($split('metrics') ?: ['revenue'], $split('dimensions'), $this->period($request), Filters::fromRequest($request), min(500, max(1, (int) $request->query('limit', 100))));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['period' => $query->period->toArray()] + $runner->run($this->current->get(), $query));
    }

    private function period(Request $request): Period
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'compare' => ['nullable', 'in:previous,year,none'],
            'granularity' => ['nullable', 'in:day,week,month'],
        ]);
        try {
            return Period::make($this->current->get()->timezone, $data['from'] ?? null, $data['to'] ?? null, $data['compare'] ?? 'previous', $data['granularity'] ?? null);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }
}
