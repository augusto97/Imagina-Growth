<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un segmento sincronizado con una audiencia de anuncios (modulos.md M06,
 * Fase 3): Meta Custom Audiences, Google Customer Match (Data Manager API) o
 * TikTok. `purpose` dice para qué sirve en la plataforma: público
 * (`target`), exclusión de compradores recientes (`exclusion`) o semilla de
 * públicos similares (`lookalike_seed`). Se sincroniza cada 12 h.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $segment_id
 * @property string $destination_id
 * @property string $name
 * @property string $purpose
 * @property string|null $platform_audience_id
 * @property string $status
 * @property int $members_count
 * @property int|null $matched_count
 * @property int $last_added
 * @property int $last_removed
 * @property int $skipped_no_consent
 * @property CarbonInterface|null $last_synced_at
 * @property CarbonInterface|null $last_attempt_at
 * @property string|null $last_error
 * @property string|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class AudienceSync extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'aud';

    public const PURPOSES = ['target', 'exclusion', 'lookalike_seed'];

    public const STATUSES = ['pending', 'active', 'paused', 'error'];

    protected $fillable = ['workspace_id', 'segment_id', 'destination_id', 'name', 'purpose', 'platform_audience_id', 'status', 'members_count', 'matched_count',
        'last_added', 'last_removed', 'skipped_no_consent', 'last_synced_at', 'last_attempt_at', 'last_error', 'created_by'];

    protected $attributes = ['status' => 'pending', 'purpose' => 'target', 'members_count' => 0, 'last_added' => 0, 'last_removed' => 0, 'skipped_no_consent' => 0];

    protected function casts(): array
    {
        return [
            'members_count' => 'integer',
            'matched_count' => 'integer',
            'last_added' => 'integer',
            'last_removed' => 'integer',
            'skipped_no_consent' => 'integer',
            'last_synced_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    /** @return HasMany<AudienceMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(AudienceMember::class, 'audience_sync_id');
    }
}
