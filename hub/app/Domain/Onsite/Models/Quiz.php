<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Preguntas y resultados de un widget quiz (M12). `questions`:
 * `[{id, text, attribute?, options: [{key, label, next?, attributes?}]}]`; `next` es
 * el id de la siguiente pregunta o `"end"`. `outcomes`: `{rules: [{id, label, match:
 * {q1: ["a"]}, products: {product_ids, category_ids, brands, max_price}, attributes,
 * message}], fallback: {products: {kind, category_ids}, message}, limit}`.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $widget_id
 * @property list<array<string, mixed>> $questions
 * @property array<string, mixed> $outcomes
 */
class Quiz extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'qz';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['questions' => 'array', 'outcomes' => 'array'];
    }
}
