<?php

use App\Domain\Workspaces\Actions\InviteToWorkspace;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Mail\InvitationMail;
use App\Domain\Workspaces\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

it('registra a una persona y le crea su primer espacio de trabajo como dueña', function () {
    $this->postJson('/auth/register', [
        'name' => 'Augusto', 'email' => 'Dueno@Tienda.co', 'password' => 'Clave-segura-2026', 'password_confirmation' => 'Clave-segura-2026',
    ])->assertCreated();
    $user = User::query()->where('email', 'dueno@tienda.co')->firstOrFail();

    $res = $this->actingAs($user)->postJson('/api/workspaces', ['name' => 'Pinturas La 14', 'country' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota'])
        ->assertCreated()
        ->assertJsonPath('role', 'owner')
        ->assertJsonPath('requires_two_factor', true);
    expect($res->json('id'))->toStartWith('ws_');

    $this->getJson('/api/me')->assertOk()->assertJsonCount(1, 'workspaces')->assertJsonPath('user.two_factor_enabled', false);
    expect(DB::table('audit_logs')->where('action', 'workspace.created')->count())->toBe(1);
});

it('exige 2FA a owner y admin pero no a marketing (M01)', function () {
    $owner = User::factory()->create();
    $ws = $this->workspaceFor($owner);
    $marketing = $this->addMember($ws, WorkspaceRole::Marketing);
    $admin = $this->addMember($ws, WorkspaceRole::Admin);

    $this->actingAs($owner)->getJson("/api/w/{$ws->publicId()}")->assertForbidden()->assertJsonPath('error', 'two_factor_required');
    $this->actingAs($admin)->getJson("/api/w/{$ws->publicId()}")->assertForbidden();
    $this->actingAs($marketing)->getJson("/api/w/{$ws->publicId()}")->assertOk()->assertJsonPath('role', 'marketing');

    $owner->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
    $this->actingAs($owner->fresh())->getJson("/api/w/{$ws->publicId()}")->assertOk();
});

it('responde 404 a quien no es miembro, sin confirmar que el espacio existe', function () {
    $ws = $this->workspaceFor($this->userWith2fa());
    $this->actingAs($this->userWith2fa())->getJson("/api/w/{$ws->publicId()}")->assertNotFound();
    $this->actingAs($this->userWith2fa())->getJson('/api/w/ws_01JD3QF0A1B2C3D4E5F6G7H8J9')->assertNotFound();
});

it('da acceso a la agencia a todos los espacios de su cuenta', function () {
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    $agency = $this->userWith2fa();
    $ws->account->users()->attach($agency->id, ['role' => 'agency_member']);

    $this->actingAs($agency)->getJson("/api/w/{$ws->publicId()}")->assertOk()->assertJsonPath('role', 'admin');
    $this->actingAs($agency)->postJson('/api/workspaces', ['name' => 'Cliente 2', 'account_id' => 'acc_'.$ws->account_id])->assertCreated();
    $this->actingAs($this->userWith2fa())->postJson('/api/workspaces', ['name' => 'Intruso', 'account_id' => 'acc_'.$ws->account_id])->assertForbidden();
});

it('solo owner y admin cambian los ajustes del espacio de trabajo', function () {
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    $viewer = $this->addMember($ws, WorkspaceRole::Viewer);

    $this->actingAs($viewer)->patchJson("/api/w/{$ws->publicId()}", ['name' => 'Otro'])->assertForbidden();
    $this->actingAs($owner)->patchJson("/api/w/{$ws->publicId()}", ['name' => 'Pinturas La 15', 'timezone' => 'America/Mexico_City'])
        ->assertOk()->assertJsonPath('name', 'Pinturas La 15');
    expect(DB::table('audit_logs')->where('action', 'workspace.updated')->exists())->toBeTrue();
});

it('invita, acepta, cambia de rol y protege al último dueño', function () {
    Mail::fake();
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    $wsId = $ws->publicId();

    $this->actingAs($owner)->postJson("/api/w/{$wsId}/invitations", ['email' => 'ana@agencia.co', 'role' => 'marketing'])->assertCreated();
    $token = null;
    Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) use (&$token) {
        $token = $mail->token;

        return $mail->hasTo('ana@agencia.co');
    });

    $this->getJson("/api/invitations/{$token}")->assertOk()->assertJsonPath('role', 'marketing')->assertJsonPath('workspace', 'Pinturas La 14');

    $ana = User::factory()->create(['email' => 'ana@agencia.co']);
    $other = User::factory()->create();
    $this->actingAs($other)->postJson("/api/invitations/{$token}/accept")->assertUnprocessable();
    $this->actingAs($ana)->postJson("/api/invitations/{$token}/accept")->assertOk();
    $this->actingAs($ana)->postJson("/api/invitations/{$token}/accept")->assertUnprocessable();

    $this->actingAs($ana)->getJson("/api/w/{$wsId}/members")->assertOk()->assertJsonCount(2, 'members');
    $this->actingAs($ana)->postJson("/api/w/{$wsId}/invitations", ['email' => 'x@y.co', 'role' => 'viewer'])->assertForbidden();

    $this->actingAs($owner)->patchJson("/api/w/{$wsId}/members/{$ana->publicId()}", ['role' => 'admin'])->assertOk();
    $this->actingAs($owner)->deleteJson("/api/w/{$wsId}/members/{$owner->publicId()}")->assertUnprocessable();

    $ana->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
    $this->actingAs($ana->fresh())->patchJson("/api/w/{$wsId}/members/{$owner->publicId()}", ['role' => 'viewer'])->assertUnprocessable();
    $this->actingAs($ana->fresh())->postJson("/api/w/{$wsId}/invitations", ['email' => 'jefe@y.co', 'role' => 'owner'])->assertUnprocessable();

    expect(DB::table('audit_logs')->whereIn('action', ['member.invited', 'member.joined', 'member.role_changed'])->count())->toBe(3);
});

it('responde los errores de validación en español', function () {
    $this->postJson('/auth/register', ['name' => '', 'email' => 'no-es-correo', 'password' => 'x'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Escribe un correo válido.')
        ->assertJsonPath('errors.name.0', 'El nombre es obligatorio.');
});

it('el registro es solo por invitación salvo para la primera persona (S3)', function () {
    $register = fn (string $email) => $this->postJson('/auth/register', [
        'name' => 'Persona', 'email' => $email, 'password' => 'Clave-segura-2026', 'password_confirmation' => 'Clave-segura-2026',
    ]);

    $register('primera@tienda.co')->assertCreated();          // instalación nueva: la primera persona administra
    auth()->logout();
    $register('curioso@otro.co')->assertUnprocessable()->assertJsonValidationErrors('email');

    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    app(InviteToWorkspace::class)->handle($ws, $owner, 'Invitada@Tienda.co', WorkspaceRole::Marketing);
    $register('invitada@tienda.co')->assertCreated();
    auth()->logout();

    config(['igr.registration_open' => true]);
    $register('abierto@otro.co')->assertCreated();
});

it('el enlace de restablecer usa el dominio del panel y no el Host de la petición (S4)', function () {
    Notification::fake();
    config(['app.url' => 'https://growth.ejemplo.co']);
    $user = User::factory()->create(['email' => 'ana@tienda.co']);

    $this->withHeader('Host', 'atacante.example')->postJson('/auth/forgot-password', ['email' => 'ana@tienda.co'])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function ($n) use ($user) {
        $url = $n->toMail($user)->actionUrl;

        return str_starts_with($url, 'https://growth.ejemplo.co/restablecer/') && ! str_contains($url, 'atacante');
    });
});

it('el panel no se deja incrustar en otro sitio (S12)', function () {
    $this->get('/login')->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
});
