<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Jobs;

use App\Domain\Webhooks\Actions\SendWebhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Envía un intento de webhook. Los reintentos los programa SendWebhook, no la cola. */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(SendWebhook $send): void
    {
        $send->handle($this->deliveryId);
    }
}
