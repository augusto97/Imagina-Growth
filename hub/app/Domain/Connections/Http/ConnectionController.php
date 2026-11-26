<?php

declare(strict_types=1);

namespace App\Domain\Connections\Http;

use App\Domain\Connections\Actions\DisconnectConnection;
use App\Domain\Connections\Actions\StartAuthorization;
use App\Domain\Connections\Actions\UseConnectionForMeasurement;
use App\Domain\Connections\Assets\GoogleAssets;
use App\Domain\Connections\Assets\MetaAssets;
use App\Domain\Connections\Assets\TikTokAssets;
use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Connections\Providers\ProviderRegistry;
use App\Domain\Connections\Support\ConnectionAlerts;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Connections\Support\ConnectionTokens;
use App\Domain\Measurement\AdSpend\ImportAdSpend;
use App\Domain\Measurement\Conversions\TargetRegistry;
use App\Domain\Measurement\Http\Controllers\DestinationController;
use App\Domain\Messaging\Actions\ConnectSocialAccount;
use App\Domain\Messaging\Channels\Drivers\MetaInstagramDriver;
use App\Domain\Messaging\Channels\Drivers\MetaMessengerDriver;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Http\Api\ChannelAccountPresenter;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Conexiones con un clic (docs/auditoria-2026-11.md §6): la pantalla «Conexiones» y los botones de Canales y
 * Medición. Ver: viewWorkspace. Conectar, elegir cuentas y desconectar: manageWorkspace (dueño y administración).
 * Los errores del proveedor vuelven como 422 con `reason` y un mensaje para el dueño del negocio.
 */
final class ConnectionController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly ProviderRegistry $providers) {}

    /** GET /api/w/{ws}/connections */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');
        $connections = OAuthConnection::query()->where('status', '!=', OAuthConnection::REVOKED)->orderBy('created_at')->get();
        $out = [];
        foreach (ConnectionProvider::cases() as $provider) {
            $oauth = $this->providers->for($provider);
            $mine = $connections->filter(fn (OAuthConnection $c) => $c->provider === $provider && ! str_starts_with($c->subject, 'staff:'));
            $out[] = [
                'key' => $provider->value,
                'configured' => $oauth->missing() === [],
                'missing' => $oauth->missing(),
                'redirect_uri' => $provider->usesRedirect() ? $provider->callbackUrl() : null,
                'connections' => $mine->map(fn (OAuthConnection $c) => ConnectionPresenter::present($c))->values(),
            ];
        }

        return response()->json([
            'providers' => $out,
            'calendar_connections' => $connections->filter(fn (OAuthConnection $c) => str_starts_with($c->subject, 'staff:'))->count(),
        ]);
    }

    /**
     * GET /api/w/{ws}/connections/alerts — lo que vence en 7 días o menos, lo vencido o inválido y los números que Meta
     * desconectó, para el aviso con «Reconectar» del Inicio y de Conexiones (ConnectionAlerts). Nunca tokens.
     */
    public function alerts(ConnectionAlerts $alerts): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');

        return response()->json(['data' => $alerts->problems()]);
    }

    /** POST /api/w/{ws}/connections/{meta|google|tiktok}/start — URL de consentimiento (la SPA navega a ella). */
    public function start(Request $request, string $provider, StartAuthorization $start): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $p = ConnectionProvider::from($provider);
        $data = $request->validate(['return' => ['sometimes', 'string', Rule::in(array_keys((array) config('igr-connections.return_paths')))]]);
        $purpose = $p === ConnectionProvider::Google ? 'measurement' : 'business';

        return $this->guard(fn () => response()->json([
            'url' => $start->handle($this->current->id(), (string) $request->user()?->getAuthIdentifier(), $p, $purpose, '', (string) ($data['return'] ?? 'conexiones')),
        ]));
    }

    /** GET /api/w/{ws}/connections/{id}/assets — cuentas para elegir (?customer=&login= | ?property= | ?advertiser=). */
    public function assets(Request $request, string $connection, MetaAssets $meta, GoogleAssets $google, TikTokAssets $tiktok): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $model = $this->find($connection);
        $data = $request->validate([
            'customer' => ['sometimes', 'nullable', 'string', 'regex:/^[\d-]{6,20}$/'],
            'login' => ['sometimes', 'nullable', 'string', 'regex:/^[\d-]{6,20}$/'],
            'property' => ['sometimes', 'nullable', 'string', 'regex:/^(properties\/)?\d{3,20}$/'],
            'advertiser' => ['sometimes', 'nullable', 'string', 'regex:/^\d{3,30}$/'],
        ]);

        return $this->guard(fn () => response()->json(match ($model->provider) {
            ConnectionProvider::Meta => $meta->list($model),
            ConnectionProvider::Google => match (true) {
                isset($data['customer']) => ['conversion_actions' => $google->conversionActions($model, (string) $data['customer'], $data['login'] ?? null)],
                isset($data['property']) => ['streams' => $google->webStreams($model, (string) $data['property'])],
                default => $google->list($model),
            },
            ConnectionProvider::TikTok => isset($data['advertiser']) ? ['pixels' => $tiktok->pixels($model, (string) $data['advertiser'])] : $tiktok->list($model),
            ConnectionProvider::MetaWhatsApp => ['whatsapp' => $model->asset('whatsapp')],
        }));
    }

    /** POST /api/w/{ws}/connections/{id}/measurement — el destino de Medición de esa plataforma usa la conexión. */
    public function measurement(Request $request, string $connection, UseConnectionForMeasurement $use, TargetRegistry $targets, ImportAdSpend $spend): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $model = $this->find($connection);
        $fields = ['dataset_id', 'ad_account_id', 'ads_customer_id', 'ads_login_customer_id', 'conversion_action_purchase', 'conversion_action_lead', 'conversion_action_lead_won', 'conversion_action_contact', 'advertiser_id', 'pixel_code'];
        $rules = array_fill_keys($fields, ['sometimes', 'nullable', 'string', 'max:64']);
        $rules['ga4_stream'] = ['sometimes', 'nullable', 'string', 'regex:#^properties/\d+/dataStreams/\d+$#'];
        $data = $request->validate($rules);

        return $this->guard(fn () => response()->json(['destination' => DestinationController::present($use->handle($model, $data), $targets, $spend)]));
    }

    /** POST /api/w/{ws}/connections/{id}/social — Messenger o Instagram de una página autorizada en Meta. */
    public function social(Request $request, string $connection, MetaAssets $meta, ConnectSocialAccount $connect, MetaMessengerDriver $messenger, MetaInstagramDriver $instagram): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $model = $this->find($connection);
        abort_unless($model->provider === ConnectionProvider::Meta, 422, __('Esta conexión no es de Meta.'));
        $data = $request->validate([
            'channel' => ['required', Rule::in(['messenger', 'instagram'])],
            'page_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
        ]);

        return $this->guard(function () use ($model, $data, $meta, $connect, $messenger, $instagram): JsonResponse {
            $page = $meta->pageToken($model, $data['page_id']);
            $config = ['page_id' => $page['id'], 'page_access_token' => $page['access_token']];
            if ($data['channel'] === 'instagram') {
                if ($page['instagram_id'] === null) {
                    throw new ConnectionError(__('Esa página no tiene una cuenta profesional de Instagram vinculada.'), 'asset');
                }
                $config['ig_user_id'] = $page['instagram_id'];
            }
            $account = $data['channel'] === 'instagram'
                ? $connect->handle($instagram, Channel::Instagram, $config, null, 'oauth', $model->id)
                : $connect->handle($messenger, Channel::Messenger, $config, null, 'oauth', $model->id);

            return response()->json(['data' => ChannelAccountPresenter::present($account)], 201);
        });
    }

    /** POST /api/w/{ws}/connections/{id}/check — comprueba ahora que el acceso siga sirviendo. */
    public function check(string $connection, ConnectionTokens $tokens): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $model = $this->find($connection);

        return $this->guard(function () use ($model, $tokens): JsonResponse {
            try {
                $model->provider === ConnectionProvider::Google ? $tokens->renew($model) : $this->providers->for($model->provider)->check($model);
            } catch (ConnectionError $e) {
                if ($e->permanent) {
                    $tokens->markExpired($model, $e->getMessage());
                }
                throw $e;
            } finally {
                $model->forceFill(['checked_at' => now()])->save();
            }

            return response()->json(['data' => ConnectionPresenter::present($model->refresh())]);
        });
    }

    /** DELETE /api/w/{ws}/connections/{id} — revoca, borra los tokens y suelta lo que la usaba. */
    public function destroy(string $connection, DisconnectConnection $disconnect): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $disconnect->handle($this->find($connection));

        return response()->json(['ok' => true]);
    }

    /** @param callable(): JsonResponse $fn */
    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (ConnectionError $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }
    }

    private function find(string $publicId): OAuthConnection
    {
        $model = OAuthConnection::findByPublicId($publicId);
        abort_if($model === null || $model->status === OAuthConnection::REVOKED, 404);

        return $model;
    }
}
