<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Loyalty\Actions\SaveLoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyReward;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Loyalty\Support\MoneyText;
use App\Domain\Loyalty\Support\ProgramRules;
use App\Domain\Strategies\Support\Previews;
use App\Domain\Strategies\Support\StrategyContext;

/**
 * El programa de puntos y niveles (modulos.md M13). Activar crea el programa con las reglas por defecto del módulo
 * (ProgramRules::defaults, con el monto por punto en la moneda del negocio), dos niveles por gasto y una recompensa
 * de descuento si no tiene, y lo deja activo con SaveLoyaltyProgram. Pausar lo deja en pausa (no se ganan puntos).
 */
final class LoyaltyProgramBlock implements StrategyBlock
{
    /** Cuánto se gasta por punto en cada moneda (1 punto por cada $1.000 COP). */
    private const UNIT = ['COP' => 1000, 'MXN' => 20, 'CLP' => 1000, 'ARS' => 1000];

    public function kind(): string
    {
        return 'loyalty_program';
    }

    public function requirements(StrategyContext $ctx, array $options = []): array
    {
        return [];
    }

    public function state(StrategyContext $ctx): BlockState
    {
        $program = $this->program($ctx);
        if ($program?->status === 'active') {
            return BlockState::on('fidelizacion', $ctx->hasStore() ? [] : ['site_down']);
        }

        return $program?->status === 'paused' ? BlockState::paused('fidelizacion') : BlockState::off('fidelizacion');
    }

    public function activate(StrategyContext $ctx, array $options, ?string $userId): array
    {
        $save = app(SaveLoyaltyProgram::class);
        $program = LoyaltyProgram::query()->first();
        if ($program?->isActive()) {
            return [];
        }
        if ($program === null) {
            $rules = ProgramRules::defaults();
            $rules['earn_rules']['per_amount']['amount'] = number_format(self::unit($ctx), 2, '.', '');
            $program = $save->handle($rules, 'draft');
        }
        if (LoyaltyTier::query()->where('program_id', $program->id)->doesntExist()) {
            $save->saveTiers($program, $this->tiers($ctx), fn (?string $id) => null);
        }
        if (LoyaltyReward::query()->where('program_id', $program->id)->where('status', 'active')->doesntExist()) {
            $save->createReward($program, $this->reward($ctx));
        }
        $save->setStatus($program, 'active');

        return [];
    }

    public function pause(StrategyContext $ctx, ?string $userId): array
    {
        $program = LoyaltyProgram::query()->first();
        if ($program?->isActive()) {
            app(SaveLoyaltyProgram::class)->setStatus($program, 'paused');
        }

        return [];
    }

    public function preview(StrategyContext $ctx): array
    {
        $currency = (string) ($ctx->workspace->currency ?: 'COP');
        $program = $this->program($ctx);
        $amount = $program !== null ? (string) ($program->earn_rules['per_amount']['amount'] ?? self::unit($ctx)) : (string) self::unit($ctx);
        $points = $program !== null ? (int) ($program->earn_rules['per_amount']['points'] ?? 1) : 1;
        $reward = $this->reward($ctx);
        [$silver, $gold] = $this->tiers($ctx);

        return Previews::summary([
            trans_choice('strategies.preview.loyalty_earn', $points, ['points' => $points, 'amount' => MoneyText::format($amount, $currency)]),
            __('strategies.preview.loyalty_reward', ['points' => MoneyText::points($reward['points_cost']), 'value' => MoneyText::format($reward['value'], $currency)]),
            __('strategies.preview.loyalty_tiers', ['silver' => MoneyText::format((string) $silver['threshold'], $currency), 'gold' => MoneyText::format((string) $gold['threshold'], $currency)]),
        ]);
    }

    private function program(StrategyContext $ctx): ?LoyaltyProgram
    {
        return $ctx->remember('loyalty_program', fn () => LoyaltyProgram::query()->first());
    }

    private static function unit(StrategyContext $ctx): int
    {
        return self::UNIT[strtoupper((string) $ctx->workspace->currency)] ?? 1;
    }

    /** @return array{0: array{name: string, threshold_type: string, threshold: int, benefits: array<string, mixed>}, 1: array{name: string, threshold_type: string, threshold: int, benefits: array<string, mixed>}} */
    private function tiers(StrategyContext $ctx): array
    {
        $unit = self::unit($ctx);

        return [
            ['name' => __('strategies.loyalty.silver'), 'threshold_type' => 'spend_12m', 'threshold' => 500 * $unit, 'benefits' => ['multiplier' => 1.25]],
            ['name' => __('strategies.loyalty.gold'), 'threshold_type' => 'spend_12m', 'threshold' => 1500 * $unit, 'benefits' => ['multiplier' => 1.5, 'free_shipping' => true]],
        ];
    }

    /**
     * 200 puntos = un descuento del 3 % de lo que se gastó para ganarlos.
     *
     * @return array{name: string, kind: string, points_cost: int, value: string, config: array<string, mixed>|null}
     */
    private function reward(StrategyContext $ctx): array
    {
        $value = number_format(200 * self::unit($ctx) * 0.03, 2, '.', '');

        return [
            'name' => __('strategies.loyalty.reward', ['value' => MoneyText::format($value, (string) ($ctx->workspace->currency ?: 'COP'))]),
            'kind' => 'coupon_fixed', 'points_cost' => 200, 'value' => $value, 'config' => null,
        ];
    }
}
