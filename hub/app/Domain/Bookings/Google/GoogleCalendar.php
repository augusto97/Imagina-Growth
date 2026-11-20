<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Google;

use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Models\BookingStaff;
use App\Domain\Connections\Actions\DisconnectConnection;
use App\Domain\Connections\Actions\StartAuthorization;
use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Providers\GoogleProvider;
use App\Domain\Connections\Support\ConnectionTokens;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Google Calendar por persona (M18) con OAuth 2.0. Las citas se crean, cambian y
 * borran en el calendario de la persona, y lo ocupado (freeBusy) bloquea horarios.
 *
 * - **Con la app de Google de la plataforma** (IGR_GOOGLE_CLIENT_ID, Conexiones con un
 *   clic): cada persona autoriza con el flujo común (state de un solo uso y PKCE) y
 *   `booking_staff.calendar` guarda solo `connection_id`; el token lo renueva ConnectionTokens.
 * - **Las conexiones de antes** (IGR_GOOGLE_CALENDAR_CLIENT_ID): el token sigue cifrado
 *   en `booking_staff.calendar` y se renueva con ese cliente, sin volver a conectar.
 *
 * Sin ninguna de las dos apps configuradas, todo esto queda apagado.
 */
final class GoogleCalendar
{
    public static function configured(): bool
    {
        return self::legacyConfigured() || (new GoogleProvider)->missing() === [];
    }

    /** El cliente propio de la agenda de antes (solo para las conexiones que ya existían o sin la app común). */
    public static function legacyConfigured(): bool
    {
        return (string) config('igr-bookings.google.client_id') !== '' && (string) config('igr-bookings.google.client_secret') !== '';
    }

    /** URL para que la persona autorice su calendario: con el flujo común si la app de la plataforma está, si no, el de antes. */
    public function connectUrl(BookingStaff $staff, string $userId): string
    {
        if ((new GoogleProvider)->missing() === []) {
            return app(StartAuthorization::class)->handle($staff->workspace_id, $userId, ConnectionProvider::Google, 'calendar', 'staff:'.$staff->id, 'agenda');
        }

        return $this->authUrl($staff);
    }

    public function authUrl(BookingStaff $staff): string
    {
        $state = Crypt::encryptString((string) json_encode(['ws' => $staff->workspace_id, 'staff' => $staff->id, 'at' => now()->getTimestamp()]));

        return (string) config('igr-bookings.google.auth_url').'?'.http_build_query([
            'client_id' => config('igr-bookings.google.client_id'),
            'redirect_uri' => self::redirect(),
            'response_type' => 'code',
            'scope' => config('igr-bookings.google.scope'),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /** @return array{ws: string, staff: string}|null */
    public static function readState(string $state): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($state), true);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($data) || ! isset($data['ws'], $data['staff'], $data['at']) || now()->getTimestamp() - (int) $data['at'] > 3600) {
            return null;
        }

        return ['ws' => (string) $data['ws'], 'staff' => (string) $data['staff']];
    }

    public function connect(BookingStaff $staff, string $code): void
    {
        $res = Http::asForm()->timeout(10)->post((string) config('igr-bookings.google.token_url'), [
            'code' => $code, 'client_id' => config('igr-bookings.google.client_id'), 'client_secret' => config('igr-bookings.google.client_secret'),
            'redirect_uri' => self::redirect(), 'grant_type' => 'authorization_code',
        ]);
        if (! $res->successful() || ! is_string($res->json('access_token'))) {
            throw new RuntimeException(__('Google no aceptó la conexión del calendario.'));
        }
        $staff->forceFill(['calendar' => [
            'access_token' => (string) $res->json('access_token'),
            'refresh_token' => (string) ($res->json('refresh_token') ?? ($staff->calendar['refresh_token'] ?? '')),
            'expires_at' => now()->getTimestamp() + (int) ($res->json('expires_in') ?? 3600) - 60,
            'calendar_id' => 'primary',
            'connected_at' => now()->toIso8601String(),
        ]])->save();
    }

    public function disconnect(BookingStaff $staff): void
    {
        $connection = $this->connection($staff);
        if ($connection !== null) {
            app(DisconnectConnection::class)->handle($connection);
        }
        $staff->forceFill(['calendar' => null])->save();
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> */
    public function busy(BookingStaff $staff, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $calendar = (string) ($staff->calendar['calendar_id'] ?? 'primary');
        $res = Http::withToken($this->token($staff))->timeout(8)->post(self::api('/freeBusy'), [
            'timeMin' => $from->utc()->toIso8601ZuluString(), 'timeMax' => $to->utc()->toIso8601ZuluString(), 'items' => [['id' => $calendar]],
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('freeBusy '.$res->status());
        }
        $out = [];
        foreach ((array) $res->json("calendars.{$calendar}.busy", []) as $b) {
            if (isset($b['start'], $b['end'])) {
                $out[] = [CarbonImmutable::parse((string) $b['start'])->utc(), CarbonImmutable::parse((string) $b['end'])->utc()];
            }
        }

        return $out;
    }

    /** Crea o actualiza el evento de la cita; devuelve su ID. */
    public function upsert(BookingStaff $staff, Appointment $appointment, BookingService $service): string
    {
        $calendar = rawurlencode((string) ($staff->calendar['calendar_id'] ?? 'primary'));
        $body = [
            // Sin datos de contacto en el calendario: solo el servicio y el enlace a la cita en el panel.
            'summary' => $service->name,
            'description' => __('Cita agendada con Imagina Growth (:id).', ['id' => $appointment->publicId()]),
            'start' => ['dateTime' => CarbonImmutable::instance($appointment->starts_at)->utc()->toIso8601ZuluString()],
            'end' => ['dateTime' => CarbonImmutable::instance($appointment->ends_at)->utc()->toIso8601ZuluString()],
        ];
        $id = $appointment->external_calendar_event_id;
        $res = $id !== null
            ? Http::withToken($this->token($staff))->timeout(8)->patch(self::api("/calendars/{$calendar}/events/".rawurlencode($id)), $body)
            : Http::withToken($this->token($staff))->timeout(8)->post(self::api("/calendars/{$calendar}/events"), $body);
        if (! $res->successful() || ! is_string($res->json('id'))) {
            throw new RuntimeException('event '.$res->status());
        }

        return (string) $res->json('id');
    }

    public function delete(BookingStaff $staff, string $eventId): void
    {
        $calendar = rawurlencode((string) ($staff->calendar['calendar_id'] ?? 'primary'));
        $res = Http::withToken($this->token($staff))->timeout(8)->delete(self::api("/calendars/{$calendar}/events/".rawurlencode($eventId)));
        if (! $res->successful() && $res->status() !== 404 && $res->status() !== 410) {
            throw new RuntimeException('delete '.$res->status());
        }
    }

    private function token(BookingStaff $staff): string
    {
        $connection = $this->connection($staff);
        if ($connection !== null) {
            return app(ConnectionTokens::class)->accessToken($connection);
        }
        $cal = (array) $staff->calendar;
        if ((string) ($cal['access_token'] ?? '') !== '' && (int) ($cal['expires_at'] ?? 0) > now()->getTimestamp()) {
            return (string) $cal['access_token'];
        }
        $res = Http::asForm()->timeout(10)->post((string) config('igr-bookings.google.token_url'), [
            'client_id' => config('igr-bookings.google.client_id'), 'client_secret' => config('igr-bookings.google.client_secret'),
            'refresh_token' => (string) ($cal['refresh_token'] ?? ''), 'grant_type' => 'refresh_token',
        ]);
        if (! $res->successful() || ! is_string($res->json('access_token'))) {
            Log::warning('igr.bookings.google_refresh_failed', ['staff' => $staff->id, 'status' => $res->status()]);

            throw new RuntimeException(__('Hay que volver a conectar el calendario de Google.'));
        }
        $cal['access_token'] = (string) $res->json('access_token');
        $cal['expires_at'] = now()->getTimestamp() + (int) ($res->json('expires_in') ?? 3600) - 60;
        $staff->forceFill(['calendar' => $cal])->save();

        return $cal['access_token'];
    }

    private function connection(BookingStaff $staff): ?OAuthConnection
    {
        $id = $staff->calendar['connection_id'] ?? null;

        return is_string($id) && $id !== ''
            ? OAuthConnection::withoutWorkspaceScope()->where('workspace_id', $staff->workspace_id)->whereKey($id)->first()
            : null;
    }

    public static function redirect(): string
    {
        return (string) (config('igr-bookings.google.redirect') ?: rtrim((string) config('app.url'), '/').'/p/_google/calendar/callback');
    }

    private static function api(string $path): string
    {
        return rtrim((string) config('igr-bookings.google.api'), '/').$path;
    }
}
