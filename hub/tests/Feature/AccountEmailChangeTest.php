<?php

use Illuminate\Support\Facades\DB;

/*
 * S11: cambiar el correo de la cuenta pide la contraseña actual (el correo es con lo que se recupera la cuenta).
 */

beforeEach(function () {
    $this->user = $this->userWith2fa(['email' => 'ana@tienda.co', 'name' => 'Ana', 'password' => 'Clave-Actual-2026']);
});

it('no cambia el correo sin la contraseña actual o con una equivocada', function () {
    $this->actingAs($this->user)->putJson('/auth/user/profile-information', ['name' => 'Ana', 'email' => 'otra@atacante.co'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->actingAs($this->user)->putJson('/auth/user/profile-information', ['name' => 'Ana', 'email' => 'otra@atacante.co', 'current_password' => 'no-es'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    expect($this->user->refresh()->email)->toBe('ana@tienda.co');
});

it('con la contraseña correcta cambia el correo y lo deja en la auditoría, enmascarado', function () {
    $this->actingAs($this->user)->putJson('/auth/user/profile-information', ['name' => 'Ana Ruiz', 'email' => 'ana.ruiz@tienda.co', 'current_password' => 'Clave-Actual-2026'])
        ->assertOk();
    expect($this->user->refresh()->email)->toBe('ana.ruiz@tienda.co')->and($this->user->name)->toBe('Ana Ruiz');
    $log = DB::table('audit_logs')->where('action', 'user.email_changed')->first();
    expect($log)->not->toBeNull()->and((string) $log->changes)->not->toContain('ana.ruiz@tienda.co')->not->toContain('ana@tienda.co');
});

it('cambiar solo el nombre (o el correo en mayúsculas) no pide la contraseña', function () {
    $this->actingAs($this->user)->putJson('/auth/user/profile-information', ['name' => 'Ana María', 'email' => 'ANA@tienda.co'])->assertOk();
    expect($this->user->refresh()->name)->toBe('Ana María');
});
