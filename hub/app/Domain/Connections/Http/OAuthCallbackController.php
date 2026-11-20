<?php

declare(strict_types=1);

namespace App\Domain\Connections\Http;

use App\Domain\Connections\Actions\CompleteAuthorization;
use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Support\ConnectionError;
use App\Domain\Connections\Support\OAuthStates;
use App\Domain\Connections\Support\ReturnUrl;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Http\Middleware\ResolveWorkspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * GET /oauth/{meta|google|tiktok}/callback — la vuelta del consentimiento (grupo `web`: necesita la sesión).
 *
 * 1. El `state` tiene que ser válido, no vencido, no usado y de la misma persona con sesión (OAuthStates).
 * 2. La persona sigue siendo del espacio de trabajo con permiso para conectar (y 2FA si su rol lo exige).
 * 3. Si canceló en el proveedor (`error`), vuelve con `estado=cancelado`; si no, se canjea el código.
 * Siempre redirige al panel (APP_URL), sin el código ni tokens en la URL.
 */
final class OAuthCallbackController
{
    public function __construct(private readonly OAuthStates $states, private readonly CompleteAuthorization $complete, private readonly CurrentWorkspace $current) {}

    public function __invoke(Request $request, string $provider): RedirectResponse
    {
        $p = ConnectionProvider::tryFrom($provider);
        abort_if($p === null || ! $p->usesRedirect(), 404);
        /** @var User|null $user */
        $user = $request->user();
        $state = $this->states->consume((string) $request->query('state', ''), $p, $user !== null ? (string) $user->getAuthIdentifier() : null);
        $workspace = $state !== null ? Workspace::query()->find($state['workspace_id']) : null;
        if ($state === null || $workspace === null || $user === null) {
            Log::notice('igr.connections.invalid_state', ['provider' => $p->value, 'signed_in' => $user !== null]);

            return redirect()->to(ReturnUrl::invalid());
        }
        $payload = $state['payload'];
        $return = (string) ($payload['return'] ?? 'conexiones');
        $back = fn (string $status, array $extra = []): RedirectResponse => redirect()->to(ReturnUrl::to($workspace->publicId(), $return, $p->value, $status, $extra));

        $role = ResolveWorkspace::roleFor($user, $workspace);
        $ability = ($payload['purpose'] ?? '') === 'calendar' ? 'manageServices' : 'manageWorkspace';
        if ($role === null || ! $role->can($ability) || (config('igr.require_two_factor') && $role->requiresTwoFactor() && ! $user->hasTwoFactorEnabled())) {
            return $back('error', ['motivo' => 'forbidden']);
        }
        $code = $request->query($p === ConnectionProvider::TikTok ? 'auth_code' : 'code') ?? $request->query('code');
        if ($request->query('error') !== null || $request->query('error_reason') !== null || ! is_string($code) || $code === '') {
            return $back('cancelado');
        }

        return $this->current->run($workspace, function () use ($workspace, $user, $p, $code, $payload, $back): RedirectResponse {
            try {
                $connection = $this->complete->handle($workspace->id, (string) $user->getAuthIdentifier(), $p, $code, $payload);
            } catch (ConnectionError $e) {
                Log::warning('igr.connections.callback_failed', ['provider' => $p->value, 'reason' => $e->reason, 'workspace' => $workspace->publicId()]);

                return $back('error', ['motivo' => $e->reason]);
            }

            return $back('ok', ['id' => $connection->publicId()]);
        });
    }
}
