<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Usage\ComputeUsage;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Console\Command;
use Throwable;

/** Cada 5 minutos: uso del mes por espacio de trabajo, avisos al 80/100 % y estado de cobro. */
final class BillingUsageCommand extends Command
{
    protected $signature = 'igr:billing:usage {--workspace= : ID público o interno de un espacio} {--period= : AAAA-MM (por defecto el mes en curso)}';

    protected $description = 'Calcula los contadores de uso de los planes (M21)';

    public function handle(ComputeUsage $usage): int
    {
        $option = $this->option('workspace');
        $query = Workspace::query()->orderBy('id');
        if (is_string($option) && $option !== '') {
            $found = Workspace::findByPublicId($option) ?? Workspace::query()->find($option);
            if ($found === null) {
                $this->error(__('No existe ese espacio de trabajo.'));

                return self::FAILURE;
            }
            $query->whereKey($found->id);
        }
        $period = $this->option('period');
        $period = is_string($period) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1 ? $period : null;
        $done = 0;
        foreach ($query->cursor() as $workspace) {
            try {
                $usage->handle($workspace, $period);
                $done++;
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->info(__('Uso calculado para :count espacios de trabajo.', ['count' => $done]));

        return self::SUCCESS;
    }
}
