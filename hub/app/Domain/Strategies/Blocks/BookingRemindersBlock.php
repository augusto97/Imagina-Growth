<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Strategies\Support\Previews;
use App\Domain\Strategies\Support\Requirements;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Workspaces\Actions\RecordAuditLog;

/**
 * La agenda con recordatorios (M18, flujo 19: los envía la propia agenda, `igr:bookings:tick`, para que nadie reciba
 * dos recordatorios de la misma cita). Activar deja la página de reservas abierta y los recordatorios de 24 y 2 horas
 * (o los que tenía); pausar apaga solo los recordatorios y los recuerda para reanudar. Son mensajes de utilidad: los
 * pidió la persona al agendar y respetan la supresión.
 */
final class BookingRemindersBlock implements StrategyBlock
{
    private const DEFAULT_HOURS = [24, 2];

    public function kind(): string
    {
        return 'booking_reminders';
    }

    public function requirements(StrategyContext $ctx, array $options = []): array
    {
        return [];
    }

    public function state(StrategyContext $ctx): BlockState
    {
        $settings = BookingSettings::for($ctx->workspace);
        if ($settings['enabled'] && (array) $settings['reminder_hours'] !== [] && Requirements::bookingServices($ctx) > 0) {
            return BlockState::on('agenda?tab=pagina');
        }

        return BlockState::off('agenda?tab=pagina');
    }

    public function activate(StrategyContext $ctx, array $options, ?string $userId): array
    {
        $settings = BookingSettings::for($ctx->workspace);
        $previous = array_values(array_map('intval', (array) ($ctx->row('appointment_reminders')?->settings['reminder_hours'] ?? [])));
        $hours = (array) $settings['reminder_hours'] !== [] ? $settings['reminder_hours'] : ($previous !== [] ? $previous : self::DEFAULT_HOURS);
        if ($settings['enabled'] && $hours === $settings['reminder_hours']) {
            return [];
        }
        BookingSettings::save($ctx->workspace, ['enabled' => true, 'reminder_hours' => $hours]);
        app(RecordAuditLog::class)->handle('bookings.settings_updated', $ctx->workspace, ['enabled' => true, 'reminder_hours' => $hours], $ctx->id(), null, $userId);

        return [];
    }

    public function pause(StrategyContext $ctx, ?string $userId): array
    {
        $hours = (array) BookingSettings::for($ctx->workspace)['reminder_hours'];
        if ($hours === []) {
            return [];
        }
        BookingSettings::save($ctx->workspace, ['reminder_hours' => []]);
        app(RecordAuditLog::class)->handle('bookings.settings_updated', $ctx->workspace, ['reminder_hours' => []], $ctx->id(), null, $userId);

        return ['reminder_hours' => array_values($hours)];
    }

    public function preview(StrategyContext $ctx): array
    {
        $hours = (array) BookingSettings::for($ctx->workspace)['reminder_hours'];

        return Previews::summary([
            __('strategies.preview.booking_reminders', ['hours' => implode(' y ', $hours !== [] ? $hours : self::DEFAULT_HOURS)]),
            __('strategies.preview.booking_buttons'),
        ]);
    }
}
