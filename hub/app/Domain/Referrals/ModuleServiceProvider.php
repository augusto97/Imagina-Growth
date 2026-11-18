<?php

declare(strict_types=1);

namespace App\Domain\Referrals;

use App\Domain\Loyalty\Contracts\MeSection;
use App\Domain\Referrals\Actions\FriendRewards;
use App\Domain\Referrals\Console\QualifyReferralsCommand;
use App\Domain\Referrals\Contracts\ReferralRewards;
use App\Domain\Referrals\Listeners\ReferralOrderListener;
use App\Domain\Referrals\Support\ReferralMeSection;
use App\Ingest\Events\EventIngested;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Referidos, afiliados e influencers (M14): el regalo del amigo para la captación,
 * el seguimiento de los pedidos (huellas antifraude y cambios de estado) y la
 * calificación cada hora. La atribución es el paso 3 de AttributeOrder.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReferralRewards::class, FriendRewards::class);
        // Sección `referral` de «Mis recompensas» (GET /p/{key}/me, controlador de M13).
        $this->app->tag([ReferralMeSection::class], MeSection::TAG);
    }

    public function boot(): void
    {
        Event::listen(EventIngested::class, ReferralOrderListener::class);

        if ($this->app->runningInConsole()) {
            $this->commands([QualifyReferralsCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:referrals:qualify')->hourlyAt(17)->withoutOverlapping()->onOneServer();
        });
    }
}
