<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Chat;

use App\Support\Ids\PrefixedId;
use Carbon\CarbonImmutable;

/**
 * IDs de las opciones que el agente manda por WhatsApp para agendar (M18, auditoría
 * §5.1 función 4). Vuelven en el webhook como `button_payload` cuando el cliente toca
 * una opción, y `ChatBookingReply` hace lo que dicen sin pasar por el modelo:
 *
 * - `bk1:b:{servicio}:{inicio}`: agendar ese servicio a esa hora (con quien esté libre).
 * - `bk1:r:{cita}:{inicio}`: mover esa cita a esa hora.
 * - `bk1:c:{cita}` y `bk1:k:{cita}`: confirmar que cancela o que la mantiene.
 *
 * El inicio va en segundos UTC. Nada de esto se confía: el servicio y la cita se buscan
 * en el espacio de trabajo del número que recibió, la cita debe ser del mismo contacto y
 * el horario se vuelve a revisar con las reglas de la agenda.
 */
final class ChatBookingIds
{
    public const PREFIX = 'bk1:';

    public static function book(string $serviceId, CarbonImmutable $start): string
    {
        return self::PREFIX.'b:'.$serviceId.':'.$start->getTimestamp();
    }

    public static function reschedule(string $appointmentId, CarbonImmutable $start): string
    {
        return self::PREFIX.'r:'.$appointmentId.':'.$start->getTimestamp();
    }

    public static function cancel(string $appointmentId): string
    {
        return self::PREFIX.'c:'.$appointmentId;
    }

    public static function keep(string $appointmentId): string
    {
        return self::PREFIX.'k:'.$appointmentId;
    }

    public static function is(?string $payload): bool
    {
        return is_string($payload) && str_starts_with($payload, self::PREFIX);
    }

    /** @return array{action: 'book'|'reschedule'|'cancel'|'keep', id: string, start: CarbonImmutable|null}|null */
    public static function parse(?string $payload): ?array
    {
        if (! self::is($payload) || preg_match('/^bk1:([brck]):([0-9A-Z]{26})(?::(\d{9,11}))?$/', (string) $payload, $m) !== 1) {
            return null;
        }
        $action = match ($m[1]) {
            'b' => 'book', 'r' => 'reschedule', 'c' => 'cancel', default => 'keep',
        };
        $start = isset($m[3]) ? CarbonImmutable::createFromTimestampUTC((int) $m[3]) : null;
        if (in_array($action, ['book', 'reschedule'], true) !== ($start !== null) || ! PrefixedId::isUlid($m[2])) {
            return null;
        }

        return ['action' => $action, 'id' => $m[2], 'start' => $start];
    }
}
