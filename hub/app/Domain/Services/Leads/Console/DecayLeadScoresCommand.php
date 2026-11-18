<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Console;

use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Services\Leads\Support\LeadScorer;
use App\Domain\Services\Leads\Support\LeadWriter;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Decaimiento nocturno del puntaje de los leads abiertos (inteligencia.md §4.9):
 * −5 por semana completa sin actividad desde la última señal. Idempotente: correrlo
 * dos veces la misma noche no resta dos veces.
 */
final class DecayLeadScoresCommand extends Command
{
    protected $signature = 'igr:leads:decay {--at= : Momento a evaluar (ISO 8601), para pruebas} {--workspace= : Solo este espacio de trabajo}';

    protected $description = 'Recalcula el decaimiento semanal del puntaje de los leads abiertos (cada noche)';

    public function handle(CurrentWorkspace $current, LeadScorer $scorer, LeadWriter $writer): int
    {
        $at = $this->option('at');
        $now = is_string($at) && $at !== '' ? CarbonImmutable::parse($at) : CarbonImmutable::now();
        $ids = DB::table('leads')->whereNull('won_at')->whereNull('lost_at')
            ->when(is_string($this->option('workspace')) && $this->option('workspace') !== '', fn ($q) => $q->where('workspace_id', $this->option('workspace')))
            ->distinct()->pluck('workspace_id')->map(fn ($v) => (string) $v)->all();
        $changed = 0;
        foreach ($ids as $id) {
            $workspace = Workspace::query()->find($id);
            if ($workspace === null) {
                continue;
            }
            $changed += $current->run($workspace, function () use ($workspace, $scorer, $writer, $now): int {
                $settings = LeadSettings::for($workspace->id);
                $n = 0;
                Lead::query()->whereNull('won_at')->whereNull('lost_at')->orderBy('id')->chunkById(500, function ($leads) use ($workspace, $settings, $scorer, $writer, $now, &$n): void {
                    foreach ($leads as $lead) {
                        if ($scorer->decay($lead, $settings, $now)) {
                            $writer->save($workspace, $lead, $settings, alert: false);
                            $n++;
                        }
                    }
                });

                return $n;
            });
        }
        if ($this->output->isVerbose()) {
            $this->info(sprintf('Leads con decaimiento recalculado: %d', $changed));
        }

        return self::SUCCESS;
    }
}
