<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Queries;

use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * «Primeros pasos» del Inicio (docs/auditoria-2026-11.md F16): el camino hasta la primera venta recuperada, con el
 * estado real de cada paso.
 *
 *   1. Conectar el sitio → 2. Importación terminada → 3. Un canal conectado (WhatsApp o correo) →
 *   4. El flujo de carrito abandonado activo → 5. La primera venta recuperada, con el monto.
 *
 * Un negocio de servicios sin tienda (sitios WordPress sin WooCommerce) sigue el mismo camino con el flujo «Lead de
 * servicios» y el primer lead ganado que captó la plataforma. Las ventas recuperadas son las de MoneyGenerated:
 * pedidos pagados atribuidos a los flujos de carrito o checkout abandonado, o que cerraron un carrito abandonado.
 */
final class GettingStarted
{
    /** Flujos de la biblioteca que recuperan carritos (flows.template_key). */
    public const CART_FLOWS = ['abandoned_cart', 'abandoned_checkout'];

    public const SERVICES_FLOW = 'service_lead';

    /** Origen de los leads que captó la plataforma (MoneyGenerated::LEAD_ORIGINS). */
    private const PLATFORM_LEAD_ORIGINS = ['form', 'whatsapp', 'booking', 'quote'];

    /** @return array<string, mixed> */
    public function handle(Workspace $workspace): array
    {
        $ws = $workspace->id;
        $sites = DB::table('sites')->where('workspace_id', $ws)->where('status', '!=', 'disconnected')
            ->orderBy('created_at')->get(['id', 'host', 'platform', 'status', 'backfill_state']);
        $connected = $sites->whereIn('status', ['connected', 'degraded']);
        $store = $sites->isEmpty() || $sites->contains('platform', 'woocommerce');

        // 2. Importación: la del sitio más avanzado.
        $import = ['status' => null, 'progress' => null, 'site_id' => null];
        foreach ($connected as $site) {
            $state = is_string($site->backfill_state) ? (array) json_decode($site->backfill_state, true) : [];
            $status = is_string($state['status'] ?? null) ? $state['status'] : null;
            if ($import['status'] !== 'completed' && $status !== null) {
                $total = 0;
                $sent = 0;
                foreach ((array) ($state['entities'] ?? []) as $e) {
                    $total += (int) ($e['total'] ?? 0);
                    $sent += (int) ($e['sent'] ?? 0);
                }
                $import = ['status' => $status, 'progress' => $total > 0 ? round($sent / $total, 4) : null, 'site_id' => 'st_'.$site->id];
            }
        }

        // 3. Canales activos.
        $channels = DB::table('channel_accounts')->where('workspace_id', $ws)->where('status', 'active')
            ->whereIn('channel', ['whatsapp', 'email'])->distinct()->pluck('channel')->map(fn ($c) => (string) $c)->sort()->values()->all();

        // 4. El flujo que recupera ventas.
        $keys = $store ? self::CART_FLOWS : [self::SERVICES_FLOW];
        $flows = DB::table('flows')->where('workspace_id', $ws)->whereIn('template_key', $keys)->where('status', '!=', 'archived')
            ->orderByRaw("FIELD(status, 'active', 'paused', 'draft')")->orderBy('created_at')->get(['id', 'status', 'template_key']);
        $flow = $flows->first();

        // 5. La primera venta recuperada (o el primer lead ganado).
        $sale = $store ? $this->recoveredSales($ws, $keys) : $this->wonLeads($ws);

        $steps = [
            ['key' => 'connect_site', 'done' => $connected->isNotEmpty(), 'site_id' => ($s = $connected->first() ?? $sites->first()) !== null ? 'st_'.$s->id : null,
                'pending' => $connected->isEmpty() && $sites->isNotEmpty()],
            ['key' => 'import', 'done' => $import['status'] === 'completed'] + $import,
            ['key' => 'channel', 'done' => $channels !== [], 'channels' => $channels],
            ['key' => 'flow', 'done' => $flow?->status === 'active', 'status' => $flow !== null ? (string) $flow->status : 'missing',
                'flow_id' => $flow !== null ? 'fl_'.$flow->id : null, 'library_key' => $keys[0]],
            ['key' => 'first_sale', 'done' => $sale['count'] > 0] + $sale,
        ];

        return [
            'variant' => $store ? 'store' : 'services',
            'currency' => (string) ($workspace->currency ?: 'COP'),
            'steps' => $steps,
            'completed' => count(array_filter($steps, fn (array $s) => $s['done'])),
            'total' => count($steps),
        ];
    }

    /**
     * @param  list<string>  $flowKeys
     * @return array{count: int, revenue: string, first_at: string|null, first_revenue: string|null}
     */
    private function recoveredSales(string $ws, array $flowKeys): array
    {
        $flowIds = DB::table('flows')->where('workspace_id', $ws)->whereIn('template_key', $flowKeys)->pluck('id')->all();
        $recovered = DB::table('carts')->where('workspace_id', $ws)->whereNotNull('recovered_order_id')->select('recovered_order_id');
        $base = DB::table('orders')->where('workspace_id', $ws)->where('is_paid', true)->whereNotIn('status', ['cancelled', 'failed', 'refunded'])
            ->where(fn ($q) => $q->whereIn('attributed_flow_id', $flowIds)->orWhereIn('id', $recovered));
        $totals = (clone $base)->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_ws), 0) AS revenue')->first();
        $first = (clone $base)->orderByRaw('COALESCE(paid_at, created_at)')->first(['paid_at', 'created_at', 'total_ws']);

        return [
            'count' => (int) ($totals->n ?? 0),
            'revenue' => number_format((float) ($totals->revenue ?? 0), 2, '.', ''),
            'first_at' => $first !== null ? self::iso($first->paid_at ?? $first->created_at) : null,
            'first_revenue' => $first !== null ? number_format((float) $first->total_ws, 2, '.', '') : null,
        ];
    }

    /** @return array{count: int, revenue: string, first_at: string|null, first_revenue: string|null} */
    private function wonLeads(string $ws): array
    {
        $base = DB::table('leads')->where('workspace_id', $ws)->where('stage', 'won')->whereNotNull('won_at')->whereIn('origin', self::PLATFORM_LEAD_ORIGINS);
        $totals = (clone $base)->selectRaw('COUNT(*) AS n, COALESCE(SUM(value_ws), 0) AS revenue')->first();
        $first = (clone $base)->orderBy('won_at')->first(['won_at', 'value_ws']);

        return [
            'count' => (int) ($totals->n ?? 0),
            'revenue' => number_format((float) ($totals->revenue ?? 0), 2, '.', ''),
            'first_at' => $first !== null ? self::iso($first->won_at) : null,
            'first_revenue' => $first !== null ? number_format((float) ($first->value_ws ?? 0), 2, '.', '') : null,
        ];
    }

    private static function iso(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value, 'UTC')->toIso8601String();
    }
}
