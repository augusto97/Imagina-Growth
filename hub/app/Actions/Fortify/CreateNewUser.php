<?php

namespace App\Actions\Fortify;

use App\Domain\Workspaces\Models\User;
use App\Support\Registration;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        if (! Registration::allows($input['email'])) {
            throw ValidationException::withMessages([
                'email' => __('El registro es solo por invitación. Pide a quien administra Imagina Growth que te invite.'),
            ]);
        }

        return User::create([
            'name' => $input['name'],
            'email' => mb_strtolower(trim($input['email'])),
            'password' => Hash::make($input['password']),
        ]);
    }
}
