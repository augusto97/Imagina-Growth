<?php

declare(strict_types=1);

namespace App\Domain\Surveys\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $kind
 * @property string $status
 * @property array{title?: string, question?: string, options?: list<array{key: string, label: string}>, allow_other?: bool, other_label?: string, thanks?: string} $questions
 * @property array<string, mixed>|null $trigger
 */
class SurveyDefinition extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'sv';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['questions' => 'array', 'trigger' => 'array'];
    }

    /** @return list<string> */
    public function optionKeys(): array
    {
        return array_values(array_map(fn ($o) => (string) $o['key'], $this->questions['options'] ?? []));
    }
}
