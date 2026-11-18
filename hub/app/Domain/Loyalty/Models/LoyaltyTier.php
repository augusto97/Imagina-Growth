<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Nivel VIP. `rank` más alto = mejor nivel. El contacto queda en el nivel más
 * alto cuyo umbral (gasto, puntos o pedidos en 12 meses) alcanza.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $program_id
 * @property string $name
 * @property int $rank
 * @property string $threshold_type
 * @property string $threshold
 * @property array<string, mixed>|null $benefits
 * @property string|null $wc_role
 */
class LoyaltyTier extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'tr';

    public const THRESHOLD_TYPES = ['spend_12m', 'points_12m', 'orders_12m'];

    protected $fillable = ['workspace_id', 'program_id', 'name', 'rank', 'threshold_type', 'threshold', 'benefits', 'wc_role'];

    protected function casts(): array
    {
        return ['benefits' => 'array', 'rank' => 'integer', 'threshold' => 'decimal:2'];
    }

    public function multiplier(): float
    {
        $m = (float) ($this->benefits['multiplier'] ?? 1);

        return $m >= 1 && $m <= 10 ? $m : 1.0;
    }
}
