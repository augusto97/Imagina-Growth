<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Actions;

use App\Domain\Referrals\Models\ReferralProgram;
use App\Domain\Referrals\Support\ProgramSettings;
use App\Domain\Referrals\Support\ReferralSwitch;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Money\Decimal;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Guarda el programa de referidos (modulos.md M14) con los valores ya validados: lo usan Referidos (que valida lo que
 * llega del panel) y la estrategia «Referidos» (con los valores recomendados de `recommended`). Borra la caché de
 * ReferralSwitch y deja la auditoría con el antes y el después.
 */
final class SaveReferralProgram
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /**
     * Los valores recomendados (los mismos del borrador de la pantalla): 10 % al amigo en su primera compra y saldo a
     * favor para quien refiere, o puntos si el programa de puntos está activo.
     *
     * @return array{friend_reward: array{kind: string, amount: string, expires_days: int}, referrer_reward: array{type: string, points?: int, amount?: string}, rules: array<string, mixed>}
     */
    public static function recommended(Workspace $workspace, bool $loyaltyActive): array
    {
        return [
            'friend_reward' => ['kind' => 'percent', 'amount' => '10.00', 'expires_days' => 30],
            'referrer_reward' => $loyaltyActive ? ['type' => 'points', 'points' => 200] : ['type' => 'wallet', 'amount' => $workspace->currency === 'COP' ? '20000.00' : '10.00'],
            'rules' => ProgramSettings::defaultRules(),
        ];
    }

    /**
     * @param  array{kind: string, amount?: string|float|int|null, expires_days: int, minimum_amount?: string|float|int|null}  $friend
     * @param  array{type: string, points?: int|null, amount?: string|float|int|null, coupon_kind?: string|null, expires_days?: int|null}  $referrer
     * @param  array<string, mixed>  $rules
     */
    public function handle(string $status, array $friend, array $referrer, array $rules, ?string $shareText): ReferralProgram
    {
        $program = ReferralProgram::query()->first() ?? new ReferralProgram;
        $before = $program->exists ? $program->only(['status', 'friend_reward', 'referrer_reward', 'rules', 'share_text']) : [];
        $program->fill([
            'status' => $status,
            'friend_reward' => array_filter([
                'kind' => $friend['kind'],
                'amount' => $friend['kind'] === 'free_shipping' ? '0.00' : Decimal::of((string) ($friend['amount'] ?? '0')),
                'expires_days' => (int) $friend['expires_days'],
                'minimum_amount' => isset($friend['minimum_amount']) && (float) $friend['minimum_amount'] > 0 ? Decimal::of((string) $friend['minimum_amount']) : null,
            ], fn ($v) => $v !== null),
            'referrer_reward' => array_filter([
                'type' => $referrer['type'],
                'points' => $referrer['type'] === 'points' ? (int) ($referrer['points'] ?? 0) : null,
                'amount' => $referrer['type'] !== 'points' ? Decimal::of((string) ($referrer['amount'] ?? '0')) : null,
                'coupon_kind' => $referrer['type'] === 'coupon' ? $referrer['coupon_kind'] ?? null : null,
                'expires_days' => $referrer['type'] === 'coupon' ? (int) ($referrer['expires_days'] ?? 60) : null,
            ], fn ($v) => $v !== null),
            'rules' => [
                'min_order' => isset($rules['min_order']) && (float) $rules['min_order'] > 0 ? Decimal::of((string) $rules['min_order']) : null,
                'monthly_limit' => isset($rules['monthly_limit']) ? (int) $rules['monthly_limit'] : null,
                'return_days' => (int) ($rules['return_days'] ?? 15),
                'qualify_on' => (string) ($rules['qualify_on'] ?? 'completed'),
                'review_suspicious' => (bool) ($rules['review_suspicious'] ?? true),
                'signal_window_days' => (int) ($rules['signal_window_days'] ?? 30),
            ],
            'share_text' => $shareText !== null && $shareText !== '' ? $shareText : null,
        ]);
        $program->save();

        return $this->saved($program, $before);
    }

    /** Cambia solo el estado (activar o pausar desde una estrategia). */
    public function setStatus(ReferralProgram $program, string $status): ReferralProgram
    {
        $before = $program->only(['status', 'friend_reward', 'referrer_reward', 'rules', 'share_text']);
        $program->forceFill(['status' => $status])->save();

        return $this->saved($program, $before);
    }

    /** @param array<string, mixed> $before */
    private function saved(ReferralProgram $program, array $before): ReferralProgram
    {
        ReferralSwitch::forget($this->current->id());
        $this->audit->handle('referrals.program_saved', $program, ['before' => $before, 'after' => $program->only(['status', 'friend_reward', 'referrer_reward', 'rules'])], $this->current->id());

        return $program;
    }
}
