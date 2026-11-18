<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights;

use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/** Un detector de hallazgos (inteligencia.md §5.1). Corre con el espacio de trabajo activo. */
interface Detector
{
    /** Clave estable, p. ej. «conversion_drop». */
    public function key(): string;

    /** @return list<InsightCandidate> */
    public function detect(Workspace $workspace, CarbonImmutable $now): array;
}
