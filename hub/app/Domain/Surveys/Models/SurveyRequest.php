<?php

declare(strict_types=1);

namespace App\Domain\Surveys\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Envío de una encuesta NPS o CSAT a un contacto, por pedido (M15). La página
 * /r/nps/{token} va firmada por este envío.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $survey_id
 * @property string|null $site_id
 * @property string $contact_id
 * @property string|null $order_id
 * @property string|null $channel
 * @property string $status
 * @property string|null $skip_reason
 * @property int $attempts
 * @property string|null $message_id
 * @property CarbonInterface|null $scheduled_for
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $answered_at
 * @property CarbonInterface|null $expires_at
 */
class SurveyRequest extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'sq';

    protected $guarded = [];

    protected $attributes = ['status' => 'scheduled', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'answered_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
