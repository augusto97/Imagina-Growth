<?php

use Illuminate\Support\Facades\Validator;

/*
 * U6 (docs/auditoria-2026-11.md): ningún error de validación del servidor sale en inglés. Cada regla de Laravel
 * tiene su texto en lang/es/validation.php.
 */

it('lang/es/validation.php tiene todas las reglas de Laravel', function () {
    $flatten = function (array $tree, string $prefix = '') use (&$flatten): array {
        $out = [];
        foreach ($tree as $key => $value) {
            if (in_array($key, ['custom', 'attributes'], true) && $prefix === '') {
                continue;
            }
            $out = array_merge($out, is_array($value) ? $flatten($value, $prefix.$key.'.') : [$prefix.$key]);
        }

        return $out;
    };
    $english = $flatten(require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'));
    $spanish = $flatten(require lang_path('es/validation.php'));

    expect(array_values(array_diff($english, $spanish)))->toBe([]);
});

it('valida en español con el nombre del campo', function () {
    app()->setLocale('es');
    $errors = Validator::make(
        ['email' => 'x', 'privacy_policy_url' => 'ftp:/x', 'from' => '2026-11-20', 'to' => '2026-11-01', 'name' => str_repeat('a', 200)],
        ['email' => 'email', 'privacy_policy_url' => 'url', 'to' => 'after:from', 'name' => 'max:120', 'phone' => 'required_without:email'],
    )->errors();

    expect($errors->first('email'))->toBe('Escribe un correo válido.')
        ->and($errors->first('privacy_policy_url'))->toBe('La URL de la política de privacidad debe ser una dirección web completa, que empiece por https://.')
        ->and($errors->first('to'))->toBe('La fecha final debe ser una fecha posterior a la fecha de inicio.')
        ->and($errors->first('name'))->toBe('El nombre no puede tener más de 120 caracteres.');
});
