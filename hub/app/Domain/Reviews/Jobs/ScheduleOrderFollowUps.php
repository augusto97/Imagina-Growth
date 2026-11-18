<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Jobs;

use App\Domain\Commerce\Models\Order;
use App\Domain\Reviews\Actions\ScheduleReviewRequest;
use App\Domain\Surveys\Actions\ScheduleSurveyRequests;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Programa la solicitud de reseña y las encuestas NPS/CSAT de un pedido entregado o pagado (M15). */
final class ScheduleOrderFollowUps implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public string $workspaceIdValue, public string $siteId, public string $externalOrderId, public string $trigger) {}

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    public function handle(ScheduleReviewRequest $reviews, ScheduleSurveyRequests $surveys): void
    {
        $order = Order::query()->where('site_id', $this->siteId)->where('external_id', $this->externalOrderId)->first();
        $workspace = Workspace::query()->find($this->workspaceIdValue);
        if ($order === null || $workspace === null) {
            return;
        }
        $reviews->handle($workspace, $order, $this->trigger);
        $surveys->handle($workspace, $order, $this->trigger);
    }
}
