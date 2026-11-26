<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Api;

use App\Domain\Billing\Support\PlatformSending;
use App\Domain\Connections\Actions\DisconnectConnection;
use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Providers\ProviderRegistry;
use App\Domain\Messaging\Actions\ConnectWhatsAppNumber;
use App\Domain\Messaging\Channels\DriverRegistry;
use App\Domain\Messaging\Channels\Drivers\MetaCloudDriver;
use App\Domain\Messaging\Channels\Drivers\MetaSocialDriver;
use App\Domain\Messaging\Channels\Drivers\SesDriver;
use App\Domain\Messaging\Channels\ImaginaWhats\ImaginaWhatsClient;
use App\Domain\Messaging\Channels\ImaginaWhats\ImaginaWhatsError;
use App\Domain\Messaging\Channels\ImaginaWhats\ImaginaWhatsSetup;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Support\EmailDomain;
use App\Domain\Messaging\Support\SesClientFactory;
use App\Domain\Messaging\Support\SesEventSetup;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Net\SafeUrl;
use App\Support\Phone\Phone;
use App\Support\Tenancy\CurrentWorkspace;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Configuración → Canales (M07): cuentas de correo (SES con asistente de
 * dominio, o SMTP), WhatsApp oficial (manual aquí; Embedded Signup con un clic en
 * Connections\Http\WhatsAppSignupController), WhatsApp no oficial —Zender e Imagina Whats— (con el aviso de
 * riesgo que acepta el owner) y SMS (Twilio). Messenger e Instagram se conectan en
 * SocialChannelController (Fase 5) o con un clic desde Conexiones → Meta.
 */
final class ChannelController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** GET /api/w/{ws}/channels */
    public function index(ProviderRegistry $providers): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');
        $accounts = ChannelAccount::query()->where('status', '!=', 'disconnected')->orderBy('channel')->orderByDesc('is_default')->orderBy('created_at')->get();

        return response()->json([
            'data' => $accounts->map(fn (ChannelAccount $a) => ChannelAccountPresenter::present($a))->values(),
            'meta' => [
                // Con un clic (Conexiones): la SPA pide el state y los datos del SDK en connections/meta_whatsapp/start.
                'embedded_signup' => ['configured' => $providers->configured(ConnectionProvider::MetaWhatsApp), 'missing' => $providers->for(ConnectionProvider::MetaWhatsApp)->missing()],
                'meta_login' => ['configured' => $providers->configured(ConnectionProvider::Meta), 'missing' => $providers->for(ConnectionProvider::Meta)->missing()],
                'zender_caps' => ['hourly' => (int) config('igr-messaging.zender.hourly_cap'), 'daily' => (int) config('igr-messaging.zender.daily_cap')],
                'imagina_whats' => [
                    'url' => rtrim((string) config('igr-messaging.imagina_whats.url'), '/'),
                    'caps' => ['hourly' => (int) config('igr-messaging.imagina_whats.hourly_cap'), 'daily' => (int) config('igr-messaging.imagina_whats.daily_cap')],
                    'abilities' => array_values((array) config('igr-messaging.imagina_whats.abilities')),
                ],
                // Si el plan incluye correo por la cuenta de SES de la plataforma (S3); si no, solo SMTP propio.
                'platform_email' => PlatformSending::emailAllowed($this->current->get()),
            ],
        ]);
    }

    /** POST /api/w/{ws}/channels/email — SES (con dominio) o SMTP. */
    public function storeEmail(Request $request, SesDriver $ses, SesEventSetup $setup): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate([
            'provider' => ['required', Rule::in(['ses', 'smtp'])],
            'display_name' => ['nullable', 'string', 'max:120'],
            'from_email' => ['required', 'email:rfc', 'max:190'],
            'from_name' => ['required', 'string', 'max:120'],
            'reply_to' => ['nullable', 'email:rfc', 'max:190'],
            'host' => ['required_if:provider,smtp', 'nullable', 'string', 'max:190'],
            'port' => ['required_if:provider,smtp', 'nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:500'],
            'encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
        ]);
        if ($data['provider'] === 'smtp' && ($problem = SafeUrl::hostProblem((string) ($data['host'] ?? ''))) !== null) {
            throw ValidationException::withMessages(['host' => $problem]);
        }
        $domain = strtolower(substr((string) strrchr($data['from_email'], '@'), 1));
        $config = array_filter([
            'from_email' => $data['from_email'], 'from_name' => $data['from_name'], 'reply_to' => $data['reply_to'] ?? null, 'domain' => $domain,
            'host' => $data['host'] ?? null, 'port' => $data['port'] ?? null, 'username' => $data['username'] ?? null,
            'password' => $data['password'] ?? null, 'encryption' => $data['encryption'] ?? null,
        ], fn ($v) => $v !== null);
        $events = null;
        if ($data['provider'] === 'ses') {
            if (! PlatformSending::emailAllowed($this->current->get())) {
                throw ValidationException::withMessages(['provider' => PlatformSending::message()]);
            }
            // Sin las credenciales de SES de la plataforma, el SDK lanzaba CredentialsException y la API respondía 500.
            if (! app(SesClientFactory::class)->configured()) {
                throw ValidationException::withMessages(['provider' => __('Esta instalación todavía no tiene Amazon SES configurado. Conecta tu correo por SMTP o pide a quien administra la plataforma que configure SES.')]);
            }
            // F5: la identidad del dominio se crea con el configuration set y todo envío sale por él; se prepara antes
            // (idempotente). Sin él SES rechaza; sin su destino de eventos el canal funciona pero sin rebotes ni quejas.
            $events = $setup->ensure();
            if ($events['status'] === 'error') {
                throw ValidationException::withMessages(['provider' => self::sesProblem($events['message'])]);
            }
            try {
                $config['dkim_tokens'] = $ses->ensureDomainIdentity($domain);
            } catch (CredentialsException) {
                throw ValidationException::withMessages(['provider' => __('Las credenciales de Amazon SES de la plataforma no son válidas. Avisa a quien administra la plataforma.')]);
            } catch (AwsException $e) {
                throw ValidationException::withMessages(['from_email' => __('Amazon SES no aceptó el dominio: :msg', ['msg' => $e->getAwsErrorMessage() ?: $e->getMessage()])]);
            }
        }
        $account = $this->create(Channel::Email, $data['provider'], $data['display_name'] ?? $data['from_name'], $config, $domain, $domain,
            $data['provider'] === 'smtp' ? 'active' : 'pending');
        $this->audit->handle('channel.created', $account, ['provider' => $data['provider'], 'domain' => $domain], $account->workspace_id);

        return response()->json(['data' => ChannelAccountPresenter::present($account), 'dns' => app(EmailDomain::class)->records($account),
            'ses_events' => $events !== null ? ['status' => $events['status'], 'message' => $events['message']] : null], 201);
    }

    /** GET /api/w/{ws}/channels/{id}/dns — verifica los registros en vivo y activa el dominio. */
    public function dns(string $channel, EmailDomain $domain, SesDriver $ses): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');
        $account = $this->find($channel);
        abort_unless($account->channel === Channel::Email, 404);
        $records = $domain->records($account, true);
        $sesStatus = null;
        if ($account->provider === 'ses') {
            try {
                $sesStatus = $ses->dkimStatus((string) $account->credential('domain'));
            } catch (AwsException) {
                $sesStatus = null;
            }
        }
        $required = array_filter($records, fn (array $r) => $r['required']);
        $dnsOk = $required !== [] && array_reduce($required, fn (bool $ok, array $r) => $ok && $r['ok'] === true, true);
        // SES tiene la última palabra sobre DKIM; DMARC lo pedimos aparte (Gmail y Yahoo, cumplimiento.md §8).
        $verified = $account->provider === 'smtp' || ($dnsOk && $sesStatus === 'SUCCESS');
        if ($verified && $account->status === 'pending') {
            $account->forceFill(['status' => 'active', 'warmup_started_at' => $account->warmup_started_at ?? now(), 'last_error' => null])->save();
            $this->ensureDefault($account);
        }

        return response()->json(['data' => ChannelAccountPresenter::present($account->fresh() ?? $account), 'records' => $records, 'ses_status' => $sesStatus, 'verified' => $verified]);
    }

    /** POST /api/w/{ws}/channels/whatsapp/manual — WABA, número y token permanente. */
    public function whatsappManual(Request $request, ConnectWhatsAppNumber $connect): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate([
            'waba_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'access_token' => ['required', 'string', 'min:20', 'max:1000'],
            'display_name' => ['nullable', 'string', 'max:120'],
        ]);

        $account = $connect->handle(
            ['waba_id' => $data['waba_id'], 'phone_number_id' => $data['phone_number_id'], 'access_token' => $data['access_token'], 'connected_via' => 'manual'],
            $data['display_name'] ?? null,
        );

        return response()->json(['data' => ChannelAccountPresenter::present($account)], 201);
    }

    /** POST /api/w/{ws}/channels/whatsapp/zender — solo el owner, aceptando el riesgo (cumplimiento.md §5.2). */
    public function zender(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        abort_unless($this->current->role() === WorkspaceRole::Owner, 403, __('Solo el dueño del espacio de trabajo puede activar la conexión no oficial.'));
        $data = $request->validate([
            'base_url' => ['required', 'url:https', 'max:300'],
            'api_secret' => ['required', 'string', 'max:300'],
            'account_id' => ['required', 'string', 'max:190'],
            'phone' => ['required', 'string', 'max:30'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'accept_risk' => ['accepted'],
        ]);
        if (($problem = SafeUrl::problem($data['base_url'])) !== null) {
            throw ValidationException::withMessages(['base_url' => $problem]);
        }
        $phone = Phone::toE164($data['phone'], strtoupper((string) ($this->current->get()->country ?: 'CO')));
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => __('Escribe un número de WhatsApp válido.')]);
        }
        $account = $this->create(Channel::WhatsApp, ChannelAccount::ZENDER, $data['display_name'] ?? __('WhatsApp (no oficial)'), [
            'base_url' => $data['base_url'], 'api_secret' => $data['api_secret'], 'account_id' => $data['account_id'],
            'webhook_secret' => Str::random(40),
        ], $data['account_id'], $phone, 'active');
        $account->forceFill(['risk_accepted_at' => now(), 'risk_accepted_by' => (string) $request->user()?->getAuthIdentifier()])->save();
        $this->audit->handle('channel.zender_risk_accepted', $account, ['caps' => ['hourly' => config('igr-messaging.zender.hourly_cap'), 'daily' => config('igr-messaging.zender.daily_cap')]], $account->workspace_id);

        return response()->json(['data' => ChannelAccountPresenter::present($account), 'webhook_secret' => $account->credential('webhook_secret')], 201);
    }

    /**
     * POST /api/w/{ws}/channels/whatsapp/imagina-whats/check — con la clave de API: la cuenta de Imagina Whats, los
     * permisos que le faltan y los números que puede usar. No guarda nada.
     */
    public function imaginaWhatsCheck(Request $request, ImaginaWhatsSetup $setup): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate(['api_key' => ['required', 'string', 'min:10', 'max:300']]);

        return response()->json(['data' => $this->inspectImaginaWhats($setup, $data['api_key'])]);
    }

    /**
     * POST /api/w/{ws}/channels/whatsapp/imagina-whats — solo el owner, aceptando el riesgo (cumplimiento.md §5.2). El
     * número debe estar conectado en Imagina Whats. Registra el webhook firmado de los entrantes y estados; si falla
     * (p. ej. el panel no es público), la cuenta queda activa para enviar con el motivo en `last_error`.
     */
    public function imaginaWhats(Request $request, ImaginaWhatsSetup $setup): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        abort_unless($this->current->role() === WorkspaceRole::Owner, 403, __('Solo el dueño del espacio de trabajo puede activar la conexión no oficial.'));
        $data = $request->validate([
            'api_key' => ['required', 'string', 'min:10', 'max:300'],
            'wa_account_id' => ['required', 'integer', 'min:1'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'accept_risk' => ['accepted'],
        ]);
        $info = $this->inspectImaginaWhats($setup, $data['api_key']);
        if ($info['missing'] !== []) {
            throw ValidationException::withMessages(['api_key' => __('A la clave de API le faltan estos permisos en Imagina Whats: :list.', ['list' => implode(', ', $info['missing'])])]);
        }
        $number = collect($info['numbers'])->firstWhere('id', (int) $data['wa_account_id']);
        if ($number === null) {
            throw ValidationException::withMessages(['wa_account_id' => __('Ese número no está en la cuenta de Imagina Whats de esta clave.')]);
        }
        if ($number['status'] !== 'connected' || ! $number['enabled']) {
            throw ValidationException::withMessages(['wa_account_id' => __('Ese número no está conectado en Imagina Whats. Vincúlalo allá (QR o código) y vuelve a intentarlo.')]);
        }
        $externalId = (string) $number['id'];
        $config = ['api_key' => $data['api_key'], 'wa_account_id' => $externalId];
        $account = ChannelAccount::query()->where('provider', ChannelAccount::IMAGINA_WHATS)->where('external_id', $externalId)->where('status', '!=', 'disconnected')->first();
        if ($account !== null) {
            $account->mergeConfig($config);
            $account->forceFill(['identity' => $number['phone'] ?? $account->identity, 'display_name' => mb_substr($data['display_name'] ?? $account->display_name, 0, 120), 'status' => 'active'])->save();
        } else {
            $account = $this->create(Channel::WhatsApp, ChannelAccount::IMAGINA_WHATS, $data['display_name'] ?? ($number['name'] ?? __('WhatsApp (Imagina Whats)')), $config, $externalId, $number['phone'], 'active');
        }
        $account->forceFill(['risk_accepted_at' => now(), 'risk_accepted_by' => (string) $request->user()?->getAuthIdentifier()])->save();
        $problem = $setup->registerWebhook($account);
        $account->forceFill(['last_error' => $problem])->save();
        $this->audit->handle('channel.imagina_whats_risk_accepted', $account, ['caps' => $account->unofficialCaps(), 'webhook' => $problem === null], $account->workspace_id);

        return response()->json(['data' => ChannelAccountPresenter::present($account->refresh()), 'webhook_problem' => $problem], 201);
    }

    /** POST /api/w/{ws}/channels/sms/twilio */
    public function twilio(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate([
            'account_sid' => ['required', 'string', 'regex:/^AC[a-f0-9]{32}$/i'],
            'auth_token' => ['required', 'string', 'min:16', 'max:200'],
            'from' => ['required_without:messaging_service_sid', 'nullable', 'string', 'max:30'],
            'messaging_service_sid' => ['nullable', 'string', 'regex:/^MG[a-f0-9]{32}$/i'],
            'display_name' => ['nullable', 'string', 'max:120'],
        ]);
        $from = isset($data['from']) ? Phone::toE164($data['from'], strtoupper((string) ($this->current->get()->country ?: 'CO'))) : null;
        $account = $this->create(Channel::Sms, 'twilio', $data['display_name'] ?? 'SMS', array_filter([
            'account_sid' => $data['account_sid'], 'auth_token' => $data['auth_token'], 'from' => $from, 'messaging_service_sid' => $data['messaging_service_sid'] ?? null,
        ]), $from ?? ($data['messaging_service_sid'] ?? null), $from, 'active');
        $this->audit->handle('channel.created', $account, ['provider' => 'twilio'], $account->workspace_id);

        return response()->json(['data' => ChannelAccountPresenter::present($account)], 201);
    }

    /** PATCH /api/w/{ws}/channels/{id} */
    public function update(Request $request, string $channel): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $account = $this->find($channel);
        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
            'from_name' => ['sometimes', 'string', 'max:120'],
            'reply_to' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
        ]);
        if (isset($data['status']) && $data['status'] === 'active' && $account->status === 'pending') {
            throw ValidationException::withMessages(['status' => __('Primero verifica el dominio o termina la conexión.')]);
        }
        $account->fill(array_intersect_key($data, array_flip(['display_name', 'status'])));
        if ($account->channel === Channel::Email) {
            $account->mergeConfig(array_intersect_key($data, array_flip(['from_name', 'reply_to'])));
        }
        $account->save();
        if (($data['is_default'] ?? false) === true) {
            ChannelAccount::query()->where('channel', $account->channel->value)->whereKeyNot($account->id)->update(['is_default' => false]);
            $account->forceFill(['is_default' => true])->save();
        }
        $this->audit->handle('channel.updated', $account, array_diff_key($data, ['reply_to' => true]), $account->workspace_id);

        return response()->json(['data' => ChannelAccountPresenter::present($account)]);
    }

    /**
     * DELETE /api/w/{ws}/channels/{id} — se desconecta y se borran sus credenciales; el historial queda. Si la
     * cuenta se conectó con un clic y nadie más usa esa conexión de WhatsApp, también se revoca (la cuenta deja
     * de mandar sus webhooks a la app).
     */
    public function destroy(string $channel, DisconnectConnection $disconnect, ImaginaWhatsSetup $setup): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $account = $this->find($channel);
        $connection = $account->linkedConnection();
        if ($account->provider === ChannelAccount::IMAGINA_WHATS) {
            $setup->forget($account); // el webhook de Imagina Whats deja de avisar (si falla, el hub responde 410)
        }
        $account->forceFill(['status' => 'disconnected', 'is_default' => false, 'oauth_connection_id' => null, 'config' => array_intersect_key($account->config ?? [], array_flip(['domain', 'from_email']))])->save();
        if ($connection !== null && $connection->provider === ConnectionProvider::MetaWhatsApp
            && ! ChannelAccount::query()->where('oauth_connection_id', $connection->id)->where('status', '!=', 'disconnected')->exists()) {
            $disconnect->handle($connection);
        }
        $this->audit->handle('channel.disconnected', $account, ['provider' => $account->provider], $account->workspace_id);

        return response()->json(['ok' => true]);
    }

    /** POST /api/w/{ws}/channels/{id}/sync — calidad del número y plantillas (WhatsApp oficial). */
    public function sync(string $channel, MetaCloudDriver $meta, DriverRegistry $drivers): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $account = $this->find($channel);
        $social = $drivers->for($account->provider);
        if ($social instanceof MetaSocialDriver) {
            // Messenger e Instagram: no hay plantillas; se vuelve a comprobar la conexión.
            return response()->json(['data' => ChannelAccountPresenter::present(SocialChannelController::check($account, $social)), 'templates' => 0]);
        }
        if ($account->provider === ChannelAccount::IMAGINA_WHATS) {
            return response()->json(['data' => ChannelAccountPresenter::present($this->checkImaginaWhats($account)), 'templates' => 0]);
        }
        abort_unless($account->provider === 'meta_cloud', 422, __('Solo las cuentas de WhatsApp oficial tienen plantillas.'));
        try {
            $info = $meta->phoneInfo($account);
            $account->forceFill(['quality_rating' => $info['quality_rating'], 'messaging_limit_tier' => $info['messaging_limit_tier'], 'identity' => $info['display_phone_number'] ?? $account->identity, 'last_error' => null])->save();
            $count = $meta->syncTemplates($account);
        } catch (RuntimeException $e) {
            $account->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            throw ValidationException::withMessages(['account' => $e->getMessage()]);
        }

        return response()->json(['data' => ChannelAccountPresenter::present($account), 'templates' => $count]);
    }

    /** POST /api/w/{ws}/channels/{id}/test — mensaje de prueba (modo prueba: sin ventana ni frecuencia). */
    public function test(Request $request, string $channel, MessageDispatcher $dispatcher, SesEventSetup $setup): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $account = $this->find($channel);
        $data = $request->validate([
            'to' => ['required', 'string', 'max:190', $account->channel === Channel::Email ? 'email:rfc' : 'regex:/^[+\d\s()-]{7,20}$/'],
        ]);
        // F5: probar un canal SES también deja listo el configuration set y sus eventos (si faltan, la prueba no saldría).
        $events = null;
        if ($account->provider === 'ses') {
            $events = $setup->ensure();
            if ($events['status'] === 'error') {
                throw ValidationException::withMessages(['to' => self::sesProblem($events['message'])]);
            }
        }
        $workspace = $this->current->get();
        $text = __('Mensaje de prueba de :name desde Imagina Growth. Si lo recibiste, el canal funciona.', ['name' => $workspace->name]);
        $result = $dispatcher->dispatch(new OutboundMessage(
            $workspace->id, null, $account->channel, MessageKind::Transactional, 'system',
            context: ['subject' => __('Prueba de :name', ['name' => $workspace->name]), 'text' => $text],
            channelAccountId: $account->id, to: $data['to'], isTest: true,
        ));

        return response()->json(['status' => $result->status, 'message_id' => $result->messageId !== null ? 'msg_'.$result->messageId : null, 'reason' => $result->reason,
            'ses_events' => $events !== null ? ['status' => $events['status'], 'message' => $events['message']] : null]);
    }

    /** @param array<string, mixed> $config */
    private function create(Channel $channel, string $provider, string $name, array $config, ?string $externalId, ?string $identity, string $status): ChannelAccount
    {
        $account = ChannelAccount::query()->create([
            'workspace_id' => $this->current->id(),
            'channel' => $channel,
            'provider' => $provider,
            'display_name' => mb_substr($name, 0, 120),
            'config' => $config,
            'external_id' => $externalId,
            'identity' => $identity,
            'status' => $status,
            'is_default' => false,
        ]);
        if ($status === 'active') {
            $this->ensureDefault($account);
        }

        return $account;
    }

    /**
     * @return array{workspace: string|null, credits: float|null, missing: list<string>, numbers: list<array{id: int, phone: string|null, name: string|null, status: string, enabled: bool}>}
     */
    private function inspectImaginaWhats(ImaginaWhatsSetup $setup, string $apiKey): array
    {
        try {
            return $setup->inspect($apiKey, strtoupper((string) ($this->current->get()->country ?: 'CO')));
        } catch (ImaginaWhatsError $e) {
            throw ValidationException::withMessages(['api_key' => $e->human()]);
        }
    }

    /** «Comprobar» una cuenta de Imagina Whats: el número sigue conectado allá y la clave sigue valiendo. */
    private function checkImaginaWhats(ChannelAccount $account): ChannelAccount
    {
        $client = ImaginaWhatsClient::for($account);
        $number = $account->credential('wa_account_id');
        if ($client === null || $number === null || ! ctype_digit($number)) {
            throw ValidationException::withMessages(['account' => __('Faltan los datos de Imagina Whats en la cuenta.')]);
        }
        try {
            $info = $client->waAccount((int) $number);
        } catch (ImaginaWhatsError $e) {
            $account->forceFill(['last_error' => mb_substr($e->human(), 0, 500)])->save();
            throw ValidationException::withMessages(['account' => $e->human()]);
        }
        $status = is_string($info['status'] ?? null) ? $info['status'] : 'unknown';
        $problem = match (true) {
            $status === 'connected' && ($info['is_enabled'] ?? true) !== false => null,
            $status === 'banned' => __('WhatsApp bloqueó este número en Imagina Whats.'),
            $status === 'connected' => __('El número está deshabilitado en Imagina Whats.'),
            default => __('El número no está conectado en Imagina Whats (:status). Vincúlalo otra vez allá.', ['status' => $status]),
        };
        $account->forceFill(['last_error' => $problem])->save();

        return $account;
    }

    private function ensureDefault(ChannelAccount $account): void
    {
        $hasDefault = ChannelAccount::query()->where('channel', $account->channel->value)->where('is_default', true)->where('status', 'active')->whereKeyNot($account->id)->exists();
        if (! $hasDefault && ! $account->isUnofficial()) {
            $account->forceFill(['is_default' => true])->save();
        }
    }

    /** El motivo técnico va para quien administra la plataforma; el cliente no puede arreglarlo. */
    private static function sesProblem(string $detail): string
    {
        return __('Esta instalación no pudo preparar el envío por Amazon SES. Avisa a quien administra la plataforma: :detail', ['detail' => $detail]);
    }

    private function find(string $publicId): ChannelAccount
    {
        return ChannelAccount::findByPublicId($publicId) ?? abort(404);
    }
}
