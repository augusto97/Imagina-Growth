<?php

declare(strict_types=1);

namespace App\Domain\Sites\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una instalación de WordPress conectada (modelo-de-datos.md §1).
 *
 * @property string $id
 * @property int $ch_id
 * @property string $workspace_id
 * @property string $platform
 * @property string|null $name
 * @property string $url
 * @property string $host
 * @property string|null $rest_url
 * @property list<string>|null $domains
 * @property string $public_key
 * @property string $secret
 * @property string|null $previous_secret
 * @property Carbon|null $previous_secret_expires_at
 * @property string $status
 * @property string|null $timezone
 * @property string|null $currency
 * @property array<string, mixed>|null $capabilities
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $backfill_state
 * @property array<string, mixed>|null $health
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $last_event_at
 * @property Carbon|null $connected_at
 */
class Site extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'st';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'workspace_id', 'platform', 'name', 'url', 'host', 'rest_url', 'domains', 'public_key', 'secret',
        'secret_rotated_at', 'previous_secret', 'previous_secret_expires_at', 'status', 'timezone', 'currency',
        'plugin_version', 'wp_version', 'wc_version', 'php_version', 'capabilities', 'settings',
        'backfill_state', 'health', 'connected_at', 'last_seen_at', 'last_event_at', 'tracking_domain',
    ];

    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return [
            'ch_id' => 'integer',
            'domains' => 'array',
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'capabilities' => 'array',
            'settings' => 'array',
            'backfill_state' => 'array',
            'health' => 'array',
            'secret_rotated_at' => 'datetime',
            'previous_secret_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    /**
     * Secretos válidos para verificar una firma: el actual y, durante 24 h
     * después de rotar, el anterior (api-connector.md §2).
     *
     * @return list<string>
     */
    public function verificationSecrets(): array
    {
        $secrets = [$this->secret];
        if ($this->previous_secret !== null && $this->previous_secret_expires_at?->isFuture()) {
            $secrets[] = $this->previous_secret;
        }

        return $secrets;
    }

    /**
     * Ajustes efectivos: los del sitio sobre los valores por defecto.
     *
     * @return array<string, mixed>
     */
    public function effectiveSettings(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = config('igr.site_defaults');

        return array_replace($defaults, $this->settings ?? []);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_CONNECTED, self::STATUS_DEGRADED], true);
    }

    public function commandsUrl(): ?string
    {
        return $this->rest_url ? rtrim($this->rest_url, '/').'/commands' : null;
    }
}
