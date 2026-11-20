<?php

declare(strict_types=1);

namespace App\Domain\Bookings;

use App\Domain\Bookings\Chat\ChatBookingReply;
use App\Domain\Bookings\Console\BookingTickCommand;
use App\Domain\Bookings\Listeners\AppointmentReply;
use App\Domain\Inbox\Events\InboundMessageReceived;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Agenda de citas (M18): recordatorios y no asistencias cada 5 minutos, las
 * respuestas «Confirmo» / «Reprogramar» al recordatorio por WhatsApp y los toques a
 * los horarios que ofrece el agente en el chat (ChatBookingReply).
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(InboundMessageReceived::class, AppointmentReply::class);
        Event::listen(InboundMessageReceived::class, ChatBookingReply::class);

        if ($this->app->runningInConsole()) {
            $this->commands([BookingTickCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:bookings:tick')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
        });
    }
}
