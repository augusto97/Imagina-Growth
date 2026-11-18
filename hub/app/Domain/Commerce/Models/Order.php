<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Proyección de un pedido de la tienda. `created_at`/`updated_at` son las
 * fechas de WooCommerce, no de la fila (ver la migración).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $external_id
 * @property string|null $contact_id
 * @property string $status
 * @property bool $is_paid
 * @property bool $is_cod
 * @property string $total
 * @property string $refunded
 * @property string|null $total_ws
 * @property list<string>|null $coupon_codes
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $projected_at
 */
class Order extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'ord';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'is_cod' => 'boolean',
            'is_offline_payment' => 'boolean',
            'is_first_order' => 'boolean',
            'coupon_codes' => 'array',
            'utm_last' => 'array',
            'wc_attribution' => 'array',
            'paid_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'projected_at' => 'datetime',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
