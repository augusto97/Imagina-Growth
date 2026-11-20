<?php

declare(strict_types=1);

namespace App\Domain\Reports\Jobs;

use App\Domain\Reports\SendWeeklyReport;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * El informe semanal de un espacio de trabajo (R10): `igr:report:weekly --due` encola uno por espacio de trabajo y
 * los workers los arman en paralelo (antes iban en serie y uno lento o caído atrasaba o cortaba a los demás).
 *
 * Una sola vez por semana:
 * - el trabajo es único por espacio de trabajo y semana mientras espera o corre (`ShouldBeUnique`);
 * - un candado por espacio de trabajo y semana evita que coincida con un envío manual;
 * - SendWeeklyReport no reenvía lo ya enviado (`report_deliveries` de esa semana) salvo `force`.
 *
 * Si falla: hasta 3 intentos (a 1 y 5 minutos) y, si los agota, la corrida de la hora siguiente lo vuelve a encolar,
 * porque la semana sigue sin informe (`SendWeeklyReport::attempted`).
 */
final class SendWeeklyReportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** Si el candado de único quedara colgado (un worker muerto), se libera antes de la corrida de la hora siguiente. */
    public int $uniqueFor = 3300;

    public function __construct(public string $workspaceId, public string $weekStart, public bool $force = false) {}

    public function uniqueId(): string
    {
        return $this->workspaceId.':'.$this->weekStart;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(SendWeeklyReport $send): void
    {
        $ws = Workspace::query()->find($this->workspaceId);
        if ($ws === null) {
            return;
        }
        $week = CarbonImmutable::createFromFormat('!Y-m-d', $this->weekStart, $ws->timezone ?: 'UTC');
        if ($week === null) {
            return;
        }
        $lock = Cache::lock('ws:'.$ws->id.':weekly_report:'.$this->weekStart, $this->timeout + 30);
        if (! $lock->get()) {
            // Otro proceso lo está armando: se revisa en un rato (y ahí ya figurará como enviado).
            $this->release(120);

            return;
        }
        try {
            $send->handle($ws, $week, $this->force);
        } finally {
            $lock->release();
        }
    }
}
