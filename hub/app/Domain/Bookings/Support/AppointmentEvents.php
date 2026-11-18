<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Support;

use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use App\Support\Ids\PrefixedId;
use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;

/** Eventos del hub de la agenda (eventos.md v5): `appointment.*`, sin datos personales. */
final class AppointmentEvents
{
    public static function booked(Workspace $workspace, Appointment $a, BookingService $service): void
    {
        app(HubEvents::class)->emit($workspace, 'appointment.booked', [
            'appointment_id' => $a->publicId(), 'service_id' => $service->publicId(), 'service_name' => $service->name,
            'staff_id' => $a->staff_id !== null ? PrefixedId::encode('sf', $a->staff_id) : null,
            'starts_at' => CarbonImmutable::instance($a->starts_at)->utc()->toIso8601ZuluString(),
            'duration_min' => $service->duration_min, 'location' => $service->location, 'channel' => $a->channel,
            'lead_id' => $a->lead_id !== null ? PrefixedId::encode('ld', $a->lead_id) : null,
        ], $a->contact_id, BookingSettings::site($workspace), eventId: self::id($a, 'booked'));
    }

    public static function rescheduled(Workspace $workspace, Appointment $a, CarbonImmutable $from, string $by): void
    {
        app(HubEvents::class)->emit($workspace, 'appointment.rescheduled', [
            'appointment_id' => $a->publicId(), 'from_starts_at' => $from->utc()->toIso8601ZuluString(),
            'starts_at' => CarbonImmutable::instance($a->starts_at)->utc()->toIso8601ZuluString(), 'by' => $by,
        ], $a->contact_id, BookingSettings::site($workspace));
    }

    public static function cancelled(Workspace $workspace, Appointment $a, string $by, ?string $reason): void
    {
        app(HubEvents::class)->emit($workspace, 'appointment.cancelled', ['appointment_id' => $a->publicId(), 'by' => $by, 'reason' => $reason],
            $a->contact_id, BookingSettings::site($workspace), eventId: self::id($a, 'cancelled'));
    }

    public static function status(Workspace $workspace, Appointment $a, string $status): void
    {
        $event = $status === 'no_show' ? 'appointment.no_show' : 'appointment.completed';
        app(HubEvents::class)->emit($workspace, $event, ['appointment_id' => $a->publicId(), 'service_id' => PrefixedId::encode('bs', $a->service_id)],
            $a->contact_id, BookingSettings::site($workspace), eventId: self::id($a, $status));
    }

    /** UUID determinista: el mismo cambio nunca se emite dos veces. */
    private static function id(Appointment $a, string $what): string
    {
        return Uuid::uuid5('6f1b3c2a-4d5e-5f60-8a9b-0c1d2e3f4a5b', 'appointment:'.$a->id.':'.$what)->toString();
    }
}
