<?php

declare(strict_types=1);

namespace App\Domain\Updates\Http;

use App\Domain\Updates\Support\ReleaseFeed;
use App\Domain\Updates\Support\UpdateState;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\AppVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Actualizaciones desde el panel de la plataforma (docs/despliegue.md §5). Solo superadmin con 2FA
 * (EnsurePlatformAdmin). El panel **no** actualiza: deja un pedido que toma growth-update-dispatch (root).
 */
final class UpdateController
{
    public function __construct(private readonly UpdateState $state) {}

    /** GET /api/platform/updates */
    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /** POST /api/platform/updates/check: busca ahora en vez de esperar a la próxima hora. */
    public function check(): JsonResponse
    {
        Artisan::call('growth:updates:check');

        return response()->json($this->payload());
    }

    /** POST /api/platform/updates/install {version, password} */
    public function install(Request $request, RecordAuditLog $audit): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $user = $request->user();
        if (! $user instanceof User || ! Hash::check($data['password'], (string) $user->getAuthPassword())) {
            return response()->json(['error' => 'invalid_password', 'message' => __('La contraseña no es correcta.')], 422);
        }
        if ($this->state->busy()) {
            return response()->json(['error' => 'update_in_progress', 'message' => __('Ya hay una actualización en curso.')], 409);
        }
        $latest = $this->state->latest();
        $current = AppVersion::current();
        if (($latest['version'] ?? null) !== $data['version'] || ! ReleaseFeed::isNewer($data['version'], $current)) {
            return response()->json(['error' => 'version_not_available', 'message' => __('Esa versión no es la última publicada o no es más nueva que la instalada (:current).', ['current' => $current])], 422);
        }

        $id = 'upd_'.Str::random(24);
        $this->state->write(UpdateState::REQUEST, [
            'version' => $data['version'],
            'id' => preg_replace('/[^A-Za-z0-9_]/', '', $id),
            'requested_by' => $user->publicId(),
            'requested_at' => now()->toIso8601String(),
        ]);
        $audit->handle('platform.update_requested', null, ['from' => $current, 'to' => $data['version'], 'request' => $id], null, null, (string) $user->getKey());

        return response()->json($this->payload(), 202);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $current = AppVersion::current();
        $latest = $this->state->latest();
        $available = is_string($latest['version'] ?? null) && ReleaseFeed::isNewer($latest['version'], $current);
        $busy = $this->state->busy();
        $pending = $this->state->pending();

        return [
            'current' => $current,
            'latest' => is_string($latest['version'] ?? null) ? array_intersect_key($latest, array_flip(['version', 'tag', 'name', 'notes', 'published_at', 'html_url'])) : null,
            'checked_at' => $latest['checked_at'] ?? null,
            'check_error' => $latest['error'] ?? null,
            'update_available' => $available,
            'busy' => $busy,
            'can_install' => $available && ! $busy,
            'pending' => $pending === null ? null : array_intersect_key($pending, array_flip(['version', 'id', 'requested_by', 'requested_at', 'accepted_at'])),
            'status' => $this->state->status(),
            'history' => $this->state->history(10),
        ];
    }
}
