<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Un amigo referido (modulos.md M14). Estados: `signed_up` (dejó sus datos con el
 * código) → `ordered` (primera compra atribuida) → `qualified` (completada y pasó el
 * periodo de devolución) → `rewarded` (quien refiere recibió su premio), o
 * `rejected` con `rejected_reason`. Los casos con señales de fraude quedan con
 * `review_status = pending_review` hasta que alguien del equipo decide.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $site_id
 * @property string $referrer_contact_id
 * @property string $code
 * @property string|null $friend_contact_id
 * @property string $status
 * @property string|null $order_id
 * @property CarbonInterface|null $ordered_at
 * @property string|null $order_amount_ws
 * @property CarbonInterface|null $qualifies_at
 * @property CarbonInterface|null $rewarded_at
 * @property string|null $friend_coupon_id
 * @property string|null $reward_ledger_id
 * @property array<string, mixed>|null $reward
 * @property string|null $reward_cost_ws
 * @property list<array{signal: string, stage: string, at: string}>|null $fraud_signals
 * @property string|null $review_status
 * @property string|null $rejected_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Referral extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'rf';

    public const SIGNED_UP = 'signed_up';

    public const ORDERED = 'ordered';

    public const QUALIFIED = 'qualified';

    public const REWARDED = 'rewarded';

    public const REJECTED = 'rejected';

    public const STATUSES = ['clicked', self::SIGNED_UP, self::ORDERED, self::QUALIFIED, self::REWARDED, self::REJECTED];

    public const PENDING_REVIEW = 'pending_review';

    protected $fillable = [
        'workspace_id', 'site_id', 'referrer_contact_id', 'code', 'friend_contact_id', 'status', 'order_id', 'ordered_at', 'order_amount_ws',
        'qualifies_at', 'rewarded_at', 'friend_coupon_id', 'reward_ledger_id', 'reward', 'reward_cost_ws', 'fraud_signals', 'review_status', 'rejected_reason',
    ];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'qualifies_at' => 'datetime',
            'rewarded_at' => 'datetime',
            'reward' => 'array',
            'fraud_signals' => 'array',
        ];
    }

    /** @return list<string> */
    public function signals(): array
    {
        return array_values(array_unique(array_map(fn (array $s) => $s['signal'], $this->fraud_signals ?? [])));
    }

    /**
     * Agrega señales sin repetir las que ya tiene.
     *
     * @param  list<string>  $signals
     */
    public function addSignals(array $signals, string $stage): void
    {
        $current = $this->fraud_signals ?? [];
        $known = $this->signals();
        foreach ($signals as $signal) {
            if (! in_array($signal, $known, true)) {
                $current[] = ['signal' => $signal, 'stage' => $stage, 'at' => now()->toIso8601String()];
                $known[] = $signal;
            }
        }
        $this->fraud_signals = $current;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::SIGNED_UP, self::ORDERED, self::QUALIFIED], true);
    }
}
