<?php

declare(strict_types=1);

namespace App\Domain\Segments\Console;

use App\Domain\Segments\Actions\RecomputeSegment;
use App\Domain\Segments\Jobs\RecomputeSegmentJob;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Console\WorkspaceOption;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;

final class RecomputeSegmentsCommand extends Command
{
    protected $signature = 'igr:segments:recompute
        {--workspace=* : ws_… (por defecto, con --all, todos)}
        {--all : Todos los espacios de trabajo activos}
        {--segment= : sg_… un solo segmento}
        {--sync : Calcular aquí en lugar de encolar}';

    protected $description = 'Recalcula la membresía de los segmentos (cada noche, escalonado por espacio de trabajo)';

    public function handle(CurrentWorkspace $current, RecomputeSegment $recompute): int
    {
        $workspaces = $this->option('all')
            ? Workspace::query()->where('status', 'active')->get()
            : WorkspaceOption::resolve((array) $this->option('workspace'), allWhenEmpty: false);
        if ($workspaces->isEmpty()) {
            $this->error('Indica --workspace=ws_… o --all.');

            return self::FAILURE;
        }

        $total = 0;
        foreach ($workspaces as $workspace) {
            // Escalonado: hasta una hora repartida por espacio de trabajo para no cargar todo a la vez.
            $delay = $this->option('all') ? ($workspace->ch_id % 60) * 60 : 0;
            $current->run($workspace, function () use ($workspace, $recompute, $delay, &$total): void {
                $segments = Segment::query()->where('kind', Segment::KIND_DYNAMIC)->whereNotNull('definition')
                    ->when($this->option('segment'), fn ($q) => $q->whereKey(PrefixedId::decode('sg', (string) $this->option('segment'))))
                    ->get();
                foreach ($segments as $segment) {
                    if ($this->option('sync')) {
                        $r = $recompute->handle($segment);
                        $this->line(sprintf('%s · %s: %d miembros (+%d −%d)', $workspace->name, $segment->name, $r['members'], $r['entered'], $r['exited']));
                    } else {
                        RecomputeSegmentJob::dispatch($workspace->id, $segment->id)->delay(now()->addSeconds($delay));
                    }
                    $total++;
                }
            });
        }
        $this->info(($this->option('sync') ? 'Calculados: ' : 'Encolados: ').$total);

        return self::SUCCESS;
    }
}
