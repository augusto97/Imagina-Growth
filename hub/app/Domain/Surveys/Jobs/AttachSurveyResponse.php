<?php

declare(strict_types=1);

namespace App\Domain\Surveys\Jobs;

use App\Domain\Commerce\Models\Order;
use App\Domain\Surveys\Actions\RecordSurveyAnswer;
use App\Domain\Surveys\Models\SurveyResponse;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Vincula una respuesta de encuesta con su pedido cuando el pedido llega al hub. */
final class AttachSurveyResponse implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 12;

    public function __construct(public string $workspaceIdValue, public string $responseId) {}

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [120, 300, 600, 1800, 3600];
    }

    public function handle(): void
    {
        $response = SurveyResponse::query()->find($this->responseId);
        if ($response === null || $response->order_id !== null) {
            return;
        }
        $order = Order::query()->where('site_id', $response->site_id)->where('external_id', $response->external_order_id)->first(['id', 'contact_id']);
        if ($order === null) {
            $this->release($this->backoff()[min($this->attempts() - 1, 4)]);

            return;
        }
        $response->forceFill(['order_id' => $order->id, 'contact_id' => $response->contact_id ?? $order->contact_id])->save();
        if (is_string($response->contact_id)) {
            RecordSurveyAnswer::fillContact($response->contact_id, (string) $response->answer);
        }
    }
}
