<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Recompensa del catálogo de canje: cupón fijo o porcentual, envío gratis,
 * producto gratis o saldo a favor, con su costo en puntos.
 *
 * `config`: `min_order` (compra mínima del cupón), `expires_in_days` (vigencia
 * del cupón; si falta, la del programa) y `product_id` (producto gratis, id de
 * la tienda).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $program_id
 * @property string $name
 * @property string $kind
 * @property int $points_cost
 * @property string $value
 * @property array<string, mixed>|null $config
 * @property string $status
 */
class LoyaltyReward extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'rw';

    public const KINDS = ['coupon_fixed', 'coupon_percent', 'free_shipping', 'free_product', 'wallet'];

    public const STATUSES = ['active', 'archived'];

    protected $fillable = ['workspace_id', 'program_id', 'name', 'kind', 'points_cost', 'value', 'config', 'status'];

    protected function casts(): array
    {
        return ['config' => 'array', 'points_cost' => 'integer', 'value' => 'decimal:2'];
    }
}
