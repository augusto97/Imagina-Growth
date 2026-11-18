<?php

declare(strict_types=1);

namespace App\Domain\Agency\Queries;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\RedisKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tablero de la agencia (M22): cada cliente con la salud de la conexión, el
 * dinero generado este mes frente al anterior, los hallazgos críticos, el uso
 * frente al plan y el estado de facturación. Solo de las cuentas que se le pasan
 * (las de la persona, `AgencyAccess::accounts`).
 */
final class AgencyOverview
{
    public function __construct(
        private readonly MoneyGenerated $money,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * @param  list<string>  $accountIds
     * @return array{accounts: list<array<string, mixed>>, clients: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function handle(array $accountIds): array
    {
        $accounts = Account::query()->whereIn('id', $accountIds)->get()->keyBy('id');
        $clients = [];
        $totals = ['clients' => 0, 'money_by_currency' => [], 'critical_insights' => 0, 'disconnected' => 0, 'over_limit' => 0];
        foreach (Workspace::query()->whereIn('account_id', $accountIds)->orderBy('name')->get() as $ws) {
            $row = $this->client($ws, $accounts->get($ws->account_id));
            $clients[] = $row;
            $totals['clients']++;
            $cur = (string) $row['money']['currency'];
            $totals['money_by_currency'][$cur] = round(($totals['money_by_currency'][$cur] ?? 0) + (float) $row['money']['total'], 2);
            $totals['critical_insights'] += (int) $row['critical_insights'];
            $totals['disconnected'] += $row['health']['status'] === 'disconnected' ? 1 : 0;
            $totals['over_limit'] += ($row['usage']['pct'] ?? 0) >= 100 ? 1 : 0;
        }

        return [
            'accounts' => array_values($accounts->map(fn (Account $a) => ['id' => $a->publicId(), 'name' => $a->name, 'billing_status' => $a->getAttribute('billing_status')])->all()),
            'clients' => $clients,
            'totals' => $totals,
        ];
    }

    /** @return array<string, mixed> */
    private function client(Workspace $ws, ?Account $account): array
    {
        [$from, $to] = MoneyGenerated::month($ws);
        [$prevFrom, $prevTo] = MoneyGenerated::month($ws, $from->subDay());
        $cacheKey = RedisKey::for($ws->id, 'agency:money:'.$from->format('Y-m'));
        $now = Cache::remember($cacheKey, now()->addMinutes(10), fn () => $this->money->forWorkspace($ws, $from, $to));
        $prev = Cache::remember(RedisKey::for($ws->id, 'agency:money:'.$prevFrom->format('Y-m')), now()->addHour(), fn () => $this->money->forWorkspace($ws, $prevFrom, $prevTo));
        $delta = (float) $prev['total'] > 0 ? round(((float) $now['total'] - (float) $prev['total']) / (float) $prev['total'] * 100, 1) : null;

        return [
            'id' => $ws->publicId(),
            'name' => $ws->name,
            'account' => $account !== null ? ['id' => $account->publicId(), 'name' => $account->name] : null,
            'country' => $ws->country,
            'currency' => $ws->currency,
            'health' => $this->health($ws),
            'money' => ['currency' => $now['currency'], 'total' => $now['total'], 'orders' => $now['orders'], 'store_total' => $now['store_total'],
                'share_pct' => $now['share_pct'], 'previous_total' => $prev['total'], 'delta_pct' => $delta, 'top_module' => $now['modules'][0]['revenue'] > 0 ? $now['modules'][0]['key'] : null],
            'critical_insights' => DB::table('insights')->where('workspace_id', $ws->id)->where('severity', 'critical')
                ->whereIn('status', ['new', 'seen'])->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->count(),
            'usage' => $this->usage($ws),
            'billing' => [
                'status' => (string) ($account?->getAttribute('billing_status') ?? 'active'),
                'trial_ends_at' => self::iso($account?->getAttribute('trial_ends_at')),
                'grace_ends_at' => self::iso($account?->getAttribute('grace_ends_at')),
            ],
        ];
    }

    /** @return array{status: string, sites: int, connected: int, last_event_at: string|null, minutes_since_event: int|null} */
    private function health(Workspace $ws): array
    {
        $sites = DB::table('sites')->where('workspace_id', $ws->id)->get(['status', 'last_event_at']);
        $connected = $sites->whereIn('status', ['connected'])->count();
        $degraded = $sites->where('status', 'degraded')->count();
        $last = $sites->max('last_event_at');
        $minutes = $last !== null ? (int) CarbonImmutable::parse((string) $last)->diffInMinutes(now(), true) : null;
        $status = match (true) {
            $sites->isEmpty() => 'no_sites',
            $connected === 0 && $degraded === 0 => 'disconnected',
            $degraded > 0 || ($minutes !== null && $minutes > 24 * 60) => 'degraded',
            default => 'healthy',
        };

        return ['status' => $status, 'sites' => $sites->count(), 'connected' => $connected, 'last_event_at' => self::iso($last), 'minutes_since_event' => $minutes];
    }

    /** @return array{plan: string|null, metric: string|null, used: int|null, limit: int|null, pct: float|null} */
    private function usage(Workspace $ws): array
    {
        $plan = $this->entitlements->planFor($ws);
        $metric = DB::table('sites')->where('workspace_id', $ws->id)->where('platform', 'woocommerce')->exists() ? 'orders' : 'contacts';
        $limit = $this->entitlements->limit($ws, $metric);
        $row = DB::table('usage_counters')->where('workspace_id', $ws->id)->where('period', now($ws->timezone ?: 'America/Bogota')->format('Y-m'))->first();
        $used = $row !== null ? (int) ($row->{$metric} ?? 0) : null;

        return ['plan' => $plan, 'metric' => $metric, 'used' => $used, 'limit' => $limit,
            'pct' => $used !== null && $limit !== null && $limit > 0 ? round($used / $limit * 100, 1) : null];
    }

    private static function iso(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toIso8601String();
    }
}
