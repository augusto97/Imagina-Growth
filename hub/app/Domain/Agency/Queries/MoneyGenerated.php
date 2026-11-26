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
 * Negocios de servicios (M18), sin pedidos: cada lead ganado en el periodo suma su
 * valor (`leads.value_ws`) a las ventas del negocio, y al dinero generado si la
 * plataforma lo captó: formulario o WhatsApp → `leads`, página de reservas →
 * `bookings`, cotizador → `quotes`. Los leads creados a mano o por la API cuentan
 * como ventas, no como dinero generado. Un lead ganado es una venta, igual que un pedido.
 *
 * La usan el panel de agencia, el reporte mensual y cualquier pantalla que
 * quiera el dinero generado.
 */
final class MoneyGenerated
{
    public const MODULES = ['affiliates', 'referrals', 'flows', 'campaigns', 'ai_agent', 'inbox', 'widgets', 'recovered_carts', 'messages', 'leads', 'bookings', 'quotes'];

    /** Canales del dinero generado (`byChannel`), en el orden en que se muestran. */
    public const CHANNELS = ['whatsapp', 'email', 'sms', 'social', 'chats', 'web', 'referrals', 'ads', 'other'];

    /** Origen del lead (leads.origin) → módulo que lo captó. */
    private const LEAD_ORIGINS = ['form' => 'leads', 'whatsapp' => 'leads', 'messenger' => 'leads', 'instagram' => 'leads', 'landing' => 'leads',
        'meta_lead_ads' => 'leads', 'google_lead_form' => 'leads', 'booking' => 'bookings', 'quote' => 'quotes'];

    /** Origen del lead → canal por el que llegó (`byChannel`). */
    private const LEAD_CHANNELS = ['form' => 'web', 'landing' => 'web', 'booking' => 'web', 'quote' => 'web', 'whatsapp' => 'whatsapp',
        'messenger' => 'social', 'instagram' => 'social', 'meta_lead_ads' => 'ads', 'google_lead_form' => 'ads'];

    /** Canal del mensaje (messages.channel) → canal del dinero generado. */
    private const MESSAGE_CHANNELS = ['whatsapp' => 'whatsapp', 'email' => 'email', 'sms' => 'sms', 'messenger' => 'social', 'instagram' => 'social'];

    /**
     * @return array{currency: string, from: string, to: string, total: string, orders: int, store_total: string, store_orders: int,
     *     share_pct: float, modules: list<array{key: string, revenue: string, orders: int}>}
     */
    public function forWorkspace(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $modules = array_fill_keys(self::MODULES, ['revenue' => '0.00', 'orders' => 0]);
        $store = '0.00';
        $total = '0.00';
        $count = 0;
        $sales = 0;
        foreach ($this->sales($workspace, $from, $to) as $sale) {
            $store = Decimal::add($store, $sale['amount']);
            $sales++;
            $key = $sale['module'];
            if ($key === null || ! isset($modules[$key])) {
                continue;
            }
            $modules[$key]['revenue'] = Decimal::add($modules[$key]['revenue'], $sale['amount']);
            $modules[$key]['orders']++;
            $total = Decimal::add($total, $sale['amount']);
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
            'store_orders' => $sales,
            'share_pct' => (float) $store > 0 ? round((float) $total / (float) $store * 100, 1) : 0.0,
            'modules' => $list,
        ];
    }

    /**
     * El mismo dinero generado, con la fuente de cada venta: por módulo y, dentro de flujos, campañas y captación en
     * el sitio, por flujo, campaña o widget. Con la misma regla de una sola vez por pedido, así la suma de las fuentes
     * es el total de `forWorkspace`. Lo usan las estrategias y el Inicio para el desglose por estrategia.
     *
     * @return array{total: string, orders: int, modules: array<string, array{revenue: string, orders: int}>,
     *     flows: array<string, array{revenue: string, orders: int}>, campaigns: array<string, array{revenue: string, orders: int}>,
     *     widgets: array<string, array{revenue: string, orders: int}>}
     */
    public function bySource(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = ['total' => '0.00', 'orders' => 0, 'modules' => [], 'flows' => [], 'campaigns' => [], 'widgets' => []];
        foreach ($this->sales($workspace, $from, $to) as $sale) {
            $module = $sale['module'];
            if ($module === null) {
                continue;
            }
            $out['total'] = Decimal::add($out['total'], $sale['amount']);
            $out['orders']++;
            $out['modules'][$module] = self::plus($out['modules'][$module] ?? null, $sale['amount']);
            $bucket = match ($module) {
                'flows' => 'flows', 'campaigns' => 'campaigns', 'widgets' => 'widgets', default => null,
            };
            if ($bucket !== null && $sale['source'] !== null) {
                $out[$bucket][$sale['source']] = self::plus($out[$bucket][$sale['source']] ?? null, $sale['amount']);
            }
        }

        return $out;
    }

    /**
     * El mismo dinero generado por el canal por el que llegó cada venta (una sola vez por venta, así la suma es el total
     * de `forWorkspace`): el canal del mensaje que la atribuyó (WhatsApp, correo, SMS, Messenger o Instagram → `social`),
     * `chats` para el agente de ventas con IA, `web` para lo que captó la web (widgets, carritos restaurados, formularios,
     * reservas y cotizador), `referrals` para referidos y afiliados, `ads` para los leads de anuncios y `other` si el
     * mensaje ya no está. Lo usa el Resumen de Resultados.
     *
     * @return list<array{channel: string, revenue: string, orders: int}>
     */
    public function byChannel(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sums = [];
        foreach ($this->sales($workspace, $from, $to) as $sale) {
            if ($sale['module'] === null) {
                continue;
            }
            $sums[$sale['channel']] = self::plus($sums[$sale['channel']] ?? null, $sale['amount']);
        }
        $list = [];
        foreach (self::CHANNELS as $channel) {
            if (isset($sums[$channel])) {
                $list[] = ['channel' => $channel] + $sums[$channel];
            }
        }
        usort($list, fn (array $a, array $b) => (float) $b['revenue'] <=> (float) $a['revenue']);

        return $list;
    }

    /**
     * Cada venta del periodo (pedidos pagados y leads ganados) con el módulo que la explica (null si ninguno), el canal
     * por el que llegó (`byChannel`) y, en flujos, campañas y widgets, el ID interno de esa fuente.
     *
     * @return \Generator<int, array{amount: string, module: string|null, source: string|null, channel: string}>
     */
    private function sales(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): \Generator
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
                'o.attributed_widget_id', 'o.attributed_message_id', 'm.source_type as msg_source', 'm.channel as msg_channel']);
        foreach ($rows as $r) {
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
            $source = match ($key) {
                'flows' => (string) $r->attributed_flow_id,
                'campaigns' => (string) $r->attributed_campaign_id,
                'widgets' => (string) $r->attributed_widget_id,
                default => null,
            };
            $channel = match ($key) {
                'affiliates', 'referrals' => 'referrals',
                'ai_agent' => 'chats',
                'widgets', 'recovered_carts' => 'web',
                default => self::MESSAGE_CHANNELS[(string) ($r->msg_channel ?? '')] ?? 'other',
            };
            yield ['amount' => Decimal::of((string) ($r->total_ws ?? '0')), 'module' => $key, 'source' => $source, 'channel' => $channel];
        }

        $won = DB::table('leads')->where('workspace_id', $ws)->where('stage', 'won')->whereNotNull('won_at')
            ->where('won_at', '>=', $from->utc())->where('won_at', '<', $to->utc())
            ->get(['origin', 'value_ws']);
        foreach ($won as $lead) {
            yield ['amount' => Decimal::of((string) ($lead->value_ws ?? '0')), 'module' => self::LEAD_ORIGINS[(string) $lead->origin] ?? null, 'source' => null,
                'channel' => self::LEAD_CHANNELS[(string) $lead->origin] ?? 'other'];
        }
    }

    /**
     * @param  array{revenue: string, orders: int}|null  $sum
     * @return array{revenue: string, orders: int}
     */
    private static function plus(?array $sum, string $amount): array
    {
        return $sum === null ? ['revenue' => $amount, 'orders' => 1] : ['revenue' => Decimal::add($sum['revenue'], $amount), 'orders' => $sum['orders'] + 1];
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
