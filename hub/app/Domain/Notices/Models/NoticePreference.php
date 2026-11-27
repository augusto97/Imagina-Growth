<?php

declare(strict_types=1);

namespace App\Domain\Notices\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * «Mis avisos» de una persona en un espacio de trabajo (lote 0.4.0): qué avisos y por dónde, y su WhatsApp (cifrado).
 * Lo que no está guardado sale de `NoticePreferences::defaults()` según su rol.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property array<string, array<string, mixed>> $settings
 * @property string|null $phone
 */
class NoticePreference extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'npf';

    protected $fillable = ['workspace_id', 'user_id', 'settings', 'phone'];

    protected $hidden = ['phone'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'phone' => 'encrypted'];
    }
}
