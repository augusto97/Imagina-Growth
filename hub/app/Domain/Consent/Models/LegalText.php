<?php

declare(strict_types=1);

namespace App\Domain\Consent\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Texto legal versionado (cumplimiento.md §2): cada consentimiento guarda la
 * versión exacta que vio la persona.
 *
 * @property string $id
 * @property string $kind
 * @property string|null $channel
 * @property string $version
 * @property string $body
 * @property CarbonInterface|null $published_at
 */
class LegalText extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'lgl';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
