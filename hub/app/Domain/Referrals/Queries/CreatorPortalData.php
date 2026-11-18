<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Queries;

use App\Domain\Commerce\Models\Order;
use App\Domain\Referrals\Models\Affiliate;
use App\Domain\Referrals\Models\AffiliateConversion;
use App\Domain\Referrals\Support\MoneyLabel;
use App\Domain\Referrals\Support\Programs;
use App\Domain\Referrals\Support\ProgramSettings;
use App\Domain\Referrals\Support\ReferralCodes;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;

/**
 * Datos del panel del creador: sus ventas y comisiones por estado, sus enlaces,
 * cupones y material, sus campañas y los últimos pedidos (sin datos del comprador).
 * Debe correr dentro del contexto del espacio de trabajo.
 */
final class CreatorPortalData
{
    public function __construct(private readonly Programs $programs) {}

    /** @return array<string, mixed> */
    public function for(Workspace $workspace, Affiliate $affiliate): array
    {
        $money = fn (?string $v) => MoneyLabel::format($v ?? '0', $workspace->currency, $workspace->locale);
        $conversions = AffiliateConversion::query()->where('affiliate_id', $affiliate->id)->orderByDesc('created_at')->limit(1000)->get();
        $live = $conversions->where('status', '!=', 'void');
        $sum = fn (string $status) => Decimal::add('0', ...$conversions->where('status', $status)->pluck('commission_ws')->map(fn ($v) => (string) $v)->all());
        $orders = Order::query()->whereIn('id', $conversions->pluck('order_id')->all())->get(['id', 'number', 'external_id', 'created_at', 'utm_last'])->keyBy('id');

        $statusLabels = ['pending' => __('Por aprobar'), 'approved' => __('Aprobada'), 'paid' => __('Pagada'), 'void' => __('Anulada')];
        $tz = $workspace->timezone ?: 'America/Bogota';
        $rows = [];
        foreach ($conversions->take(50) as $c) {
            $order = $orders->get($c->order_id);
            $rows[] = [
                'date' => CarbonImmutable::instance($order->created_at ?? $c->created_at ?? now())->setTimezone($tz)->format('d/m/Y'),
                'number' => (string) ($order?->getAttribute('number') ?: $order?->external_id ?: '—'),
                'via' => $c->via === 'coupon' ? __('Cupón') : __('Enlace'),
                'amount' => $money($c->amount_ws),
                'commission' => $money($c->commission_ws),
                'status' => $c->status,
                'status_label' => $statusLabels[$c->status] ?? $c->status,
            ];
        }

        $campaigns = [];
        foreach ($live as $c) {
            $order = $orders->get($c->order_id);
            $utm = $order?->getAttribute('utm_last');
            $label = is_array($utm) && is_string($utm['campaign'] ?? null) && $utm['campaign'] !== '' ? $utm['campaign'] : ($c->via === 'coupon' ? __('Con tu cupón') : __('Con tu enlace'));
            $campaigns[$label] ??= ['label' => $label, 'orders' => 0, 'revenue' => '0.00', 'commission' => '0.00'];
            $campaigns[$label]['orders']++;
            $campaigns[$label]['revenue'] = Decimal::add($campaigns[$label]['revenue'], (string) $c->amount_ws);
            $campaigns[$label]['commission'] = Decimal::add($campaigns[$label]['commission'], (string) $c->commission_ws);
        }
        usort($campaigns, fn ($a, $b) => (float) $b['revenue'] <=> (float) $a['revenue']);

        $sites = Site::query()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->get();
        $settings = $this->programs->settings();

        return [
            'brand' => $workspace->name,
            'name' => $affiliate->name,
            'commissionLabel' => self::commissionLabel($affiliate, $workspace),
            'returnDays' => $settings?->returnDays() ?? (int) ProgramSettings::defaultRules()['return_days'],
            'kpis' => [
                'orders' => $live->count(),
                'revenue' => $money(Decimal::add('0', ...$live->pluck('amount_ws')->map(fn ($v) => (string) $v)->all())),
                'pending' => $money($sum('pending')),
                'approved' => $money($sum('approved')),
                'paid' => $money($sum('paid')),
            ],
            'links' => $sites->map(fn (Site $s) => ReferralCodes::link($s, $affiliate->link_code))->values()->all(),
            'coupons' => array_values(array_map('strtoupper', $affiliate->coupon_codes ?? [])),
            'material' => array_values(array_filter($affiliate->material ?? [], fn ($m) => is_array($m) && is_string($m['url'] ?? null) && preg_match('#^https?://#i', $m['url']) === 1)),
            'campaigns' => array_map(fn ($r) => ['label' => $r['label'], 'orders' => $r['orders'], 'revenue' => $money($r['revenue']), 'commission' => $money($r['commission'])], array_slice($campaigns, 0, 10)),
            'conversions' => $rows,
        ];
    }

    public static function commissionLabel(Affiliate $affiliate, Workspace $workspace): string
    {
        $rule = $affiliate->commission;
        $value = (string) ($rule['value'] ?? '0');
        if (($rule['type'] ?? 'percent') === 'fixed') {
            return __('Tu comisión: :amount por pedido completado', ['amount' => MoneyLabel::format($value, $workspace->currency, $workspace->locale)]);
        }
        $pct = rtrim(rtrim(Decimal::of($value), '0'), '.').'%';

        return ($rule['basis'] ?? 'net') === 'total'
            ? __('Tu comisión: :pct del total de cada pedido completado', ['pct' => $pct])
            : __('Tu comisión: :pct del valor de los productos de cada pedido completado', ['pct' => $pct]);
    }
}
