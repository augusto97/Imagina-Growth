<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Models;

use App\Domain\Contacts\Models\Contact;
use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un lead (M18): una persona que pidió algo y su paso por el pipeline.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $pipeline_id
 * @property string $contact_id
 * @property string|null $site_id
 * @property string $stage
 * @property Carbon|null $stage_changed_at
 * @property string|null $service
 * @property string|null $value_ws
 * @property string|null $owner_id
 * @property array<string, mixed>|null $source
 * @property int $score
 * @property string $temperature cold | warm | hot
 * @property list<array<string, mixed>>|null $score_reasons
 * @property array<string, array<string, mixed>>|null $brief ficha de la conversación (LeadBrief), cifrada
 * @property Carbon|null $brief_updated_at
 * @property array<string, mixed>|null $qualification respuestas a las preguntas de calificación (Qualification), cifradas
 * @property Carbon|null $qualified_at cuándo se aplicó la derivación
 * @property string $origin form | whatsapp | quote | booking | manual | api
 * @property string|null $origin_ref
 * @property Carbon|null $first_response_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $won_at
 * @property Carbon|null $offline_conversion_at
 * @property Carbon|null $lost_at
 * @property string|null $lost_reason
 * @property string|null $next_step
 * @property Carbon|null $next_step_at
 * @property string|null $external_crm_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Lead extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'ld';

    public const OPEN_STAGES_EXCLUDED = ['won', 'lost'];

    protected $hidden = ['brief', 'qualification'];

    protected $fillable = [
        'workspace_id', 'pipeline_id', 'contact_id', 'site_id', 'stage', 'stage_changed_at', 'service', 'value_ws', 'owner_id', 'source', 'score',
        'temperature', 'score_reasons', 'brief', 'brief_updated_at', 'qualification', 'qualified_at', 'origin', 'origin_ref', 'first_response_at', 'last_activity_at', 'won_at', 'offline_conversion_at',
        'lost_at', 'lost_reason', 'next_step', 'next_step_at', 'external_crm_id',
    ];

    protected function casts(): array
    {
        return [
            'source' => 'array',
            'score_reasons' => 'array',
            'brief' => 'encrypted:array',
            'brief_updated_at' => 'datetime',
            'qualification' => 'encrypted:array',
            'qualified_at' => 'datetime',
            'stage_changed_at' => 'datetime',
            'score' => 'integer',
            'value_ws' => 'decimal:2',
            'first_response_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'won_at' => 'datetime',
            'offline_conversion_at' => 'datetime',
            'lost_at' => 'datetime',
            'next_step_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isOpen(): bool
    {
        return $this->won_at === null && $this->lost_at === null;
    }
}
