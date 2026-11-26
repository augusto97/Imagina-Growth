<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Actions;

use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Support\WidgetValidator;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Illuminate\Validation\ValidationException;

/**
 * Activa, pausa o archiva un widget (modulos.md M12). Activar exige que esté completo (WidgetValidator); cada cambio
 * queda en la auditoría. Lo usan En el sitio y las estrategias.
 */
final class ChangeWidgetStatus
{
    private const ACTIONS = ['active' => 'onsite_widget.activated', 'paused' => 'onsite_widget.paused', 'archived' => 'onsite_widget.archived'];

    public function __construct(private readonly WidgetValidator $validator, private readonly RecordAuditLog $audit) {}

    /** @throws ValidationException si se activa un widget incompleto */
    public function handle(OnsiteWidget $widget, string $status): OnsiteWidget
    {
        if ($status === 'active') {
            $this->validator->assertActivatable($widget->loadMissing('quiz'));
        }
        $from = $widget->status;
        $widget->forceFill(['status' => $status])->save();
        $this->audit->handle(self::ACTIONS[$status] ?? 'onsite_widget.updated', $widget, ['from' => $from, 'to' => $status], $widget->workspace_id);

        return $widget;
    }
}
