<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Console;

use App\Domain\Measurement\AdSpend\ImportAdSpend;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/** Importación diaria del gasto en anuncios (modulos.md M06). Reimporta los últimos días porque las plataformas ajustan cifras. */
final class ImportAdSpendCommand extends Command
{
    protected $signature = 'igr:ad-spend:import {--days=3 : Días hacia atrás} {--workspace=* : ws_…}';

    protected $description = 'Importa el gasto diario de Meta, Google Ads y TikTok';

    public function handle(ImportAdSpend $import, CurrentWorkspace $current): int
    {
        $ids = (array) $this->option('workspace');
        $workspaces = $ids === []
            ? Workspace::query()->where('status', 'active')->get()
            : Workspace::query()->whereIn('id', array_map(fn ($id) => substr((string) $id, 3), $ids))->get();
        $to = CarbonImmutable::yesterday();
        $from = $to->subDays(max(1, (int) $this->option('days')) - 1);
        $failed = 0;

        foreach ($workspaces as $workspace) {
            $current->run($workspace, function () use ($import, $from, $to, $workspace, &$failed): void {
                foreach (AdDestination::query()->where('status', 'active')->get() as $destination) {
                    if (! $import->supports($destination)) {
                        continue;
                    }
                    try {
                        $rows = $import->handle($destination, $from, $to);
                        $this->line(sprintf('%s · %s: %d filas.', $workspace->name, $destination->platform->label(), $rows));
                    } catch (Throwable $e) {
                        $failed++;
                        $destination->forceFill(['last_error' => Pii::scrub(mb_substr($e->getMessage(), 0, 500))])->save();
                        $this->error(sprintf('%s · %s: %s', $workspace->name, $destination->platform->label(), Pii::scrub($e->getMessage())));
                    }
                }
            });
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
