<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingAvailability;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Models\BookingStaff;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Actions\CreateFlowFromLibrary;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Quotes\Support\QuoteCapture;
use App\Domain\Quotes\Support\QuoteEngine;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadSeller;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Domain\Services\Verticals\ApplyVerticalDefaults;
use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Support\SiteKeys;
use App\Domain\Workspaces\Actions\CreateWorkspace;
use App\Domain\Workspaces\Data\WorkspaceData;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Negocio de servicios de prueba en Colombia (rediseño v2 §11.2, `igr:seed-demo --tipo=servicios --vertical=…`): sin
 * WooCommerce, con un sitio WordPress, el embudo de su vertical, contactos con su consentimiento por formulario, leads
 * de varias fuentes (formularios, WhatsApp, Instagram, anuncios de Facebook e Instagram y de Google, agenda y
 * cotizador) en todas las etapas con su historia, citas cumplidas, no asistidas y próximas, cotizaciones,
 * conversaciones de WhatsApp y formularios del sitio. Montos en COP y ciudades reales. Determinista por `seed` y sin
 * duplicar: si el espacio de trabajo ya tiene leads, no agrega nada. Nunca en producción (lo revisa el comando).
 */
final class DemoServicesSeeder
{
    /**
     * @var array<string, array{name: string, host: string, industry: string|null, address: string, services: list<array{0: string, 1: int, 2: int, 3: string}>,
     *     asks: list<string>, values: array{0: int, 1: int}, calculator: array{name: string, options: list<array{0: string, 1: int}>},
     *     forms: list<string>, lost: list<string>, chats: list<string>}>
     */
    private const VERTICALS = [
        'health' => [
            'name' => 'Clínica Sonrisa Demo', 'host' => 'demo.clinicasonrisa.co', 'industry' => 'salud_bienestar', 'address' => 'Calle 93 # 14-20, consultorio 402, Bogotá',
            'services' => [['Valoración inicial', 30, 60000, 'onsite'], ['Limpieza dental', 45, 120000, 'onsite'], ['Blanqueamiento', 60, 650000, 'onsite'], ['Control de ortodoncia', 30, 90000, 'onsite']],
            'asks' => ['Ortodoncia invisible', 'Diseño de sonrisa', 'Limpieza dental', 'Blanqueamiento', 'Implante dental', 'Valoración de ortodoncia', 'Endodoncia', 'Calza de resina'],
            'values' => [250000, 9800000],
            'calculator' => ['name' => 'Cotiza tu tratamiento', 'options' => [['Blanqueamiento', 650000], ['Diseño de sonrisa (por diente)', 850000], ['Ortodoncia invisible', 7900000], ['Implante dental', 3600000]]],
            'forms' => ['Agenda tu valoración', 'Pide información de ortodoncia'],
            'lost' => ['Muy caro', 'Eligió otra clínica', 'No respondió', 'Lo hará más adelante'],
            'chats' => ['Hola, ¿cuánto cuesta el blanqueamiento?', 'Buenas, quiero una cita de valoración', '¿Atienden los sábados?', 'Quiero información de ortodoncia invisible'],
        ],
        'professional' => [
            'name' => 'Consultores Andinos Demo', 'host' => 'demo.consultoresandinos.co', 'industry' => 'servicios_profesionales', 'address' => 'Carrera 7 # 71-21, oficina 802, Bogotá',
            'services' => [['Consulta inicial', 30, 0, 'online'], ['Asesoría tributaria', 60, 350000, 'onsite'], ['Revisión de contratos', 60, 450000, 'online']],
            'asks' => ['Declaración de renta', 'Constitución de empresa', 'Asesoría laboral', 'Revisión de contrato', 'Contabilidad mensual', 'Planeación tributaria'],
            'values' => [400000, 18000000],
            'calculator' => ['name' => 'Cotiza tu asesoría', 'options' => [['Declaración de renta persona natural', 450000], ['Contabilidad mensual (pyme)', 1200000], ['Constitución de SAS', 1800000]]],
            'forms' => ['Agenda una reunión', 'Solicita una propuesta'],
            'lost' => ['Muy caro', 'Eligió otra firma', 'No respondió', 'Ya no lo necesita'],
            'chats' => ['Hola, necesito declarar renta este año', 'Buenas tardes, ¿hacen contabilidad para pymes?', 'Quiero constituir una SAS', '¿Cuánto cobran por una asesoría laboral?'],
        ],
        'real_estate' => [
            'name' => 'Inmobiliaria Horizonte Demo', 'host' => 'demo.inmohorizonte.co', 'industry' => null, 'address' => 'Carrera 43A # 1-50, Medellín',
            'services' => [['Visita a la propiedad', 45, 0, 'onsite'], ['Avalúo comercial', 60, 600000, 'onsite'], ['Asesoría de crédito hipotecario', 30, 0, 'online']],
            'asks' => ['Apartamento en El Poblado', 'Casa en Envigado', 'Apartamento en arriendo en Laureles', 'Lote en Rionegro', 'Apartaestudio en Belén', 'Oficina en Ciudad del Río'],
            'values' => [2500000, 95000000],
            'calculator' => ['name' => 'Calcula tu cuota inicial', 'options' => [['Apartamento hasta $300 millones', 60000000], ['Apartamento de $300 a $600 millones', 120000000], ['Casa campestre', 180000000]]],
            'forms' => ['Agenda una visita', 'Quiero que me llamen'],
            'lost' => ['Compró con otra inmobiliaria', 'No le aprobaron el crédito', 'No respondió', 'Cambió de planes'],
            'chats' => ['Hola, ¿el apartamento de El Poblado sigue disponible?', 'Quiero agendar una visita', '¿Reciben crédito hipotecario?', '¿Cuánto es la administración?'],
        ],
    ];

    /** Nombre del espacio de trabajo de otras verticales (sin datos propios: usa los de servicios profesionales). */
    private const FALLBACK = 'professional';

    /** Peso de cada origen y la fuente con la que llega. @var list<array{0: string, 1: float, 2: string}> */
    private const ORIGINS = [
        ['form', 0.24, 'organic_search'], ['form', 0.12, 'paid_search'], ['form', 0.14, 'paid_social'], ['whatsapp', 0.24, 'whatsapp'],
        ['instagram', 0.06, 'organic_social'], ['booking', 0.10, 'direct'], ['quote', 0.06, 'paid_search'], ['manual', 0.04, 'referral'],
    ];

    private Randomizer $rng;

    public function __construct(private readonly CurrentWorkspace $current, private readonly CreateWorkspace $create, private readonly ApplyVerticalDefaults $defaults) {}

    /** @return array{workspace: Workspace, created: bool, contacts: int, leads: int, appointments: int, quotes: int, conversations: int} */
    public function handle(string $vertical, int $contacts = 300, int $seed = 2026, ?CarbonImmutable $now = null): array
    {
        $this->rng = new Randomizer(new Mt19937($seed));
        $now ??= CarbonImmutable::now('America/Bogota')->startOfHour();
        $data = self::VERTICALS[$vertical] ?? self::VERTICALS[self::FALLBACK];
        if (! isset(self::VERTICALS[$vertical])) {
            $data['name'] = (string) __('onboarding.verticals.'.$vertical.'.name').' Demo';
            $data['host'] = 'demo.'.Str::slug($vertical).'.servicios.co';
            $data['industry'] = null;
        }
        $workspace = $this->workspace($data['name'], $data['industry']);

        return $this->current->run($workspace, function () use ($workspace, $vertical, $data, $contacts, $now): array {
            if (Lead::query()->exists()) {
                return ['workspace' => $workspace, 'created' => false, 'contacts' => Contact::query()->count(), 'leads' => Lead::query()->count(),
                    'appointments' => Appointment::query()->count(), 'quotes' => Quote::query()->count(), 'conversations' => Conversation::query()->count()];
            }

            return DB::transaction(fn (): array => $this->seed($workspace, $vertical, $data, $contacts, $now));
        });
    }

    private function workspace(string $name, ?string $industry): Workspace
    {
        $user = User::query()->firstOrCreate(['email' => 'demo@growth.test'], ['name' => 'Dueño demo', 'password' => Hash::make('demo-growth-2026')]);
        $workspace = $user->workspaces()->where('workspaces.name', $name)->first();
        if (! $workspace instanceof Workspace) {
            $workspace = $this->create->handle($user, new WorkspaceData(name: $name, industry: $industry));
        }
        if (PlanCatalog::ensure('servicios_pro')) {
            DB::table('accounts')->where('id', $workspace->account_id)->whereNull('plan_id')->update(['plan_id' => 'servicios_pro', 'updated_at' => now()]);
            app(PlanResolver::class)->refresh($workspace);
        }

        return $workspace;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{workspace: Workspace, created: bool, contacts: int, leads: int, appointments: int, quotes: int, conversations: int}
     */
    private function seed(Workspace $workspace, string $vertical, array $d, int $count, CarbonImmutable $now): array
    {
        $tz = 'America/Bogota';
        $ownerId = (string) $workspace->users()->value('users.id');
        $workspace->forceFill(['vertical' => $vertical, 'country' => 'CO', 'timezone' => $tz, 'currency' => 'COP', 'settings' => array_replace((array) ($workspace->settings ?? []), [
            'business_profile' => ['name' => $d['name'], 'address' => $d['address'], 'contact' => 'hola@'.preg_replace('/^demo\./', '', $d['host']), 'whatsapp' => '573001234567', 'logo_url' => ''],
        ])])->save();
        $this->defaults->handle($workspace, $vertical);
        $pipeline = Pipelines::default($workspace->id);
        $site = Site::query()->create([
            'workspace_id' => $workspace->id, 'platform' => 'wordpress', 'name' => $d['name'], 'url' => 'https://'.$d['host'], 'host' => $d['host'], 'domains' => [$d['host']],
            'public_key' => SiteKeys::publicKey(), 'secret' => SiteKeys::secret(), 'status' => Site::STATUS_CONNECTED, 'timezone' => $tz, 'currency' => 'COP', 'connected_at' => $now->subMonths(7),
        ]);
        LeadSeller::query()->create(['workspace_id' => $workspace->id, 'user_id' => $ownerId, 'phone' => '+573001112233', 'services' => [], 'active' => true]);
        $wa = ChannelAccount::query()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud', 'display_name' => 'WhatsApp (demo)',
            'status' => 'disconnected', 'is_default' => true, 'external_id' => 'demo-'.Str::lower((string) Str::ulid()), 'identity' => '+57 300 123 4567', 'config' => []]);

        // Agenda: dos personas, horario de lunes a sábado y los servicios de la vertical.
        $staff = [];
        foreach (['Laura Gómez', 'Andrés Restrepo'] as $name) {
            $staff[] = BookingStaff::query()->create(['workspace_id' => $workspace->id, 'name' => $name, 'timezone' => $tz, 'active' => true]);
        }
        foreach ([1, 2, 3, 4, 5] as $day) {
            BookingAvailability::query()->create(['workspace_id' => $workspace->id, 'staff_id' => null, 'weekday' => $day, 'start_time' => '08:00', 'end_time' => '12:00']);
            BookingAvailability::query()->create(['workspace_id' => $workspace->id, 'staff_id' => null, 'weekday' => $day, 'start_time' => '14:00', 'end_time' => '18:00']);
        }
        BookingAvailability::query()->create(['workspace_id' => $workspace->id, 'staff_id' => null, 'weekday' => 6, 'start_time' => '08:00', 'end_time' => '12:00']);
        $services = [];
        foreach ($d['services'] as [$name, $minutes, $price, $location]) {
            $services[] = BookingService::query()->create(['workspace_id' => $workspace->id, 'name' => $name, 'slug' => Str::slug($name), 'duration_min' => $minutes, 'buffer_min' => 0,
                'price' => $price > 0 ? (string) $price : null, 'location' => $location, 'min_notice_hours' => 2, 'max_days_ahead' => 60, 'active' => true]);
        }
        $calculator = QuoteCalculator::query()->create(['workspace_id' => $workspace->id, 'site_id' => $site->id, 'name' => $d['calculator']['name'], 'kind' => 'service', 'status' => 'active',
            'capture' => QuoteCapture::defaults(), 'definition' => QuoteEngine::normalize(['base_price' => 0, 'minimum' => 0, 'range_pct' => 10, 'round_to' => 10000, 'fields' => [
                ['key' => 'servicio', 'label' => 'Servicio', 'type' => 'select', 'options' => array_map(fn (array $o) => ['value' => Str::slug($o[0], '_'), 'label' => $o[0], 'price' => $o[1]], $d['calculator']['options'])],
            ]], 'service')]);
        $forms = [];
        foreach ($d['forms'] as $name) {
            $forms[] = OnsiteWidget::query()->create(['workspace_id' => $workspace->id, 'site_id' => $site->id, 'name' => $name, 'kind' => 'embedded_form', 'status' => 'active',
                'content' => ['title' => $name, 'button' => 'Enviar'], 'consent_config' => ['channels' => ['email', 'whatsapp']], 'created_by' => $ownerId]);
        }

        // Dos automatizaciones de la biblioteca (en borrador: la demo no envía nada) con los mensajes que ayudaron a cerrar.
        $flows = [];
        foreach (['service_lead', $vertical === 'health' ? 'appointment_confirmation' : 'quote_followup'] as $key) {
            $flows[] = app(CreateFlowFromLibrary::class)->handle($key, false, $ownerId)['flow'];
        }
        $open = array_values(array_filter($pipeline->stages, fn (array $s) => $s['kind'] === 'open'));
        $counts = ['contacts' => 0, 'leads' => 0, 'appointments' => 0, 'quotes' => 0, 'conversations' => 0];
        for ($i = 0; $i < $count; $i++) {
            $created = $now->subMinutes($this->int(30, 180 * 24 * 60))->setTimezone($tz);
            $created = $this->businessHour($created);
            $contact = $this->contact($workspace, $i, $created, $forms);
            $counts['contacts']++;
            if ($this->float() > 0.86) {
                continue; // contactos sin lead (suscritos a novedades)
            }
            [$origin, $channel] = $this->origin();
            $form = $origin === 'form' ? $forms[$i % count($forms)] : null;
            $ask = $d['asks'][$this->int(0, count($d['asks']) - 1)];
            $value = (string) (round($this->int($d['values'][0], $d['values'][1]) / 10000) * 10000);
            $age = (int) $created->diffInDays($now);
            // Más tiempo, más probable que ya se haya cerrado.
            $roll = $this->float();
            $outcome = $roll < min(0.38, 0.06 + $age / 400) ? 'won' : ($roll < min(0.62, 0.14 + $age / 260) ? 'lost' : 'open');
            $depth = $this->int(0, count($open) - 1);
            $path = array_column(array_slice($open, 0, $depth + 1), 'key');
            $final = $outcome === 'open' ? $path[count($path) - 1] : $outcome;
            $responded = $origin !== 'manual' && $this->float() < 0.85;
            $firstResponse = $responded ? $created->addMinutes($this->float() < 0.6 ? $this->int(1, 15) : $this->int(16, 720)) : null;
            $lead = new Lead;
            $lead->forceFill([
                'workspace_id' => $workspace->id, 'pipeline_id' => $pipeline->id, 'contact_id' => $contact->id, 'site_id' => $site->id,
                'stage' => $final, 'service' => $ask, 'value_ws' => $value, 'owner_id' => $ownerId, 'origin' => $origin,
                'origin_ref' => $form !== null ? $form->publicId().':'.$i : null,
                'source' => array_filter(['channel' => $channel, 'form_id' => $form?->publicId(), 'form_name' => $form?->name,
                    'utm_source' => match ($channel) {
                        'paid_social' => 'facebook', 'paid_search' => 'google', default => null
                    },
                    'utm_campaign' => in_array($channel, ['paid_social', 'paid_search'], true) ? 'servicios-'.Str::slug($ask) : null]),
                'score' => $this->int(5, 95), 'temperature' => ['cold', 'warm', 'hot'][$this->int(0, 2)], 'score_reasons' => [],
                'first_response_at' => $firstResponse?->utc(), 'created_at' => $created->utc(), 'updated_at' => $created->utc(), 'last_activity_at' => $created->utc(),
            ]);
            $lead->save();
            $counts['leads']++;
            $at = $created;
            $activities = [['kind' => 'created', 'actor' => 'system', 'data' => ['origin' => $origin], 'created_at' => $at]];
            $from = (string) $path[0];
            foreach ([...array_slice($path, 1), ...($outcome !== 'open' ? [$outcome] : [])] as $to) {
                $at = $at->addHours($this->int(3, 24 * 9));
                $activities[] = ['kind' => 'stage', 'actor' => (string) $ownerId, 'data' => array_filter(['from' => $from, 'to' => $to, 'reason' => $to === 'lost' ? $d['lost'][$this->int(0, count($d['lost']) - 1)] : null]), 'created_at' => $at];
                $from = $to;
            }
            $closedAt = $at->greaterThan($now) ? $now->subHour() : $at;
            $lead->forceFill([
                'stage_changed_at' => $closedAt->utc(), 'last_activity_at' => $closedAt->utc(),
                'won_at' => $outcome === 'won' ? $closedAt->utc() : null, 'lost_at' => $outcome === 'lost' ? $closedAt->utc() : null,
                'lost_reason' => $outcome === 'lost' ? (string) (end($activities)['data']['reason'] ?? '') : null,
            ])->save();
            foreach ($activities as $a) {
                DB::table('lead_activities')->insert(['workspace_id' => $workspace->id, 'lead_id' => $lead->id, 'kind' => $a['kind'], 'actor' => $a['actor'],
                    'data' => json_encode($a['data'], JSON_UNESCAPED_UNICODE), 'created_at' => ($a['created_at']->greaterThan($now) ? $now : $a['created_at'])->utc()]);
            }
            $contact->forceFill(['lifecycle_stage' => $outcome === 'won' ? 'customer' : 'lead'])->save();
            if ($outcome === 'won' && $this->float() < 0.35) {
                $flow = $flows[$this->int(0, count($flows) - 1)];
                $clicked = $closedAt->subHours($this->int(2, 72));
                $message = new Message;
                $message->forceFill(['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'channel' => 'email', 'direction' => 'out', 'kind' => 'marketing',
                    'source_type' => 'flow', 'source_id' => $flow->id, 'flow_id' => $flow->id, 'status' => 'delivered', 'idempotency_key' => 'demo:flow:'.$lead->id,
                    'queued_at' => $clicked->subHour()->utc(), 'sent_at' => $clicked->subHour()->utc(), 'delivered_at' => $clicked->subHour()->utc(), 'opened_at' => $clicked->utc(),
                    'clicked_at' => $clicked->utc(), 'created_at' => $clicked->subHour()->utc(), 'updated_at' => $clicked->utc()])->save();
            }

            // Citas: quien agendó o pasó por la etapa de la cita; las pasadas cumplidas o no asistidas, las futuras agendadas.
            $hadAppointment = $origin === 'booking' || count(array_intersect($path, ['appointment', 'attended', 'visit', 'trial', 'meeting'])) > 0 || ($outcome === 'won' && $this->float() < 0.6);
            if ($hadAppointment) {
                $start = $this->businessHour($created->addDays($this->int(1, 12)));
                if ($start->greaterThan($now->addDays(25))) {
                    $start = $this->businessHour($now->addDays($this->int(1, 20)));
                }
                $status = $start->greaterThan($now) ? ($this->float() < 0.45 ? 'confirmed' : 'booked')
                    : ($this->float() < 0.1 ? 'cancelled' : ($this->float() < 0.83 || $outcome === 'won' ? 'completed' : 'no_show'));
                $service = $services[$this->int(0, count($services) - 1)];
                Appointment::query()->create(['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'service_id' => $service->id,
                    'staff_id' => $staff[$this->int(0, count($staff) - 1)]->id, 'lead_id' => $lead->id, 'starts_at' => $start->utc(), 'ends_at' => $start->addMinutes($service->duration_min)->utc(),
                    'status' => $status, 'channel' => $origin === 'booking' ? 'page' : 'manual', 'manage_token' => Str::random(40)])->forceFill(['created_at' => $created->utc()])->save();
                $counts['appointments']++;
            }
            if ($origin === 'quote') {
                $option = $d['calculator']['options'][$this->int(0, count($d['calculator']['options']) - 1)];
                $quote = new Quote;
                $quote->forceFill(['workspace_id' => $workspace->id, 'calculator_id' => $calculator->id, 'contact_id' => $contact->id, 'lead_id' => $lead->id,
                    'answers' => ['servicio' => Str::slug($option[0], '_')], 'estimate_min' => number_format($option[1] * 0.9, 2, '.', ''), 'estimate_max' => number_format($option[1] * 1.1, 2, '.', ''),
                    'currency' => 'COP', 'created_at' => $created->utc(), 'updated_at' => $created->utc()])->save();
                $counts['quotes']++;
            }
            if ($origin === 'whatsapp') {
                $this->conversation($workspace, $wa, $contact, $d['chats'][$this->int(0, count($d['chats']) - 1)], $ask, $created, $firstResponse, $now);
                $counts['conversations']++;
            }
        }

        return ['workspace' => $workspace->refresh(), 'created' => true] + $counts;
    }

    /** @param list<OnsiteWidget> $forms */
    private function contact(Workspace $workspace, int $i, CarbonImmutable $created, array $forms): Contact
    {
        $first = ColombiaData::FIRST_NAMES[$this->int(0, count(ColombiaData::FIRST_NAMES) - 1)];
        $last = ColombiaData::LAST_NAMES[$this->int(0, count(ColombiaData::LAST_NAMES) - 1)];
        [$city, $region] = $this->city();
        $email = strtolower(Str::ascii($first).'.'.Str::ascii($last)).$i.'@correo.test';
        $phone = '+573'.$this->int(0, 2).$this->int(0, 9).$this->int(1000000, 9999999);
        $contact = new Contact;
        $contact->forceFill([
            'workspace_id' => $workspace->id, 'first_name' => $first, 'last_name' => $last, 'email' => $email, 'email_bidx' => BlindIndex::email($workspace->id, $email),
            'phone' => $phone, 'phone_bidx' => BlindIndex::phone($workspace->id, $phone), 'city' => $city, 'region_code' => $region, 'country' => 'CO',
            'lifecycle_stage' => 'subscriber', 'first_seen_at' => $created->utc(), 'last_seen_at' => $created->utc(), 'created_at' => $created->utc(), 'updated_at' => $created->utc(),
        ])->save();
        // Consentimiento por formulario (casillas sin marcar, Ley 1581): la mayoría acepta correo y una parte WhatsApp.
        $form = $forms[$i % count($forms)];
        foreach (['email' => 0.78, 'whatsapp' => 0.6] as $channel => $share) {
            if ($this->float() >= $share) {
                continue;
            }
            DB::table('contact_consents')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'channel' => $channel,
                'purpose' => 'marketing', 'status' => 'granted', 'source' => 'widget:'.$form->publicId(), 'legal_text_version' => 'v1',
                'granted_at' => $created->utc(), 'created_at' => $created->utc(), 'updated_at' => $created->utc()]);
            DB::table('consent_events')->insert(['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'channel' => $channel, 'purpose' => 'marketing',
                'action' => 'granted', 'source' => 'widget:'.$form->publicId(), 'legal_text_version' => 'v1',
                'evidence' => json_encode(['form_snapshot' => $form->name, 'demo' => true], JSON_UNESCAPED_UNICODE), 'occurred_at' => $created->utc(), 'created_at' => $created->utc()]);
        }

        return $contact;
    }

    private function conversation(Workspace $workspace, ChannelAccount $wa, Contact $contact, string $question, string $ask, CarbonImmutable $at, ?CarbonImmutable $answered, CarbonImmutable $now): void
    {
        $conversation = Conversation::query()->create(['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'channel_account_id' => $wa->id, 'status' => $answered !== null ? 'closed' : 'open',
            'last_inbound_at' => $at->utc(), 'last_outbound_at' => $answered?->utc(), 'last_message_at' => ($answered ?? $at)->utc(),
            'first_response_seconds' => $answered !== null ? (int) $at->diffInSeconds($answered) : null, 'awaiting_since' => $answered === null && $at->greaterThan($now->subDays(2)) ? $at->utc() : null]);
        $messages = [['in', $question, $at]];
        if ($answered !== null) {
            $messages[] = ['out', 'Hola, con gusto te ayudamos con '.mb_strtolower($ask).'. ¿Qué día te queda bien para una cita?', $answered];
            $messages[] = ['in', 'El jueves en la tarde me sirve', $answered->addMinutes($this->int(5, 90))];
        }
        foreach ($messages as [$direction, $text, $when]) {
            $message = new Message;
            $message->forceFill(['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'channel_account_id' => $wa->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud',
                'direction' => $direction, 'kind' => 'service', 'source_type' => 'inbox', 'status' => $direction === 'in' ? 'received' : 'read', 'conversation_id' => $conversation->id,
                'payload' => ['type' => 'text', 'text' => $text], 'queued_at' => $when->utc(), 'sent_at' => $direction === 'out' ? $when->utc() : null,
                'idempotency_key' => 'demo:'.$conversation->id.':'.$direction.':'.$when->getTimestamp(), 'created_at' => $when->utc(), 'updated_at' => $when->utc()])->save();
        }
    }

    /** @return array{0: string, 1: string} origen del lead y canal de la fuente */
    private function origin(): array
    {
        $roll = $this->float();
        $acc = 0.0;
        foreach (self::ORIGINS as [$origin, $weight, $channel]) {
            $acc += $weight;
            if ($roll < $acc) {
                return [$origin, $channel];
            }
        }

        return ['form', 'direct'];
    }

    /** @return array{0: string, 1: string} */
    private function city(): array
    {
        $total = array_sum(array_column(ColombiaData::CITIES, 2));
        $roll = $this->float() * $total;
        foreach (ColombiaData::CITIES as [$city, $region, $weight]) {
            $roll -= $weight;
            if ($roll <= 0) {
                return [$city, $region];
            }
        }

        return ['Bogotá', 'CO-DC'];
    }

    /** Lunes a sábado entre las 8:00 y las 17:00. */
    private function businessHour(CarbonImmutable $at): CarbonImmutable
    {
        $at = $at->setTimezone('America/Bogota');
        if ($at->dayOfWeekIso === 7) {
            $at = $at->addDay();
        }

        return $at->setTime($this->int(8, 16), [0, 15, 30, 45][$this->int(0, 3)]);
    }

    private function int(int $min, int $max): int
    {
        return $this->rng->getInt($min, $max);
    }

    private function float(): float
    {
        return $this->rng->getFloat(0, 1);
    }
}
