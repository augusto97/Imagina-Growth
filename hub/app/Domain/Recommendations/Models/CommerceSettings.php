<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Models;

use App\Domain\Commerce\Contracts\RecommendationService;
use App\Support\Tenancy\BelongsToWorkspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Ajustes de comercio en el sitio de un espacio de trabajo (una fila por espacio):
 *
 * - `recommendations`: `{enabled: bool, kinds: {para_ti: bool, …}}`.
 * - `watches`: `{enabled: bool, channels: ["whatsapp","email","sms"], templates: {back_in_stock: {whatsapp: "tpl_…"}, price_drop: {…}}}`.
 *   Sin plantilla elegida, el correo y el SMS usan las de la biblioteca (M08);
 *   WhatsApp necesita una plantilla aprobada elegida en el panel.
 *
 * @property string $workspace_id
 * @property array<string, mixed>|null $recommendations
 * @property array<string, mixed>|null $watches
 */
class CommerceSettings extends Model
{
    use BelongsToWorkspace;

    protected $table = 'commerce_settings';

    protected $primaryKey = 'workspace_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['workspace_id', 'recommendations', 'watches'];

    protected function casts(): array
    {
        return ['recommendations' => 'array', 'watches' => 'array'];
    }

    /** Los ajustes del espacio de trabajo activo (sin guardar si no existen). */
    public static function current(): self
    {
        $id = app(CurrentWorkspace::class)->id();

        return self::query()->find($id) ?? new self(['workspace_id' => $id]);
    }

    /** @return array{enabled: bool, kinds: array<string, bool>} */
    public function recommendationSettings(): array
    {
        $raw = $this->recommendations ?? [];
        $kinds = [];
        foreach (RecommendationService::KINDS as $kind) {
            $kinds[$kind] = (bool) ($raw['kinds'][$kind] ?? true);
        }

        return ['enabled' => (bool) ($raw['enabled'] ?? true), 'kinds' => $kinds];
    }

    /** @return array{enabled: bool, channels: list<string>, templates: array<string, array<string, string|null>>} */
    public function watchSettings(): array
    {
        $raw = $this->watches ?? [];
        $channels = array_values(array_intersect(['whatsapp', 'email', 'sms'], (array) ($raw['channels'] ?? ['whatsapp', 'email', 'sms'])));
        $templates = [];
        foreach (ProductWatch::KINDS as $kind) {
            foreach (['email', 'whatsapp', 'sms'] as $channel) {
                $value = $raw['templates'][$kind][$channel] ?? null;
                $templates[$kind][$channel] = is_string($value) && $value !== '' ? $value : null;
            }
        }

        return ['enabled' => (bool) ($raw['enabled'] ?? true), 'channels' => $channels, 'templates' => $templates];
    }
}
