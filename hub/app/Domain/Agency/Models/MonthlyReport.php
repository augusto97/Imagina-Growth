<?php

declare(strict_types=1);

namespace App\Domain\Agency\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Reporte mensual con marca (M22): hechos del mes, resumen (IA con cifras
 * validadas o plantilla), PDF y envío.
 *
 * @property string $id
 * @property string $workspace_id
 * @property Carbon $period
 * @property array<string, mixed> $facts
 * @property string|null $summary
 * @property string|null $summary_source
 * @property string|null $pdf_path
 * @property Carbon|null $sent_at
 * @property array{count?: int, masked?: list<string>}|null $recipients sin correos en claro: cuántos y enmascarados
 */
class MonthlyReport extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'mrp';

    protected $fillable = ['workspace_id', 'period', 'facts', 'summary', 'summary_source', 'pdf_path', 'sent_at', 'recipients'];

    protected function casts(): array
    {
        return ['period' => 'date', 'facts' => 'array', 'sent_at' => 'datetime', 'recipients' => 'array'];
    }
}
