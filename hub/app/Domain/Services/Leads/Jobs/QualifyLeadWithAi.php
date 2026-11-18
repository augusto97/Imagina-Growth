<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Jobs;

use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Services\Leads\Support\LeadQualifier;
use App\Domain\Services\Leads\Support\LeadScorer;
use App\Domain\Services\Leads\Support\LeadWriter;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Califica con IA el mensaje del formulario (cola `ai`). El mensaje viaja cifrado
 * en el job y no se guarda: quedan los puntos (±20) y la razón en el lead.
 */
final class QualifyLeadWithAi implements ShouldBeEncrypted, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(public string $workspaceIdValue, public string $leadId, public string $message)
    {
        $this->onQueue('ai');
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

    public function handle(LeadQualifier $qualifier, LeadWriter $writer): void
    {
        $workspace = Workspace::query()->find($this->workspaceIdValue);
        $lead = Lead::query()->find($this->leadId);
        if ($workspace === null || $lead === null || ! $lead->isOpen()) {
            return;
        }
        $settings = LeadSettings::for($workspace->id);
        if (! $settings->ai_qualify) {
            return;
        }
        $max = (int) ($settings->scoringRules()['ai_max_points'] ?? 20);
        $result = $qualifier->qualify($workspace->id, $this->message, $lead->service, $max);
        if ($result === null) {
            return;
        }
        app(LeadScorer::class)->aiReason($lead, $settings, $result['points'], $result['reason']);
        if ($lead->service === null && $result['service'] !== null) {
            $lead->service = $result['service'];
        }
        LeadActivity::log($lead, 'score', 'ai', ['signal' => 'ai', 'points' => $result['points'], 'reason' => $result['reason']]);
        $writer->save($workspace, $lead, $settings, actor: 'ai');
    }
}
