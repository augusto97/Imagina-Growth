<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una suscripción de webhooks salientes (M19): a qué URL se mandan qué eventos, firmados
 * con su secreto (`whsec_…`, cifrado en reposo).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $name
 * @property string $url
 * @property list<string> $events eventos exactos (`order.paid`), por prefijo (`order.*`) o `*`
 * @property bool $include_pii
 * @property string|null $preset crm | base | proposals… (integración del ecosistema que la creó)
 * @property string $secret
 * @property string $status active | failing | paused
 * @property int $consecutive_failures
 * @property Carbon|null $last_success_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookSubscription extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'whk';

    public const ACTIVE = 'active';

    public const FAILING = 'failing';

    public const PAUSED = 'paused';

    protected $fillable = ['workspace_id', 'name', 'url', 'events', 'include_pii', 'preset', 'secret', 'status', 'consecutive_failures', 'last_success_at', 'created_by'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'include_pii' => 'boolean',
            'secret' => 'encrypted',
            'consecutive_failures' => 'integer',
            'last_success_at' => 'datetime',
        ];
    }

    public function wants(string $event): bool
    {
        foreach ((array) $this->events as $pattern) {
            if (self::matches((string) $pattern, $event)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $pattern, string $event): bool
    {
        if ($pattern === '*' || $pattern === $event) {
            return true;
        }

        return str_ends_with($pattern, '.*') && str_starts_with($event, substr($pattern, 0, -1));
    }
}
