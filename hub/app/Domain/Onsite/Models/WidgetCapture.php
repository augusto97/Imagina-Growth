<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Quién dejó sus datos en qué widget y variante (modelo-de-datos.md §13). Una fila
 * por widget y contacto: sirve para «un cupón por persona», las estadísticas y la
 * atribución de captación. `captured_at` es la primera captura; `updated_at`, el
 * último paso o reenvío.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $widget_id
 * @property string|null $variant
 * @property string $contact_id
 * @property string|null $anonymous_id
 * @property string|null $coupon_id
 * @property string|null $prize
 * @property array<string, string>|null $answers
 * @property bool $was_known
 * @property CarbonInterface $captured_at
 * @property CarbonInterface|null $updated_at
 */
class WidgetCapture extends Model
{
    use BelongsToWorkspace;

    public const CREATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['answers' => 'array', 'was_known' => 'boolean', 'captured_at' => 'datetime'];
    }
}
