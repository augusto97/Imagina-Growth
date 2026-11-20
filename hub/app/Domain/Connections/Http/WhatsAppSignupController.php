<?php

declare(strict_types=1);

namespace App\Domain\Connections\Http;

use App\Domain\Connections\Actions\AttachWhatsAppNumber;
use App\Domain\Connections\Actions\CompleteWhatsAppSignup;
use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Providers\ProviderRegistry;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Connections\Support\OAuthStates;
use App\Domain\Messaging\Http\Api\ChannelAccountPresenter;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * WhatsApp con un clic (Embedded Signup v5 con coexistencia). La SPA:
 * 1. pide `start` (state de un solo uso y los datos públicos del SDK: app_id, config_id, versiones);
 * 2. abre la ventana de Meta con `FB.login({config_id, response_type: 'code', override_default_response_type: true,
 *    extras: {version: 'v5'}})` y escucha los mensajes `WA_EMBEDDED_SIGNUP` de facebook.com;
 * 3. con `FINISH` o `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING` manda `complete` con el código y los IDs; con `CANCEL`
 *    o `ERROR`, `abandon` (queda en la auditoría el paso o el código de error, sin datos personales).
 * https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/versions/
 */
final class WhatsAppSignupController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly OAuthStates $states, private readonly ProviderRegistry $providers) {}

    /** POST /api/w/{ws}/connections/meta_whatsapp/start */
    public function start(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $missing = $this->providers->for(ConnectionProvider::MetaWhatsApp)->missing();
        if ($missing !== []) {
            return response()->json(['message' => ConnectionError::notConfigured('WhatsApp')->getMessage(), 'reason' => 'not_configured', 'missing' => $missing], 422);
        }

        return response()->json([
            'state' => $this->states->issue($this->current->id(), (string) $request->user()?->getAuthIdentifier(), ConnectionProvider::MetaWhatsApp, ['purpose' => 'whatsapp']),
            'app_id' => (string) config('igr-connections.meta.app_id'),
            'config_id' => (string) config('igr-connections.meta.whatsapp_config_id'),
            'graph_version' => (string) config('igr-connections.meta.graph_version'),
            'version' => (string) config('igr-connections.meta.embedded_signup_version'),
        ]);
    }

    /** POST /api/w/{ws}/connections/meta_whatsapp/complete */
    public function complete(Request $request, CompleteWhatsAppSignup $signup): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate([
            'state' => ['required', 'string', 'max:200'],
            'code' => ['required', 'string', 'max:2000'],
            'event' => ['required', Rule::in(['FINISH', CompleteWhatsAppSignup::COEXISTENCE_EVENT])],
            'waba_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'phone_number_id' => ['sometimes', 'nullable', 'string', 'regex:/^\d{5,30}$/'],
            'business_id' => ['sometimes', 'nullable', 'string', 'regex:/^\d{5,30}$/'],
        ]);
        if (! $this->consume($request, (string) $data['state'])) {
            return self::invalidState();
        }
        try {
            $result = $signup->handle($this->current->id(), (string) $request->user()?->getAuthIdentifier(), $data);
        } catch (ConnectionError $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json([
            'connection' => ConnectionPresenter::present($result['connection']),
            'data' => $result['account'] !== null ? ChannelAccountPresenter::present($result['account']) : null,
            'phones' => $result['account'] === null ? $result['phones'] : [],
        ], $result['account'] !== null ? 201 : 200);
    }

    /** POST /api/w/{ws}/connections/meta_whatsapp/abandon — la persona cerró la ventana o Meta mostró un error. */
    public function abandon(Request $request, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate([
            'state' => ['required', 'string', 'max:200'],
            'event' => ['required', Rule::in(['CANCEL', 'ERROR'])],
            'current_step' => ['sometimes', 'nullable', 'string', 'max:80'],
            'error_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'session_id' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        if (! $this->consume($request, (string) $data['state'])) {
            return self::invalidState();
        }
        $audit->handle($data['event'] === 'CANCEL' ? 'connection.whatsapp_cancelled' : 'connection.whatsapp_failed', null, array_filter([
            'step' => $data['current_step'] ?? null, 'error_code' => $data['error_code'] ?? null, 'session_id' => $data['session_id'] ?? null,
        ]), $this->current->id());

        return response()->json(['ok' => true]);
    }

    /** POST /api/w/{ws}/connections/{id}/whatsapp-number — el número elegido (o reintentar el registro). */
    public function number(Request $request, string $connection, AttachWhatsAppNumber $attach): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $model = OAuthConnection::findByPublicId($connection);
        abort_if($model === null || $model->provider !== ConnectionProvider::MetaWhatsApp || $model->status === OAuthConnection::REVOKED, 404);
        $data = $request->validate(['phone_number_id' => ['required', 'string', 'regex:/^\d{5,30}$/']]);
        try {
            $account = $attach->handle($model, $data['phone_number_id']);
        } catch (ConnectionError $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json(['data' => ChannelAccountPresenter::present($account)], 201);
    }

    private function consume(Request $request, string $state): bool
    {
        $row = $this->states->consume($state, ConnectionProvider::MetaWhatsApp, $request->user() !== null ? (string) $request->user()->getAuthIdentifier() : null);

        return $row !== null && $row['workspace_id'] === $this->current->id();
    }

    private static function invalidState(): JsonResponse
    {
        return response()->json(['message' => __('La conexión venció o ya se usó. Vuelve a intentarlo desde el botón.'), 'reason' => 'state'], 422);
    }
}
