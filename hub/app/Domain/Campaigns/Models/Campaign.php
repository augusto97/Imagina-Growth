<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Envío puntual a una audiencia (modulos.md M10, modelo-de-datos.md §5).
 *
 * Estados: draft → scheduled (aprobada) → sending → sent; paused y cancelled.
 * `audience`: {segments_include[], segments_exclude[], all_contacts, rules_include[], rules_exclude[]}
 * `variants`: [{key: A|B|C|D, template_id, weight}] (A/B); sin variantes usa `template_id`.
 * `ab_config`: {sample_pct, metric: clicks|revenue, wait_hours}.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string $channel
 * @property string $status
 * @property array<string, mixed> $audience
 * @property string|null $template_id
 * @property list<array{key: string, template_id: string|null, weight?: int}>|null $variants
 * @property array{sample_pct?: int, metric?: string, wait_hours?: int}|null $ab_config
 * @property string|null $winner_variant
 * @property int $holdout_pct
 * @property CarbonInterface|null $send_at
 * @property string $send_mode
 * @property array<string, mixed>|null $cost_estimate
 * @property array<string, mixed>|null $compliance_check
 * @property array<string, mixed>|null $stats
 * @property string|null $created_by
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Campaign extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'cmp';

    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const PAUSED = 'paused';

    public const CANCELLED = 'cancelled';

    public const CHANNELS = ['email', 'whatsapp', 'sms'];

    public const SEND_MODES = ['now', 'scheduled', 'contact_timezone', 'best_time'];

    protected $fillable = [
        'workspace_id', 'name', 'channel', 'status', 'audience', 'template_id', 'variants', 'ab_config', 'winner_variant',
        'holdout_pct', 'send_at', 'send_mode', 'cost_estimate', 'compliance_check', 'stats', 'created_by', 'started_at', 'finished_at',
    ];

    protected $attributes = ['status' => self::DRAFT, 'send_mode' => 'now', 'holdout_pct' => 0, 'audience' => '{}'];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'variants' => 'array',
            'ab_config' => 'array',
            'cost_estimate' => 'array',
            'compliance_check' => 'array',
            'stats' => 'array',
            'holdout_pct' => 'integer',
            'send_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SCHEDULED], true) && $this->started_at === null;
    }

    /** ¿Tiene A/B con muestra (el resto espera al ganador)? */
    public function hasAbTest(): bool
    {
        return count($this->variantList()) > 1;
    }

    /**
     * Variantes efectivas: las de A/B o una sola con la plantilla principal.
     *
     * @return list<array{key: string, template_id: string|null, weight: int}>
     */
    public function variantList(): array
    {
        $out = [];
        foreach ((array) $this->variants as $v) {
            if (is_array($v) && isset($v['key'])) {
                $out[] = ['key' => (string) $v['key'], 'template_id' => isset($v['template_id']) ? (string) $v['template_id'] : null, 'weight' => max(1, (int) ($v['weight'] ?? 1))];
            }
        }

        return $out !== [] ? $out : [['key' => 'A', 'template_id' => $this->template_id, 'weight' => 1]];
    }

    public function templateFor(?string $variant): ?string
    {
        foreach ($this->variantList() as $v) {
            if ($v['key'] === $variant) {
                return $v['template_id'];
            }
        }

        return $this->template_id ?? ($this->variantList()[0]['template_id'] ?? null);
    }
}
