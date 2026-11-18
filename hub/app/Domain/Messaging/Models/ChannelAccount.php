<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Messaging\Enums\Channel;
use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuenta de un canal (modelo-de-datos.md §5): un dominio de correo, un número de
 * WhatsApp o una cuenta de SMS. `config` va cifrado (tokens, waba_id,
 * phone_number_id, DKIM). La gestiona M07.
 *
 * @property string $id
 * @property string $workspace_id
 * @property Channel $channel
 * @property string $provider
 * @property string $display_name
 * @property array<string, mixed>|null $config
 * @property string|null $identity
 * @property string|null $external_id
 * @property CarbonInterface|null $warmup_started_at
 * @property string|null $quality_rating
 * @property string|null $messaging_limit_tier
 * @property bool $is_default
 * @property string $status
 * @property string|null $last_error
 * @property CarbonInterface|null $risk_accepted_at
 * @property string|null $risk_accepted_by
 */
class ChannelAccount extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'cha';

    public const ZENDER = 'zender';

    protected $fillable = ['workspace_id', 'channel', 'provider', 'display_name', 'config', 'identity', 'external_id', 'quality_rating', 'messaging_limit_tier', 'warmup_started_at', 'is_default', 'status', 'last_error', 'risk_accepted_at', 'risk_accepted_by'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'config' => 'encrypted:array',
            'is_default' => 'boolean',
            'risk_accepted_at' => 'datetime',
            'warmup_started_at' => 'datetime',
        ];
    }

    /** Un dato de la configuración cifrada (token, waba_id, dominio…). */
    public function credential(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** @param array<string, mixed> $values */
    public function mergeConfig(array $values): void
    {
        $this->config = array_replace($this->config ?? [], $values);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** La cuenta por defecto y activa del canal en el espacio de trabajo actual. */
    public static function defaultFor(Channel $channel): ?self
    {
        return self::query()->where('channel', $channel->value)->where('status', 'active')
            ->orderByDesc('is_default')->orderBy('created_at')->first();
    }
}
