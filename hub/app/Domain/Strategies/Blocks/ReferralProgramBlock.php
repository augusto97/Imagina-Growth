<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Domain\Referrals\Actions\SaveReferralProgram;
use App\Domain\Referrals\Models\ReferralProgram;
use App\Domain\Referrals\Support\ProgramSettings;
use App\Domain\Strategies\Support\Previews;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Strategies\Support\StrategyNotReady;

/**
 * El programa de referidos (modulos.md M14) con los valores recomendados de SaveReferralProgram: 10 % al amigo en su
 * primera compra y saldo a favor (o puntos, con el programa de puntos activo) para quien refiere.
 */
final class ReferralProgramBlock implements StrategyBlock
{
    public function kind(): string
    {
        return 'referral_program';
    }

    public function requirements(StrategyContext $ctx, array $options = []): array
    {
        return [];
    }

    public function state(StrategyContext $ctx): BlockState
    {
        $program = $this->program($ctx);
        if ($program?->status === 'active') {
            return BlockState::on('referidos', $ctx->hasStore() ? [] : ['site_down']);
        }

        return $program?->status === 'paused' ? BlockState::paused('referidos') : BlockState::off('referidos');
    }

    public function activate(StrategyContext $ctx, array $options, ?string $userId): array
    {
        $save = app(SaveReferralProgram::class);
        $loyalty = app(LoyaltyLedger::class)->isActive();
        $program = ReferralProgram::query()->first();
        if ($program?->status === 'active') {
            return [];
        }
        if ($program === null || $program->status === 'draft') {
            $r = SaveReferralProgram::recommended($ctx->workspace, $loyalty);
            $save->handle('active', $r['friend_reward'], $r['referrer_reward'], $r['rules'], $program?->share_text);

            return [];
        }
        if (($program->referrer_reward['type'] ?? null) === 'points' && ! $loyalty) {
            throw new StrategyNotReady(__('Para dar puntos, activa primero el programa de puntos en Fidelización. También puedes dar saldo a favor o un cupón.'), [], [], 'referidos');
        }
        $save->setStatus($program, 'active');

        return [];
    }

    public function pause(StrategyContext $ctx, ?string $userId): array
    {
        $program = ReferralProgram::query()->first();
        if ($program?->status === 'active') {
            app(SaveReferralProgram::class)->setStatus($program, 'paused');
        }

        return [];
    }

    public function preview(StrategyContext $ctx): array
    {
        $program = $this->program($ctx) ?? new ReferralProgram(['status' => 'draft'] + SaveReferralProgram::recommended($ctx->workspace, app(LoyaltyLedger::class)->isActive()));
        $settings = new ProgramSettings($program, $ctx->workspace);

        return Previews::summary([
            __('strategies.preview.referral_friend', ['reward' => $settings->friendRewardLabel()]),
            __('strategies.preview.referral_referrer', ['reward' => $settings->referrerRewardLabel()]),
        ]);
    }

    private function program(StrategyContext $ctx): ?ReferralProgram
    {
        return $ctx->remember('referral_program', fn () => ReferralProgram::query()->first());
    }
}
