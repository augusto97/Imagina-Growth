<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Models;

use App\Domain\Services\Leads\Support\LeadDefaults;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Ajustes de leads del espacio de trabajo: reglas del puntaje (inteligencia.md §4.9),
 * umbrales, avisos al vendedor, asignación y WhatsApp como origen.
 *
 * @property string $workspace_id
 * @property array<string, mixed>|null $scoring
 * @property array<string, mixed>|null $alerts
 * @property array<string, mixed>|null $assignment
 * @property bool $whatsapp_leads
 * @property bool $ai_qualify
 * @property bool $ai_brief la IA llena la ficha del lead desde la conversación
 * @property array<string, mixed>|null $assistant «Hoy» y el resumen matutino (LeadDefaults::ASSISTANT)
 */
class LeadSettings extends Model
{
    use BelongsToWorkspace;

    protected $primaryKey = 'workspace_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['workspace_id', 'scoring', 'alerts', 'assignment', 'whatsapp_leads', 'ai_qualify', 'ai_brief', 'assistant'];

    protected function casts(): array
    {
        return ['scoring' => 'array', 'alerts' => 'array', 'assignment' => 'array', 'assistant' => 'array', 'whatsapp_leads' => 'boolean', 'ai_qualify' => 'boolean', 'ai_brief' => 'boolean'];
    }

    public static function for(string $workspaceId): self
    {
        $row = self::query()->where('workspace_id', $workspaceId)->first();

        return $row ?? new self(['workspace_id' => $workspaceId, 'whatsapp_leads' => true, 'ai_qualify' => true, 'ai_brief' => true]);
    }

    /** @return array<string, mixed> reglas del puntaje con los valores por defecto */
    public function scoringRules(): array
    {
        return array_replace_recursive(LeadDefaults::SCORING, (array) ($this->scoring ?? []));
    }

    /** @return array<string, mixed> */
    public function alertRules(): array
    {
        return array_replace(LeadDefaults::ALERTS, (array) ($this->alerts ?? []));
    }

    /** @return array{stale_days: int, whatsapp_template_id: string|null, digest: bool} */
    public function assistantRules(): array
    {
        $rules = array_replace(LeadDefaults::ASSISTANT, (array) ($this->assistant ?? []));

        return [
            'stale_days' => max(1, min(90, (int) $rules['stale_days'])),
            'whatsapp_template_id' => is_string($rules['whatsapp_template_id']) && $rules['whatsapp_template_id'] !== '' ? $rules['whatsapp_template_id'] : null,
            'digest' => (bool) $rules['digest'],
        ];
    }

    /** @return array<string, mixed> */
    public function assignmentRules(): array
    {
        return array_replace(LeadDefaults::ASSIGNMENT, (array) ($this->assignment ?? []));
    }
}
