<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Support;

use App\Domain\Loyalty\Models\LoyaltyProgram;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Reglas del programa ya normalizadas (los JSON de `loyalty_programs` con sus
 * valores por defecto). Montos en la moneda del espacio de trabajo.
 *
 * earn_rules:
 *   per_amount   {points, amount}: N puntos por cada $X (p. ej. 1 por cada $1.000)
 *   basis        without_shipping (por defecto) | total
 *   category_multipliers [{category_id (id de la tienda), multiplier}]
 *   signup, birthday, review, review_media, profile: puntos fijos (0 = apagado). Los de
 *   referidos los define el programa de referidos (M14), que llama a earnPoints con su monto.
 *   campaigns    [{name, multiplier, starts_on, ends_on}] (puntos dobles; fechas locales, inclusivas)
 * redeem_rules:  {min_points, coupon_expiry_days}
 * expiry_policy: {months (0 = no vencen), warning_days}
 * wallet_rules:  {cashback_pct}
 */
final class ProgramRules
{
    /** Motivos con puntos fijos configurables. */
    public const FIXED_REASONS = ['signup', 'birthday', 'review', 'review_media', 'profile'];

    /**
     * @param  array<string, int>  $fixed
     * @param  list<array{category_id: string, multiplier: float}>  $categoryMultipliers
     * @param  list<array{name: string, multiplier: float, starts_on: string, ends_on: string}>  $campaigns
     */
    public function __construct(
        public readonly int $pointsPer,
        public readonly string $amountPer,
        public readonly string $basis,
        public readonly array $categoryMultipliers,
        public readonly array $fixed,
        public readonly array $campaigns,
        public readonly int $minRedeemPoints,
        public readonly int $couponExpiryDays,
        public readonly int $expiryMonths,
        public readonly int $warningDays,
        public readonly int $pendingDays,
        public readonly string $cashbackPct,
    ) {}

    public static function fromProgram(LoyaltyProgram $program): self
    {
        return self::fromArrays((array) $program->earn_rules, (array) ($program->redeem_rules ?? []), (array) ($program->expiry_policy ?? []), (array) ($program->wallet_rules ?? []), (int) $program->pending_days);
    }

    /**
     * @param  array<string, mixed>  $earn
     * @param  array<string, mixed>  $redeem
     * @param  array<string, mixed>  $expiry
     * @param  array<string, mixed>  $wallet
     */
    public static function fromArrays(array $earn, array $redeem, array $expiry, array $wallet, int $pendingDays): self
    {
        $per = is_array($earn['per_amount'] ?? null) ? $earn['per_amount'] : [];
        $fixed = [];
        foreach (self::FIXED_REASONS as $reason) {
            $fixed[$reason] = max(0, min(1_000_000, (int) ($earn[$reason] ?? 0)));
        }
        $multipliers = [];
        foreach ((array) ($earn['category_multipliers'] ?? []) as $m) {
            if (is_array($m) && isset($m['category_id']) && is_numeric($m['multiplier'] ?? null) && (float) $m['multiplier'] >= 1) {
                $multipliers[] = ['category_id' => (string) $m['category_id'], 'multiplier' => min(10.0, (float) $m['multiplier'])];
            }
        }
        $campaigns = [];
        foreach ((array) ($earn['campaigns'] ?? []) as $c) {
            if (is_array($c) && is_numeric($c['multiplier'] ?? null) && is_string($c['starts_on'] ?? null) && is_string($c['ends_on'] ?? null)) {
                $campaigns[] = [
                    'name' => mb_substr((string) ($c['name'] ?? ''), 0, 80),
                    'multiplier' => max(1.0, min(10.0, (float) $c['multiplier'])),
                    'starts_on' => $c['starts_on'],
                    'ends_on' => $c['ends_on'],
                ];
            }
        }
        $amount = is_numeric($per['amount'] ?? null) && (float) $per['amount'] > 0 ? number_format((float) $per['amount'], 2, '.', '') : '1000.00';

        return new self(
            pointsPer: max(0, (int) ($per['points'] ?? 1)),
            amountPer: $amount,
            basis: ($earn['basis'] ?? null) === 'total' ? 'total' : 'without_shipping',
            categoryMultipliers: $multipliers,
            fixed: $fixed,
            campaigns: $campaigns,
            minRedeemPoints: max(0, (int) ($redeem['min_points'] ?? 0)),
            couponExpiryDays: max(1, min(365, (int) ($redeem['coupon_expiry_days'] ?? 30))),
            expiryMonths: max(0, min(60, (int) ($expiry['months'] ?? 12))),
            warningDays: max(1, min(90, (int) ($expiry['warning_days'] ?? 15))),
            pendingDays: max(0, min(120, $pendingDays)),
            cashbackPct: is_numeric($wallet['cashback_pct'] ?? null) ? number_format(max(0, min(50, (float) $wallet['cashback_pct'])), 2, '.', '') : '0.00',
        );
    }

    /**
     * Valores con los que arranca un programa nuevo.
     *
     * @return array{currency_name: string, earn_rules: array<string, mixed>, redeem_rules: array<string, int>, expiry_policy: array<string, int>, pending_days: int, wallet_rules: array<string, string>}
     */
    public static function defaults(): array
    {
        return [
            'currency_name' => 'Puntos',
            'earn_rules' => [
                'per_amount' => ['points' => 1, 'amount' => '1000.00'],
                'basis' => 'without_shipping',
                'category_multipliers' => [],
                'signup' => 100, 'birthday' => 200, 'review' => 50, 'review_media' => 100, 'profile' => 50,
                'campaigns' => [],
            ],
            'redeem_rules' => ['min_points' => 0, 'coupon_expiry_days' => 30],
            'expiry_policy' => ['months' => 12, 'warning_days' => 15],
            'pending_days' => 15,
            'wallet_rules' => ['cashback_pct' => '0'],
        ];
    }

    public function fixedPoints(string $reason): int
    {
        return $this->fixed[$reason] ?? 0;
    }

    /** Multiplicador de la campaña de puntos activa en esa fecha local (1 si no hay). */
    public function campaignMultiplier(CarbonInterface $at, string $timezone): float
    {
        $day = CarbonImmutable::instance($at)->setTimezone($timezone)->toDateString();
        $best = 1.0;
        foreach ($this->campaigns as $c) {
            if ($day >= $c['starts_on'] && $day <= $c['ends_on']) {
                $best = max($best, $c['multiplier']);
            }
        }

        return $best;
    }

    /** @param array<array-key, string> $categoryIds */
    public function categoryMultiplier(array $categoryIds): float
    {
        $best = 1.0;
        foreach ($this->categoryMultipliers as $m) {
            if (in_array($m['category_id'], $categoryIds, true)) {
                $best = max($best, $m['multiplier']);
            }
        }

        return $best;
    }

    /** Puntos base por un monto (sin multiplicadores). */
    public function basePoints(string $amount): int
    {
        if ($this->pointsPer <= 0 || (float) $amount <= 0) {
            return 0;
        }

        return (int) floor(((float) $amount / (float) $this->amountPer) * $this->pointsPer + 1e-9);
    }

    public function expiresAt(CarbonInterface $from): ?CarbonImmutable
    {
        return $this->expiryMonths > 0 ? CarbonImmutable::instance($from)->addMonths($this->expiryMonths) : null;
    }
}
