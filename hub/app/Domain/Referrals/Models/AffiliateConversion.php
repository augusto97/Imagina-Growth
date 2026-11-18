<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Pedido atribuido a un afiliado con su comisión: `pending` (pagado, esperando que
 * se complete y pase el periodo de devolución) → `approved` → `paid` (pago manual
 * exportado), o `void` si se cancela o reembolsa antes de pagarse.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $affiliate_id
 * @property string $order_id
 * @property string $via coupon | link
 * @property string $amount_ws
 * @property string $commission_ws
 * @property string $status
 * @property CarbonInterface|null $qualifies_at
 * @property CarbonInterface|null $approved_at
 * @property CarbonInterface|null $paid_at
 * @property string|null $void_reason
 * @property CarbonInterface|null $created_at
 */
class AffiliateConversion extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'afc';

    public const STATUSES = ['pending', 'approved', 'paid', 'void'];

    protected $fillable = ['workspace_id', 'affiliate_id', 'order_id', 'via', 'amount_ws', 'commission_ws', 'status', 'qualifies_at', 'approved_at', 'paid_at', 'void_reason'];

    protected function casts(): array
    {
        return [
            'qualifies_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
