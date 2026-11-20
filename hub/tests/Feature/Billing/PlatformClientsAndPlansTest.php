<?php

use App\Domain\Billing\Models\Plan;
use App\Domain\Workspaces\Mail\InvitationMail;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\Invitation;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
 * Administración de planes y clientes desde la plataforma (y alta de clientes desde la agencia).
 */

beforeEach(function () {
    Mail::fake();
    $this->admin = $this->userWith2fa(['is_platform_admin' => true]);
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
});

function clientPayload(array $over = []): array
{
    return array_replace_recursive([
        'name' => 'Ferretería El Tornillo',
        'type' => 'direct',
        'country' => 'CO',
        'plan_id' => 'tienda_inicio',
        'owner_email' => 'Dueno@ElTornillo.co',
        'workspace' => ['name' => 'El Tornillo', 'country' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota'],
    ], $over);
}

it('los planes por defecto quedan cargados por la migración (antes el selector salía vacío)', function () {
    expect(Plan::query()->count())->toBeGreaterThan(0)
        ->and(Plan::query()->whereKey('tienda_inicio')->exists())->toBeTrue();
});

it('solo el superadmin administra planes y clientes', function (string $method, string $path) {
    $this->actingAs($this->owner)->json($method, '/api/platform/'.$path, [])->assertForbidden();
})->with([
    ['GET', 'plans'], ['POST', 'plans'], ['PATCH', 'plans/tienda_inicio'], ['POST', 'plans/tienda_inicio/archive'],
    ['POST', 'accounts'], ['GET', 'accounts/acc_x'], ['PATCH', 'accounts/acc_x'], ['POST', 'accounts/acc_x/workspaces'], ['POST', 'accounts/acc_x/invitations'],
]);

it('crea, edita y archiva un plan; el archivado no se puede asignar', function () {
    $body = [
        'id' => 'servicios_prueba', 'name' => 'Servicios · Pro', 'metric' => 'contacts', 'included' => 5000,
        'modules' => ['inbox', 'ai', 'services', 'bookings', 'no_existe'], 'limits' => ['emails' => 20000, 'users' => 5, 'ai_budget_usd' => 15, 'api_requests_per_minute' => null],
        'price_usd' => 99, 'price_cop' => 399000, 'public' => true,
    ];
    $this->actingAs($this->admin)->postJson('/api/platform/plans', $body)->assertUnprocessable()->assertJsonValidationErrors('modules.4');
    unset($body['modules'][4]);
    $this->actingAs($this->admin)->postJson('/api/platform/plans', $body)->assertCreated()
        ->assertJsonPath('plan.id', 'servicios_prueba')->assertJsonPath('plan.limits.users', 5);
    $this->actingAs($this->admin)->postJson('/api/platform/plans', $body)->assertUnprocessable()->assertJsonValidationErrors('id');

    $this->actingAs($this->admin)->patchJson('/api/platform/plans/servicios_prueba', ['name' => 'Servicios · Pro+', 'included' => 8000] + $body)
        ->assertOk()->assertJsonPath('plan.name', 'Servicios · Pro+')->assertJsonPath('plan.included', 8000);

    $this->actingAs($this->admin)->postJson('/api/platform/plans/servicios_prueba/archive')->assertOk()->assertJsonPath('plan.archived', true);
    $account = $this->ws->account;
    $this->actingAs($this->admin)->putJson('/api/platform/accounts/'.$account->publicId().'/plan', ['plan_id' => 'servicios_prueba'])
        ->assertUnprocessable();
    $this->actingAs($this->admin)->postJson('/api/platform/plans/servicios_prueba/restore')->assertOk()->assertJsonPath('plan.archived', false);
    $this->actingAs($this->admin)->putJson('/api/platform/accounts/'.$account->publicId().'/plan', ['plan_id' => 'servicios_prueba'])->assertOk();

    $list = $this->actingAs($this->admin)->getJson('/api/platform/plans')->assertOk();
    expect(collect($list->json('plans'))->firstWhere('id', 'servicios_prueba')['accounts'])->toBe(1);
});

it('da de alta un cliente con su espacio, su plan y la invitación al dueño, sin meter al superadmin', function () {
    $res = $this->actingAs($this->admin)->postJson('/api/platform/accounts', clientPayload())->assertCreated();
    $account = Account::findByPublicId((string) $res->json('account.id'));
    $workspace = Workspace::query()->where('account_id', $account->id)->sole();

    expect($account->name)->toBe('Ferretería El Tornillo')
        ->and($account->plan_id)->toBe('tienda_inicio')
        ->and($account->billing_email)->toBe('Dueno@ElTornillo.co')
        ->and($workspace->users()->whereKey($this->admin->id)->exists())->toBeFalse()
        ->and($res->json('account.workspaces.0.invitations.0.email'))->toBe('dueno@eltornillo.co')
        ->and($res->json('account.workspaces.0.invitations.0.role'))->toBe('owner');
    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $m) => $m->hasTo('dueno@eltornillo.co') && $m->workspace->is($workspace));
    expect(DB::table('audit_logs')->where('action', 'platform.client_created')->exists())->toBeTrue();
});

it('el dueño de una agencia cliente entra al espacio y a la cuenta al aceptar la invitación', function () {
    $this->actingAs($this->admin)->postJson('/api/platform/accounts', clientPayload(['type' => 'agency', 'name' => 'Agencia Norte', 'owner_email' => 'ana@norte.co']))->assertCreated();
    $account = Account::query()->where('name', 'Agencia Norte')->sole();
    $invitation = Invitation::withoutWorkspaceScope()->where('email', 'ana@norte.co')->sole();
    expect($invitation->getAttribute('account_role'))->toBe('agency_owner');

    $token = 'tok-'.str_repeat('x', 40);
    $invitation->forceFill(['token_hash' => hash('sha256', $token)])->save();
    $ana = $this->userWith2fa(['email' => 'ana@norte.co']);
    $this->actingAs($ana)->postJson('/api/invitations/'.$token.'/accept')->assertOk();

    expect($ana->accounts()->whereKey($account->id)->first()?->pivot?->getAttribute('role'))->toBe('agency_owner')
        ->and(Workspace::query()->where('account_id', $account->id)->sole()->users()->whereKey($ana->id)->exists())->toBeTrue();
});

it('edita los datos del cliente, le agrega espacios e invita personas a sus espacios', function () {
    $res = $this->actingAs($this->admin)->postJson('/api/platform/accounts', clientPayload())->assertCreated();
    $id = (string) $res->json('account.id');

    $this->actingAs($this->admin)->patchJson('/api/platform/accounts/'.$id, ['legal_name' => 'El Tornillo S.A.S.', 'billing_email' => 'pagos@eltornillo.co'])
        ->assertOk()->assertJsonPath('account.legal_name', 'El Tornillo S.A.S.');
    $this->actingAs($this->admin)->postJson('/api/platform/accounts/'.$id.'/workspaces', ['name' => 'El Tornillo Medellín', 'country' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota'])
        ->assertCreated()->assertJsonCount(2, 'account.workspaces');

    $otherWs = $this->ws->publicId();
    $this->actingAs($this->admin)->postJson('/api/platform/accounts/'.$id.'/invitations', ['workspace_id' => $otherWs, 'email' => 'x@y.co', 'role' => 'admin'])
        ->assertNotFound(); // un espacio de otra cuenta
    $ws = (string) $res->json('account.workspaces.0.id');
    $this->actingAs($this->admin)->postJson('/api/platform/accounts/'.$id.'/invitations', ['workspace_id' => $ws, 'email' => 'vendedor@eltornillo.co', 'role' => 'agent'])
        ->assertCreated();
    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $m) => $m->hasTo('vendedor@eltornillo.co'));
});

it('la agencia da de alta un cliente en su cuenta e invita al dueño; otra persona no puede', function () {
    $agency = Account::query()->create(['type' => 'agency', 'name' => 'Imagina La Web', 'country' => 'CO', 'status' => 'active']);
    $staff = $this->userWith2fa();
    $staff->accounts()->attach($agency->id, ['role' => 'agency_member']);
    $home = Workspace::query()->create(['account_id' => $agency->id, 'name' => 'Casa', 'slug' => 'casa', 'status' => 'active']);
    $home->users()->attach($staff->id, ['role' => 'owner']);

    $body = ['account_id' => $agency->publicId(), 'name' => 'Café Sierra', 'country' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota', 'invite_email' => 'dueno@cafesierra.co', 'invite_role' => 'owner'];
    $this->actingAs($staff)->postJson('/api/w/'.$home->publicId().'/agency/clients', $body)->assertCreated()->assertJsonPath('name', 'Café Sierra');
    $client = Workspace::query()->where('account_id', $agency->id)->where('name', 'Café Sierra')->sole();
    expect($client->users()->whereKey($staff->id)->exists())->toBeFalse();
    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $m) => $m->hasTo('dueno@cafesierra.co'));

    // Un dueño de otro espacio (sin rol en la agencia) no crea clientes en ella.
    $this->actingAs($this->owner)->postJson('/api/w/'.$this->ws->publicId().'/agency/clients', $body)->assertForbidden();
});

it('devuelve el enlace de la invitación a quien la crea (por si el correo no llega)', function () {
    $res = $this->actingAs($this->admin)->postJson('/api/platform/accounts', clientPayload())->assertCreated();
    $url = (string) $res->json('invite_url');
    expect($url)->toContain('/invitacion/');
    $token = substr($url, strrpos($url, '/') + 1);
    expect(Invitation::withoutWorkspaceScope()->where('token_hash', hash('sha256', $token))->exists())->toBeTrue();

    $id = (string) $res->json('account.id');
    $ws = (string) $res->json('account.workspaces.0.id');
    $this->actingAs($this->admin)->postJson('/api/platform/accounts/'.$id.'/invitations', ['workspace_id' => $ws, 'email' => 'otra@eltornillo.co', 'role' => 'admin'])
        ->assertCreated()->assertJsonPath('invite_url', fn ($v) => is_string($v) && str_contains($v, '/invitacion/'));
});

it('dice si el correo de la plataforma está configurado y prueba el envío', function () {
    config(['mail.default' => 'log']);
    $this->actingAs($this->admin)->getJson('/api/platform/mail')->assertOk()->assertJsonPath('configured', false);
    config(['mail.default' => 'smtp']);
    $this->actingAs($this->admin)->getJson('/api/platform/mail')->assertOk()->assertJsonPath('configured', true);
    $this->actingAs($this->admin)->postJson('/api/platform/mail/test')->assertOk()->assertJsonPath('sent', true);
    $this->actingAs($this->owner)->getJson('/api/platform/mail')->assertForbidden();
});
