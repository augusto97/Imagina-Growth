<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Jobs;

use App\Domain\Campaigns\Actions\CampaignSender;
use App\Domain\Campaigns\Models\Campaign;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Un lote de una campaña (CampaignSender::sendDue). Si quedan más con la hora
 * vencida, se vuelve a encolar. Un candado por campaña evita dos lotes a la vez;
 * aun así la clave de idempotencia del despachador impide reenvíos.
 */
final class SendCampaignBatch implements ShouldBeUniqueUntilProcessing, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public string $workspaceIdValue, public string $campaignId) {}

    public function uniqueId(): string
    {
        return $this->campaignId;
    }

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    public function handle(CampaignSender $sender, CurrentWorkspace $current): void
    {
        $lock = Cache::lock('ws:'.$this->workspaceIdValue.':campaign:'.$this->campaignId.':batch', 300);
        if (! $lock->get()) {
            return;
        }
        try {
            $campaign = Campaign::query()->find($this->campaignId);
            if ($campaign === null) {
                return;
            }
            $processed = $sender->sendDue($current->get(), $campaign);
            $sender->finishIfDone($campaign->refresh());
        } finally {
            $lock->release();
        }
        if ($processed >= CampaignSender::BATCH) {
            self::dispatch($this->workspaceIdValue, $this->campaignId);
        }
    }
}
