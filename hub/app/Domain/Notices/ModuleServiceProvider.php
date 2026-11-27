<?php

declare(strict_types=1);

namespace App\Domain\Notices;

use App\Domain\Notices\Listeners\AppointmentTeamListener;
use App\Ingest\Events\EventIngested;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Avisos (lote 0.4.0, docs/avisos.md): el centro de avisos de la app, «Mis avisos», los avisos al equipo de cada cita
 * y el correo de la plataforma como respaldo de los transaccionales.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(EventIngested::class, AppointmentTeamListener::class);
    }
}
