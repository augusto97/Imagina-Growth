<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Widget de captación en el sitio (modulos.md M12): popup, formulario incrustado,
 * barra, ruleta, teaser, formulario en 2 pasos, quiz, widget de WhatsApp o
 * bienvenida del referido. `coupon_config` (el regalo y las probabilidades de la
 * ruleta) nunca sale al navegador.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $site_id
 * @property string $name
 * @property string $kind
 * @property string $status
 * @property int $priority
 * @property array<string, mixed>|null $targeting
 * @property array<string, mixed>|null $design
 * @property array<string, mixed>|null $content
 * @property list<array<string, mixed>>|null $variants
 * @property array<string, mixed>|null $consent_config
 * @property array<string, mixed>|null $coupon_config
 * @property array<string, mixed>|null $stats
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property string|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Quiz|null $quiz
 */
class OnsiteWidget extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'wg';

    public const KINDS = ['popup', 'embedded_form', 'bar', 'spin_wheel', 'teaser', 'two_step', 'quiz', 'wa_widget', 'referral_welcome'];

    public const STATUSES = ['draft', 'active', 'paused', 'archived'];

    protected $guarded = [];

    protected $attributes = ['status' => 'draft', 'priority' => 100];

    protected function casts(): array
    {
        return [
            'targeting' => 'array',
            'design' => 'array',
            'content' => 'array',
            'variants' => 'array',
            'consent_config' => 'array',
            'coupon_config' => 'array',
            'stats' => 'array',
            'priority' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return HasOne<Quiz, $this> */
    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class, 'widget_id');
    }

    /** ¿Se muestra ahora? Activo y dentro de su programación. */
    public function isLive(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->status === 'active'
            && ($this->starts_at === null || $this->starts_at->lessThanOrEqualTo($at))
            && ($this->ends_at === null || $this->ends_at->greaterThan($at));
    }

    /** Incrustado en un espacio del sitio (formulario o quiz con slot) en vez de overlay. */
    public function slot(): ?string
    {
        return match ($this->kind) {
            'embedded_form' => 'form',
            'quiz' => ($this->design['display'] ?? 'popup') === 'embedded' ? 'quiz' : null,
            default => null,
        };
    }

    /** ¿Captura datos? (el widget de WhatsApp solo abre el chat). */
    public function captures(): bool
    {
        return $this->kind !== 'wa_widget';
    }
}
