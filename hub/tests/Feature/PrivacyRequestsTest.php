<?php

use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Privacy\Models\DataRequest;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
    $order = $this->orderSnapshot(['consents' => ['whatsapp_marketing' => true, 'text_version' => 'v1']]);
    $this->postSigned($this->site, $this->secret, [
        $this->envelope($this->site, 'order.paid', ['order' => $order], ['identity' => ['anonymous_id' => 'anon-ana']]),
    ]);
    $this->runWorker();
    $this->contact = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->firstOrFail());
    $this->api = "/api/w/{$this->ws->publicId()}";
});

it('exporta todos los datos de la persona con auditoría', function () {
    $req = $this->actingAs($this->owner)->postJson("{$this->api}/contacts/{$this->contact->publicId()}/data-requests", ['kind' => 'access'])->assertCreated();
    expect($req->json('due_at'))->not->toBeNull();
    $this->actingAs($this->owner)->postJson("{$this->api}/data-requests/{$req->json('id')}/execute")->assertOk()->assertJsonPath('status', 'completed');

    $download = $this->actingAs($this->owner)->get("{$this->api}/data-requests/{$req->json('id')}/download")->assertOk();
    $data = json_decode($download->getContent(), true);
    expect($data['profile']['email'])->toBe('ana@ejemplo.com')
        ->and($data['orders'])->toHaveCount(1)
        ->and(collect($data['consents'])->pluck('channel')->sort()->values()->all())->toBe(['email', 'whatsapp'])
        ->and($data['consent_evidence'])->toHaveCount(2)
        ->and(count($data['events']))->toBeGreaterThanOrEqual(1);
    expect(DB::table('audit_logs')->where('action', 'contact.exported')->exists())->toBeTrue();
});

it('borra de punta a punta: MariaDB, ClickHouse y caché, conservando pedidos y la evidencia mínima', function () {
    $req = $this->actingAs($this->owner)->postJson("{$this->api}/contacts/{$this->contact->publicId()}/data-requests", ['kind' => 'delete'])->assertCreated();
    $this->actingAs($this->owner)->postJson("{$this->api}/data-requests/{$req->json('id')}/execute")->assertUnprocessable();
    $this->actingAs($this->owner)->postJson("{$this->api}/data-requests/{$req->json('id')}/execute", ['confirm' => 'BORRAR'])->assertOk()
        ->assertJsonPath('result.deleted.contacts', 1);

    app(CurrentWorkspace::class)->run($this->ws, function () {
        expect(Contact::query()->withTrashed()->count())->toBe(0)
            ->and(Order::query()->count())->toBe(1)
            ->and(Order::query()->value('contact_id'))->toBeNull()
            ->and(DB::table('contact_identities')->count())->toBe(0)
            ->and(DB::table('contact_consents')->count())->toBe(0)
            ->and(DB::table('suppressions')->where('workspace_id', $this->ws->id)->count())->toBe(3);
    });
    $left = $this->chRows($this->ws, "SELECT count() AS c FROM events WHERE workspace_id = {workspace_id:UInt32} AND (contact_id != '' OR anonymous_id = 'anon-ana')");
    expect((int) $left[0]['c'])->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'contact.deleted')->exists())->toBeTrue();
});

it('solo owner y admin borran; marketing no exporta', function () {
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $this->actingAs($marketing)->postJson("{$this->api}/contacts/{$this->contact->publicId()}/data-requests", ['kind' => 'access'])->assertForbidden();
    $this->actingAs($marketing)->postJson("{$this->api}/contacts/{$this->contact->publicId()}/data-requests", ['kind' => 'delete'])->assertForbidden();
});

it('WordPress crea solicitudes sin revelar si la persona existe y con plazo en días hábiles', function () {
    $known = $this->signedJson('POST', '/v1/s/privacy/requests', $this->site, $this->secret, ['kind' => 'delete', 'email' => 'ANA@ejemplo.com', 'wp_request_id' => 7])->assertStatus(202);
    $unknown = $this->signedJson('POST', '/v1/s/privacy/requests', $this->site, $this->secret, ['kind' => 'access', 'email' => 'nadie@ejemplo.com'])->assertStatus(202);
    expect(array_keys($known->json()))->toBe(array_keys($unknown->json()));

    app(CurrentWorkspace::class)->run($this->ws, function () {
        $rows = DataRequest::query()->orderBy('kind')->get();
        expect($rows->pluck('contact_id')->filter()->values()->all())->toBe([$this->contact->id])
            ->and($rows[0]->requester)->toBe('nadie@ejemplo.com')
            ->and($rows[0]->getRawOriginal('requester'))->not->toContain('nadie@')
            ->and($rows[0]->due_at->isWeekend())->toBeFalse();
    });
});
