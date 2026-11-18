<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Console;

use App\Domain\Commerce\Actions\DetectAbandonedCarts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class AbandonCartsCommand extends Command
{
    protected $signature = 'igr:carts:abandon {--at= : Momento a evaluar (ISO 8601), para pruebas y para reprocesar}';

    protected $description = 'Marca los carritos abandonados y emite cart.abandoned (cada minuto)';

    public function handle(DetectAbandonedCarts $detect): int
    {
        $at = $this->option('at');
        $result = $detect->handle(is_string($at) && $at !== '' ? CarbonImmutable::parse($at) : null);
        if ($this->output->isVerbose()) {
            $this->info("Abandonados: {$result['abandoned']} · vencidos: {$result['expired']}");
        }

        return self::SUCCESS;
    }
}
