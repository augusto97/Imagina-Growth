<?php

declare(strict_types=1);

namespace App\Domain\Connections\Models;

use App\Domain\Connections\Enums\ConnectionProvider;
use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Conexión OAuth de un espacio de trabajo con un proveedor (modelo-de-datos.md §16). `credentials` va
 * cifrado (access_token, refresh_token) y nunca vuelve a la SPA; `assets` guarda las cuentas que la persona
 * autorizó (IDs y nombres). `subject` separa varias conexiones del mismo proveedor: `''` es la del espacio
 * de trabajo, `staff:{id}` la de una persona de la agenda y `waba:{id}` la de una cuenta de WhatsApp.
 *
 * @property string $id
 * @property string $workspace_id
 * @property ConnectionProvider $provider
 * @property string $subject
 * @property string $status
 * @property string|null $external_id
 * @property string|null $account_name
 * @property list<string>|null $scopes
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $assets
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $refreshed_at
 * @property CarbonInterface|null $checked_at
 * @property string|null $last_error
 * @property string|null $connected_by
 * @property CarbonInterface|null $revoked_at
 */
class OAuthConnection extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'oac';

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const ERROR = 'error';

    protected $table = 'oauth_connections';

    protected $fillable = ['workspace_id', 'provider', 'subject', 'status', 'external_id', 'account_name', 'scopes', 'credentials', 'assets', 'expires_at', 'refreshed_at', 'checked_at', 'last_error', 'connected_by', 'revoked_at'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'provider' => ConnectionProvider::class,
            'scopes' => 'array',
            'credentials' => 'encrypted:array',
            'assets' => 'array',
            'expires_at' => 'datetime',
            'refreshed_at' => 'datetime',
            'checked_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function secret(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /**
     * El token de acceso si la conexión está activa y no vence en el próximo minuto, sin llamar al proveedor.
     * Los de Meta (usuario del sistema) y TikTok no vencen; los de Google los renueva ConnectionTokens.
     */
    public function freshAccessToken(): ?string
    {
        if (! $this->isActive()) {
            return null;
        }
        if ($this->expires_at !== null && $this->expires_at->getTimestamp() <= now()->getTimestamp() + 60) {
            return null;
        }

        return $this->secret('access_token');
    }

    /** @return array<array-key, mixed> */
    public function asset(string $key): array
    {
        $value = $this->assets[$key] ?? [];

        return is_array($value) ? $value : [];
    }
}
