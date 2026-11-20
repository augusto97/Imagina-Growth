<?php

declare(strict_types=1);

namespace App\Domain\Platform\Http;

use App\Domain\Platform\Actions\PromotePlatformAdmin;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Plataforma → Ajustes → Administradores (F17): quién administra la plataforma, sin consola (antes `igr:admin:create`,
 * que sigue para la primera persona de una instalación). Agregar o quitar pide la contraseña de quien lo hace. A una
 * persona nueva se le genera una contraseña que se muestra una sola vez; al entrar se le pide la verificación en dos
 * pasos. Nadie se quita a sí mismo y nunca queda la plataforma sin administradores.
 */
final class PlatformAdminsController
{
    /** GET /api/platform/admins */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->list($request->user())]);
    }

    /** POST /api/platform/admins {email, name?, password} */
    public function store(Request $request, PromotePlatformAdmin $promote): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $actor = $request->user();
        if (! $actor instanceof User || ! Hash::check((string) $data['password'], (string) $actor->getAuthPassword())) {
            return response()->json(['error' => 'invalid_password', 'message' => __('Tu contraseña no es correcta.'), 'errors' => ['password' => [__('Tu contraseña no es correcta.')]]], 422);
        }
        $email = mb_strtolower(trim((string) $data['email']));
        $exists = User::query()->where('email', $email)->exists();
        // Solo a una persona nueva se le crea una contraseña (se muestra una vez); la de alguien que ya existe no cambia.
        $password = $exists ? null : Str::password(20, symbols: false);
        ['user' => $user, 'created' => $created] = $promote->handle($email, isset($data['name']) ? (string) $data['name'] : null, $password);

        return response()->json([
            'created' => $created,
            'admin' => $this->present($user, $actor),
            'password' => $password,
            'data' => $this->list($actor),
        ], $created ? 201 : 200, ['Cache-Control' => 'no-store']);
    }

    /** DELETE /api/platform/admins/{user} {password} */
    public function destroy(Request $request, string $user, RecordAuditLog $audit): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:200']]);
        $actor = $request->user();
        if (! $actor instanceof User || ! Hash::check((string) $data['password'], (string) $actor->getAuthPassword())) {
            return response()->json(['error' => 'invalid_password', 'message' => __('Tu contraseña no es correcta.'), 'errors' => ['password' => [__('Tu contraseña no es correcta.')]]], 422);
        }
        $target = User::findByPublicId($user);
        abort_if($target === null || ! (bool) ($target->getAttributes()['is_platform_admin'] ?? false), 404);
        if ($target->getKey() === $actor->getKey()) {
            return response()->json(['error' => 'self', 'message' => __('No puedes quitarte a ti mismo: pídeselo a otra persona que administre la plataforma.')], 422);
        }
        if (User::query()->where('is_platform_admin', true)->count() <= 1) {
            return response()->json(['error' => 'last_admin', 'message' => __('La plataforma no puede quedar sin administradores.')], 422);
        }
        DB::transaction(fn () => $target->forceFill(['is_platform_admin' => false])->save());
        $audit->handle('platform.admin_removed', $target);

        return response()->json(['data' => $this->list($actor)]);
    }

    /** @return list<array<string, mixed>> */
    private function list(mixed $actor): array
    {
        $out = [];
        foreach (User::query()->where('is_platform_admin', true)->orderBy('name')->get() as $user) {
            $out[] = $this->present($user, $actor);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function present(User $user, mixed $actor): array
    {
        $last = $user->getAttribute('last_login_at');

        return [
            'id' => $user->publicId(),
            'name' => $user->name,
            'email' => $user->email,
            'two_factor' => $user->hasTwoFactorEnabled(),
            'last_login_at' => $last instanceof \DateTimeInterface ? $last->format(DATE_ATOM) : null,
            'is_me' => $actor instanceof User && $actor->getKey() === $user->getKey(),
        ];
    }
}
