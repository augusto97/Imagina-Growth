<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Http\Api;

use App\Domain\Messaging\Channels\DriverRegistry;
use App\Domain\Messaging\Channels\Drivers\MetaCloudDriver;
use App\Domain\Messaging\Channels\Drivers\MetaSocialDriver;
use App\Domain\Messaging\Channels\Drivers\SesDriver;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Support\EmailDomain;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Phone\Phone;
use App\Support\Tenancy\CurrentWorkspace;
use Aws\Exception\AwsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Configuración → Canales (M07): cuentas de correo (SES con asistente de
 * dominio, o SMTP), WhatsApp oficial (Embedded Signup o manual), Zender (con
 * el aviso de riesgo que acepta el owner) y SMS (Twilio). Messenger e Instagram
 * se conectan en SocialChannelController (Fase 5).
 */
final class ChannelController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** GET /api/w/{ws}/channels */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');
        $accounts = ChannelAccount::query()->where('status', '!=', 'disconnected')->orderBy('channel')->orderByDesc('is_default')->orderBy('created_at')->get();

        return response()->json([
            'data' => $accounts->map(fn (ChannelAccount $a) => ChannelAccountPresenter::present($a))->values(),
            'meta' => [
                'embedded_signup' => [
                    'app_id' => config('igr-messaging.meta.app_id'),
                    'config_id' => config('igr-messaging.meta.embedded_signup_config_id'),
                    'graph_version' => config('igr-messaging.meta.graph_version'),
                ],
                'zender_caps' => ['hourly' => (int) config('igr-messaging.zender.hourly_cap'), 'daily' => (int) config('igr-messaging.zender.daily_cap')],
            ],
        ]);
    }

    /** POST /api/w/{ws}/channels/email — SES (con dominio) o SMTP. */
    public function storeEmail(Request $request, SesDriver $ses): JsonResponse
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
        $domain = strtolower(substr((string) strrchr($data['from_email'], '@'), 1));
        $config = array_filter([
            'from_email' => $data['from_email'], 'from_name' => $data['from_name'], 'reply_to' => $data['reply_to'] ?? null, 'domain' => $domain,
            'host' => $data['host'] ?? null, 'port' => $data['port'] ?? null, 'username' => $data['username'] ?? null,
            'password' => $data['password'] ?? null, 'encryption' => $data['encryption'] ?? null,
        ], fn ($v) => $v !== null);
        if ($data['provider'] === 'ses') {
            try {
                $config['dkim_tokens'] = $ses->ensureDomainIdentity($domain);
            } catch (AwsException $e) {
                throw ValidationException::withMessages(['from_email' => __('Amazon SES no aceptó el dominio: :msg', ['msg' => $e->getAwsErrorMessage() ?: $e->getMessage()])]);
            }
        }
        $account = $this->create(Channel::Email, $data['provider'], $data['display_name'] ?? $data['from_name'], $config, $domain, $domain,
            $data['provider'] === 'smtp' ? 'active' : 'pending');
        $this->audit->handle('channel.created', $account, ['provider' => $data['provider'], 'domain' => $domain], $account->workspace_id);

        return response()->json(['data' => ChannelAccountPresenter::present($account), 'dns' => app(EmailDomain::class)->records($account)], 201);
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

    /** POST /api/w/{ws}/channels/whatsapp/embedded-signup — canje del código de Meta [verificar]. */
    public function embeddedSignup(Request $request, MetaCloudDriver $meta): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:1000'],
            'waba_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
        ]);
        try {
            $token = $meta->exchangeEmbeddedSignup($data['code']);
            $meta->subscribeApp($data['waba_id'], $token);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['code' => __('Meta no completó la conexión: :msg', ['msg' => $e->getMessage()])]);
        }

        return $this->connectWhatsApp($meta, $data['waba_id'], $data['phone_number_id'], $token, null, 'embedded_signup');
    }

    /** POST /api/w/{ws}/channels/whatsapp/manual — WABA, número y token permanente. */
    public function whatsappManual(Request $request, MetaCloudDriver $meta): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate([
            'waba_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'access_token' => ['required', 'string', 'min:20', 'max:1000'],
            'display_name' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->connectWhatsApp($meta, $data['waba_id'], $data['phone_number_id'], $data['access_token'], $data['display_name'] ?? null, 'manual');
    }

    private function connectWhatsApp(MetaCloudDriver $meta, string $waba, string $phoneId, string $token, ?string $name, string $via): JsonResponse
    {
        if (ChannelAccount::withoutWorkspaceScope()->where('provider', 'meta_cloud')->where('external_id', $phoneId)->where('status', '!=', 'disconnected')->exists()) {
            throw ValidationException::withMessages(['phone_number_id' => __('Ese número ya está conectado en Imagina Growth.')]);
        }
        $account = new ChannelAccount(['channel' => Channel::WhatsApp, 'provider' => 'meta_cloud', 'display_name' => $name ?? 'WhatsApp', 'external_id' => $phoneId, 'status' => 'pending']);
        $account->config = ['waba_id' => $waba, 'phone_number_id' => $phoneId, 'access_token' => $token, 'connected_via' => $via];
        try {
            $info = $meta->phoneInfo($account);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['access_token' => __('Meta no reconoció el número con ese token: :msg', ['msg' => $e->getMessage()])]);
        }
        $created = $this->create(Channel::WhatsApp, 'meta_cloud', $name ?? ($info['verified_name'] ?? 'WhatsApp'), $account->config, $phoneId, $info['display_phone_number'], 'active');
        $created->forceFill(['quality_rating' => $info['quality_rating'], 'messaging_limit_tier' => $info['messaging_limit_tier']])->save();
        $this->audit->handle('channel.created', $created, ['provider' => 'meta_cloud', 'via' => $via], $created->workspace_id);
        try {
            $meta->syncTemplates($created);
        } catch (RuntimeException) {
            // Las plantillas se pueden sincronizar después desde el panel.
        }

        return response()->json(['data' => ChannelAccountPresenter::present($created)], 201);
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

    /** DELETE /api/w/{ws}/channels/{id} — se desconecta y se borran sus credenciales; el historial queda. */
    public function destroy(string $channel): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $account = $this->find($channel);
        $account->forceFill(['status' => 'disconnected', 'is_default' => false, 'config' => array_intersect_key($account->config ?? [], array_flip(['domain', 'from_email']))])->save();
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
    public function test(Request $request, string $channel, MessageDispatcher $dispatcher): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $account = $this->find($channel);
        $data = $request->validate([
            'to' => ['required', 'string', 'max:190', $account->channel === Channel::Email ? 'email:rfc' : 'regex:/^[+\d\s()-]{7,20}$/'],
        ]);
        $workspace = $this->current->get();
        $text = __('Mensaje de prueba de :name desde Imagina Growth. Si lo recibiste, el canal funciona.', ['name' => $workspace->name]);
        $result = $dispatcher->dispatch(new OutboundMessage(
            $workspace->id, null, $account->channel, MessageKind::Transactional, 'system',
            context: ['subject' => __('Prueba de :name', ['name' => $workspace->name]), 'text' => $text],
            channelAccountId: $account->id, to: $data['to'], isTest: true,
        ));

        return response()->json(['status' => $result->status, 'message_id' => $result->messageId !== null ? 'msg_'.$result->messageId : null, 'reason' => $result->reason]);
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

    private function ensureDefault(ChannelAccount $account): void
    {
        $hasDefault = ChannelAccount::query()->where('channel', $account->channel->value)->where('is_default', true)->where('status', 'active')->whereKeyNot($account->id)->exists();
        if (! $hasDefault && $account->provider !== ChannelAccount::ZENDER) {
            $account->forceFill(['is_default' => true])->save();
        }
    }

    private function find(string $publicId): ChannelAccount
    {
        return ChannelAccount::findByPublicId($publicId) ?? abort(404);
    }
}
