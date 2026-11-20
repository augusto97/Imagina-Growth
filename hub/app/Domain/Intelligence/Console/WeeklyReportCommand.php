<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Console;

use App\Domain\Reports\SendWeeklyReport;
use Illuminate\Console\Command;

final class WeeklyReportCommand extends Command
{
    use SelectsWorkspaces;

    protected $signature = 'igr:report:weekly {--workspace=} {--due : Solo a los que les toca (lunes desde las 07:00 local, una vez por semana)} {--force : Reenvía aunque ya se haya enviado}';

    protected $description = 'Envía el resumen semanal al dueño';

    public function handle(SendWeeklyReport $send): int
    {
        foreach ($this->workspaces() as $ws) {
            if ($this->option('due') && (! SendWeeklyReport::isDue($ws) || SendWeeklyReport::attempted($ws))) {
                continue;
            }
            $result = $send->handle($ws, null, (bool) $this->option('force'));
            $this->line(sprintf('%s: %s %s', $ws->name, $result['status'], implode(', ', $result['recipients'])));
        }

        return self::SUCCESS;
    }
}
