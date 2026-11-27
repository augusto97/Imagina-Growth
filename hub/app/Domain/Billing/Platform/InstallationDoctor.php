<?php

declare(strict_types=1);

namespace App\Domain\Billing\Platform;

use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Bookings\Google\GoogleCalendar;
use App\Domain\Campaigns\Support\WaRateCard;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Support\SesClientFactory;
use App\Domain\Messaging\Support\SesEventSetup;
use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Support\PluginRelease;
use App\Domain\Sites\Support\SiteSignatures;
use App\Domain\Workspaces\Models\User;
use App\Support\Hmac\RequestSignature;
use App\Support\Money\ExchangeRates;
use App\Support\Money\UpdateExchangeRates;
use App\Support\Monitor\SystemProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Diagnóstico de la instalación (F3, F15): qué falta configurar y cómo arreglarlo. Lo usan `igr:doctor` y la
 * pestaña «Instalación» de Plataforma. Solo lee: no cambia nada ni muestra secretos (dice si están, nunca su valor).
 */
final class InstallationDoctor
{
    /** Clave de caché que el scheduler renueva cada minuto. */
    public const SCHEDULER_HEARTBEAT = 'igr:scheduler:heartbeat';

    public function __construct(private readonly SystemProbe $probe, private readonly SesClientFactory $ses) {}

    /** @return list<DoctorCheck> */
    public function run(): array
    {
        return [
            ...$this->installation(),
            ...$this->services(),
            ...$this->email(),
            ...$this->whatsapp(),
            ...$this->integrations(),
            ...$this->data(),
        ];
    }

    /**
     * error si algo impide funcionar, warning si falta algo prometido, si no ok.
     *
     * @param  list<DoctorCheck>  $checks
     */
    public static function overall(array $checks): string
    {
        $statuses = array_map(fn (DoctorCheck $c) => $c->status, $checks);

        return in_array(DoctorCheck::ERROR, $statuses, true) ? DoctorCheck::ERROR : (in_array(DoctorCheck::WARNING, $statuses, true) ? DoctorCheck::WARNING : DoctorCheck::OK);
    }

    /** @return list<DoctorCheck> */
    private function installation(): array
    {
        $production = app()->environment('production');
        $checks = [];
        $checks[] = (string) config('app.key') === ''
            ? new DoctorCheck('app.key', 'installation', DoctorCheck::ERROR, __('Falta la llave de la aplicación'), __('Sin APP_KEY no se pueden cifrar sesiones ni datos. La crea el instalador una sola vez y no se cambia: vuelve a correr el instalador en el servidor (no pisa lo que ya existe).'))
            : new DoctorCheck('app.key', 'installation', DoctorCheck::OK, __('Llave de la aplicación configurada'));
        $checks[] = (string) config('igr.blind_index_key') === ''
            ? new DoctorCheck('app.blind_index', 'installation', DoctorCheck::ERROR, __('Falta la llave de búsqueda de datos cifrados'), __('Sin IGR_BLIND_INDEX_KEY no se pueden buscar contactos por correo o teléfono. La crea el instalador una sola vez y no se cambia.'))
            : new DoctorCheck('app.blind_index', 'installation', DoctorCheck::OK, __('Llave de búsqueda de datos cifrados configurada'));
        if ($production && (bool) config('app.debug')) {
            $checks[] = new DoctorCheck('app.debug', 'installation', DoctorCheck::ERROR, __('El modo de depuración está prendido en producción'), __('Con APP_DEBUG=true los errores muestran datos internos. Pon APP_DEBUG=false en el .env.'));
        }
        $url = (string) config('app.url');
        $checks[] = $production && ! str_starts_with($url, 'https://')
            ? new DoctorCheck('app.url', 'installation', DoctorCheck::WARNING, __('La dirección del panel no usa HTTPS'), __('APP_URL debe ser la dirección pública con https:// (los enlaces de invitación y de recuperación de contraseña salen de ahí).'))
            : new DoctorCheck('app.url', 'installation', DoctorCheck::OK, __('Dirección del panel: :url', ['url' => $url]));
        $checks[] = (string) config('igr.health.token') === ''
            ? new DoctorCheck('app.health_token', 'installation', DoctorCheck::INFO, __('El detalle de /health solo se ve desde el servidor'), __('Para ver el detalle desde un monitor externo, genera el token en Plataforma → Ajustes → Registro y seguridad y envíalo en el encabezado X-IGR-Health-Token. Sin token, en público solo responde si está bien o no.'), [], DoctorCheck::settings('security'))
            : new DoctorCheck('app.health_token', 'installation', DoctorCheck::OK, __('Token del detalle de /health configurado'));

        return $checks;
    }

    /** @return list<DoctorCheck> */
    private function services(): array
    {
        $checks = [];
        $names = ['mariadb' => 'MariaDB', 'clickhouse' => 'ClickHouse', 'redis' => 'Redis'];
        foreach ($this->probe->dependencies() as $dep => $ok) {
            $name = $names[$dep] ?? $dep;
            $checks[] = $ok
                ? new DoctorCheck("services.{$dep}", 'services', DoctorCheck::OK, __(':name responde', ['name' => $name]))
                : new DoctorCheck("services.{$dep}", 'services', DoctorCheck::ERROR, __(':name no responde', ['name' => $name]), __('Revisa el servicio en el servidor (systemctl status) y los datos de conexión del .env.'));
        }

        $horizon = $this->probe->horizon();
        $checks[] = $horizon === 'running'
            ? new DoctorCheck('services.horizon', 'services', DoctorCheck::OK, __('Las colas están corriendo (Horizon)'))
            : new DoctorCheck('services.horizon', 'services', DoctorCheck::ERROR, $horizon === 'paused' ? __('Las colas están en pausa (Horizon)') : __('Las colas no están corriendo (Horizon)'),
                __('Sin colas no salen mensajes, no avanzan los flujos ni se envían conversiones. En el servidor: sudo systemctl restart growth-horizon.'));

        $heartbeat = Cache::get(self::SCHEDULER_HEARTBEAT);
        $age = is_numeric($heartbeat) ? now()->getTimestamp() - (int) $heartbeat : null;
        $checks[] = $age !== null && $age < 300
            ? new DoctorCheck('services.scheduler', 'services', DoctorCheck::OK, __('Las tareas programadas están corriendo'))
            : new DoctorCheck('services.scheduler', 'services', DoctorCheck::ERROR, __('Las tareas programadas no están corriendo'),
                __('Sin el programador no se detectan carritos abandonados, no salen campañas ni informes. En el servidor: sudo systemctl status growth-scheduler.'));

        $queues = $this->probe->queues();
        $queued = $queues === null ? null : array_sum($queues);
        $max = (int) config('igr-monitor.max_queue_backlog', 10000);
        if ($queued !== null) {
            $checks[] = $queued < $max
                ? new DoctorCheck('services.queue_backlog', 'services', DoctorCheck::OK, __('Colas al día (:n trabajos en espera)', ['n' => number_format($queued, 0, ',', '.')]))
                : new DoctorCheck('services.queue_backlog', 'services', DoctorCheck::WARNING, __('Colas atrasadas: :n trabajos en espera', ['n' => number_format($queued, 0, ',', '.')]),
                    __('El servidor no da abasto. Revisa en Horizon qué cola crece y si un proveedor está fallando.'),
                    array_values(array_map(fn (string $q, int $n) => "{$q}: {$n}", array_keys($queues), $queues)));
        }
        $failed = $this->failedJobs();
        if ($failed !== null) {
            $checks[] = $failed === 0
                ? new DoctorCheck('services.failed_jobs', 'services', DoctorCheck::OK, __('Sin trabajos fallidos en las últimas 24 horas'))
                : new DoctorCheck('services.failed_jobs', 'services', DoctorCheck::WARNING, trans_choice(':n trabajo falló en las últimas 24 horas|:n trabajos fallaron en las últimas 24 horas', $failed, ['n' => $failed]),
                    __('Revísalos en Horizon (/horizon → Trabajos fallidos) y reintenta ahí los que correspondan.'));
        }

        $streams = $this->probe->streams();
        if ($streams !== null) {
            $oldest = max([0, ...array_column($streams, 'oldest_seconds')]);
            $backlog = array_sum(array_map(fn (array $s) => (int) $s['lag'] + (int) $s['pending'], $streams));
            $late = $oldest >= (int) config('igr-monitor.max_stream_age_seconds', 120) || $backlog >= (int) config('igr-monitor.max_stream_lag', 5000);
            $checks[] = $late
                ? new DoctorCheck('services.ingest', 'services', DoctorCheck::WARNING, __('La ingesta va atrasada (:s s)', ['s' => $oldest]), __('Los eventos de los sitios tardan en verse. En el servidor: sudo systemctl status "growth-ingest@*".'))
                : new DoctorCheck('services.ingest', 'services', DoctorCheck::OK, __('La ingesta de eventos está al día'));
        }

        return $checks;
    }

    /** @return list<DoctorCheck> */
    private function email(): array
    {
        $checks = [];
        $mailer = (string) config('mail.default');
        $checks[] = in_array($mailer, ['log', 'array'], true)
            ? new DoctorCheck('email.mailer', 'email', DoctorCheck::WARNING, __('El correo del sistema está apagado'),
                __('Con el correo en «:m» no salen las invitaciones, la recuperación de contraseña ni los informes (sí se puede copiar el enlace de invitación). Configúralo en Plataforma → Ajustes → Correo (SMTP o Amazon SES) y prueba con «Enviar correo de prueba».', ['m' => $mailer]), [], DoctorCheck::settings('mail'))
            : new DoctorCheck('email.mailer', 'email', DoctorCheck::OK, __('Correo del sistema configurado (:m)', ['m' => $mailer]));
        if ($mailer === 'ses' && ! $this->ses->configured()) {
            $checks[] = new DoctorCheck('email.mailer_ses', 'email', DoctorCheck::ERROR, __('El correo del sistema usa Amazon SES, pero SES no está configurado'),
                __('Pon la llave y el secreto de AWS en Plataforma → Ajustes → Amazon SES, o elige otra forma de envío en Ajustes → Correo.'), [], DoctorCheck::settings('ses'));
        } elseif ($mailer === 'smtp' && (string) config('mail.mailers.smtp.host') === '') {
            $checks[] = new DoctorCheck('email.mailer_smtp', 'email', DoctorCheck::ERROR, __('Falta el servidor de correo (SMTP)'),
                __('Escribe el servidor, el puerto, el usuario y la contraseña de tu proveedor en Plataforma → Ajustes → Correo.'), [], DoctorCheck::settings('mail'));
        }
        $from = (string) config('mail.from.address');
        if ($from === '' || str_ends_with($from, '@example.com')) {
            $checks[] = new DoctorCheck('email.from', 'email', DoctorCheck::WARNING, __('Falta el remitente del correo del sistema'), __('Escribe el correo y el nombre del remitente, con un dominio verificado, en Plataforma → Ajustes → Correo.'), [], DoctorCheck::settings('mail'));
        }

        if (! $this->ses->configured()) {
            $checks[] = new DoctorCheck('email.ses', 'email', DoctorCheck::INFO, __('Amazon SES no está configurado'),
                __('Los clientes solo pueden enviar correo por su propio SMTP. Para enviar con la cuenta de SES de la plataforma, pon la llave, el secreto y la región de AWS y el tema de SNS en Plataforma → Ajustes → Amazon SES.'), [], DoctorCheck::settings('ses'));
        } else {
            $checks[] = new DoctorCheck('email.ses', 'email', DoctorCheck::OK, __('Amazon SES configurado (configuration set :set)', ['set' => (string) config('igr-messaging.ses.configuration_set')]));
            $topics = array_filter(array_map('trim', explode(',', (string) config('igr-messaging.ses.sns_topic_arn'))));
            $checks[] = $topics === []
                ? new DoctorCheck('email.sns_topic', 'email', DoctorCheck::ERROR, __('Falta el tema de SNS de los eventos de SES'),
                    __('Sin el tema de SNS los avisos de SES (entregas, rebotes y quejas) se rechazan y no se suprimen los correos que rebotan. Pon el ARN del tema en Plataforma → Ajustes → Amazon SES y suscribe :url.', ['url' => rtrim((string) config('app.url'), '/').'/webhooks/ses']), [], DoctorCheck::settings('ses'))
                : new DoctorCheck('email.sns_topic', 'email', DoctorCheck::OK, __('Eventos de SES desde el tema configurado'));
            $checks[] = $this->sesEvents();
        }

        return $checks;
    }

    /** @return list<DoctorCheck> */
    private function whatsapp(): array
    {
        $checks = [];
        $metaAccounts = $this->count(fn () => ChannelAccount::withoutWorkspaceScope()->whereIn('provider', ['meta_cloud', 'meta_messenger', 'meta_instagram'])->where('status', '!=', 'disconnected')->count());
        $webhook = rtrim((string) config('app.url'), '/').'/webhooks/meta';
        $hasMetaApp = (string) config('igr-messaging.meta.app_id') !== '';
        if ((string) config('igr-messaging.meta.app_secret') === '' && ! $hasMetaApp && $metaAccounts === 0) {
            // Sin app de Meta y sin cuentas de Meta no hay webhooks que verificar: no es algo «por configurar».
            $checks[] = new DoctorCheck('whatsapp.app_secret', 'whatsapp', DoctorCheck::INFO, __('Sin el secreto de la app de Meta'),
                __('Solo hace falta para WhatsApp oficial, Messenger, Instagram o los leads de anuncios de Meta, y hoy no hay ninguno conectado. Cuando crees la app, copia su clave secreta (Configuración → Básica) a Plataforma → Ajustes → Meta.'), [], DoctorCheck::settings('meta'));
        } elseif ((string) config('igr-messaging.meta.app_secret') === '') {
            $checks[] = new DoctorCheck('whatsapp.app_secret', 'whatsapp', $metaAccounts > 0 ? DoctorCheck::ERROR : DoctorCheck::WARNING, __('Falta el secreto de la app de Meta'),
                __('Sin el secreto de la app de Meta los webhooks responden 403: no llegan los estados de los mensajes ni los mensajes entrantes de WhatsApp, Messenger e Instagram. Cópialo de la app de Meta (Configuración → Básica → Clave secreta) a Plataforma → Ajustes → Meta.'), [], DoctorCheck::settings('meta'));
        } else {
            $checks[] = new DoctorCheck('whatsapp.app_secret', 'whatsapp', DoctorCheck::OK, __('Secreto de la app de Meta configurado'));
        }
        $checks[] = (string) config('igr-messaging.meta.webhook_verify_token') === ''
            ? new DoctorCheck('whatsapp.verify_token', 'whatsapp', DoctorCheck::WARNING, __('Falta el token de verificación del webhook de Meta'),
                __('Genera el token en Plataforma → Ajustes → Meta y úsalo al registrar :url en la app de Meta.', ['url' => $webhook]), [], DoctorCheck::settings('meta'))
            : new DoctorCheck('whatsapp.verify_token', 'whatsapp', DoctorCheck::OK, __('Webhook de Meta: :url', ['url' => $webhook]));
        if (! $hasMetaApp) {
            $checks[] = new DoctorCheck('whatsapp.app_id', 'whatsapp', DoctorCheck::INFO, __('Sin app de Meta para conectar WhatsApp con un clic'),
                __('Mientras no esté la app de Meta (Plataforma → Ajustes → Meta), los clientes conectan su número a mano (identificador de la cuenta, del número y un token permanente).'), [], DoctorCheck::settings('meta'));
        }
        $rates = WaRateCard::latestEffectiveFrom();
        $checks[] = $rates === null
            ? new DoctorCheck('whatsapp.rates', 'whatsapp', DoctorCheck::WARNING, __('Faltan las tarifas de WhatsApp'), __('Sin ellas las campañas no estiman su costo. Cárgalas en Plataforma → Ajustes → Tarifas de WhatsApp.'), [], DoctorCheck::settings('wa_rates'))
            : (WaRateCard::isStale()
                ? new DoctorCheck('whatsapp.rates', 'whatsapp', DoctorCheck::WARNING, __('Las tarifas de WhatsApp son del :date', ['date' => $rates->toDateString()]), __('Meta las cambia cada trimestre: revisa :url y actualízalas en Plataforma → Ajustes → Tarifas de WhatsApp.', ['url' => WaRateCard::SOURCE_URL]), [], DoctorCheck::settings('wa_rates'))
                : new DoctorCheck('whatsapp.rates', 'whatsapp', DoctorCheck::OK, __('Tarifas de WhatsApp vigentes desde el :date', ['date' => $rates->toDateString()])));

        return $checks;
    }

    /** @return list<DoctorCheck> */
    private function integrations(): array
    {
        $checks = [];
        if (! (bool) config('igr-ai.enabled')) {
            $checks[] = new DoctorCheck('ai.key', 'integrations', DoctorCheck::INFO, __('La IA está apagada'), __('Está apagada en Plataforma → Ajustes → IA: los informes y resúmenes usan plantillas sin IA.'), [], DoctorCheck::settings('ai'));
        } else {
            $checks[] = (string) config('igr-ai.api_key') === ''
                ? new DoctorCheck('ai.key', 'integrations', DoctorCheck::WARNING, __('Falta la clave de Anthropic'), __('Sin la clave de Anthropic la IA queda apagada: el analista semanal, «Pregúntale a tus datos» y el agente de ventas no funcionan, y los informes usan plantillas. Pégala en Plataforma → Ajustes → IA.'), [], DoctorCheck::settings('ai'))
                : new DoctorCheck('ai.key', 'integrations', DoctorCheck::OK, __('Clave de Anthropic configurada'));
        }
        $checks[] = GoogleCalendar::configured()
            ? new DoctorCheck('integrations.google_calendar', 'integrations', DoctorCheck::OK, __('Google Calendar configurado para la agenda'))
            : new DoctorCheck('integrations.google_calendar', 'integrations', DoctorCheck::INFO, __('Google Calendar no está configurado'),
                __('La agenda funciona sin él, pero no revisa la disponibilidad del calendario de cada persona. Pon el cliente de Google en Plataforma → Ajustes → Google (el mismo sirve para Google Ads y Analytics).'), [], DoctorCheck::settings('google'));
        $broadcast = (string) config('broadcasting.default');
        if ($broadcast !== 'reverb') {
            $checks[] = new DoctorCheck('integrations.reverb', 'integrations', DoctorCheck::INFO, __('Tiempo real apagado'), __('Sin Reverb (BROADCAST_CONNECTION=reverb) la bandeja se actualiza cada 5 segundos en lugar de al instante.'));
        } else {
            $checks[] = (string) config('broadcasting.connections.reverb.key') === '' || (string) config('broadcasting.connections.reverb.secret') === ''
                ? new DoctorCheck('integrations.reverb', 'integrations', DoctorCheck::ERROR, __('Faltan las llaves de Reverb'), __('Con BROADCAST_CONNECTION=reverb hacen falta REVERB_APP_ID, REVERB_APP_KEY y REVERB_APP_SECRET.'))
                : new DoctorCheck('integrations.reverb', 'integrations', DoctorCheck::OK, __('Tiempo real con Reverb'));
        }
        $checks[] = (string) config('igr-predictions.ml.token') === ''
            ? new DoctorCheck('integrations.ml', 'integrations', DoctorCheck::INFO, __('El servicio de predicciones no tiene token'), __('Sin IGR_ML_TOKEN (lo crea el instalador) las predicciones de valor futuro y próxima compra usan solo la heurística. Vuelve a correr el instalador en el servidor para que lo cree.'))
            : new DoctorCheck('integrations.ml', 'integrations', DoctorCheck::OK, __('Servicio de predicciones configurado'));

        return $checks;
    }

    /** @return list<DoctorCheck> */
    private function data(): array
    {
        $checks = [];
        $admins = $this->count(fn () => User::query()->where('is_platform_admin', true)->count());
        $without2fa = $this->count(fn () => User::query()->where('is_platform_admin', true)->whereNull('two_factor_confirmed_at')->count());
        $checks[] = $admins === 0
            ? new DoctorCheck('data.admins', 'data', DoctorCheck::ERROR, __('Nadie administra la plataforma'), __('Nadie puede abrir Plataforma: crea a la primera persona en el servidor con «growth-php artisan igr:admin:create correo». Las demás se agregan en Plataforma → Ajustes → Administradores.'))
            : ($without2fa > 0
                ? new DoctorCheck('data.admins', 'data', DoctorCheck::WARNING, trans_choice(':n administrador sin verificación en dos pasos|:n administradores sin verificación en dos pasos', $without2fa, ['n' => $without2fa]), __('Sin ella no entran a Plataforma ni a Horizon. Se activa en Seguridad.'))
                : new DoctorCheck('data.admins', 'data', DoctorCheck::OK, trans_choice(':n persona administra la plataforma|:n personas administran la plataforma', $admins, ['n' => $admins])));

        $default = PlanCatalog::defaultPlanId();
        $unassigned = $this->count(fn () => DB::table('accounts')->where(fn ($q) => $q->whereNull('plan_id')->orWhere('plan_id', ''))->count());
        if ($default === null) {
            $checks[] = new DoctorCheck('data.default_plan', 'data', app()->environment('production') ? DoctorCheck::ERROR : DoctorCheck::INFO, __('Sin plan, todo está permitido'),
                __('Con «none» cualquier cuenta sin plan recibe todos los módulos, la IA y el correo por la plataforma. Úsalo solo en desarrollo; en producción elige el plan por defecto en Plataforma → Ajustes → Registro y seguridad (Diagnóstico es el gratuito).'), [], DoctorCheck::settings('security'));
        } elseif ($unassigned > 0) {
            $checks[] = new DoctorCheck('data.default_plan', 'data', DoctorCheck::INFO, trans_choice(':n cuenta sin plan usa el plan gratuito|:n cuentas sin plan usan el plan gratuito', $unassigned, ['n' => $unassigned]),
                __('Asígnales su plan en Plataforma → Clientes.'), $this->unassignedAccounts());
        }

        $checks[] = $this->sites();
        $checks[] = $this->signatures();
        $checks[] = $this->exchangeRates();

        return $checks;
    }

    /**
     * S14: los sitios con un plugin anterior a la 0.1.4 firman con v1 (sin método, ruta ni nonce). Dice cuándo se puede
     * apagar la v1 y avisa si ya se apagó y algún sitio todavía la usa (sus datos dejan de llegar).
     */
    private function signatures(): DoctorCheck
    {
        $legacy = $this->legacySignatureSites();
        $min = RequestSignature::V2_MIN_PLUGIN_VERSION;
        $link = DoctorCheck::settings('security');
        if (SiteSignatures::acceptsV1()) {
            return $legacy === []
                ? new DoctorCheck('data.signature', 'data', DoctorCheck::INFO, __('Todos los sitios firman con la versión 2'),
                    __('Ya puedes dejar de aceptar la firma anterior: apaga «Aceptar la firma anterior del plugin» en Plataforma → Ajustes → Registro y seguridad.'), [], $link)
                : new DoctorCheck('data.signature', 'data', DoctorCheck::INFO,
                    trans_choice(':n sitio todavía firma con la versión 1|:n sitios todavía firman con la versión 1', count($legacy), ['n' => count($legacy)]),
                    __('Se sigue aceptando mientras tengan un plugin anterior a la :v. Cuando todos lo actualicen, apaga «Aceptar la firma anterior del plugin» en Plataforma → Ajustes → Registro y seguridad.', ['v' => $min]),
                    array_slice($legacy, 0, 50), $link);
        }

        return $legacy === []
            ? new DoctorCheck('data.signature', 'data', DoctorCheck::OK, __('Solo se acepta la firma versión 2'))
            : new DoctorCheck('data.signature', 'data', DoctorCheck::ERROR,
                trans_choice(':n sitio firma con la versión 1 y el hub ya no la acepta|:n sitios firman con la versión 1 y el hub ya no la acepta', count($legacy), ['n' => count($legacy)]),
                __('Sus datos no llegan. Que instalen el plugin :v o más nuevo, o vuelve a encender «Aceptar la firma anterior del plugin» en Plataforma → Ajustes → Registro y seguridad mientras tanto.', ['v' => $min]),
                array_slice($legacy, 0, 50), $link);
    }

    /**
     * Los sitios conectados que todavía firman con la v1 (plugin anterior a la 0.1.4), como «host (versión)».
     *
     * @return list<string>
     */
    public function legacySignatureSites(): array
    {
        $legacy = [];
        try {
            $sites = Site::withoutWorkspaceScope()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('host')->limit(500)->get(['id', 'host', 'plugin_version']);
        } catch (Throwable) {
            $sites = collect();
        }
        foreach ($sites as $site) {
            if (! SiteSignatures::speaksV2($site)) {
                $version = $site->getAttribute('plugin_version');
                $legacy[] = $site->host.' ('.(is_string($version) && $version !== '' ? $version : __('versión desconocida')).')';
            }
        }

        return $legacy;
    }

    /** F5: el configuration set y su destino de eventos (lo deja `igr:ses:setup` o el alta y la prueba de un canal SES). */
    private function sesEvents(): DoctorCheck
    {
        $last = SesEventSetup::lastResult();
        $set = SesEventSetup::configurationSet();
        if ($last === null) {
            return new DoctorCheck('email.ses_events', 'email', DoctorCheck::WARNING, __('El configuration set :set no se ha revisado', ['set' => $set]),
                __('Se prepara solo cada noche (03:47) y al conectar o probar un canal SES; para no esperar, usa «Preparar ahora» en Plataforma → Ajustes → Amazon SES.'), [], DoctorCheck::settings('ses'));
        }
        $when = is_string($last['checked_at'] ?? null) ? (string) $last['checked_at'] : '';

        return match ($last['status'] ?? 'error') {
            'ok' => new DoctorCheck('email.ses_events', 'email', DoctorCheck::OK, __('Configuration set :set con eventos hacia SNS', ['set' => $set])),
            'no_topic' => new DoctorCheck('email.ses_events', 'email', DoctorCheck::WARNING, __('El configuration set :set no tiene destino de eventos', ['set' => $set]), (string) ($last['message'] ?? ''), [], DoctorCheck::settings('ses')),
            default => new DoctorCheck('email.ses_events', 'email', DoctorCheck::ERROR, __('No se pudo preparar el configuration set :set', ['set' => $set]),
                trim((string) ($last['message'] ?? '').' '.__('Última revisión: :at. Corrige y usa «Preparar ahora» en Plataforma → Ajustes → Amazon SES.', ['at' => $when])), [], DoctorCheck::settings('ses')),
        };
    }

    /** F11: tasas de cambio para mostrar el costo de los mensajes en la moneda de cada espacio de trabajo. */
    private function exchangeRates(): DoctorCheck
    {
        try {
            $rates = app(ExchangeRates::class);
            $missing = [];
            $stale = [];
            foreach (ExchangeRates::currencies() as $currency) {
                $d = $rates->describe($currency);
                if ($d['rate'] === null) {
                    $missing[] = $currency;
                } elseif ($d['stale']) {
                    $stale[] = $currency.' ('.$d['date'].')';
                }
            }
        } catch (Throwable) {
            return new DoctorCheck('data.fx', 'data', DoctorCheck::WARNING, __('No se pudieron revisar las tasas de cambio'), __('Revisa la base de datos y usa «Actualizar ahora» en Plataforma → Tasas de cambio.'), [], 'pestana=fx');
        }
        $status = UpdateExchangeRates::status();
        $error = is_array($status) && ($status['ok'] ?? true) === false ? ' '.__('Último error: :error', ['error' => (string) ($status['error'] ?? '')]) : '';
        if ($missing !== []) {
            return new DoctorCheck('data.fx', 'data', DoctorCheck::WARNING, __('Faltan tasas de cambio'),
                __('Sin ellas el costo de los mensajes se muestra en dólares. Usa «Actualizar ahora» o fija la tasa a mano en Plataforma → Tasas de cambio.').$error, $missing, 'pestana=fx');
        }
        if ($stale !== []) {
            return new DoctorCheck('data.fx', 'data', DoctorCheck::WARNING, __('Tasas de cambio desactualizadas'),
                __('Rige la última tasa conocida. Revisa que las tareas programadas corran y que el servidor llegue a la fuente (Plataforma → Ajustes → Tasas de cambio), o fija la tasa a mano en Plataforma → Tasas de cambio.').$error, $stale, 'pestana=fx');
        }

        return new DoctorCheck('data.fx', 'data', DoctorCheck::OK, __('Tasas de cambio al día'));
    }

    private function sites(): DoctorCheck
    {
        $bundled = PluginRelease::bundledVersion();
        $min = (string) config('igr.min_plugin_version', '0.1.0');
        $outdated = [];
        $tooOld = 0;
        try {
            $sites = Site::withoutWorkspaceScope()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('host')->limit(500)->get(['id', 'host', 'plugin_version']);
        } catch (Throwable) {
            $sites = collect();
        }
        foreach ($sites as $site) {
            $version = $site->getAttribute('plugin_version');
            $version = is_string($version) ? $version : null;
            if (PluginRelease::older($version, $min)) {
                $tooOld++;
            }
            if (PluginRelease::older($version, $bundled ?? $min)) {
                $outdated[] = $site->host.' ('.($version ?? __('versión desconocida')).')';
            }
        }
        if ($outdated === []) {
            return new DoctorCheck('data.plugin', 'data', DoctorCheck::OK, $bundled !== null
                ? __('Todos los sitios tienen el plugin al día (:v)', ['v' => $bundled])
                : __('Todos los sitios tienen el plugin al día'));
        }

        return new DoctorCheck('data.plugin', 'data', $tooOld > 0 ? DoctorCheck::ERROR : DoctorCheck::WARNING,
            trans_choice(':n sitio tiene el plugin viejo|:n sitios tienen el plugin viejo', count($outdated), ['n' => count($outdated)]),
            __('La versión actual es :v. Descárgala desde Sitios → Descargar el plugin e instálala en WordPress (Plugins → Añadir nuevo → Subir plugin).', ['v' => $bundled ?? $min]),
            array_slice($outdated, 0, 50));
    }

    /** @return list<string> */
    private function unassignedAccounts(): array
    {
        try {
            return array_values(array_map(fn ($n) => (string) $n, DB::table('accounts')->where(fn ($q) => $q->whereNull('plan_id')->orWhere('plan_id', ''))->orderBy('name')->limit(50)->pluck('name')->all()));
        } catch (Throwable) {
            return [];
        }
    }

    private function failedJobs(): ?int
    {
        try {
            return (int) DB::table((string) config('queue.failed.table', 'failed_jobs'))->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param callable(): int $query */
    private function count(callable $query): int
    {
        try {
            return (int) $query();
        } catch (Throwable) {
            return 0;
        }
    }
}
