<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Queries;

use App\Domain\Commerce\Models\Order;
use App\Domain\Referrals\Http\ContactNames;
use App\Domain\Referrals\Models\Affiliate;
use App\Domain\Referrals\Models\AffiliateConversion;
use App\Domain\Referrals\Models\Referral;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reportes de M14 (modulos.md): ingresos por referidos y por afiliados, costo de
 * adquisición por referido, embudo de referidos, mejores clientes que refieren y la
 * mejor campaña de cada creador. Fechas en la zona horaria del espacio de trabajo.
 * Debe correr dentro del contexto del espacio de trabajo.
 */
final class ReferralReport
{
    private const CONVERTED = [Referral::ORDERED, Referral::QUALIFIED, Referral::REWARDED];

    /** @return array<string, mixed> */
    public function build(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = $workspace->timezone ?: 'America/Bogota';
        $start = $from->setTimezone($tz)->startOfDay()->utc();
        $end = $to->setTimezone($tz)->endOfDay()->utc();

        $converted = Referral::query()->whereIn('status', self::CONVERTED)->whereBetween('ordered_at', [$start, $end])->get();
        $rewarded = Referral::query()->where('status', Referral::REWARDED)->whereBetween('rewarded_at', [$start, $end])->get();
        $signups = Referral::query()->whereBetween('created_at', [$start, $end])->count();
        $rejected = Referral::query()->where('status', Referral::REJECTED)->whereBetween('updated_at', [$start, $end])->count();
        $conversions = AffiliateConversion::query()->where('status', '!=', 'void')->whereBetween('created_at', [$start, $end])->get();

        $referralRevenue = Decimal::add('0', ...$converted->pluck('order_amount_ws')->map(fn ($v) => (string) ($v ?? '0'))->all());
        $affiliateRevenue = Decimal::add('0', ...$conversions->pluck('amount_ws')->map(fn ($v) => (string) $v)->all());
        $costs = $rewarded->whereNotNull('reward_cost_ws');
        $totalCost = Decimal::add('0', ...$costs->pluck('reward_cost_ws')->map(fn ($v) => (string) $v)->all());
        $points = (int) $rewarded->sum(fn (Referral $r) => (int) ($r->reward['points'] ?? 0));

        return [
            'from' => $from->setTimezone($tz)->toDateString(),
            'to' => $to->setTimezone($tz)->toDateString(),
            'currency' => $workspace->currency,
            'kpis' => [
                'referral_revenue' => $referralRevenue,
                'referral_orders' => $converted->count(),
                'affiliate_revenue' => $affiliateRevenue,
                'affiliate_orders' => $conversions->count(),
                'commissions' => Decimal::add('0', ...$conversions->pluck('commission_ws')->map(fn ($v) => (string) $v)->all()),
                'signups' => $signups,
                'rewarded' => $rewarded->count(),
                'rejected' => $rejected,
                'reward_cost' => $totalCost,
                'cost_per_referral' => $costs->count() > 0 ? Decimal::div($totalCost, $costs->count()) : null,
                'points_given' => $points,
                'pending_review' => Referral::query()->where('review_status', Referral::PENDING_REVIEW)->count(),
            ],
            'series' => $this->series($converted, $conversions, $from, $to, $tz),
            'top_referrers' => $this->topReferrers($converted),
            'creators' => $this->creators($conversions),
        ];
    }

    /**
     * Ingreso por semana (o por mes, en rangos de más de 26 semanas) de cada fuente.
     *
     * @param  Collection<int, Referral>  $referrals
     * @param  Collection<int, AffiliateConversion>  $conversions
     * @return array{bucket: string, points: list<array{period: string, referrals: string, affiliates: string}>}
     */
    private function series($referrals, $conversions, CarbonImmutable $from, CarbonImmutable $to, string $tz): array
    {
        $monthly = $from->diffInWeeks($to) > 26;
        $key = fn (CarbonImmutable $d) => $monthly ? $d->setTimezone($tz)->startOfMonth()->toDateString() : $d->setTimezone($tz)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        /** @var array<string, string> $fromReferrals */
        $fromReferrals = [];
        /** @var array<string, string> $fromAffiliates */
        $fromAffiliates = [];
        for ($d = $from->setTimezone($tz); $d->lte($to->setTimezone($tz)); $d = $monthly ? $d->addMonth()->startOfMonth() : $d->addWeek()->startOfWeek(CarbonImmutable::MONDAY)) {
            $fromReferrals[$key($d)] = '0.00';
            $fromAffiliates[$key($d)] = '0.00';
        }
        foreach ($referrals as $r) {
            $k = $r->ordered_at !== null ? $key(CarbonImmutable::instance($r->ordered_at)) : null;
            if ($k !== null && isset($fromReferrals[$k])) {
                $fromReferrals[$k] = Decimal::add($fromReferrals[$k], (string) ($r->order_amount_ws ?? '0'));
            }
        }
        foreach ($conversions as $c) {
            $k = $c->created_at !== null ? $key(CarbonImmutable::instance($c->created_at)) : null;
            if ($k !== null && isset($fromAffiliates[$k])) {
                $fromAffiliates[$k] = Decimal::add($fromAffiliates[$k], (string) $c->amount_ws);
            }
        }
        $points = [];
        foreach ($fromReferrals as $period => $amount) {
            $points[] = ['period' => $period, 'referrals' => $amount, 'affiliates' => $fromAffiliates[$period] ?? '0.00'];
        }

        return ['bucket' => $monthly ? 'month' : 'week', 'points' => $points];
    }

    /**
     * @param  Collection<int, Referral>  $referrals
     * @return list<array{contact: array{id: string, name: string}|null, referrals: int, revenue: string}>
     */
    private function topReferrers($referrals): array
    {
        $groups = $referrals->groupBy('referrer_contact_id')
            ->map(fn ($g, $id) => ['id' => (string) $id, 'referrals' => $g->count(), 'revenue' => Decimal::add('0', ...$g->pluck('order_amount_ws')->map(fn ($v) => (string) ($v ?? '0'))->all())])
            ->sortByDesc(fn ($r) => (float) $r['revenue'])->take(10)->values();
        $names = ContactNames::for(array_values(array_map('strval', $groups->pluck('id')->all())));

        return array_values($groups->map(fn ($r) => ['contact' => $names[$r['id']] ?? null, 'referrals' => $r['referrals'], 'revenue' => $r['revenue']])->all());
    }

    /**
     * Por creador: pedidos, ventas, comisión y su mejor campaña (la `utm_campaign` del
     * pedido, o «cupón»/«enlace» si no trae campaña).
     *
     * @param  Collection<int, AffiliateConversion>  $conversions
     * @return list<array<string, mixed>>
     */
    private function creators($conversions): array
    {
        $affiliates = Affiliate::query()->whereIn('id', $conversions->pluck('affiliate_id')->unique()->all())->get()->keyBy('id');
        $orders = Order::query()->whereIn('id', $conversions->pluck('order_id')->all())->get(['id', 'utm_last'])->keyBy('id');
        $rows = [];
        foreach ($conversions->groupBy('affiliate_id') as $affiliateId => $group) {
            $affiliate = $affiliates->get($affiliateId);
            if ($affiliate === null) {
                continue;
            }
            $campaigns = [];
            foreach ($group as $c) {
                $utm = $orders->get($c->order_id)?->getAttribute('utm_last');
                $label = is_array($utm) && is_string($utm['campaign'] ?? null) && $utm['campaign'] !== '' ? $utm['campaign'] : null;
                $k = $label ?? '~'.$c->via;
                $campaigns[$k] ??= ['campaign' => $label, 'via' => $label === null ? $c->via : null, 'orders' => 0, 'revenue' => '0.00'];
                $campaigns[$k]['orders']++;
                $campaigns[$k]['revenue'] = Decimal::add($campaigns[$k]['revenue'], (string) $c->amount_ws);
            }
            usort($campaigns, fn ($a, $b) => (float) $b['revenue'] <=> (float) $a['revenue']);
            $rows[] = [
                'affiliate' => ['id' => $affiliate->publicId(), 'name' => $affiliate->name, 'kind' => $affiliate->kind],
                'orders' => $group->count(),
                'revenue' => Decimal::add('0', ...$group->pluck('amount_ws')->map(fn ($v) => (string) $v)->all()),
                'commission' => Decimal::add('0', ...$group->pluck('commission_ws')->map(fn ($v) => (string) $v)->all()),
                'best_campaign' => $campaigns[0] ?? null,
            ];
        }
        usort($rows, fn ($a, $b) => (float) $b['revenue'] <=> (float) $a['revenue']);

        return $rows;
    }
}
