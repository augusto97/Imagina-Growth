<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Nota interna del equipo sobre un contacto. Cifrada: puede tener datos personales.
 *
 * @property string $id
 * @property string $contact_id
 * @property string|null $user_id
 * @property string $body
 */
class ContactNote extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'nt';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }
}
