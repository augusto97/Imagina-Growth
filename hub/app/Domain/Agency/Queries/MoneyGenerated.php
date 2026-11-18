<?php

declare(strict_types=1);

namespace App\Domain\Agency\Queries;

use App\Domain\Workspaces\Models\Workspace;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * «Todo módulo muestra el dinero que generó» (CLAUDE.md §3.7): las ventas pagadas
 * de un periodo que la plataforma ayudó a cerrar, por módulo. Cada pedido cuenta
 * **una sola vez**, en el primer módulo que lo explica según este orden:
 *
 *   afiliados → referidos → flujos → campañas → agente de IA → bandeja →
 *   captación en el sitio → carritos recuperados → otros mensajes
 *
 * Fuentes: `orders.attributed_*` (atribución, hub/CLAUDE.md §9), los carritos que
 * armó el agente (`ai_agent_turns.cart_id`), los mensajes de la bandeja
 * (`messages.source_type = inbox`) y los carritos recuperados
 * (`carts.recovered_order_id`). Ventas = `total_ws` de los pedidos pagados en el
 * periodo, sin los cancelados, fallidos ni reembolsados.
 *
 * La usan el panel de agencia, el reporte mensual y cualquier pantalla que
 * quiera el dinero generado.
 */
final class MoneyGenerated
{
    public const MODULES = ['affiliates', 'referrals', 'flows', 'campaigns', 'ai_agent', 'inbox', 'widgets', 'recovered_carts', 'messages'];

    /**
     * @return array{currency: string, from: string, to: string, total: string, orders: int, store_total: string, store_orders: int,
     *     share_pct: float, modules: list<array{key: string, revenue: string, orders: int}>}
     */
    public function forWorkspace(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ws = $workspace->id;
        $agentOrders = DB::table('carts')->where('workspace_id', $ws)
            ->whereIn('id', DB::table('ai_agent_turns')->where('workspace_id', $ws)->whereNotNull('cart_id')->select('cart_id'))
            ->selectRaw('COALESCE(recovered_order_id, order_id) AS oid')->pluck('oid')->filter()->map(fn ($v) => (string) $v)->flip()->all();
        $recovered = DB::table('carts')->where('workspace_id', $ws)->whereNotNull('recovered_order_id')
            ->pluck('recovered_order_id')->map(fn ($v) => (string) $v)->flip()->all();

        $rows = DB::table('orders as o')
            ->leftJoin('messages as m', 'm.id', '=', 'o.attributed_message_id')
            ->where('o.workspace_id', $ws)->where('o.is_paid', true)
            ->whereNotIn('o.status', ['cancelled', 'failed', 'refunded'])
            ->where('o.paid_at', '>=', $from->utc())->where('o.paid_at', '<', $to->utc())
            ->get(['o.id', 'o.total_ws', 'o.attributed_affiliate_id', 'o.attributed_referral_id', 'o.attributed_flow_id', 'o.attributed_campaign_id',
                'o.attributed_widget_id', 'o.attributed_message_id', 'm.source_type as msg_source']);

        $modules = array_fill_keys(self::MODULES, ['revenue' => '0.00', 'orders' => 0]);
        $store = '0.00';
        $total = '0.00';
        $count = 0;
        foreach ($rows as $r) {
            $amount = Decimal::of((string) ($r->total_ws ?? '0'));
            $store = Decimal::add($store, $amount);
            $key = match (true) {
                $r->attributed_affiliate_id !== null => 'affiliates',
                $r->attributed_referral_id !== null => 'referrals',
                $r->attributed_flow_id !== null => 'flows',
                $r->attributed_campaign_id !== null => 'campaigns',
                isset($agentOrders[(string) $r->id]) => 'ai_agent',
                $r->attributed_message_id !== null && $r->msg_source === 'inbox' => 'inbox',
                $r->attributed_widget_id !== null => 'widgets',
                isset($recovered[(string) $r->id]) => 'recovered_carts',
                $r->attributed_message_id !== null => 'messages',
                default => null,
            };
            if ($key === null) {
                continue;
            }
            $modules[$key]['revenue'] = Decimal::add($modules[$key]['revenue'], $amount);
            $modules[$key]['orders']++;
            $total = Decimal::add($total, $amount);
            $count++;
        }

        $list = [];
        foreach ($modules as $key => $m) {
            $list[] = ['key' => $key, 'revenue' => $m['revenue'], 'orders' => $m['orders']];
        }
        usort($list, fn (array $a, array $b) => (float) $b['revenue'] <=> (float) $a['revenue']);

        return [
            'currency' => (string) ($workspace->currency ?: 'COP'),
            'from' => $from->toDateString(),
            'to' => $to->subDay()->toDateString(),
            'total' => $total,
            'orders' => $count,
            'store_total' => $store,
            'store_orders' => $rows->count(),
            'share_pct' => (float) $store > 0 ? round((float) $total / (float) $store * 100, 1) : 0.0,
            'modules' => $list,
        ];
    }

    /**
     * El mes calendario en la zona horaria del espacio de trabajo.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function month(Workspace $workspace, ?CarbonImmutable $anyDayOfMonth = null): array
    {
        $tz = (string) ($workspace->timezone ?: 'America/Bogota');
        $day = ($anyDayOfMonth ?? CarbonImmutable::now($tz))->setTimezone($tz);
        $from = $day->startOfMonth();

        return [$from, $from->addMonth()];
    }
}
