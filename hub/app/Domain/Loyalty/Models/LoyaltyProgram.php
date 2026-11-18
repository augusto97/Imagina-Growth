<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Models;

use App\Domain\Loyalty\Support\ProgramRules;
use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Programa de fidelización del espacio de trabajo (uno por espacio de trabajo,
 * modelo-de-datos.md §6 y §13). Las reglas se leen con ProgramRules.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $status
 * @property string $currency_name
 * @property array<string, mixed> $earn_rules
 * @property array<string, mixed>|null $redeem_rules
 * @property array<string, mixed>|null $expiry_policy
 * @property int $pending_days
 * @property array<string, mixed>|null $wallet_rules
 */
class LoyaltyProgram extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'lp';

    public const STATUSES = ['draft', 'active', 'paused'];

    protected $fillable = ['workspace_id', 'status', 'currency_name', 'earn_rules', 'redeem_rules', 'expiry_policy', 'pending_days', 'wallet_rules'];

    protected function casts(): array
    {
        return [
            'earn_rules' => 'array',
            'redeem_rules' => 'array',
            'expiry_policy' => 'array',
            'wallet_rules' => 'array',
            'pending_days' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function rules(): ProgramRules
    {
        return ProgramRules::fromProgram($this);
    }

    /** @return HasMany<LoyaltyTier, $this> */
    public function tiers(): HasMany
    {
        return $this->hasMany(LoyaltyTier::class, 'program_id')->orderBy('rank');
    }

    /** @return HasMany<LoyaltyReward, $this> */
    public function rewards(): HasMany
    {
        return $this->hasMany(LoyaltyReward::class, 'program_id');
    }
}
