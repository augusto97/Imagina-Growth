<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Console;

use App\Domain\Webhooks\Jobs\DeliverWebhook;
use App\Domain\Webhooks\Models\WebhookDelivery;
use Illuminate\Console\Command;

/** Encola los reintentos de webhooks que ya tocan (cada minuto). */
final class RetryWebhooksCommand extends Command
{
    protected $signature = 'igr:webhooks:retry {--limit=500}';

    protected $description = 'Encola los reintentos de webhooks programados que ya vencieron';

    public function handle(): int
    {
        $due = WebhookDelivery::withoutWorkspaceScope()
            ->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', now())
            ->whereNull('status_code')->whereNull('error')
            ->orderBy('next_attempt_at')->limit(max(1, (int) $this->option('limit')))->pluck('id');
        $queued = 0;
        foreach ($due as $id) {
            // Se reclama la fila antes de encolar: dos servidores no la envían dos veces.
            $claimed = WebhookDelivery::withoutWorkspaceScope()->whereKey($id)->whereNotNull('next_attempt_at')->update(['next_attempt_at' => null]);
            if ($claimed === 1) {
                DeliverWebhook::dispatch((int) $id);
                $queued++;
            }
        }
        $this->info("Reintentos encolados: {$queued}");

        return self::SUCCESS;
    }
}
