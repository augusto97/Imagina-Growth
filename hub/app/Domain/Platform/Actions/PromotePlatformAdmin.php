<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Billing\Plans\PlatformAccounts;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\User;
use Illuminate\Support\Str;

/**
 * Crea o promueve a quien administra la plataforma (F15, F17). Lo usan `igr:admin:create` (la primera persona, desde
 * el servidor) y Plataforma → Ajustes → Administradores. Una persona que ya existe solo se promueve (su contraseña no
 * cambia salvo que se pida); sus cuentas sin plan reciben el plan de la plataforma. La verificación en dos pasos se le
 * pide al entrar.
 */
final class PromotePlatformAdmin
{
    public function __construct(private readonly RecordAuditLog $audit, private readonly PlanResolver $plans) {}

    /**
     * @return array{user: User, created: bool, accounts: int}
     */
    public function handle(string $email, ?string $name = null, ?string $password = null, ?string $actorId = null): array
    {
        $email = mb_strtolower(trim($email));
        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();
        $isNew = $user === null;
        if ($user === null) {
            $name = trim((string) $name);
            $user = new User;
            $user->forceFill(['name' => $name !== '' ? mb_substr($name, 0, 255) : Str::before($email, '@'), 'email' => $email, 'email_verified_at' => now()]);
        }
        $user->forceFill(['is_platform_admin' => true]);
        if ($password !== null) {
            $user->forceFill(['password' => $password]);
        }
        $user->save();

        $granted = PlatformAccounts::grant($user->id);
        foreach (Account::query()->whereIn('id', $granted)->get() as $account) {
            $this->plans->refreshAccount($account);
        }
        $this->audit->handle($isNew ? 'platform.admin_created' : 'platform.admin_promoted', $user,
            ['password_changed' => $password !== null, 'accounts_with_platform_plan' => count($granted)], null, null, $actorId);

        return ['user' => $user, 'created' => $isNew, 'accounts' => count($granted)];
    }
}
