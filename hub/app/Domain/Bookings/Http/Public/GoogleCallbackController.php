<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Http\Public;

use App\Domain\Bookings\Google\GoogleCalendar;
use App\Domain\Bookings\Models\BookingStaff;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GET /p/_google/calendar/callback — vuelta del consentimiento de Google Calendar
 * de una persona de la agenda (M18). El `state` va cifrado con la llave de la app,
 * lleva el espacio de trabajo y la persona y vence en una hora.
 */
final class GoogleCallbackController
{
    public function __invoke(Request $request, GoogleCalendar $google, CurrentWorkspace $current, RecordAuditLog $audit): RedirectResponse
    {
        $state = GoogleCalendar::readState((string) $request->query('state', ''));
        $workspace = $state !== null ? Workspace::query()->find($state['ws']) : null;
        if ($state === null || $workspace === null) {
            return redirect()->to(rtrim((string) config('app.url'), '/').'/');
        }
        $panel = rtrim((string) config('app.url'), '/').'/w/'.$workspace->publicId().'/agenda?pestana=personas&google=';
        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return redirect()->to($panel.'cancelado');
        }

        return $current->run($workspace, function () use ($state, $google, $code, $panel, $audit, $workspace): RedirectResponse {
            $staff = BookingStaff::query()->find($state['staff']);
            if ($staff === null) {
                return redirect()->to($panel.'error');
            }
            try {
                $google->connect($staff, $code);
            } catch (Throwable $e) {
                Log::warning('bookings.google_connect_failed', ['staff' => $staff->publicId(), 'error' => $e::class]);

                return redirect()->to($panel.'error');
            }
            $audit->handle('booking_staff.calendar_connected', $staff, [], $workspace->id);

            return redirect()->to($panel.'ok');
        });
    }
}
