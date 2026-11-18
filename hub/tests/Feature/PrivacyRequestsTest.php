<?php

use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Measurement\Audiences\Models\AudienceMember;
use App\Domain\Measurement\Audiences\Models\AudienceSync;
use App\Domain\Measurement\Enums\DestinationMode;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Privacy\Models\DataRequest;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

it('la supresión llega a mensajes, fidelización, referidos, reseñas, avisos, capturas y audiencias', function () {
    $cid = $this->contact->id;
    $ws = $this->ws->id;
    $now = now();
    $destination = app(CurrentWorkspace::class)->run($this->ws, fn () => AdDestination::query()->create([
        'workspace_id' => $ws, 'platform' => Platform::Meta, 'name' => 'Meta',
        'config' => ['ad_account_id' => '1234567', 'ads_access_token' => 'EAAB-token'], 'mode' => DestinationMode::ServerOnly, 'status' => 'active',
    ]));
    $sync = app(CurrentWorkspace::class)->run($this->ws, fn () => AudienceSync::query()->create([
        'workspace_id' => $ws, 'segment_id' => '01JD3QG7Y2V8K6N4M5P9R0S1T2', 'destination_id' => $destination->id, 'name' => 'Clientes', 'platform_audience_id' => '777', 'status' => 'active', 'members_count' => 1,
    ]));
    AudienceMember::query()->create(['audience_sync_id' => $sync->id, 'contact_id' => $cid, 'identifiers' => ['email' => hash('sha256', 'ana@ejemplo.com')], 'synced_at' => $now]);
    Http::fake(['graph.facebook.com/*' => Http::response(['num_received' => 1])]);

    DB::table('messages')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $ws, 'contact_id' => $cid, 'channel' => 'email', 'direction' => 'out', 'kind' => 'marketing', 'source_type' => 'manual', 'status' => 'sent', 'idempotency_key' => 'k1', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('loyalty_ledger')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $ws, 'contact_id' => $cid, 'kind' => 'points', 'delta' => 100, 'balance_after' => 100, 'currency' => null, 'status' => 'confirmed', 'reason' => 'order', 'idempotency_key' => 'order:1', 'created_at' => $now]);
    DB::table('reviews')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $ws, 'site_id' => $this->site->id, 'contact_id' => $cid, 'rating' => 5, 'body' => 'Muy buena', 'author_name' => 'Ana R.', 'status' => 'published', 'source' => 'hub', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('product_watches')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $ws, 'site_id' => $this->site->id, 'contact_id' => $cid, 'external_product_id' => '88', 'kind' => 'back_in_stock', 'channel' => 'email', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

    $req = $this->actingAs($this->owner)->postJson("{$this->api}/contacts/{$this->contact->publicId()}/data-requests", ['kind' => 'access'])->assertCreated();
    $this->actingAs($this->owner)->postJson("{$this->api}/data-requests/{$req->json('id')}/execute")->assertOk();
    $data = json_decode($this->actingAs($this->owner)->get("{$this->api}/data-requests/{$req->json('id')}/download")->getContent(), true);
    expect($data['messages'])->toHaveCount(1)->and($data['loyalty']['ledger'])->toHaveCount(1)
        ->and($data['reviews'][0]['body'])->toBe('Muy buena')->and($data['product_alerts'])->toHaveCount(1);

    $del = $this->actingAs($this->owner)->postJson("{$this->api}/contacts/{$this->contact->publicId()}/data-requests", ['kind' => 'delete'])->assertCreated();
    $this->actingAs($this->owner)->postJson("{$this->api}/data-requests/{$del->json('id')}/execute", ['confirm' => 'BORRAR'])->assertOk()
        ->assertJsonPath('result.deleted.audiences', 1);

    expect(DB::table('messages')->where('contact_id', $cid)->count())->toBe(0)
        ->and(DB::table('loyalty_ledger')->where('contact_id', $cid)->count())->toBe(0)
        ->and(DB::table('product_watches')->where('contact_id', $cid)->count())->toBe(0)
        ->and(DB::table('reviews')->count())->toBe(1)
        ->and(DB::table('reviews')->value('contact_id'))->toBeNull()
        ->and(DB::table('reviews')->value('author_name'))->toBeNull()
        ->and(DB::table('audience_members')->count())->toBe(0);
    $removed = Http::recorded(fn ($r) => str_ends_with($r->url(), '/users') && $r->method() === 'DELETE');
    expect($removed)->toHaveCount(1);
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
