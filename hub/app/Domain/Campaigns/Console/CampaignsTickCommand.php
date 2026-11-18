<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Console;

use App\Domain\Campaigns\Actions\CampaignSender;
use App\Domain\Campaigns\Jobs\SendCampaignBatch;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Support\SendSchedule;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cada minuto (hub/CLAUDE.md §15): materializa las campañas programadas que ya
 * empiezan, encola los lotes vencidos, elige el ganador del A/B y cierra las
 * terminadas. Con --sync despacha los lotes aquí mismo (pruebas y recorridos).
 */
final class CampaignsTickCommand extends Command
{
    protected $signature = 'igr:campaigns:tick {--sync : Despacha los lotes aquí en lugar de encolarlos}';

    protected $description = 'Avanza las campañas programadas y en envío';

    public function handle(CampaignSender $sender, CurrentWorkspace $current): int
    {
        $now = CarbonImmutable::now()->utc();
        $rows = DB::table('campaigns')->whereIn('status', [Campaign::SCHEDULED, Campaign::SENDING])->get(['id', 'workspace_id']);
        foreach ($rows->groupBy('workspace_id') as $workspaceId => $campaigns) {
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace === null) {
                continue;
            }
            $current->run($workspace, function () use ($workspace, $campaigns, $sender, $now): void {
                foreach ($campaigns as $row) {
                    $campaign = Campaign::query()->find((string) $row->id);
                    if ($campaign === null) {
                        continue;
                    }
                    if ($campaign->status === Campaign::SCHEDULED) {
                        if (SendSchedule::materializeAt($campaign)->greaterThan($now)) {
                            continue;
                        }
                        $sender->materialize($workspace, $campaign, $now);
                        $this->line(__('Campaña :name: destinatarios listos', ['name' => $campaign->name]));
                    }
                    $sender->pickWinner($workspace, $campaign, $now);
                    $hasDue = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)
                        ->whereIn('status', ['pending', 'deferred'])->where('scheduled_for', '<=', $now)->exists();
                    if ($hasDue) {
                        $this->option('sync')
                            ? (new SendCampaignBatch($workspace->id, $campaign->id))->handle($sender, app(CurrentWorkspace::class))
                            : SendCampaignBatch::dispatch($workspace->id, $campaign->id);
                    }
                    $sender->finishIfDone($campaign->refresh(), $now);
                }
            });
        }

        return self::SUCCESS;
    }
}
