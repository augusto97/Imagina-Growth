<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Agent\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuración del agente de ventas (una fila por espacio de trabajo,
 * modelo-de-datos.md §14). Sin fila, el agente está apagado.
 *
 * @property string $workspace_id
 * @property bool $enabled
 * @property string $default_mode off | assist | auto
 * @property string|null $scope
 * @property string|null $tone
 * @property array<string, string>|null $policies shipping, returns, payment_methods, hours, other
 * @property array<string, mixed>|null $handoff_rules keywords, intents, out_of_hours
 * @property array<string, mixed>|null $discount_rules enabled, coupon_code, min_subtotal, description
 * @property int $max_steps
 * @property string|null $updated_by
 */
class AgentSettings extends Model
{
    use BelongsToWorkspace;

    public const MODES = ['off', 'assist', 'auto'];

    public const POLICY_KEYS = ['shipping', 'returns', 'payment_methods', 'hours', 'other'];

    protected $table = 'ai_agent_settings';

    protected $primaryKey = 'workspace_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['workspace_id', 'enabled', 'default_mode', 'scope', 'tone', 'policies', 'handoff_rules', 'discount_rules', 'max_steps', 'updated_by'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'policies' => 'array',
            'handoff_rules' => 'array',
            'discount_rules' => 'array',
            'max_steps' => 'integer',
        ];
    }

    /** La configuración del espacio de trabajo activo, o una sin guardar con los valores por defecto. */
    public static function current(string $workspaceId): self
    {
        return self::query()->where('workspace_id', $workspaceId)->first() ?? new self([
            'workspace_id' => $workspaceId, 'enabled' => false, 'default_mode' => 'off', 'max_steps' => 6,
            'policies' => [], 'handoff_rules' => self::defaultHandoff(), 'discount_rules' => ['enabled' => false],
        ]);
    }

    /** @return array<string, mixed> */
    public static function defaultHandoff(): array
    {
        return ['keywords' => ['asesor', 'humano', 'persona', 'hablar con alguien'], 'intents' => ['complaint'], 'out_of_hours' => false];
    }

    /** @return array<string, string> políticas con texto (sin las vacías) */
    public function policyTexts(): array
    {
        $out = [];
        foreach (self::POLICY_KEYS as $key) {
            $text = trim((string) (($this->policies ?? [])[$key] ?? ''));
            if ($text !== '') {
                $out[$key] = $text;
            }
        }

        return $out;
    }
}
