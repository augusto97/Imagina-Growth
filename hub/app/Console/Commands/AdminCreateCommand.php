<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Platform\Actions\PromotePlatformAdmin;
use App\Domain\Workspaces\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Crea o promueve a quien administra la plataforma (F15), sin tocar la base a mano. Hace falta para la primera persona
 * de una instalación; después, las demás se agregan en Plataforma → Ajustes → Administradores (F17). La contraseña se
 * pide dos veces sin mostrarla o, con --generate, se genera y se muestra una sola vez; nunca va a los registros. Una
 * persona que ya existe solo se promueve (su contraseña no cambia salvo con --reset-password). Sus cuentas sin plan
 * reciben el plan de la plataforma. La verificación en dos pasos se le pide al entrar.
 */
final class AdminCreateCommand extends Command
{
    protected $signature = 'igr:admin:create
        {email : Correo de la persona}
        {--name= : Nombre (si es nueva)}
        {--generate : Genera una contraseña segura y la muestra una sola vez}
        {--reset-password : Si la persona ya existe, también le cambia la contraseña}';

    protected $description = 'Crea o promueve un administrador de la plataforma (superadmin)';

    public function handle(PromotePlatformAdmin $promote): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:255']])->fails()) {
            $this->error(__('El correo no es válido.'));

            return self::FAILURE;
        }
        $isNew = ! User::query()->where('email', $email)->exists();
        $password = null;
        if ($isNew || $this->option('reset-password')) {
            $password = $this->password();
            if ($password === null) {
                return self::FAILURE;
            }
        }
        $name = null;
        if ($isNew) {
            $name = trim((string) ($this->option('name') ?: ($this->input->isInteractive() ? $this->ask(__('Nombre'), Str::before($email, '@')) : Str::before($email, '@'))));
        }
        ['user' => $user, 'accounts' => $granted] = $promote->handle($email, $name, $password);

        $this->info($isNew ? __('Listo: :email administra la plataforma.', ['email' => $email]) : __('Listo: :email ya existía y ahora administra la plataforma.', ['email' => $email]));
        if ($password !== null && $this->option('generate')) {
            $this->line(__('Contraseña (se muestra una sola vez): :p', ['p' => $password]));
        }
        if ($granted > 0) {
            $this->line(trans_choice(':n cuenta suya quedó con el plan de la plataforma.|:n cuentas suyas quedaron con el plan de la plataforma.', $granted, ['n' => $granted]));
        }
        if (config('igr.require_two_factor') && $user->two_factor_confirmed_at === null) {
            $this->warn(__('Al entrar se le pedirá activar la verificación en dos pasos: sin ella no puede abrir Plataforma ni Horizon.'));
        }

        return self::SUCCESS;
    }

    private function password(): ?string
    {
        if ($this->option('generate')) {
            return Str::password(20, symbols: false);
        }
        if (! $this->input->isInteractive()) {
            $this->error(__('Sin terminal interactiva usa --generate para crear la contraseña.'));

            return null;
        }
        for ($try = 0; $try < 3; $try++) {
            $first = (string) $this->secret(__('Contraseña'));
            $second = (string) $this->secret(__('Repite la contraseña'));
            $validator = Validator::make(['password' => $first, 'password_confirmation' => $second], ['password' => ['required', 'string', Password::default(), 'confirmed']]);
            if (! $validator->fails()) {
                return $first;
            }
            $this->error((string) $validator->errors()->first('password'));
        }

        return null;
    }
}
