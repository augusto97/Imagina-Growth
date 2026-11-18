<?php

declare(strict_types=1);

namespace App\Domain\Agency\Models;

use App\Support\Ids\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Model;

/**
 * Dominio propio de una cuenta (M22): el panel de la agencia, los enlaces, la
 * página de reservas o el seguimiento. Caddy solo emite el certificado cuando
 * está verificado (`CustomDomains::servable`).
 *
 * @property string $id
 * @property string $account_id
 * @property string|null $workspace_id
 * @property string $host
 * @property string $purpose
 * @property string $status
 * @property string $verification_token
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property string|null $last_error
 */
class CustomDomain extends Model
{
    use HasPrefixedUlid;

    public const ID_PREFIX = 'dom';

    public const PURPOSES = ['panel', 'links', 'booking', 'tracking'];

    protected $fillable = ['account_id', 'workspace_id', 'host', 'purpose', 'status', 'verification_token', 'verified_at', 'last_error'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }
}
