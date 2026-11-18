<?php

declare(strict_types=1);

namespace App\Domain\Surveys\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $survey_id
 * @property string|null $site_id
 * @property string|null $contact_id
 * @property string|null $order_id
 * @property string|null $external_order_id
 * @property string|null $answer
 * @property array<string, mixed> $answers
 * @property int|null $score
 * @property string|null $comment
 */
class SurveyResponse extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'svr';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['answers' => 'array', 'score' => 'integer', 'comment' => 'encrypted', 'created_at' => 'datetime'];
    }
}
