<?php

declare(strict_types=1);

namespace App\Domain\Consent;

use App\Domain\Consent\Console\HolidayOverrideCommand;
use Illuminate\Support\ServiceProvider;

/** Consentimiento y política de envío (cumplimiento.md §3 y §4). */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([HolidayOverrideCommand::class]);
        }
    }
}
