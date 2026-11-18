<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla de WhatsApp de un número (modelo-de-datos.md §5), sincronizada con
 * Meta. `components` sigue el formato de la Graph API (HEADER, BODY, FOOTER,
 * BUTTONS).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $channel_account_id
 * @property string $name
 * @property string $language
 * @property string $category
 * @property string $status
 * @property string|null $quality
 * @property list<array<string, mixed>> $components
 * @property string|null $meta_template_id
 * @property string|null $rejected_reason
 * @property CarbonInterface|null $synced_at
 */
class WaTemplate extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'wat';

    public const CATEGORIES = ['MARKETING', 'UTILITY', 'AUTHENTICATION'];

    protected $fillable = ['workspace_id', 'channel_account_id', 'name', 'language', 'category', 'status', 'quality', 'components', 'meta_template_id', 'rejected_reason', 'synced_at'];

    protected function casts(): array
    {
        return ['components' => 'array', 'synced_at' => 'datetime'];
    }

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }
}
