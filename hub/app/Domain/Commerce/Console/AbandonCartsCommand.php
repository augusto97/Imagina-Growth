<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Console;

use App\Domain\Commerce\Actions\DetectAbandonedCarts;
use Illuminate\Console\Command;

final class AbandonCartsCommand extends Command
{
    protected $signature = 'igr:carts:abandon';

    protected $description = 'Marca los carritos abandonados y emite cart.abandoned (cada minuto)';

    public function handle(DetectAbandonedCarts $detect): int
    {
        $result = $detect->handle();
        if ($this->output->isVerbose()) {
            $this->info("Abandonados: {$result['abandoned']} · vencidos: {$result['expired']}");
        }

        return self::SUCCESS;
    }
}
