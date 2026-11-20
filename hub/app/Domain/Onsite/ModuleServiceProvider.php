<?php

declare(strict_types=1);

namespace App\Domain\Onsite;

use App\Domain\Consent\Models\LegalText;
use App\Domain\Onsite\Console\AbWinnersCommand;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Models\Quiz;
use App\Domain\Onsite\Support\WidgetsCache;
use App\Domain\Surveys\Models\SurveyDefinition;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

/** En el sitio (M12): widgets de captación, quiz y su A/B. */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // R11: guardar o borrar lo que arma widgets.json renueva su caché en ese espacio de trabajo.
        foreach ([OnsiteWidget::class, Quiz::class, SurveyDefinition::class, LegalText::class] as $model) {
            $model::saved(fn (Model $m) => WidgetsCache::bump((string) $m->getAttribute('workspace_id')));
            $model::deleted(fn (Model $m) => WidgetsCache::bump((string) $m->getAttribute('workspace_id')));
        }
        if ($this->app->runningInConsole()) {
            $this->commands([AbWinnersCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:onsite:ab-winners')->dailyAt('04:20')->withoutOverlapping()->onOneServer();
        });
    }
}
