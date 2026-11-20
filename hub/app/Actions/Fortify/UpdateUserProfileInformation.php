<?php

namespace App\Actions\Fortify;

use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Pii\Pii;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information. Cambiar el correo pide la contraseña actual (S11):
     * el correo es con lo que se recupera la cuenta, así que una sesión robada no debe poder cambiarlo.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'current_password' => ['nullable', 'string', 'max:255'],
        ])->validateWithBag('updateProfileInformation');

        $emailChanged = mb_strtolower(trim($input['email'])) !== mb_strtolower((string) $user->email);
        if ($emailChanged && ! Hash::check((string) ($input['current_password'] ?? ''), (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('Para cambiar el correo escribe tu contraseña actual.'),
            ])->errorBag('updateProfileInformation');
        }
        $previous = (string) $user->email;

        if ($emailChanged && $user instanceof MustVerifyEmail) {
            $this->updateVerifiedUser($user, $input);
        } else {
            $user->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
            ])->save();
        }

        if ($emailChanged) {
            app(RecordAuditLog::class)->handle('user.email_changed', $user, ['from' => Pii::maskEmail($previous), 'to' => Pii::maskEmail((string) $input['email'])], null, null, $user->id);
        }
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
