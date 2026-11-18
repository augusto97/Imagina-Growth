<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Console;

use App\Domain\Webhooks\Models\WebhookDelivery;
use Illuminate\Console\Command;

/** Borra el registro de entregas de más de 30 días (el cuerpo cifrado puede traer datos personales). */
final class PruneWebhookDeliveriesCommand extends Command
{
    protected $signature = 'igr:webhooks:prune {--days=30}';

    protected $description = 'Borra entregas de webhooks viejas';

    public function handle(): int
    {
        $before = now()->subDays(max(1, (int) $this->option('days')));
        $total = 0;
        do {
            $deleted = WebhookDelivery::withoutWorkspaceScope()->where('created_at', '<', $before)->limit(5000)->delete();
            $total += $deleted;
        } while ($deleted > 0);
        $this->info("Entregas borradas: {$total}");

        return self::SUCCESS;
    }
}
