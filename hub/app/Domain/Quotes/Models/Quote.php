<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una cotización pedida (M18): las respuestas, el rango estimado y quién la pidió.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $calculator_id
 * @property string|null $contact_id
 * @property string|null $lead_id
 * @property array<string, mixed> $answers
 * @property string|null $estimate_min
 * @property string|null $estimate_max
 * @property string $currency
 * @property string|null $anonymous_id
 * @property Carbon $created_at
 */
class Quote extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'qt';

    protected $fillable = ['workspace_id', 'calculator_id', 'contact_id', 'lead_id', 'answers', 'estimate_min', 'estimate_max', 'currency', 'anonymous_id'];

    protected $hidden = ['anonymous_id'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'estimate_min' => 'decimal:2', 'estimate_max' => 'decimal:2'];
    }
}
