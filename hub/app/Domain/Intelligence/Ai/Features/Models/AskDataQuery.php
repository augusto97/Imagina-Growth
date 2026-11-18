<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Una pregunta de «Pregúntale a tus datos» (inteligencia.md §6.3) con la consulta
 * estructurada que armó el modelo y lo que respondió.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $user_id
 * @property string $question
 * @property array<string, mixed>|null $query
 * @property string|null $answer
 * @property array<string, mixed>|null $result_meta
 * @property string $status
 * @property string|null $model
 * @property array<string, mixed>|null $validation
 * @property string|null $saved_as
 * @property string|null $saved_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class AskDataQuery extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'adq';

    protected $table = 'ask_data_queries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'query' => 'array',
            'result_meta' => 'array',
            'validation' => 'array',
            'question' => 'encrypted',
        ];
    }
}
