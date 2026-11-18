<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Console;

use App\Domain\Messaging\Jobs\SendMessageJob;
use App\Domain\Messaging\Models\Message;
use Illuminate\Console\Command;

/**
 * Cada minuto: los mensajes que el job de envío aplazó (ventana legal cerrada,
 * tope de la cuenta) vuelven a la cola cuando llega su hora. Así no quedan
 * jobs con demoras de horas en Redis.
 */
final class RequeueDeferredCommand extends Command
{
    protected $signature = 'igr:messages:requeue {--limit=2000}';

    protected $description = 'Vuelve a encolar los mensajes aplazados cuya hora ya llegó';

    public function handle(): int
    {
        $count = 0;
        Message::withoutWorkspaceScope()->where('status', 'deferred')->where('deferred_until', '<=', now())
            ->orderBy('deferred_until')->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'workspace_id', 'channel'])
            ->each(function (Message $message) use (&$count): void {
                if (Message::withoutWorkspaceScope()->whereKey($message->id)->where('status', 'deferred')->update(['status' => 'queued', 'updated_at' => now()]) === 1) {
                    SendMessageJob::dispatch($message->workspace_id, $message->id)->onQueue('messages:'.$message->channel->value);
                    $count++;
                }
            });
        $this->line("Mensajes reencolados: {$count}");

        return self::SUCCESS;
    }
}
