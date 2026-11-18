<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Un contacto que ya está en la audiencia de la plataforma, con los
 * identificadores con hash que se le enviaron (cifrados): así se le puede sacar
 * aunque cambie su correo o se borre su perfil (cumplimiento.md §9, paso 4).
 * Sin workspace_id propio: se consulta siempre a través de su AudienceSync.
 *
 * @property string $audience_sync_id
 * @property string $contact_id
 * @property array<string, string>|null $identifiers
 * @property CarbonInterface $synced_at
 */
class AudienceMember extends Model
{
    protected $table = 'audience_members';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'contact_id';

    protected $keyType = 'string';

    protected $fillable = ['audience_sync_id', 'contact_id', 'identifiers', 'synced_at'];

    protected function casts(): array
    {
        return ['identifiers' => 'encrypted:array', 'synced_at' => 'datetime'];
    }
}
