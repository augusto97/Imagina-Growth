<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Cupón único creado en el sitio por el hub (modelo-de-datos.md §4). Se marca
 * `redeemed` cuando llega un pedido pagado que lo usa.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $code
 * @property string|null $contact_id
 * @property string $source_type
 * @property string|null $source_id
 * @property string $kind
 * @property string $amount
 * @property array<string, mixed>|null $rules
 * @property CarbonInterface|null $expires_at
 * @property string|null $external_coupon_id
 * @property string|null $redeemed_order_id
 * @property CarbonInterface|null $redeemed_at
 * @property string $status
 * @property string|null $last_error
 */
class CouponIssued extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'cpn';

    protected $table = 'coupons_issued';

    protected $fillable = ['workspace_id', 'site_id', 'code', 'contact_id', 'source_type', 'source_id', 'kind', 'amount', 'rules', 'expires_at', 'external_coupon_id', 'redeemed_order_id', 'redeemed_at', 'status', 'last_error'];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }
}
