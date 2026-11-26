<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Http;

use App\Domain\Bookings\Actions\BookAppointment;
use App\Domain\Bookings\Actions\ChangeAppointment;
use App\Domain\Bookings\Google\GoogleCalendar;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingAvailability;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Models\BookingStaff;
use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Bookings\Support\SlotFinder;
use App\Domain\Bookings\Support\SlotTaken;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Contacts\Http\ContactAccess;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Agenda de citas en el panel (M18). Ver la agenda y mover citas: `useLeads`;
 * configurar servicios, personas, horarios y ajustes: `manageServices`.
 */
final class BookingController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** GET bookings — ajustes, servicios, personas, horarios y la página de reservas */
    public function overview(): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $ws = $this->current->get();

        return response()->json([
            'settings' => BookingSettings::for($ws),
            'page_url' => BookingSettings::pageUrl($ws),
            'page_qr' => self::qr(BookingSettings::pageUrl($ws)),
            // La misma página servida por el hub, para la vista previa del panel (un dominio propio no se deja enmarcar aquí).
            'preview_url' => rtrim((string) config('app.url'), '/').'/p/'.BookingSettings::publicKey($ws).'/reservar',
            'google_configured' => GoogleCalendar::configured(),
            'can_manage' => Gate::allows('ws.manageServices'),
            'timezone' => $ws->timezone,
            'services' => BookingService::query()->orderBy('name')->get()->map(fn (BookingService $s) => self::service($s))->values(),
            'staff' => BookingStaff::query()->orderBy('name')->get()->map(fn (BookingStaff $s) => self::staff($s))->values(),
            'availability' => BookingAvailability::query()->orderBy('weekday')->orderBy('start_time')->get()->map(fn (BookingAvailability $a) => [
                'id' => $a->publicId(), 'staff_id' => $a->staff_id !== null ? PrefixedId::encode('sf', $a->staff_id) : null,
                'weekday' => $a->weekday, 'start' => substr((string) $a->start_time, 0, 5), 'end' => substr((string) $a->end_time, 0, 5),
                'exceptions' => $a->exceptions ?? [],
            ])->values(),
        ]);
    }

    /** PUT bookings/settings */
    public function settings(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'], 'page_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'intro' => ['sometimes', 'nullable', 'string', 'max:600'], 'ask_email' => ['sometimes', 'boolean'], 'require_email' => ['sometimes', 'boolean'],
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'consent_channels' => ['sometimes', 'array'], 'consent_channels.*' => ['in:email,whatsapp,sms'],
            'whatsapp_templates' => ['sometimes', 'array'], 'whatsapp_templates.*' => ['nullable', 'string', 'max:40'],
            'auto_no_show' => ['sometimes', 'boolean'], 'reminder_hours' => ['sometimes', 'array', 'max:3'], 'reminder_hours.*' => ['integer', 'min:1', 'max:72'],
        ]);
        $ws = $this->current->get();
        BookingSettings::save($ws, $data);
        $this->audit->handle('bookings.settings_updated', null, ['fields' => array_keys($data)], $ws->id);

        return response()->json(['settings' => BookingSettings::for($ws->refresh())]);
    }

    /** POST bookings/services · PATCH bookings/services/{service} */
    public function saveService(Request $request, ?string $service = null): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $model = $service !== null ? (BookingService::findByPublicId($service) ?? abort(404)) : new BookingService;
        $data = $request->validate([
            'name' => [$model->exists ? 'sometimes' : 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'duration_min' => ['sometimes', 'integer', 'min:5', 'max:600'], 'buffer_min' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999'], 'location' => ['sometimes', Rule::in(BookingService::LOCATIONS)],
            'staff_ids' => ['sometimes', 'array'], 'staff_ids.*' => ['string'],
            'min_notice_hours' => ['sometimes', 'integer', 'min:0', 'max:720'], 'max_days_ahead' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('staff_ids', $data)) {
            $data['staff_ids'] = array_values(array_filter(array_map(fn ($id) => BookingStaff::findByPublicId((string) $id)?->id, (array) $data['staff_ids'])));
        }
        if (! $model->exists) {
            $base = Str::slug((string) $data['name']) ?: 'servicio';
            $slug = $base;
            for ($i = 2; BookingService::query()->where('slug', $slug)->exists(); $i++) {
                $slug = $base.'-'.$i;
            }
            $data['slug'] = mb_substr($slug, 0, 80);
            $data['workspace_id'] = $this->current->id();
        }
        $model->fill($data)->save();
        $this->audit->handle($service === null ? 'booking_service.created' : 'booking_service.updated', $model, ['fields' => array_keys($data)], $this->current->id());

        return response()->json(self::service($model->refresh()), $service === null ? 201 : 200);
    }

    /** DELETE bookings/services/{service} — con citas, se desactiva */
    public function deleteService(string $service): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $model = BookingService::findByPublicId($service) ?? abort(404);
        if (Appointment::query()->where('service_id', $model->id)->exists()) {
            $model->forceFill(['active' => false])->save();
        } else {
            $model->delete();
        }
        $this->audit->handle('booking_service.deleted', $model, [], $this->current->id());

        return response()->json(null, 204);
    }

    /** POST bookings/staff · PATCH bookings/staff/{staff} */
    public function saveStaff(Request $request, ?string $staff = null): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $model = $staff !== null ? (BookingStaff::findByPublicId($staff) ?? abort(404)) : new BookingStaff;
        $data = $request->validate([
            'name' => [$model->exists ? 'sometimes' : 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'], 'timezone' => ['sometimes', 'timezone:all'],
            'user_id' => ['sometimes', 'nullable', 'string', 'max:40'], 'active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('user_id', $data) && $data['user_id'] !== null) {
            $userId = PrefixedId::decode('usr', (string) $data['user_id']) ?? (string) $data['user_id'];
            $member = DB::table('workspace_user')->where('workspace_id', $this->current->id())->where('user_id', $userId)->exists();
            if (! $member) {
                throw ValidationException::withMessages(['user_id' => __('Esa persona no es del equipo de este espacio de trabajo.')]);
            }
            $data['user_id'] = $userId;
        }
        if (! $model->exists) {
            $data += ['workspace_id' => $this->current->id(), 'timezone' => $this->current->get()->timezone ?: 'America/Bogota'];
        }
        $model->fill($data)->save();
        $this->audit->handle($staff === null ? 'booking_staff.created' : 'booking_staff.updated', $model, ['fields' => array_keys($data)], $this->current->id());

        return response()->json(self::staff($model->refresh()), $staff === null ? 201 : 200);
    }

    /** DELETE bookings/staff/{staff} — se desactiva (sus citas siguen) */
    public function deleteStaff(string $staff): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $model = BookingStaff::findByPublicId($staff) ?? abort(404);
        if ($model->calendarConnected()) {
            app(GoogleCalendar::class)->disconnect($model);  // también revoca la conexión común de esa persona
        }
        $model->forceFill(['active' => false, 'calendar' => null])->save();
        $this->audit->handle('booking_staff.deactivated', $model, [], $this->current->id());

        return response()->json(null, 204);
    }

    /** POST bookings/staff/{staff}/google — URL para conectar · DELETE para desconectar */
    public function google(Request $request, string $staff, GoogleCalendar $google): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $model = BookingStaff::findByPublicId($staff) ?? abort(404);
        if ($request->isMethod('delete')) {
            $google->disconnect($model);
            $this->audit->handle('booking_staff.calendar_disconnected', $model, [], $this->current->id());

            return response()->json(self::staff($model->refresh()));
        }
        if (! GoogleCalendar::configured()) {
            return response()->json(['message' => __('La conexión con Google Calendar todavía no está disponible en esta instalación. Escríbenos para activarla.')], 422);
        }

        try {
            return response()->json(['url' => $google->connectUrl($model, (string) $request->user()?->getAuthIdentifier())]);
        } catch (ConnectionError $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** PUT bookings/availability {staff_id?, rows: [{weekday, start, end}], exceptions: [...]} — reemplaza el horario */
    public function availability(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $data = $request->validate([
            'staff_id' => ['nullable', 'string'],
            'rows' => ['present', 'array', 'max:50'], 'rows.*.weekday' => ['required', 'integer', 'between:1,7'],
            'rows.*.start' => ['required', 'date_format:H:i'], 'rows.*.end' => ['required', 'date_format:H:i'],
            'exceptions' => ['sometimes', 'array', 'max:200'], 'exceptions.*.date' => ['required', 'date_format:Y-m-d'],
            'exceptions.*.closed' => ['sometimes', 'boolean'], 'exceptions.*.start' => ['sometimes', 'date_format:H:i'], 'exceptions.*.end' => ['sometimes', 'date_format:H:i'],
        ]);
        $staffId = isset($data['staff_id']) ? (BookingStaff::findByPublicId((string) $data['staff_id'])->id ?? abort(404)) : null;
        foreach ($data['rows'] as $i => $row) {
            if ($row['end'] <= $row['start']) {
                throw ValidationException::withMessages(["rows.{$i}.end" => __('La hora de cierre debe ser después de la de apertura.')]);
            }
        }
        $exceptions = array_values(array_map(fn (array $e) => array_filter([
            'date' => $e['date'], 'closed' => ! empty($e['closed']) ? true : null, 'start' => $e['start'] ?? null, 'end' => $e['end'] ?? null,
        ], fn ($v) => $v !== null), (array) ($data['exceptions'] ?? [])));
        DB::transaction(function () use ($staffId, $data, $exceptions) {
            BookingAvailability::query()->when($staffId === null, fn ($q) => $q->whereNull('staff_id'), fn ($q) => $q->where('staff_id', $staffId))->delete();
            foreach ($data['rows'] as $i => $row) {
                BookingAvailability::query()->create([
                    'workspace_id' => $this->current->id(), 'staff_id' => $staffId, 'weekday' => (int) $row['weekday'],
                    'start_time' => $row['start'], 'end_time' => $row['end'], 'exceptions' => $i === 0 ? $exceptions : null,
                ]);
            }
        });
        $this->audit->handle('bookings.availability_updated', null, ['staff' => $data['staff_id'] ?? null, 'rows' => count($data['rows'])], $this->current->id());

        return $this->overview();
    }

    /** GET bookings/slots?service=&date=&days=&staff= */
    public function slots(Request $request, SlotFinder $finder): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $data = $request->validate(['service' => ['required', 'string'], 'date' => ['required', 'date_format:Y-m-d'], 'days' => ['sometimes', 'integer', 'min:1', 'max:31'], 'staff' => ['nullable', 'string']]);
        $service = BookingService::findByPublicId((string) $data['service']) ?? abort(404);
        $staff = isset($data['staff']) ? (BookingStaff::findByPublicId((string) $data['staff'])->id ?? abort(404)) : null;
        $ws = $this->current->get();
        $slots = $finder->slots($ws, $service, CarbonImmutable::parse((string) $data['date'], $ws->timezone ?: 'America/Bogota'), (int) ($data['days'] ?? 7), $staff);

        return response()->json(['data' => array_map(fn (array $s) => [
            'start' => $s['start']->toIso8601ZuluString(), 'end' => $s['end']->toIso8601ZuluString(),
            'staff_id' => $s['staff_id'] !== null ? PrefixedId::encode('sf', $s['staff_id']) : null,
        ], $slots)]);
    }

    /** GET bookings/appointments?from=&to=&staff=&status= */
    public function appointments(Request $request): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after:from'], 'staff' => ['nullable', 'string'], 'status' => ['nullable', 'string']]);
        $query = Appointment::query()->where('starts_at', '>=', CarbonImmutable::parse((string) $data['from'])->utc())
            ->where('starts_at', '<', CarbonImmutable::parse((string) $data['to'])->utc())->orderBy('starts_at')->limit(1000);
        if (isset($data['staff'])) {
            $query->where('staff_id', BookingStaff::findByPublicId((string) $data['staff'])->id ?? '-');
        }
        if (isset($data['status'])) {
            $query->whereIn('status', explode(',', (string) $data['status']));
        }
        if (! Gate::allows('ws.viewAllContacts')) {
            // El rol agente ve las citas de sus contactos (los de sus conversaciones asignadas).
            $visible = Contact::query();
            ContactAccess::scope($visible);
            $query->whereIn('contact_id', $visible->select('contacts.id'));
        }
        $rows = $query->get();
        $contacts = Contact::query()->whereIn('id', $rows->pluck('contact_id')->unique())->get()->keyBy('id');
        $services = BookingService::query()->whereIn('id', $rows->pluck('service_id')->unique())->get()->keyBy('id');

        return response()->json(['data' => $rows->map(fn (Appointment $a) => self::appointment($a, $contacts->get($a->contact_id), $services->get($a->service_id)))->values()]);
    }

    /** POST bookings/appointments {contact_id, service_id, start, staff_id?, notes?} — cita agendada desde el panel */
    public function book(Request $request, BookAppointment $book): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $data = $request->validate(['contact_id' => ['required', 'string'], 'service_id' => ['required', 'string'], 'start' => ['required', 'date'],
            'staff_id' => ['nullable', 'string'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $contact = ContactAccess::find((string) $data['contact_id']);
        $service = BookingService::findByPublicId((string) $data['service_id']) ?? abort(404);
        $staff = isset($data['staff_id']) ? (BookingStaff::findByPublicId((string) $data['staff_id'])->id ?? abort(404)) : null;
        try {
            $a = $book->handle($this->current->get(), $service, CarbonImmutable::parse((string) $data['start']), $staff, $contact->id, 'manual', $data['notes'] ?? null);
        } catch (SlotTaken $e) {
            throw ValidationException::withMessages(['start' => $e->getMessage()]);
        }
        $this->audit->handle('appointment.created', $a, ['service' => $service->publicId()], $this->current->id());

        return response()->json(self::appointment($a, $contact, $service), 201);
    }

    /** POST bookings/appointments/{appointment}/{action} — confirmar, cumplida, no asistió, reprogramar o cancelar */
    public function change(Request $request, string $appointment, string $action, ChangeAppointment $change): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $model = Appointment::findByPublicId($appointment) ?? abort(404);
        $ws = $this->current->get();
        try {
            match ($action) {
                'confirm' => $change->mark($ws, $model, 'confirmed'),
                'complete' => $change->mark($ws, $model, 'completed'),
                'no-show' => $change->mark($ws, $model, 'no_show'),
                'cancel' => $change->cancel($ws, $model, 'staff', $request->validate(['reason' => ['nullable', 'string', 'max:120']])['reason'] ?? null),
                'reschedule' => $change->reschedule($ws, $model, CarbonImmutable::parse((string) $request->validate(['start' => ['required', 'date']])['start']), 'staff'),
                default => abort(404),
            };
        } catch (SlotTaken|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }
        $this->audit->handle('appointment.'.$action, $model, [], $ws->id);
        $model->refresh();

        return response()->json(self::appointment($model, Contact::query()->find($model->contact_id), BookingService::query()->find($model->service_id)));
    }

    /** GET bookings/metrics?days=30 */
    public function metrics(Request $request): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $days = (int) ($request->validate(['days' => ['sometimes', 'integer', 'min:7', 'max:365']])['days'] ?? 30);
        $since = now()->subDays($days);
        $rows = Appointment::query()->where('starts_at', '>=', $since)->where('starts_at', '<=', now()->addDays(60))
            ->get(['status', 'service_id', 'channel', 'starts_at']);
        $past = $rows->filter(fn (Appointment $a) => $a->starts_at->isPast());
        $done = $past->whereIn('status', ['completed', 'no_show']);
        $services = BookingService::query()->pluck('name', 'id');

        return response()->json([
            'days' => $days,
            'booked' => $rows->count(),
            'upcoming' => $rows->filter(fn (Appointment $a) => $a->starts_at->isFuture() && $a->isActive())->count(),
            'completed' => $rows->where('status', 'completed')->count(),
            'no_show' => $rows->where('status', 'no_show')->count(),
            'cancelled' => $rows->where('status', 'cancelled')->count(),
            'no_show_rate' => $done->count() > 0 ? round($done->where('status', 'no_show')->count() / $done->count(), 4) : null,
            'by_service' => $rows->groupBy('service_id')->map(fn ($g, $id) => ['service' => (string) ($services[$id] ?? '—'), 'count' => $g->count(),
                'no_show' => $g->where('status', 'no_show')->count()])->values(),
            'by_channel' => $rows->groupBy('channel')->map(fn ($g, $channel) => ['channel' => (string) $channel, 'count' => $g->count()])->values(),
        ]);
    }

    /** QR del enlace de la página de reservas, para imprimir o compartir (data URI SVG). */
    private static function qr(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(240, 1), new SvgImageBackEnd)))->writeString($url);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** @return array<string, mixed> */
    public static function service(BookingService $s): array
    {
        return [
            'id' => $s->publicId(), 'name' => $s->name, 'slug' => $s->slug, 'description' => $s->description,
            'duration_min' => $s->duration_min, 'buffer_min' => $s->buffer_min, 'price' => $s->price !== null ? (string) $s->price : null,
            'location' => $s->location, 'staff_ids' => array_map(fn ($id) => PrefixedId::encode('sf', (string) $id), array_values((array) $s->staff_ids)),
            'min_notice_hours' => $s->min_notice_hours, 'max_days_ahead' => $s->max_days_ahead, 'active' => $s->active,
        ];
    }

    /** @return array<string, mixed> */
    public static function staff(BookingStaff $s): array
    {
        return [
            'id' => $s->publicId(), 'name' => $s->name, 'email' => ContactAccess::email($s->email), 'timezone' => $s->timezone,
            'user_id' => $s->user_id !== null ? PrefixedId::encode('usr', $s->user_id) : null, 'active' => $s->active,
            'calendar_connected' => $s->calendarConnected(),
        ];
    }

    /** @return array<string, mixed> */
    public static function appointment(Appointment $a, ?Contact $contact, ?BookingService $service): array
    {
        return [
            'id' => $a->publicId(), 'status' => $a->status, 'channel' => $a->channel,
            'starts_at' => $a->starts_at->toIso8601ZuluString(), 'ends_at' => $a->ends_at->toIso8601ZuluString(),
            'service' => $service !== null ? ['id' => $service->publicId(), 'name' => $service->name, 'location' => $service->location] : null,
            'staff_id' => $a->staff_id !== null ? PrefixedId::encode('sf', $a->staff_id) : null,
            'contact' => $contact !== null ? [
                'id' => $contact->publicId(), 'name' => trim($contact->first_name.' '.$contact->last_name) ?: null,
                'email' => ContactAccess::email($contact->email), 'phone' => ContactAccess::phone($contact->phone),
            ] : null,
            'lead_id' => $a->lead_id !== null ? PrefixedId::encode('ld', $a->lead_id) : null,
            'calendar_synced' => $a->external_calendar_event_id !== null,
        ];
    }
}
