<?php

use App\Domain\Workspaces\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

it('envía el enlace de restablecimiento hacia la pantalla de la SPA', function () {
    Notification::fake();
    $this->withoutVite(); // en la CI no hay public/build
    $user = User::factory()->create(['email' => 'ana@tienda.co']);

    $this->postJson('/auth/forgot-password', ['email' => 'ana@tienda.co'])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        return str_contains($url, '/restablecer/'.$notification->token) && str_contains($url, 'email=ana%40tienda.co');
    });
    $this->get('/restablecer/abc?email=ana%40tienda.co')->assertOk()->assertSee('id="root"', false);
});

it('inicia sesión por /auth/login con el proveedor de usuarios real (sin actingAs)', function () {
    User::factory()->create(['email' => 'luis@tienda.co', 'password' => 'Clave-segura-2026']);

    $this->postJson('/auth/login', ['email' => 'luis@tienda.co', 'password' => 'Clave-segura-2026'])
        ->assertOk()->assertJsonPath('two_factor', false);
    $this->getJson('/api/me')->assertOk()->assertJsonPath('user.email', 'luis@tienda.co');
});
