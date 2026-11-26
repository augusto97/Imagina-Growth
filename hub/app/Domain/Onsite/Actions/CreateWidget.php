<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Actions;

use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Models\Quiz;
use App\Domain\Onsite\Support\WidgetSchema;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * Crea un widget en borrador con los valores por defecto de su tipo (WidgetSchema::defaults) y su quiz de ejemplo
 * (modulos.md M12). Lo usan «Nuevo widget» de En el sitio, la galería de plantillas (WidgetTemplates) y las
 * estrategias, que ajustan los textos, el cupón, el disparador, el diseño o el quiz con `overrides`. Queda en la
 * auditoría.
 */
final class CreateWidget
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /**
     * @param  array{content?: array<string, mixed>, consent_config?: array<string, mixed>, coupon_config?: array<string, mixed>, targeting?: array<string, mixed>, design?: array<string, mixed>, quiz?: array<string, mixed>}  $overrides
     */
    public function handle(string $name, string $kind, ?string $siteId, ?string $userId, array $overrides = []): OnsiteWidget
    {
        $defaults = WidgetSchema::defaults($kind);
        $content = array_replace($defaults['content'], $overrides['content'] ?? []);
        $consent = array_replace($defaults['consent_config'], $overrides['consent_config'] ?? []);
        $coupon = array_replace($defaults['coupon_config'], $overrides['coupon_config'] ?? []);
        $targeting = array_replace($defaults['targeting'], $overrides['targeting'] ?? []);
        $design = array_replace($defaults['design'], $overrides['design'] ?? []);
        $quiz = is_array($defaults['quiz']) ? array_replace($defaults['quiz'], $overrides['quiz'] ?? []) : null;
        $widget = DB::transaction(function () use ($name, $kind, $siteId, $userId, $targeting, $design, $quiz, $content, $consent, $coupon): OnsiteWidget {
            $widget = OnsiteWidget::query()->create([
                'workspace_id' => $this->current->id(),
                'site_id' => $siteId,
                'name' => trim($name),
                'kind' => $kind,
                'targeting' => $targeting,
                'design' => $design,
                'content' => $content,
                'variants' => [['key' => 'a', 'weight' => 100, 'design' => $design, 'content' => $content]],
                'consent_config' => $consent,
                'coupon_config' => $coupon,
                'created_by' => $userId,
            ]);
            if (is_array($quiz)) {
                Quiz::query()->create(['widget_id' => $widget->id] + $quiz);
            }

            return $widget;
        });
        $this->audit->handle('onsite_widget.created', $widget, ['name' => $widget->name, 'kind' => $widget->kind], $this->current->id());

        return $widget;
    }
}
