<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Suscripción de Web Push de un navegador (la PWA de la bandeja). El endpoint y
 * las llaves van cifrados; `endpoint_hash` evita duplicados.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property string $endpoint
 * @property array{p256dh?: string, auth?: string} $keys
 */
class PushSubscription extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'psh';

    protected $fillable = ['workspace_id', 'user_id', 'endpoint', 'endpoint_hash', 'keys', 'user_agent', 'last_used_at'];

    protected $hidden = ['endpoint', 'keys'];

    protected function casts(): array
    {
        return ['endpoint' => 'encrypted', 'keys' => 'encrypted:array', 'last_used_at' => 'datetime'];
    }
}
