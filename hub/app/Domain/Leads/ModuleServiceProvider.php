<?php

declare(strict_types=1);

namespace App\Domain\Leads;

use App\Domain\Inbox\Events\InboundMessageReceived;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Leads\Actions\CaptureLead;
use App\Domain\Services\Leads\Console\DecayLeadScoresCommand;
use App\Domain\Services\Leads\Console\MorningDigestCommand;
use App\Domain\Services\Leads\Crm\CrmSync;
use App\Domain\Services\Leads\Crm\NullCrmSync;
use App\Domain\Services\Leads\Listeners\FirstResponseListener;
use App\Domain\Services\Leads\Listeners\LeadBriefListener;
use App\Domain\Services\Leads\Listeners\LeadSignalsListener;
use App\Domain\Services\Leads\Listeners\WhatsAppLeadListener;
use App\Ingest\Events\EventIngested;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Leads (modulos.md M18, Fase 5): reemplaza `NullLeadIntake` por `CaptureLead`
 * (se registra antes que el de Services, que usa bindIf), escucha formularios,
 * navegación, WhatsApp y respuestas de la bandeja, y programa el decaimiento
 * nocturno del puntaje. El código vive en app/Domain/Services/Leads.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LeadIntake::class, CaptureLead::class);
        $this->app->bindIf(CrmSync::class, NullCrmSync::class);
    }

    public function boot(): void
    {
        Event::listen(EventIngested::class, LeadSignalsListener::class);
        Event::listen(InboundMessageReceived::class, [WhatsAppLeadListener::class, 'inbound']);
        Event::listen(InboundMessageReceived::class, [LeadBriefListener::class, 'inbound']);
        Conversation::saved(fn (Conversation $c) => app(WhatsAppLeadListener::class)->conversationSaved($c));
        Message::created(fn (Message $m) => app(FirstResponseListener::class)->created($m));

        if ($this->app->runningInConsole()) {
            $this->commands([DecayLeadScoresCommand::class, MorningDigestCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:leads:decay')->dailyAt('03:40')->withoutOverlapping()->onOneServer();
            // Resumen matutino de «Hoy» (si está activado): cada hora, sale desde las 7:00 de la zona de cada negocio.
            $schedule->command('igr:leads:morning-digest')->hourlyAt(5)->withoutOverlapping()->onOneServer();
        });
    }
}
