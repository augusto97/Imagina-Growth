<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Gasto diario importado de las plataformas (modelo-de-datos.md §7).
 *
 * @property int $id
 * @property string $platform
 * @property string $spend
 * @property string $currency
 */
class AdSpendDaily extends Model
{
    use BelongsToWorkspace;

    protected $table = 'ad_spend_daily';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
