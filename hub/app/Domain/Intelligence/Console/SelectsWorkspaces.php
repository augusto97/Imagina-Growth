<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Console;

use App\Analytics\Builders\KpiDailyBuilder;
use App\Analytics\Builders\SessionsBuilder;
use App\Domain\Intelligence\Insights\InsightEngine;
use App\Domain\Intelligence\Jobs\RunNightlyIntelligence;
use App\Domain\Intelligence\Rfm\RfmCalculator;
use App\Domain\Reports\SendWeeklyReport;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

trait SelectsWorkspaces
{
    /** @return Collection<int, Workspace> */
    protected function workspaces(): Collection
    {
        $id = $this->option('workspace');
        if (is_string($id) && $id !== '') {
            $ws = Workspace::findByPublicId($id);

            return new Collection($ws === null ? [] : [$ws]);
        }

        return Workspace::query()->where('status', 'active')->orderBy('ch_id')->get();
    }
}
