<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Console;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/** Genera el par de llaves VAPID de las notificaciones push de la bandeja. Lo mismo hace «Generar llaves» en Plataforma → Ajustes (F17). */
final class VapidKeysCommand extends Command
{
    protected $signature = 'igr:push:vapid';

    protected $description = 'Genera las llaves VAPID para las notificaciones push de la bandeja';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->line('IGR_PUSH_VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('IGR_PUSH_VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->comment(__('Pégalas en Plataforma → Ajustes → Avisos en el celular (o usa «Generar llaves» ahí mismo; la privada nunca se comparte). Cambiarlas obliga a suscribir de nuevo los navegadores.'));

        return self::SUCCESS;
    }
}
