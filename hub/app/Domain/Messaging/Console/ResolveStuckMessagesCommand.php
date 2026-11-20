<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Console;

use App\Domain\Messaging\Actions\ResolveStuckMessages;
use Illuminate\Console\Command;

/** Cada 5 minutos: resuelve los mensajes que se quedaron en `sending` (F9; ver ResolveStuckMessages). */
final class ResolveStuckMessagesCommand extends Command
{
    protected $signature = 'igr:messages:stuck {--limit=500}';

    protected $description = 'Reintenta o marca como fallidos los mensajes que se quedaron enviándose';

    public function handle(ResolveStuckMessages $resolve): int
    {
        $out = $resolve->handle(limit: max(1, (int) $this->option('limit')));
        $this->line("Reintentados: {$out['retried']} · fallidos: {$out['failed']}");

        return self::SUCCESS;
    }
}
