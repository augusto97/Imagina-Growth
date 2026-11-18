<?php

/*
 * Lado del hub de la prueba de la Fase 5 (infra/e2e/fase5.sh). Subcomandos:
 *
 *   php fase5-hub.php setup                    → la agencia «Imagina La Web» (dueña con 2FA) con tres clientes:
 *                                                 «Clínica Dental Sonrisa» (sitio corporativo sin WooCommerce, con su
 *                                                 dueño, una vendedora, WhatsApp y correo, Meta y Google Ads, reservas
 *                                                 y cotizador), «Pinturas La 14» (tienda demo) y «Café Sierra» (sin conectar).
 *                                                 Imprime: ws_clínica ws_tienda ws_café código qc_…
 *   php fase5-hub.php lead ws_… correo         → el lead de esa persona: etapa, origen, puntaje, fuente, avisos, citas
 *   php fase5-hub.php quote ws_… correo        → el lead del cotizador y su cotización
 *   php fase5-hub.php remind ws_… correo       → corre el programador de la agenda dentro de las 2 h antes de la cita (y
 *                                                 30 min después de agendarla) y muestra los recordatorios que salieron
 *   php fase5-hub.php appt ws_… correo         → la cita de esa persona (estado y confirmación)
 *   php fase5-hub.php inbound ws_… +57… "texto" id → un WhatsApp entrante por el webhook firmado de Meta (HTTP al hub)
 *   php fase5-hub.php site ws_…                → el sitio conectado: estado, plataforma y versión de WooCommerce (ninguna)
 *   php fase5-hub.php money ws_…               → el dinero generado del mes (la consulta del panel de agencia)
 *
 * Nada de datos personales en la salida: identificadores, conteos, montos y estados.
 */

declare(strict_types=1);

use App\Domain\Agency\Queries\MoneyGenerated;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingAvailability;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Measurement\Enums\DestinationMode;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\WaTemplate;
use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Quotes\Support\QuoteCapture;
use App\Domain\Quotes\Support\QuoteEngine;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadSeller;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Sites\Actions\CreateConnectCode;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Actions\CreateWorkspace;
use App\Domain\Workspaces\Data\WorkspaceData;
use App\Domain\Workspaces\Enums\AccountType;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Console\WorkspaceOption;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$hub = dirname(__DIR__, 2).'/hub';
require $hub.'/vendor/autoload.php';
$app = require $hub.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$cmd = $argv[1] ?? '';
$current = app(CurrentWorkspace::class);
$out = fn (array $data) => print(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
$totp = getenv('TOTP_SECRET') ?: 'JBSWY3DPEHPK3PXP';
$user = function (string $name, string $email, string $password) use ($totp): User {
    $u = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
    $u->forceFill(['two_factor_secret' => encrypt($totp), 'two_factor_confirmed_at' => now()])->save();

    return $u;
};

if ($cmd === 'setup') {
    $agencyOwner = $user('Dueña de la agencia', getenv('AGENCY_EMAIL') ?: 'agencia@imaginalaweb.test', getenv('AGENCY_PASS') ?: 'Clave-agencia-2026');
    $clinicOwner = $user('Dueño de la clínica', getenv('OWNER_EMAIL') ?: 'dueno@clinicasonrisa.test', getenv('OWNER_PASS') ?: 'Clave-clinica-2026');
    $seller = $user('Vendedora de la clínica', 'ventas@clinicasonrisa.test', 'Clave-ventas-2026');
    $agency = Account::query()->create(['type' => AccountType::Agency, 'name' => 'Imagina La Web', 'country' => 'CO', 'status' => 'active']);
    $agency->users()->attach($agencyOwner->id, ['role' => 'agency_owner']);

    // Los clientes viven en la cuenta de la agencia (quien paga); cada uno con su dueño directo.
    $make = function (User $owner, string $name, string $industry) use ($agency): Workspace {
        $ws = app(CreateWorkspace::class)->handle($owner, new WorkspaceData(name: $name, industry: $industry));
        $old = $ws->account_id;
        $ws->forceFill(['account_id' => $agency->id, 'country' => 'CO', 'timezone' => 'America/Bogota', 'currency' => 'COP'])->save();
        if ($old !== $agency->id) {
            DB::table('accounts')->where('id', $old)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('workspaces')->whereColumn('workspaces.account_id', 'accounts.id'))->delete();
        }

        return $ws->refresh();
    };
    $clinic = $make($clinicOwner, 'Clínica Dental Sonrisa', 'salud');
    $store = $make($user('Dueña de la tienda', 'duena@pinturasla14.test', 'Clave-tienda-2026'), 'Pinturas La 14', 'ferreteria_pinturas');
    $cafe = $make($user('Dueño del café', 'dueno@cafesierra.test', 'Clave-cafe-2026'), 'Café Sierra', 'alimentos');
    $clinic->users()->attach($seller->id, ['role' => 'agent']);

    $calculatorId = $current->run($clinic, function () use ($clinic, $seller): string {
        $settings = $clinic->settings ?? [];
        $settings['business_profile'] = ['name' => 'Clínica Dental Sonrisa', 'address' => 'Carrera 23 # 65-10, Manizales, Caldas', 'contact' => 'hola@clinicasonrisa.test · 606 885 0000', 'whatsapp' => '573005550000', 'logo_url' => ''];
        $clinic->forceFill(['settings' => $settings])->save();

        ChannelAccount::query()->create([
            'workspace_id' => $clinic->id, 'channel' => 'email', 'provider' => 'smtp', 'display_name' => 'Correo de la clínica',
            'identity' => 'clinicasonrisa.test', 'status' => 'active', 'is_default' => true,
            'config' => ['host' => '127.0.0.1', 'port' => (int) (getenv('SMTP_PORT') ?: 2527), 'from_email' => 'hola@clinicasonrisa.test', 'from_name' => 'Clínica Dental Sonrisa'],
        ]);
        $wa = ChannelAccount::query()->create([
            'workspace_id' => $clinic->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud', 'display_name' => 'WhatsApp de la clínica',
            'status' => 'active', 'quality_rating' => 'GREEN', 'is_default' => true, 'external_id' => '1111', 'identity' => '+57 300 555 0000',
            'config' => ['waba_id' => '2222', 'phone_number_id' => '1111', 'access_token' => 'EAAB-e2e-token'],
        ]);
        // La plantilla de utilidad que Meta aprobó para avisarle al equipo (library_key team_alert).
        $approved = WaTemplate::query()->create([
            'workspace_id' => $clinic->id, 'channel_account_id' => $wa->id, 'name' => 'aviso_equipo', 'language' => 'es', 'category' => 'UTILITY', 'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Aviso de Imagina Growth: {{1}}']], 'meta_template_id' => '5551',
        ]);
        Template::query()->create([
            'workspace_id' => $clinic->id, 'channel' => 'whatsapp', 'name' => 'Aviso al equipo', 'category' => 'utility', 'status' => 'ready',
            'library_key' => 'team_alert', 'wa_template_id' => $approved->id, 'variables' => ['body' => ['1' => '{{ aviso.texto }}']],
        ]);

        // Medición: Meta (Conversions API) y Google Ads (Data Manager) para la conversión offline del lead ganado.
        AdDestination::query()->create([
            'workspace_id' => $clinic->id, 'platform' => Platform::Meta, 'name' => 'Meta', 'mode' => DestinationMode::Full, 'status' => 'active',
            'config' => ['dataset_id' => '1234567890', 'access_token' => 'EAAB-e2e-fase5'], 'pixel_id' => '1234567890',
        ]);
        AdDestination::query()->create([
            'workspace_id' => $clinic->id, 'platform' => Platform::Google, 'name' => 'Google Ads', 'mode' => DestinationMode::Full, 'status' => 'active',
            'config' => ['ads_customer_id' => '123-456-7890', 'conversion_action_lead_won' => '7770001', 'oauth_client_id' => 'cid-e2e', 'oauth_client_secret' => 'cs-e2e', 'oauth_refresh_token' => 'rt-e2e'],
        ]);

        // Leads: aviso con cada lead nuevo (resumen al vendedor) y reparto a la vendedora.
        $leadSettings = LeadSettings::for($clinic->id);
        $leadSettings->fill(['workspace_id' => $clinic->id, 'alerts' => ['on_hot' => true, 'on_new' => true, 'channel' => 'whatsapp', 'whatsapp_template_id' => null]])->save();
        LeadSeller::query()->create(['workspace_id' => $clinic->id, 'user_id' => $seller->id, 'phone' => (string) getenv('SELLER_PHONE'), 'active' => true]);

        // Agenda: valoración de 30 minutos, todos los días de 6:00 a 22:00 (la prueba corre a cualquier hora).
        BookingService::query()->create([
            'workspace_id' => $clinic->id, 'name' => 'Valoración inicial', 'slug' => 'valoracion', 'duration_min' => 30, 'buffer_min' => 0,
            'price' => '0', 'location' => 'onsite', 'min_notice_hours' => 2, 'max_days_ahead' => 30, 'active' => true,
        ]);
        foreach (range(1, 7) as $day) {
            BookingAvailability::query()->create(['workspace_id' => $clinic->id, 'staff_id' => null, 'weekday' => $day, 'start_time' => '06:00', 'end_time' => '22:00']);
        }

        // Cotizador: diseño de sonrisa por número de dientes.
        $definition = [
            'base_price' => 400000, 'minimum' => 1200000, 'range_pct' => 10, 'round_to' => 10000, 'note' => 'Incluye la valoración y el plan de tratamiento.',
            'internal_notes' => 'Precio de lista 2026',
            'fields' => [
                ['key' => 'dientes', 'label' => 'Número de dientes', 'type' => 'number', 'unit' => 'dientes', 'min' => 2, 'max' => 20, 'unit_price' => 350000],
                ['key' => 'material', 'label' => 'Material', 'type' => 'select', 'options' => [
                    ['value' => 'resina', 'label' => 'Resina', 'price' => 0, 'multiplier' => 1],
                    ['value' => 'porcelana', 'label' => 'Porcelana', 'price' => 0, 'multiplier' => 1.6],
                ]],
            ],
        ];
        $qc = QuoteCalculator::query()->create([
            'workspace_id' => $clinic->id, 'name' => 'Diseño de sonrisa', 'kind' => 'area', 'status' => 'active',
            'capture' => QuoteCapture::defaults(), 'definition' => QuoteEngine::normalize($definition, 'area'),
        ]);

        return $qc->publicId();
    });
    $code = app(CreateConnectCode::class)->handle($clinic, $clinicOwner, [])['code'];
    echo $clinic->publicId(), ' ', $store->publicId(), ' ', $cafe->publicId(), ' ', $code, ' ', $calculatorId, "\n";
    exit(0);
}

$ws = WorkspaceOption::resolve($argv[2] ?? '')->firstOrFail();

exit($current->run($ws, function () use ($cmd, $ws, $argv, $out): int {
    $contactByEmail = fn (string $email) => Contact::query()->where('email_bidx', BlindIndex::email($ws->id, $email))->whereNull('merged_into_id')->first();
    $leadOf = function (string $email) use ($contactByEmail): ?Lead {
        $contact = $contactByEmail($email);

        return $contact === null ? null : Lead::query()->where('contact_id', $contact->id)->orderByDesc('created_at')->first();
    };
    $leadJson = fn (Lead $lead) => [
        'id' => $lead->publicId(), 'stage' => $lead->stage, 'origin' => $lead->origin, 'service' => $lead->service,
        'score' => $lead->score, 'temperature' => $lead->temperature,
        'signals' => array_values(array_unique(array_map(fn ($r) => (string) ($r['signal'] ?? '?'), (array) $lead->score_reasons))),
        'channel' => $lead->source['channel'] ?? null, 'campaign' => $lead->source['campaign'] ?? null,
        'has_gclid' => ! empty($lead->source['click_ids']['gclid'] ?? $lead->source['gclid'] ?? null),
        'owner' => $lead->owner_id !== null ? DB::table('users')->where('id', $lead->owner_id)->value('name') : null,
        'value' => $lead->value_ws, 'won' => $lead->won_at !== null, 'offline_conversion' => $lead->offline_conversion_at !== null,
        'activities' => LeadActivity::query()->where('lead_id', $lead->id)->orderBy('created_at')->get()
            ->map(fn (LeadActivity $a) => $a->kind.(isset($a->data['channel']) ? ':'.$a->data['channel'] : '').(isset($a->data['status']) ? ':'.$a->data['status'] : ''))->all(),
    ];

    switch ($cmd) {
        case 'lead':
            $lead = $leadOf((string) $argv[3]);
            if ($lead === null) {
                $out(['lead' => null]);

                return 1;
            }
            $appointments = Appointment::query()->where('contact_id', $lead->contact_id)->get();
            $out(['lead' => $leadJson($lead), 'appointments' => $appointments->map(fn (Appointment $a) => [
                'status' => $a->status, 'same_lead' => $a->lead_id === $lead->id, 'starts_at' => $a->starts_at?->toIso8601String(),
            ])->all(), 'leads_of_contact' => Lead::query()->where('contact_id', $lead->contact_id)->count()]);

            return 0;

        case 'quote':
            $lead = $leadOf((string) $argv[3]);
            $quote = $lead !== null ? DB::table('quotes')->where('workspace_id', $ws->id)->where('contact_id', $lead->contact_id)->orderByDesc('created_at')->first() : null;
            $out(['lead' => $lead !== null ? $leadJson($lead) : null, 'quote' => $quote === null ? null : [
                'estimate_min' => $quote->estimate_min ?? null, 'estimate_max' => $quote->estimate_max ?? null, 'same_lead' => ($quote->lead_id ?? null) === $lead?->id,
            ]]);

            return $lead !== null && $quote !== null ? 0 : 1;

        case 'appt':
            $contact = $contactByEmail((string) $argv[3]);
            $a = $contact !== null ? Appointment::query()->where('contact_id', $contact->id)->orderByDesc('created_at')->first() : null;
            $out(['appointment' => $a === null ? null : ['status' => $a->status, 'reminders' => array_keys((array) $a->getAttribute('reminders')), 'starts_at' => $a->starts_at?->toIso8601String()]]);

            return $a !== null ? 0 : 1;

        case 'remind':
            $contact = $contactByEmail((string) $argv[3]) ?? throw new RuntimeException('No existe ese contacto');
            $a = Appointment::query()->where('contact_id', $contact->id)->orderByDesc('created_at')->firstOrFail();
            // Dentro de las 2 h antes de la cita y al menos 30 min después de agendarla (antes de eso el
            // recordatorio no sale: la persona acaba de recibir la confirmación).
            $start = CarbonImmutable::parse($a->starts_at);
            $now = $start->subMinutes(119)->max(CarbonImmutable::parse($a->created_at)->addMinutes(31));
            if ($now->greaterThanOrEqualTo($start)) {
                throw new RuntimeException('La cita empieza antes de que pueda salir el recordatorio');
            }
            Artisan::call('igr:bookings:tick', ['--now' => $now->toIso8601String()]);
            $sent = DB::table('messages')->where('workspace_id', $ws->id)->where('contact_id', $contact->id)->where('source_type', 'system')->where('source_id', $a->publicId())
                ->orderBy('created_at')->get(['channel', 'kind', 'status']);
            $out(['now' => $now->toIso8601String(), 'tick' => trim(Artisan::output()), 'reminders' => array_keys((array) $a->fresh()?->getAttribute('reminders')),
                'messages' => $sent->map(fn ($m) => $m->channel.':'.$m->kind.':'.$m->status)->all()]);

            return $sent->isNotEmpty() ? 0 : 1;

        case 'inbound':
            $from = ltrim((string) $argv[3], '+');
            $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '2222', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1111', 'display_phone_number' => '573005550000'],
                'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Paciente E2E']]],
                'messages' => [['from' => $from, 'id' => (string) $argv[5], 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => (string) $argv[4]]]],
            ]]]]]];
            $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
            $res = Http::withBody($body, 'application/json')
                ->withHeaders(['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, (string) config('igr-messaging.meta.app_secret'))])
                ->post(rtrim((string) getenv('HUB'), '/').'/webhooks/meta');
            $out(['status' => $res->status()]);

            return $res->successful() ? 0 : 1;

        case 'site':
            $site = DB::table('sites')->where('workspace_id', $ws->id)->orderByDesc('connected_at')->first();
            $out(['site' => $site === null ? null : ['status' => $site->status, 'platform' => $site->platform, 'wc_version' => $site->wc_version ?? null,
                'tracker' => (bool) (json_decode((string) ($site->settings ?? '{}'), true)['tracker']['enabled'] ?? true)]]);

            return $site !== null ? 0 : 1;

        case 'money':
            [$from, $to] = MoneyGenerated::month($ws);
            $m = app(MoneyGenerated::class)->forWorkspace($ws, $from, $to);
            $out(['total' => $m['total'], 'orders' => $m['orders'], 'store_total' => $m['store_total'], 'top' => $m['modules'][0]['key'] ?? null,
                'modules' => array_values(array_filter($m['modules'], fn ($x) => (float) $x['revenue'] > 0))]);

            return 0;
    }
    fwrite(STDERR, "Subcomando desconocido: {$cmd}\n");

    return 2;
}));
