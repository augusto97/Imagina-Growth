<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Carrito proyectado desde `cart.*` y `checkout.started` (modelo-de-datos.md §4).
 * Estados: active → abandoned (job de carritos) → recovered (pedido después de
 * abandonarse) o converted (pedido sin abandonarse); expired a los 30 días.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $token
 * @property string|null $contact_id
 * @property string|null $anonymous_id
 * @property list<array<string, mixed>>|null $items
 * @property int $items_count
 * @property string $subtotal
 * @property string $subtotal_ws
 * @property string|null $currency
 * @property list<string>|null $coupons
 * @property string $status
 * @property CarbonInterface|null $checkout_started_at
 * @property CarbonInterface|null $abandoned_at
 * @property CarbonInterface $last_activity_at
 * @property string|null $restore_url_token
 * @property string|null $order_id
 * @property string|null $recovered_order_id
 */
class Cart extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'crt';

    protected $fillable = ['workspace_id', 'site_id', 'token', 'contact_id', 'anonymous_id', 'items', 'items_count', 'subtotal', 'subtotal_ws', 'currency', 'coupons', 'status', 'checkout_started_at', 'abandoned_at', 'last_activity_at', 'restore_url_token', 'order_id', 'recovered_order_id'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'coupons' => 'array',
            'items_count' => 'integer',
            'checkout_started_at' => 'datetime',
            'abandoned_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }
}
