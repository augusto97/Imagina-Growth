<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Http\Public;

use App\Domain\Bookings\Actions\BookAppointment;
use App\Domain\Bookings\Actions\ChangeAppointment;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Models\BookingStaff;
use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Bookings\Support\SlotFinder;
use App\Domain\Bookings\Support\SlotTaken;
use App\Domain\Contacts\Actions\CaptureContact;
use App\Domain\Contacts\Data\CaptureInput;
use App\Domain\Onsite\Support\PublicWidgetConfig;
use App\Domain\Sites\Models\Site;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Página pública de reservas (M18): `/p/{clave}/reservar[/{servicio}]`. Se ve
 * igual sin JavaScript (formulario normal), en el celular primero, con la marca
 * del negocio. Sin sesión ni CSRF (routes/public): la protege el límite por IP.
 * Los horarios salen de `SlotFinder` en la zona horaria del negocio y
 * `BookAppointment` vuelve a revisar el horario dentro de la transacción.
 */
final class BookingPageController
{
    public const WINDOW_DAYS = 14;

    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly PublicBooking $public,
        private readonly SlotFinder $finder,
    ) {}

    /** GET /p/{key}/reservar — los servicios del negocio (con uno solo, ese) */
    public function index(Request $request, string $key): Response
    {
        $ctx = $this->public->resolve($key);
        if ($ctx === null || $this->public->limited($request, 'page', 60)) {
            return $this->public->missing(null, $ctx === null ? 404 : 429);
        }
        [$workspace] = $ctx;

        return $this->current->run($workspace, function () use ($workspace, $key, $request): Response {
            $settings = BookingSettings::for($workspace);
            $services = BookingService::query()->where('active', true)->orderBy('name')->get();
            if (! $settings['enabled'] || $services->isEmpty()) {
                return $this->public->missing($workspace);
            }
            if ($services->count() === 1) {
                return $this->page($request, $workspace, $key, $services->first(), [], []);
            }

            return $this->public->view('bookings.services', $workspace, [
                'settings' => $settings,
                'services' => $services->map(fn (BookingService $s) => [
                    'name' => $s->name, 'description' => $s->description, 'duration' => $s->duration_min,
                    'price' => $s->price !== null ? self::money((string) $s->price, (string) $workspace->currency) : null,
                    'location' => __('booking.location.'.$s->location),
                    'url' => self::base($key).'/reservar/'.$s->slug.self::carry($request),
                ])->all(),
            ]);
        });
    }

    /** GET /p/{key}/reservar/{slug}?desde=Y-m-d&dia=Y-m-d&persona=sf_… */
    public function show(Request $request, string $key, string $slug): Response
    {
        $ctx = $this->public->resolve($key);
        if ($ctx === null || $this->public->limited($request, 'page', 60)) {
            return $this->public->missing(null, $ctx === null ? 404 : 429);
        }
        [$workspace] = $ctx;

        return $this->current->run($workspace, function () use ($workspace, $key, $slug, $request): Response {
            $service = BookingService::query()->where('slug', $slug)->where('active', true)->first();
            if ($service === null || ! BookingSettings::for($workspace)['enabled']) {
                return $this->public->missing($workspace);
            }

            return $this->page($request, $workspace, $key, $service, [], []);
        });
    }

    /** POST /p/{key}/reservar/{slug} (formulario) */
    public function store(Request $request, string $key, string $slug, CaptureContact $capture, BookAppointment $book, PublicWidgetConfig $widgets): Response
    {
        $ctx = $this->public->resolve($key);
        if ($ctx === null || $this->public->limited($request, 'book', 10)) {
            return $this->public->missing(null, $ctx === null ? 404 : 429);
        }
        [$workspace, $site] = $ctx;

        return $this->current->run($workspace, function () use ($workspace, $site, $key, $slug, $request, $capture, $book, $widgets): Response {
            $settings = BookingSettings::for($workspace);
            $service = BookingService::query()->where('slug', $slug)->where('active', true)->first();
            if ($service === null || ! $settings['enabled']) {
                return $this->public->missing($workspace);
            }
            if (! $site instanceof Site) {
                return $this->page($request, $workspace, $key, $service, ['form' => __('Este negocio todavía no puede recibir reservas en línea. Escríbele directamente.')], $request->all(), 422);
            }
            $validator = Validator::make($request->all(), [
                'start' => ['required', 'date'],
                'persona' => ['nullable', 'string', 'max:40'],
                'name' => ['required', 'string', 'min:2', 'max:80'],
                'phone' => [$settings['require_email'] ? 'nullable' : 'required_without:email', 'nullable', 'string', 'max:30'],
                'email' => [$settings['require_email'] ? 'required' : 'nullable', 'email', 'max:255'],
                'notes' => ['nullable', 'string', 'max:500'],
                'consent' => ['nullable', 'array'],
            ], [], ['start' => __('horario'), 'name' => __('nombre'), 'phone' => __('WhatsApp'), 'email' => __('correo'), 'notes' => __('nota')]);
            if ($validator->fails()) {
                return $this->page($request, $workspace, $key, $service, self::flat($validator->errors()->toArray()), $request->all(), 422);
            }
            $staff = is_string($request->input('persona')) && $request->input('persona') !== '' ? BookingStaff::findByPublicId((string) $request->input('persona')) : null;
            $offered = array_column($widgets->legalConsents($workspace), 'channel');
            $consents = [];
            foreach ((array) $settings['consent_channels'] as $channel) {
                if (in_array($channel, $offered, true)) {
                    $consents[(string) $channel] = (bool) ($request->input('consent.'.$channel) ?? false);
                }
            }
            [$first, $last] = self::splitName((string) $request->input('name'));
            try {
                $start = self::instant($request->input('start'));
            } catch (InvalidArgumentException $e) {
                return $this->page($request, $workspace, $key, $service, ['start' => $e->getMessage()], $request->all(), 422);
            }
            try {
                $result = $capture->handle($workspace, $site, new CaptureInput(
                    source: 'booking', email: self::clean($request->input('email')), phone: self::clean($request->input('phone')),
                    firstName: $first, lastName: $last, anonymousId: self::aid($request->input('aid')), consents: $consents,
                    pageUrl: $request->fullUrl(), ip: $request->ip(), userAgent: (string) $request->userAgent(),
                ));
                $appointment = $book->handle(
                    $workspace, $service, $start, $staff?->id, $result->contact->id, 'page',
                    self::clean($request->input('notes')), self::source($request), $site->id,
                );
            } catch (InvalidArgumentException $e) {
                return $this->page($request, $workspace, $key, $service, ['phone' => $e->getMessage()], $request->all(), 422);
            } catch (SlotTaken $e) {
                return $this->page($request, $workspace, $key, $service, ['start' => $e->getMessage()], $request->except('start'), 409);
            }

            return redirect()->to(self::base($key).'/cita/'.$appointment->manage_token.'?ok=nueva', 303)->withHeaders(['Cache-Control' => 'no-store, private']);
        });
    }

    /** GET /p/{key}/cita/{token}?ok=…&reprogramar=1&desde=&dia= */
    public function manage(Request $request, string $key, string $token): Response
    {
        $ctx = $this->appointment($request, $key, $token, 60);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$workspace, $appointment] = $ctx;

        return $this->current->run($workspace, fn () => $this->managePage($request, $workspace, $key, $appointment, []));
    }

    /** POST /p/{key}/cita/{token}/{accion} — confirmar, reprogramar o cancelar */
    public function act(Request $request, string $key, string $token, string $action, ChangeAppointment $change): Response
    {
        $ctx = $this->appointment($request, $key, $token, 10);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$workspace, $appointment] = $ctx;

        return $this->current->run($workspace, function () use ($workspace, $appointment, $key, $token, $action, $request, $change): Response {
            if (! $appointment->isActive() || $appointment->starts_at->isPast()) {
                return $this->managePage($request, $workspace, $key, $appointment, ['form' => __('Esta cita ya no se puede cambiar. Escríbele al negocio.')], 409);
            }
            try {
                $ok = match ($action) {
                    'confirmar' => $change->mark($workspace, $appointment, 'confirmed')->status === 'confirmed' ? 'confirmada' : null,
                    'cancelar' => $change->cancel($workspace, $appointment, 'contact')->status === 'cancelled' ? 'cancelada' : null,
                    'reprogramar' => $change->reschedule($workspace, $appointment, self::instant($request->input('start')), 'contact')->status === 'rescheduled' ? 'reprogramada' : null,
                    default => null,
                };
            } catch (SlotTaken|InvalidArgumentException $e) {
                return $this->managePage($request->merge(['reprogramar' => '1']), $workspace, $key, $appointment->refresh(), ['start' => $e->getMessage()], 409);
            }
            if ($ok === null) {
                return $this->public->missing($workspace);
            }

            return redirect()->to(self::base($key).'/cita/'.$token.'?ok='.$ok, 303)->withHeaders(['Cache-Control' => 'no-store, private']);
        });
    }

    /** GET /p/{key}/cita/{token}/calendario.ics */
    public function ics(Request $request, string $key, string $token): Response
    {
        $ctx = $this->appointment($request, $key, $token, 60);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$workspace, $appointment] = $ctx;

        return $this->current->run($workspace, function () use ($workspace, $appointment, $key, $token): Response {
            $service = BookingService::query()->find($appointment->service_id);
            $business = (string) (BusinessProfile::for($workspace)['name'] ?: $workspace->name);
            $host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'imagina.cloud');
            $esc = fn (string $v) => str_replace(['\\', ';', ',', "\r", "\n"], ['\\\\', '\;', '\,', '', '\n'], $v);
            $fmt = fn ($at) => CarbonImmutable::instance($at)->utc()->format('Ymd\THis\Z');
            $lines = [
                'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Imagina Growth//Agenda//ES', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
                'BEGIN:VEVENT',
                'UID:'.$appointment->publicId().'@'.$host,
                'DTSTAMP:'.$fmt($appointment->updated_at ?? now()),
                'DTSTART:'.$fmt($appointment->starts_at),
                'DTEND:'.$fmt($appointment->ends_at),
                'SUMMARY:'.$esc(($service->name ?? __('Cita')).' · '.$business),
                'LOCATION:'.$esc($business.' ('.__('booking.location.'.($service->location ?? 'onsite')).')'),
                'DESCRIPTION:'.$esc(__('Para reprogramar o cancelar: :url', ['url' => self::base($key).'/cita/'.$token])),
                'STATUS:'.($appointment->status === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
                'SEQUENCE:'.max(0, (int) CarbonImmutable::instance($appointment->updated_at ?? now())->getTimestamp() % 100000),
                'END:VEVENT', 'END:VCALENDAR',
            ];

            return response(implode("\r\n", $lines)."\r\n", 200, [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="cita.ics"',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex',
            ]);
        });
    }

    /**
     * @param  array<string, string>  $errors
     * @param  array<string, mixed>  $old
     */
    private function page(Request $request, Workspace $workspace, string $key, BookingService $service, array $errors, array $old, int $status = 200): Response
    {
        $settings = BookingSettings::for($workspace);
        $picker = $this->picker($request, $workspace, $service, $old);
        $consents = [];
        $wanted = (array) $settings['consent_channels'];
        foreach (app(PublicWidgetConfig::class)->legalConsents($workspace) as $text) {
            if (in_array($text['channel'], $wanted, true)) {
                $consents[] = $text;
            }
        }

        return $this->public->view('bookings.page', $workspace, $picker + [
            'settings' => $settings,
            'service' => [
                'name' => $service->name, 'description' => $service->description, 'duration' => $service->duration_min,
                'price' => $service->price !== null ? self::money((string) $service->price, (string) $workspace->currency) : null,
                'location' => __('booking.location.'.$service->location),
            ],
            'action' => self::base($key).'/reservar/'.$service->slug,
            'selfUrl' => self::base($key).'/reservar/'.$service->slug,
            'servicesUrl' => BookingService::query()->where('active', true)->count() > 1 ? self::base($key).'/reservar' : null,
            'consents' => $consents,
            'errors' => $errors,
            'old' => $old,
            'carry' => self::carryFields($request),
            'canBook' => BookingSettings::site($workspace) !== null,
        ], $status);
    }

    /** @param array<string, string> $errors */
    private function managePage(Request $request, Workspace $workspace, string $key, Appointment $appointment, array $errors, int $status = 200): Response
    {
        $service = BookingService::query()->find($appointment->service_id);
        $tz = $workspace->timezone ?: 'America/Bogota';
        $start = CarbonImmutable::instance($appointment->starts_at);
        $changeable = $appointment->isActive() && $start->isFuture();
        $rescheduling = $changeable && $service !== null && ($request->query('reprogramar') === '1' || $request->input('reprogramar') === '1');
        $picker = $rescheduling ? $this->picker($request, $workspace, $service, [], $appointment) : [];
        $ok = is_string($request->query('ok')) ? $request->query('ok') : null;
        $base = self::base($key).'/cita/'.$appointment->manage_token;

        return $this->public->view('bookings.manage', $workspace, $picker + [
            'ok' => in_array($ok, ['nueva', 'confirmada', 'cancelada', 'reprogramada'], true) ? $ok : null,
            'status' => $appointment->status,
            'service' => $service->name ?? __('Cita'),
            'location' => __('booking.location.'.($service->location ?? 'onsite')),
            'day' => PublicBooking::day($start, $tz),
            'time' => PublicBooking::time($start, $tz),
            'duration' => $service?->duration_min,
            'staff' => $appointment->staff_id !== null ? BookingStaff::query()->find($appointment->staff_id)?->name : null,
            'changeable' => $changeable,
            'rescheduling' => $rescheduling,
            'base' => $base,
            'selfUrl' => $base,
            'icsUrl' => $base.'/calendario.ics',
            'errors' => $errors,
            'old' => [],
            'carry' => [['reprogramar', '1']],
        ], $status);
    }

    /**
     * Días con horario libre (14 desde `desde`) y los horarios del día elegido, en la hora del negocio.
     *
     * @param  array<string, mixed>  $old
     * @return array<string, mixed>
     */
    private function picker(Request $request, Workspace $workspace, BookingService $service, array $old, ?Appointment $moving = null): array
    {
        $tz = $workspace->timezone ?: 'America/Bogota';
        $today = CarbonImmutable::now($tz)->startOfDay();
        $from = self::date($request->query('desde'), $tz) ?? $today;
        $from = $from->lessThan($today) ? $today : $from;
        $staffOptions = $this->staffOptions($service);
        $persona = $request->query('persona', $old['persona'] ?? null);
        $staff = is_string($persona) && $persona !== '' ? BookingStaff::findByPublicId($persona) : null;
        if ($staff !== null && ! array_key_exists($staff->publicId(), $staffOptions)) {
            $staff = null;
        }
        $staffId = $staff->id ?? ($moving !== null && count($staffOptions) <= 1 ? $moving->staff_id : null);
        $slots = $moving !== null
            ? $this->withoutSelf($workspace, $service, $from, $staffId, $moving)
            : $this->finder->slots($workspace, $service, $from, self::WINDOW_DAYS, $staffId);

        $days = [];
        foreach ($slots as $slot) {
            $local = $slot['start']->setTimezone($tz);
            $date = $local->format('Y-m-d');
            $value = $slot['start']->toIso8601ZuluString();
            $days[$date] ??= ['date' => $date, 'weekday' => PublicBooking::es($local)->isoFormat('ddd'), 'label' => PublicBooking::es($local)->isoFormat('D MMM'), 'slots' => []];
            $days[$date]['slots'][$value] ??= ['value' => $value, 'label' => $local->format('g:i a')];
        }
        $chosen = self::date($request->query('dia'), $tz)?->format('Y-m-d');
        $oldStart = is_string($old['start'] ?? null) ? (self::date(substr((string) $old['start'], 0, 10), 'UTC') !== null ? CarbonImmutable::parse((string) $old['start'])->setTimezone($tz)->format('Y-m-d') : null) : null;
        $selected = $chosen !== null && isset($days[$chosen]) ? $chosen : ($oldStart !== null && isset($days[$oldStart]) ? $oldStart : (array_key_first($days) ?? null));
        $latest = CarbonImmutable::now($tz)->addDays($service->max_days_ahead)->startOfDay();
        $next = $from->addDays(self::WINDOW_DAYS);

        return [
            'days' => array_values(array_map(fn (array $d) => ['date' => $d['date'], 'weekday' => $d['weekday'], 'label' => $d['label'], 'count' => count($d['slots'])], $days)),
            'selectedDay' => $selected,
            'selectedDayLabel' => $selected !== null ? PublicBooking::day(CarbonImmutable::parse($selected, $tz), $tz) : null,
            'slots' => $selected !== null ? array_values($days[$selected]['slots']) : [],
            'from' => $from->format('Y-m-d'),
            'prevFrom' => $from->greaterThan($today) ? $from->subDays(self::WINDOW_DAYS)->max($today)->format('Y-m-d') : null,
            'nextFrom' => $next->lessThanOrEqualTo($latest) ? $next->format('Y-m-d') : null,
            'staffOptions' => $staffOptions,
            'persona' => $staff?->publicId(),
        ];
    }

    /**
     * Horarios para reprogramar: la cita que se mueve no se bloquea a sí misma.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, staff_id: string|null}>
     */
    private function withoutSelf(Workspace $workspace, BookingService $service, CarbonImmutable $from, ?string $staffId, Appointment $moving): array
    {
        return $this->finder->ignoring($moving)->slots($workspace, $service, $from, self::WINDOW_DAYS, $staffId);
    }

    /** @return array<string, string> personas que atienden el servicio (ID público ⇒ nombre), si hay más de una */
    private function staffOptions(BookingService $service): array
    {
        $query = BookingStaff::query()->where('active', true)->orderBy('name');
        $ids = array_values((array) $service->staff_ids);
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        $staff = $query->get();

        return $staff->count() > 1 ? $staff->mapWithKeys(fn (BookingStaff $s) => [$s->publicId() => $s->name])->all() : [];
    }

    /** @return array{0: Workspace, 1: Appointment}|Response */
    private function appointment(Request $request, string $key, string $token, int $perMinute): array|Response
    {
        $ctx = $this->public->resolve($key);
        if ($ctx === null || ! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return $this->public->missing(null);
        }
        if ($this->public->limited($request, 'manage', $perMinute)) {
            return $this->public->missing($ctx[0], 429);
        }
        $appointment = Appointment::withoutWorkspaceScope()->where('workspace_id', $ctx[0]->id)->where('manage_token', $token)->first();

        return $appointment === null ? $this->public->missing($ctx[0]) : [$ctx[0], $appointment];
    }

    public static function base(string $key): string
    {
        return rtrim((string) config('app.url'), '/').'/p/'.$key;
    }

    /** @throws InvalidArgumentException */
    private static function instant(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})$/', $value)) {
            throw new InvalidArgumentException(__('Elige un horario.'));
        }

        return CarbonImmutable::parse($value);
    }

    private static function date(mixed $value, string $tz): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $tz);

        return $date instanceof CarbonImmutable ? $date : null;
    }

    private static function money(string $amount, string $currency): string
    {
        $decimals = in_array($currency, ['COP'], true) ? 0 : 2;

        return '$'.number_format((float) $amount, $decimals, ',', '.').' '.$currency;
    }

    /** @return array{0: string, 1: string|null} */
    private static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [''];

        return [(string) $parts[0], isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null];
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');

        return $value === '' ? null : $value;
    }

    private static function aid(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f-]{36}$/i', $value) ? strtolower($value) : null;
    }

    /** @return array<string, string> origen del lead: UTM y clics de anuncios que traía el enlace */
    private static function source(Request $request): array
    {
        $out = ['channel' => 'booking_page'];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid', 'ttclid'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && $value !== '') {
                $out[$field] = mb_substr($value, 0, 200);
            }
        }

        return $out;
    }

    /** @return list<array{0: string, 1: string}> campos que viajan de una vista a la otra (UTM, aid) */
    private static function carryFields(Request $request): array
    {
        $out = [];
        foreach (['aid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid', 'ttclid'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && $value !== '') {
                $out[] = [$field, mb_substr($value, 0, 200)];
            }
        }

        return $out;
    }

    private static function carry(Request $request): string
    {
        $fields = self::carryFields($request);

        return $fields === [] ? '' : '?'.http_build_query(array_column($fields, 1, 0));
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, string>
     */
    private static function flat(array $errors): array
    {
        return array_map(fn (array $e) => (string) ($e[0] ?? ''), $errors);
    }
}
