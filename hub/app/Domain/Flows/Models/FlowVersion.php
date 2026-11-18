<?php

declare(strict_types=1);

namespace App\Domain\Flows\Models;

use App\Domain\Flows\Graph\Graph;
use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Versión del grafo de un flujo. Sin `published_at` es el borrador en edición;
 * al publicarse queda inmutable y los runs que entraron con ella siguen en ella.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $flow_id
 * @property int $version
 * @property array{nodes?: list<array<string, mixed>>, edges?: list<array<string, mixed>>} $graph
 * @property CarbonInterface|null $published_at
 * @property string|null $published_by
 */
class FlowVersion extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'flv';

    protected $fillable = ['workspace_id', 'flow_id', 'version', 'graph', 'published_at', 'published_by'];

    protected function casts(): array
    {
        return ['graph' => 'array', 'version' => 'integer', 'published_at' => 'datetime'];
    }

    public function parsed(): Graph
    {
        return Graph::fromArray((array) $this->graph);
    }
}
