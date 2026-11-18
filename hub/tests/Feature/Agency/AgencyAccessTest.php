<?php

use App\Domain\Agency\Models\SupportSession;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Agency\AgencyWorld;

beforeEach(function () {
    $this->w = AgencyWorld::make($this);
});

it('el panel de agencia lista solo los clientes de las cuentas de agencia de la persona', function () {
    $w = $this->w;
    $res = $this->actingAs($w->member)->getJson($w->api($w->a, 'agency/overview'))->assertOk();
    $ids = collect($res->json('clients'))->pluck('id')->all();
    expect($ids)->toEqualCanonicalizing([$w->a->publicId(), $w->b->publicId()])
        ->and($ids)->not->toContain($w->c->publicId())
        ->and($res->json('accounts.0.can_manage'))->toBeFalse()
        ->and($res->json('totals.clients'))->toBe(2);

    $this->actingAs($w->owner)->getJson($w->api($w->b, 'agency/overview'))->assertOk()->assertJsonPath('accounts.0.can_manage', true);
    // La otra agencia ve solo a su cliente y no puede usar los espacios de la primera.
    $foreign = $this->actingAs($w->foreignOwner)->getJson($w->api($w->c, 'agency/overview'))->assertOk();
    expect(collect($foreign->json('clients'))->pluck('id')->all())->toBe([$w->c->publicId()]);
    $this->actingAs($w->foreignOwner)->getJson($w->api($w->a, 'agency/overview'))->assertNotFound();
});

it('el dueño directo de un cliente no ve el panel de agencia ni la capacidad viewAgency', function () {
    $w = $this->w;
    $this->actingAs($w->clientOwnerA)->getJson($w->api($w->a, 'agency/overview'))->assertForbidden();
    $this->actingAs($w->clientOwnerA)->getJson($w->api($w->a, 'agency/templates'))->assertForbidden();
    $me = $this->actingAs($w->clientOwnerA)->getJson('/api/me')->assertOk();
    $abilities = collect($me->json('workspaces'))->firstWhere('id', $w->a->publicId())['abilities'];
    expect($abilities)->not->toContain('viewAgency')->toContain('manageWorkspace');

    $agencyMe = $this->actingAs($w->member)->getJson('/api/me')->assertOk();
    $ws = collect($agencyMe->json('workspaces'))->firstWhere('id', $w->a->publicId());
    expect($ws['abilities'])->toContain('viewAgency')
        ->and($ws['branding']['name'])->toBe('Imagina Growth');
});

it('no deja tocar clientes de otra agencia con IDs ajenos', function () {
    $w = $this->w;
    $c = $w->c->publicId();
    $this->actingAs($w->owner)->getJson($w->api($w->a, "agency/clients/{$c}/items?kind=flow"))->assertNotFound();
    $this->actingAs($w->owner)->postJson($w->api($w->a, "agency/clients/{$c}/enter"))->assertNotFound();
    $this->actingAs($w->owner)->postJson($w->api($w->a, "agency/reports/{$c}/generate"), ['month' => '2026-10'])->assertNotFound();
    $this->actingAs($w->owner)->postJson($w->api($w->a, 'agency/templates'), ['source_workspace' => $c, 'kind' => 'flow', 'id' => 'fl_01JD3QF0A1B2C3D4E5F6G7H8J9'])->assertNotFound();
    $this->actingAs($w->owner)->putJson($w->api($w->a, 'agency/branding/acc_'.$w->foreign->id), ['brand_name' => 'X'])->assertNotFound();
    $this->actingAs($w->owner)->getJson($w->api($w->c, '/'))->assertNotFound();
});

it('quien entra por la agencia sin ser miembro deja una sesión de soporte auditada; el dueño directo no', function () {
    $w = $this->w;
    $this->actingAs($w->clientOwnerA)->getJson($w->api($w->a))->assertOk();
    expect(SupportSession::withoutWorkspaceScope()->count())->toBe(0);

    $this->actingAs($w->member)->getJson($w->api($w->a))->assertOk()->assertJsonPath('role', WorkspaceRole::Admin->value);
    $this->actingAs($w->member)->getJson($w->api($w->a, 'overview'))->assertOk();
    $sessions = SupportSession::withoutWorkspaceScope()->where('workspace_id', $w->a->id)->get();
    expect($sessions)->toHaveCount(1)
        ->and($sessions[0]->user_id)->toBe($w->member->id)
        ->and($sessions[0]->ip_hash)->toHaveLength(64)->not->toBe('127.0.0.1')
        ->and(DB::table('audit_logs')->where('action', 'support.entered')->where('workspace_id', $w->a->id)->where('user_id', $w->member->id)->count())->toBe(1);

    // «Entrar» desde el panel con un motivo abre una sesión con ese motivo.
    $this->actingAs($w->owner)->postJson($w->api($w->a, 'agency/clients/'.$w->b->publicId().'/enter'), ['reason' => 'Revisar el flujo de carrito'])
        ->assertOk()->assertJsonPath('workspace_id', $w->b->publicId());
    expect(SupportSession::withoutWorkspaceScope()->where('workspace_id', $w->b->id)->where('reason', 'Revisar el flujo de carrito')->exists())->toBeTrue();
});

it('una cuenta directa con varias tiendas no abre sesiones de soporte', function () {
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner, 'Tienda Uno');
    $ws->account->users()->attach($owner->id, ['role' => 'agency_owner']);
    $other = $this->userWith2fa();
    $ws->account->users()->attach($other->id, ['role' => 'agency_member']);

    $this->actingAs($other)->getJson('/api/w/'.$ws->publicId())->assertOk();
    expect(SupportSession::withoutWorkspaceScope()->count())->toBe(0);
    $this->actingAs($other)->getJson('/api/w/'.$ws->publicId().'/agency/overview')->assertForbidden();
});
