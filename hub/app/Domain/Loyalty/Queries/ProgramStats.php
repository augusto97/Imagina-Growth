<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Queries;

use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyReward;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Números del programa: miembros, puntos emitidos, canjeados y vencidos por
 * mes, puntos y saldo por pagar (el pasivo del programa) y miembros por nivel.
 */
final class ProgramStats
{
    /** @return array<string, mixed> */
    public function handle(Workspace $workspace, ?LoyaltyProgram $program, int $months = 6): array
    {
        $tz = $workspace->timezone ?: 'America/Bogota';
        $start = CarbonImmutable::now($tz)->startOfMonth()->subMonths($months - 1);
        $ledger = fn () => DB::table('loyalty_ledger')->where('workspace_id', $workspace->id);
        $contacts = fn () => DB::table('contacts')->where('workspace_id', $workspace->id)->whereNull('merged_into_id');

        // Mes local de cada movimiento (las fechas se guardan en UTC).
        $offset = $start->format('P');
        $rows = $ledger()->where('kind', 'points')->where('created_at', '>=', $start->utc())
            ->selectRaw("DATE_FORMAT(CONVERT_TZ(created_at, '+00:00', ?), '%Y-%m') AS month", [$offset])
            ->selectRaw("SUM(CASE WHEN status = 'confirmed' AND delta > 0 AND reason NOT IN ('merge', 'redeem_undo') THEN delta ELSE 0 END) AS issued")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN delta ELSE 0 END) AS pending")
            ->selectRaw("SUM(CASE WHEN reason = 'redeem' THEN -delta WHEN reason = 'redeem_undo' THEN -delta ELSE 0 END) AS redeemed")
            ->selectRaw("SUM(CASE WHEN status = 'expired' THEN -delta ELSE 0 END) AS expired")
            ->groupBy('month')->get()->keyBy('month');
        $monthly = [];
        for ($i = 0; $i < $months; $i++) {
            $key = $start->addMonths($i)->format('Y-m');
            $r = $rows->get($key);
            $monthly[] = [
                'month' => $key,
                'issued' => (int) round((float) ($r->issued ?? 0)),
                'redeemed' => (int) round((float) ($r->redeemed ?? 0)),
                'expired' => (int) round((float) ($r->expired ?? 0)),
            ];
        }

        $members = (int) $contacts()->whereExists(fn ($q) => $q->from('loyalty_ledger as l')->whereColumn('l.contact_id', 'contacts.id'))->count();
        $outstanding = (int) $contacts()->where('points_balance', '>', 0)->sum('points_balance');
        $wallet = (string) $contacts()->where('wallet_balance_ws', '>', 0)->sum('wallet_balance_ws');
        $pointValue = $program !== null ? self::pointValue($program) : null;

        $byTier = [];
        if ($program !== null) {
            $counts = $contacts()->whereNotNull('tier_id')->groupBy('tier_id')->selectRaw('tier_id, COUNT(*) AS n')->pluck('n', 'tier_id');
            foreach (DB::table('loyalty_tiers')->where('program_id', $program->id)->orderBy('rank')->get(['id', 'name']) as $tier) {
                $byTier[] = ['id' => 'tr_'.$tier->id, 'name' => $tier->name, 'members' => (int) ($counts[$tier->id] ?? 0)];
            }
        }
        $since30 = CarbonImmutable::now()->subDays(30);
        $redemptions = (int) $ledger()->where('kind', 'points')->where('reason', 'redeem')->where('created_at', '>=', $since30)->count();
        $couponRevenue = (string) DB::table('coupons_issued as c')->join('orders as o', 'o.id', '=', 'c.redeemed_order_id')
            ->where('c.workspace_id', $workspace->id)->where('c.source_type', 'loyalty')->where('c.redeemed_at', '>=', $since30)
            ->sum(DB::raw('COALESCE(o.total_ws, o.total)'));

        return [
            'members' => $members,
            'points_outstanding' => $outstanding,
            'points_pending' => $this->pending($workspace),
            'wallet_outstanding' => number_format((float) $wallet, 2, '.', ''),
            'point_value' => $pointValue,
            'points_liability' => $pointValue !== null ? number_format($outstanding * (float) $pointValue, 2, '.', '') : null,
            'monthly' => $monthly,
            'by_tier' => $byTier,
            'redemptions_30d' => $redemptions,
            'redeemed_orders_revenue_30d' => number_format((float) $couponRevenue, 2, '.', ''),
        ];
    }

    /** Puntos pendientes de todo el programa: las entradas pendientes sin confirmar menos sus reversiones. */
    private function pending(Workspace $workspace): int
    {
        $confirmedParents = DB::table('loyalty_ledger')->where('workspace_id', $workspace->id)->where('status', 'confirmed')->whereNotNull('parent_id')->select('parent_id');

        return (int) round((float) DB::table('loyalty_ledger')->where('workspace_id', $workspace->id)->where('kind', 'points')
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('status', 'pending')->whereNotIn('id', $confirmedParents))
                ->orWhere(fn ($b) => $b->where('status', 'reversed')->whereNotIn('parent_id', $confirmedParents)))
            ->sum('delta'));
    }

    /**
     * Cuánto vale un punto para la tienda: la mejor relación valor/puntos entre
     * las recompensas de monto fijo (lo que costaría si todos canjean la mejor).
     */
    public static function pointValue(LoyaltyProgram $program): ?string
    {
        $best = null;
        foreach (LoyaltyReward::query()->where('program_id', $program->id)->where('status', 'active')->whereIn('kind', ['coupon_fixed', 'wallet', 'free_product'])->get() as $reward) {
            if ($reward->points_cost > 0 && (float) $reward->value > 0) {
                $v = (float) $reward->value / $reward->points_cost;
                $best = $best === null ? $v : max($best, $v);
            }
        }

        return $best === null ? null : number_format($best, 4, '.', '');
    }
}
