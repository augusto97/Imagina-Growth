<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\Spa;

use App\Domain\Api\Actions\IssueApiToken;
use App\Domain\Api\Models\ApiClient;
use App\Domain\Api\Support\ApiMeter;
use App\Domain\Api\Support\ClientResolver;
use App\Domain\Api\Support\EcosystemPresets;
use App\Domain\Api\Support\Scopes;
use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Webhooks\Actions\SaveWebhookSubscription;
use App\Domain\Webhooks\Http\WebhookPresenter;
use App\Domain\Webhooks\Support\WebhookEvents;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Pantalla «API e integraciones» (M19): resumen, tokens y las integraciones del ecosistema.
 * Solo quien puede `manageApi` (dueño y administrador).
 */
final class IntegrationsController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET integrations */
    public function overview(Entitlements $entitlements, ClientResolver $resolver): JsonResponse
    {
        Gate::authorize('ws.manageApi');
        $workspace = $this->current->get();
        $base = rtrim((string) config('app.url'), '/');
        $connected = ApiClient::query()->whereNull('revoked_at')->whereNotNull('preset')->pluck('preset')->all();

        return new JsonResponse([
            'plan' => ['api' => $entitlements->allows($workspace, 'api'), 'mcp' => $entitlements->allows($workspace, 'mcp')],
            'rate_limit_per_minute' => $resolver->perMinute($workspace),
            'requests_this_month' => ApiMeter::count($workspace->id),
            'base_url' => $base.'/api/v1',
            'mcp_url' => $base.'/api/mcp',
            'docs_url' => $base.'/api/docs',
            'scopes' => Scopes::ALL,
            'default_scopes' => Scopes::DEFAULT,
            'events' => WebhookEvents::GROUPS,
            'ecosystem' => array_map(fn (string $key, array $p) => [
                'key' => $key, 'name' => $p['name'], 'scopes' => $p['scopes'], 'events' => $p['events'],
                'connected' => in_array($key, $connected, true),
            ], array_keys(EcosystemPresets::ALL), EcosystemPresets::ALL),
        ]);
    }

    /** GET integrations/tokens */
    public function tokens(): JsonResponse
    {
        Gate::authorize('ws.manageApi');

        return new JsonResponse(['data' => ApiClient::query()->orderByDesc('created_at')->limit(200)->get()->map(fn (ApiClient $c) => self::client($c))->values()->all()]);
    }

    /** POST integrations/tokens — el token se muestra una sola vez. */
    public function createToken(Request $request, IssueApiToken $issue): JsonResponse
    {
        Gate::authorize('ws.manageApi');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'kind' => ['sometimes', Rule::in(['token', 'mcp'])],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(Scopes::ALL)],
        ]);
        if (ApiClient::query()->whereNull('revoked_at')->count() >= IssueApiToken::MAX_ACTIVE) {
            return new JsonResponse(['message' => __('Llegaste al máximo de :n tokens activos. Revoca los que no uses.', ['n' => IssueApiToken::MAX_ACTIVE])], 422);
        }
        [$client, $plain] = $issue->handle($this->current->id(), (string) $data['name'], (string) ($data['kind'] ?? 'token'), array_values($data['scopes']), $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => self::client($client), 'token' => $plain], 201);
    }

    /** DELETE integrations/tokens/{client} */
    public function revokeToken(string $client, IssueApiToken $issue): JsonResponse
    {
        Gate::authorize('ws.manageApi');
        $model = ApiClient::findByPublicId($client) ?? abort(404);
        $issue->revoke($model);

        return new JsonResponse(['data' => self::client($model->refresh())]);
    }

    /**
     * POST integrations/ecosystem/{preset} — conecta un producto de Imagina: token con sus
     * alcances y, si tiene eventos y se da la URL, el webhook.
     */
    public function connectPreset(Request $request, string $preset, IssueApiToken $issue, SaveWebhookSubscription $save): JsonResponse
    {
        Gate::authorize('ws.manageApi');
        $config = EcosystemPresets::ALL[$preset] ?? abort(404);
        $data = $request->validate(['webhook_url' => ['nullable', 'url', 'max:500']]);
        $userId = $request->user()?->getAuthIdentifier();
        [$client, $plain] = $issue->handle($this->current->id(), $config['name'], 'token', $config['scopes'], is_string($userId) ? $userId : null, $preset);
        $webhook = null;
        $secret = null;
        if ($config['events'] !== [] && ! empty($data['webhook_url'])) {
            [$sub, $secret] = $save->handle($this->current->id(), null, [
                'name' => $config['name'], 'url' => (string) $data['webhook_url'], 'events' => $config['events'],
                'include_pii' => $config['include_pii'], 'preset' => $preset,
            ], is_string($userId) ? $userId : null, 'user');
            $webhook = WebhookPresenter::subscription($sub);
        }

        return new JsonResponse(['data' => self::client($client), 'token' => $plain, 'webhook' => $webhook, 'secret' => $secret], 201);
    }

    /** @return array<string, mixed> */
    public static function client(ApiClient $c): array
    {
        return [
            'id' => $c->publicId(),
            'name' => $c->name,
            'kind' => $c->kind,
            'preset' => $c->preset,
            'scopes' => array_values((array) $c->scopes),
            'token_prefix' => $c->token_prefix,
            'last_used_at' => $c->last_used_at?->toIso8601String(),
            'revoked_at' => $c->revoked_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
