<?php

declare(strict_types=1);

namespace App\Domain\Sites\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Código de un solo uso del handshake (api-connector.md §3). Se guarda con hash.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $site_url
 * @property string|null $site_host
 * @property string|null $nonce
 * @property string|null $return_url
 * @property string|null $platform
 * @property string|null $name
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class SiteConnectCode extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'scc';

    protected $fillable = ['workspace_id', 'code_hash', 'site_url', 'site_host', 'nonce', 'return_url', 'platform', 'name', 'created_by', 'expires_at', 'used_at', 'site_id'];

    protected $hidden = ['code_hash', 'nonce'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
