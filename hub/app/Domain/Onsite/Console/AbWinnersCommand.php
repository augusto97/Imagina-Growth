<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Console;

use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Queries\WidgetStats;
use App\Domain\Onsite\Support\WidgetSchema;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * A/B con ganador automático (M12): en los widgets activos con `auto_winner`, si una
 * variante supera a la segunda en tasa de envío con 95 % de confianza y al menos
 * 100 vistas por variante (últimos 30 días), se queda con todo el tráfico (las demás
 * pasan a peso 0) y queda registrado en el widget y en la auditoría.
 */
final class AbWinnersCommand extends Command
{
    protected $signature = 'igr:onsite:ab-winners';

    protected $description = 'Elige la variante ganadora de los widgets con A/B automático';

    public function handle(CurrentWorkspace $current, WidgetStats $stats, RecordAuditLog $audit): int
    {
        $widgets = OnsiteWidget::withoutWorkspaceScope()->where('status', 'active')->get()
            ->filter(fn (OnsiteWidget $w) => (bool) ($w->stats['auto_winner'] ?? false) && ! isset($w->stats['winner']) && count(WidgetSchema::variants($w)) > 1);
        $decided = 0;
        foreach ($widgets as $widget) {
            $workspace = Workspace::query()->find($widget->workspace_id);
            if ($workspace === null) {
                continue;
            }
            $decided += (int) $current->run($workspace, function () use ($workspace, $widget, $stats, $audit): bool {
                $now = CarbonImmutable::now();
                $ab = $stats->detail($workspace, $widget, $now->subDays(30), $now)['ab'];
                if (! is_array($ab) || ! $ab['significant'] || $ab['leader'] === null) {
                    return false;
                }
                $variants = array_map(fn (array $v) => ['weight' => $v['key'] === $ab['leader'] ? 100 : 0] + $v, WidgetSchema::variants($widget));
                $widget->forceFill([
                    'variants' => $variants,
                    'stats' => array_merge((array) ($widget->stats ?? []), ['winner' => [
                        'key' => $ab['leader'], 'confidence' => $ab['confidence'], 'lift' => $ab['lift'], 'decided_at' => $now->toIso8601String(),
                    ]]),
                ])->save();
                $audit->handle('onsite_widget.ab_winner', $widget, ['winner' => $ab['leader'], 'confidence' => $ab['confidence']], $workspace->id, null, null);

                return true;
            });
        }
        $this->line("Ganadoras elegidas: {$decided}");

        return self::SUCCESS;
    }
}
