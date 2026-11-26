<?php

declare(strict_types=1);

namespace App\Domain\Capture\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Página de captura alojada en el hub (rediseño v2 §11.3, modulos.md M12): `/p/{slug}` con el título, el texto, el
 * logo o la imagen, el color de la marca, los campos, las casillas de consentimiento por canal (sin marcar, con el
 * texto legal vigente), el mensaje de gracias o la redirección y el botón opcional de WhatsApp. Para negocios sin
 * buena web o para el local físico (con su QR). El slug es único en toda la plataforma.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $site_id
 * @property string $slug
 * @property string $name
 * @property string $status
 * @property array<string, mixed>|null $content
 * @property list<array<string, mixed>>|null $fields
 * @property list<string>|null $consent_channels
 * @property array<string, mixed>|null $settings
 * @property string|null $wa_link_id
 * @property CarbonInterface|null $published_at
 * @property string|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class LandingPage extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'lp';

    public const STATUSES = ['draft', 'published', 'archived'];

    protected $guarded = [];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'fields' => 'array',
            'consent_channels' => 'array',
            'settings' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /** Un valor de `content` como texto (vacío si no está). */
    public function text(string $key): string
    {
        $value = ($this->content ?? [])[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }
}
