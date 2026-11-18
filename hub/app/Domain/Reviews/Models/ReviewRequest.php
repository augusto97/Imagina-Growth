<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Solicitud de reseña por pedido (una sola por pedido). `products` son los IDs
 * de la tienda que se piden reseñar; el enlace /r/{token} va firmado por esta
 * solicitud, el pedido y el contacto.
 *
 * Estados: scheduled → sent → completed, o skipped (con `skip_reason`) y expired.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $site_id
 * @property string $order_id
 * @property string $contact_id
 * @property string $channel
 * @property string $kind
 * @property string $status
 * @property string|null $skip_reason
 * @property int $attempts
 * @property list<string>|null $products
 * @property string|null $message_id
 * @property CarbonInterface|null $scheduled_for
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $created_at
 */
class ReviewRequest extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'rr';

    protected $guarded = [];

    protected $attributes = ['status' => 'scheduled', 'channel' => 'pending', 'kind' => 'marketing', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'products' => 'array',
            'attempts' => 'integer',
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
