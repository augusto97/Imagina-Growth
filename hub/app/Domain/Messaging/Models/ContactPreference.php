<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que el contacto eligió en el centro de preferencias: temas y frecuencia
 * (`weekly` o `monthly` los aplica SendPolicy).
 *
 * @property string $contact_id
 * @property string $workspace_id
 * @property list<string>|null $topics
 * @property string $frequency
 * @property CarbonInterface|null $updated_at
 */
class ContactPreference extends Model
{
    use BelongsToWorkspace;

    public const CREATED_AT = null;

    public const FREQUENCIES = ['normal', 'weekly', 'monthly'];

    protected $primaryKey = 'contact_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['contact_id', 'workspace_id', 'topics', 'frequency'];

    protected function casts(): array
    {
        return ['topics' => 'array'];
    }
}
