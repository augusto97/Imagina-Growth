<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\Period;
use App\Domain\Workspaces\Models\Workspace;

/** 9. Mensajería: llega con los canales, flujos y campañas de la Fase 2. */
final class MessagingDashboard extends Dashboard
{
    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        return ['available' => false, 'phase' => 2];
    }
}
