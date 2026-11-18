<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Banner personalizado por segmento (modulos.md M16). Se resuelve por visitante
 * en `GET /p/{key}/banner` (api-connector.md §8.3). `text` puede usar
 * {{nombre}} y {{puntos}}, que solo se llenan con sesión iniciada (`igrUser.token`);
 * sin sesión se muestra `text_public`, que no lleva datos personales.
 *
 * Audiencias: `segment` (un segmento), `points` (tiene puntos por usar),
 * `pending_cart` (carrito sin pagar) y `at_risk` (RFM en riesgo o «no podemos perderlos»).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $site_id
 * @property string $name
 * @property string $placement
 * @property string $audience
 * @property string|null $segment_id
 * @property int|null $min_points
 * @property string $text
 * @property string|null $text_public
 * @property string|null $cta
 * @property string|null $url
 * @property string $style
 * @property int $priority
 * @property string $status
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property string|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class OnsiteBanner extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'bn';

    public const AUDIENCES = ['segment', 'points', 'pending_cart', 'at_risk'];

    public const PLACEMENTS = ['top', 'account'];

    public const STYLES = ['brand', 'info', 'success', 'warning'];

    protected $fillable = ['workspace_id', 'site_id', 'name', 'placement', 'audience', 'segment_id', 'min_points', 'text', 'text_public', 'cta', 'url',
        'style', 'priority', 'status', 'starts_at', 'ends_at', 'created_by'];

    protected $attributes = ['placement' => 'top', 'style' => 'brand', 'priority' => 100, 'status' => 'active'];

    protected function casts(): array
    {
        return [
            'min_points' => 'integer',
            'priority' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function isLive(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return $this->status === 'active'
            && ($this->starts_at === null || $this->starts_at->lte($now))
            && ($this->ends_at === null || $this->ends_at->gt($now));
    }
}
