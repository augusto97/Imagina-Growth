<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Models;

use App\Domain\Measurement\Enums\DestinationMode;
use App\Domain\Measurement\Enums\Platform;
use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Destino de conversiones (modelo-de-datos.md §7). `config` va cifrado: tokens,
 * dataset/pixel, cuentas y acciones de conversión. `pixel_id` es público.
 *
 * @property string $id
 * @property string $workspace_id
 * @property Platform $platform
 * @property string|null $name
 * @property array<string, mixed> $config
 * @property list<string>|null $events_enabled
 * @property DestinationMode $mode
 * @property string|null $pixel_id
 * @property string|null $test_event_code
 * @property string $status
 * @property string|null $last_error
 * @property CarbonInterface|null $last_delivered_at
 */
class AdDestination extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'dst';

    protected $fillable = ['workspace_id', 'platform', 'name', 'config', 'events_enabled', 'mode', 'pixel_id', 'test_event_code', 'status', 'last_error', 'last_delivered_at'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'mode' => DestinationMode::class,
            'config' => 'encrypted:array',
            'events_enabled' => 'array',
            'last_delivered_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** ¿Este destino envía el evento del catálogo de Imagina Growth? */
    public function sends(string $event): bool
    {
        /** @var array<string, array<string, mixed>> $map */
        $map = config('igr-measurement.events');
        if (! isset($map[$event])) {
            return false;
        }
        if ($this->events_enabled === null) {
            return (bool) ($map[$event]['default'] ?? false);
        }

        return in_array($event, $this->events_enabled, true);
    }

    public function credential(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
