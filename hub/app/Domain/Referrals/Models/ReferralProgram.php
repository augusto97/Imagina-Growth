<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Programa de referidos del espacio de trabajo (uno por espacio, modulos.md M14).
 *
 * - `friend_reward`: el cupón de primera compra del amigo `{kind, amount, expires_days, minimum_amount?}`.
 * - `referrer_reward`: lo que gana quien refiere `{type: points|wallet|coupon, points?, amount?, coupon_kind?, expires_days?}`.
 * - `rules`: `{min_order?, monthly_limit?, return_days, qualify_on: completed|paid, review_suspicious, signal_window_days}`.
 * - `share_text`: con `{regalo}`, `{tienda}`, `{enlace}` y `{codigo}`.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $status draft | active | paused
 * @property array<string, mixed> $referrer_reward
 * @property array<string, mixed> $friend_reward
 * @property array<string, mixed>|null $rules
 * @property string|null $share_text
 */
class ReferralProgram extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'rfp';

    protected $fillable = ['workspace_id', 'status', 'referrer_reward', 'friend_reward', 'rules', 'share_text'];

    protected function casts(): array
    {
        return [
            'referrer_reward' => 'array',
            'friend_reward' => 'array',
            'rules' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
