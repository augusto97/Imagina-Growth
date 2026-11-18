<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * «Avísame cuando vuelva» o «avísame si baja de precio» (modulos.md M16).
 * `threshold` es el precio al suscribirse para `price_drop`: se avisa cuando el
 * precio queda por debajo. Una suscripción se avisa una sola vez (`notified`).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $contact_id
 * @property string $external_product_id
 * @property string $kind
 * @property string|null $threshold
 * @property string|null $price_at_subscribe
 * @property string $channel
 * @property string $status
 * @property CarbonInterface|null $notified_at
 * @property string|null $notified_price
 * @property string|null $message_id
 * @property CarbonInterface|null $next_attempt_at
 * @property int $attempts
 * @property string|null $last_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ProductWatch extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'pw';

    public const KINDS = ['back_in_stock', 'price_drop'];

    public const ACTIVE = 'active';

    public const NOTIFIED = 'notified';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['workspace_id', 'site_id', 'contact_id', 'external_product_id', 'kind', 'threshold', 'price_at_subscribe', 'channel', 'status',
        'notified_at', 'notified_price', 'message_id', 'next_attempt_at', 'attempts', 'last_reason'];

    protected $attributes = ['status' => self::ACTIVE, 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
