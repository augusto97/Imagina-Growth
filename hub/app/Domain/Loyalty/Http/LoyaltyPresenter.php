<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Http;

use App\Domain\Loyalty\Models\LedgerEntry;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyReward;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Loyalty\Support\ProgramRules;

/** Forma JSON de los objetos de M13 para la SPA. */
final class LoyaltyPresenter
{
    /** @return array<string, mixed> */
    public static function program(?LoyaltyProgram $program): array
    {
        if ($program === null) {
            $d = ProgramRules::defaults();

            return ['id' => null, 'status' => 'draft'] + $d;
        }

        return [
            'id' => $program->publicId(),
            'status' => $program->status,
            'currency_name' => $program->currency_name,
            'earn_rules' => $program->earn_rules,
            'redeem_rules' => $program->redeem_rules ?? ProgramRules::defaults()['redeem_rules'],
            'expiry_policy' => $program->expiry_policy ?? ProgramRules::defaults()['expiry_policy'],
            'pending_days' => $program->pending_days,
            'wallet_rules' => $program->wallet_rules ?? ProgramRules::defaults()['wallet_rules'],
        ];
    }

    /** @return array<string, mixed> */
    public static function tier(LoyaltyTier $tier, ?int $members = null): array
    {
        $b = (array) ($tier->benefits ?? []);

        return [
            'id' => $tier->publicId(),
            'name' => $tier->name,
            'rank' => $tier->rank,
            'threshold_type' => $tier->threshold_type,
            'threshold' => (string) $tier->threshold,
            'benefits' => [
                'multiplier' => (float) ($b['multiplier'] ?? 1),
                'free_shipping' => (bool) ($b['free_shipping'] ?? false),
                'early_access' => (bool) ($b['early_access'] ?? false),
                'gifts' => (string) ($b['gifts'] ?? ''),
            ],
            'wc_role' => $tier->wc_role,
            'members' => $members,
        ];
    }

    /** @return array<string, mixed> */
    public static function reward(LoyaltyReward $reward, ?int $redeemed = null): array
    {
        $c = (array) ($reward->config ?? []);

        return [
            'id' => $reward->publicId(),
            'name' => $reward->name,
            'kind' => $reward->kind,
            'points_cost' => $reward->points_cost,
            'value' => (string) $reward->value,
            'config' => [
                'min_order' => isset($c['min_order']) ? (string) $c['min_order'] : null,
                'expires_in_days' => isset($c['expires_in_days']) ? (int) $c['expires_in_days'] : null,
                'product_id' => isset($c['product_id']) ? (string) $c['product_id'] : null,
            ],
            'status' => $reward->status,
            'redeemed' => $redeemed,
        ];
    }

    /**
     * @param  string|null  $settled  cómo terminó una entrada pendiente: confirmed | reversed | null (sigue pendiente)
     * @return array<string, mixed>
     */
    public static function entry(LedgerEntry $e, ?string $settled = null): array
    {
        return [
            'id' => $e->publicId(),
            'kind' => $e->kind,
            'delta' => $e->kind === 'points' ? $e->points() : (string) $e->delta,
            'balance_after' => $e->kind === 'points' ? (int) round((float) $e->balance_after) : (string) $e->balance_after,
            'currency' => $e->currency,
            'status' => $e->status,
            'settled' => $settled,
            'reason' => $e->reason,
            'ref_type' => $e->ref_type,
            'ref_id' => $e->ref_id,
            'parent_id' => $e->parent_id !== null ? LedgerEntry::ID_PREFIX.'_'.$e->parent_id : null,
            'expires_at' => $e->expires_at?->toIso8601String(),
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }
}
