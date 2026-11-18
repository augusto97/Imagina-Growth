<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Reseña de un producto o de la tienda (modulos.md M15, modelo-de-datos.md §13).
 * `author_name` es lo que se publica («Ana R.»). El texto nunca va a ClickHouse:
 * `review.collected` solo lleva la calificación y si tiene medios.
 *
 * `media`: [{key, type (image|video), mime, size, width?, height?, thumb_key?}]. Los
 * archivos viven en el disco privado y se sirven por /r/m/{key} solo mientras la
 * reseña está publicada (o con una URL firmada para la moderación).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string|null $product_id
 * @property string|null $external_product_id
 * @property string|null $contact_id
 * @property string|null $order_id
 * @property string|null $request_id
 * @property int $rating
 * @property string|null $title
 * @property string|null $body
 * @property string|null $author_name
 * @property list<array<string, mixed>>|null $media
 * @property string $status
 * @property bool $verified_buyer
 * @property string $source
 * @property string|null $external_review_id
 * @property string|null $owner_reply
 * @property CarbonInterface|null $replied_at
 * @property string|null $external_reply_id
 * @property array<string, mixed>|null $ai_flags
 * @property string|null $moderated_by
 * @property CarbonInterface|null $moderated_at
 * @property CarbonInterface|null $published_at
 * @property string|null $reject_reason
 * @property string|null $sync_status
 * @property string|null $sync_error
 * @property string|null $import_key
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Review extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'rv';

    public const STATUSES = ['pending', 'published', 'rejected'];

    public const SOURCES = ['request', 'widget', 'import', 'woocommerce'];

    /** Motivos de rechazo: siempre por el contenido, nunca por la calificación (M15). */
    public const REJECT_REASONS = ['offensive', 'spam', 'personal_data', 'off_topic', 'duplicate', 'other'];

    protected $guarded = [];

    protected $attributes = ['status' => 'pending', 'verified_buyer' => false];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'media' => 'array',
            'ai_flags' => 'array',
            'verified_buyer' => 'boolean',
            'replied_at' => 'datetime',
            'moderated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function hasMedia(): bool
    {
        return ($this->media ?? []) !== [];
    }

    public function isStoreReview(): bool
    {
        return $this->external_product_id === null;
    }

    /** @return list<string> claves de las banderas de moderación activas */
    public function flagKeys(): array
    {
        return array_values(array_map('strval', (array) ($this->ai_flags['flags'] ?? [])));
    }
}
