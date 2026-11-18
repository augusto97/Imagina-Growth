<?php

declare(strict_types=1);

namespace App\Domain\Agency\Console;

use App\Domain\Agency\Jobs\SendMonthlyReportJob;
use App\Domain\Agency\Reports\MonthlyReports;
use App\Support\Console\WorkspaceOption;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Reporte mensual con marca (M22): el día 1 a las 08:00 de cada espacio de
 * trabajo (en su zona horaria) se genera el del mes anterior y se envía a los
 * dueños. Con --month=AAAA-MM se genera ese mes; con --force se regenera y reenvía.
 */
final class MonthlyReportsCommand extends Command
{
    protected $signature = 'igr:agency:monthly-reports {--workspace=*} {--due : Solo a los que les toca (día 1, 08:00 local)} {--month= : AAAA-MM} {--force} {--sync : Sin cola}';

    protected $description = 'Genera y envía el reporte mensual con marca';

    public static function isDue(string $timezone, ?CarbonImmutable $now = null): bool
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone($timezone !== '' ? $timezone : 'America/Bogota');

        return $local->day === 1 && $local->hour === 8;
    }

    public function handle(): int
    {
        $value = $this->option('workspace');
        $month = $this->option('month');
        foreach (WorkspaceOption::resolve(is_array($value) && $value !== [] ? $value : null) as $ws) {
            if ($ws->status !== 'active') {
                continue;
            }
            if ($this->option('due') && ! self::isDue((string) $ws->timezone)) {
                continue;
            }
            $period = is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1
                ? CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01', (string) ($ws->timezone ?: 'America/Bogota')) ?: MonthlyReports::lastMonth($ws)
                : MonthlyReports::lastMonth($ws);
            $job = new SendMonthlyReportJob($ws->id, $period->format('Y-m'), (bool) $this->option('force'));
            $this->option('sync') ? dispatch_sync($job) : dispatch($job);
            $this->line(sprintf('%s: %s', $ws->name, $period->format('Y-m')));
        }

        return self::SUCCESS;
    }
}
