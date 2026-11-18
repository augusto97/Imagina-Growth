<?php

use App\Domain\Consent\Actions\RecordConsent;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Measurement\Audiences\Models\AudienceMember;
use App\Domain\Measurement\Audiences\Models\AudienceSync;
use App\Domain\Measurement\Enums\DestinationMode;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// M06, Fase 3: segmento → audiencias de Meta, Google y TikTok, solo con hashes y respetando el consentimiento.
beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws->fresh(), $fn);
    $this->segment = ($this->in)(fn () => Segment::query()->create(['workspace_id' => $this->ws->id, 'name' => 'Clientes VIP', 'kind' => 'static', 'member_count' => 0]));
    $this->api = '/api/w/'.$this->ws->publicId().'/measurement/audiences';
});

function audiencesDestination($test, Platform $platform, array $config): AdDestination
{
    return ($test->in)(fn () => AdDestination::query()->create(['workspace_id' => $test->ws->id, 'platform' => $platform, 'name' => $platform->label(), 'config' => $config, 'mode' => DestinationMode::ServerOnly, 'status' => 'active']));
}

function audiencesMember($test, array $attributes, ?Segment $segment = null): Contact
{
    $contact = ($test->in)(fn () => Contact::query()->create($attributes + ['workspace_id' => $test->ws->id]));
    DB::table('segment_memberships')->insert(['segment_id' => ($segment ?? $test->segment)->id, 'contact_id' => $contact->id, 'workspace_id' => $test->ws->id, 'entered_at' => now()]);

    return $contact;
}

function audiencesSync($test, AdDestination $destination, string $purpose = 'target'): AudienceSync
{
    return ($test->in)(fn () => AudienceSync::query()->create(['workspace_id' => $test->ws->id, 'segment_id' => $test->segment->id, 'destination_id' => $destination->id, 'name' => 'IG · Clientes VIP', 'purpose' => $purpose]));
}

function audiencesConsent($test, Contact $contact, string $action): void
{
    ($test->in)(fn () => app(RecordConsent::class)->handle($test->ws->fresh(), $contact->id, 'ads', 'marketing', $action, 'admin', null, now()));
}

function audiencesMeta(): array
{
    return ['ad_account_id' => '1234567', 'ads_access_token' => 'EAAB-token'];
}

it('sube solo hashes a Meta, saca a quien salió del segmento y actualiza a quien cambió de correo', function () {
    $sha = fn (string $v) => hash('sha256', $v);
    $ana = audiencesMember($this, ['email' => ' Ana@Ejemplo.com ', 'phone' => '+573001234567', 'country' => 'CO']);
    $leo = audiencesMember($this, ['phone' => '+573109998877']);
    audiencesMember($this, ['first_name' => 'Sin datos']);
    $no = audiencesMember($this, ['email' => 'no@ejemplo.com']);
    audiencesConsent($this, $no, 'revoked');
    $sync = audiencesSync($this, audiencesDestination($this, Platform::Meta, audiencesMeta()));
    Http::fake([
        'graph.facebook.com/*/act_1234567/customaudiences' => Http::response(['id' => '23850001']),
        'graph.facebook.com/*/23850001/users' => Http::response(['audience_id' => '23850001', 'num_received' => 2]),
    ]);

    $this->artisan('igr:audiences:sync', ['--sync' => true])->assertSuccessful();
    $sync->refresh();
    expect($sync->status)->toBe('active')->and($sync->platform_audience_id)->toBe('23850001')
        ->and($sync->members_count)->toBe(2)->and($sync->last_added)->toBe(2)->and($sync->skipped_no_consent)->toBe(1)->and($sync->last_error)->toBeNull();
    $added = Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/users') && $r->method() === 'POST')->values();
    expect($added)->toHaveCount(1);
    $payload = $added[0][0]->data()['payload'];
    expect($payload['schema'])->toBe(['EMAIL', 'PHONE'])
        ->and($payload['data'])->toContain([$sha('ana@ejemplo.com'), $sha('573001234567')])
        ->and($payload['data'])->toContain(['', $sha('573109998877')]);
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->body(), 'ejemplo.com') || str_contains($r->body(), '3001234567'));
    // Lo enviado queda cifrado en reposo.
    expect(DB::table('audience_members')->where('contact_id', $ana->id)->value('identifiers'))->not->toContain($sha('ana@ejemplo.com'))
        ->and(AudienceMember::query()->where('contact_id', $ana->id)->first()->identifiers)->toBe(['email' => $sha('ana@ejemplo.com'), 'phone' => $sha('573001234567')]);

    // Leo sale del segmento y Ana cambia de correo.
    DB::table('segment_memberships')->where('contact_id', $leo->id)->delete();
    ($this->in)(fn () => Contact::query()->whereKey($ana->id)->first()->forceFill(['email' => 'ana.nueva@ejemplo.com'])->save());
    $this->artisan('igr:audiences:sync', ['--sync' => true])->assertSuccessful();

    $removed = Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/users') && $r->method() === 'DELETE')->values();
    expect($removed)->toHaveCount(1);
    $gone = $removed[0][0]->data()['payload']['data'];
    expect($gone)->toHaveCount(2)->toContain(['', $sha('573109998877')])->toContain([$sha('ana@ejemplo.com'), $sha('573001234567')]);
    $reAdded = Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/users') && $r->method() === 'POST')->values();
    expect($reAdded)->toHaveCount(2)->and($reAdded[1][0]->data()['payload']['data'])->toBe([[$sha('ana.nueva@ejemplo.com'), $sha('573001234567')]]);
    $sync->refresh();
    expect($sync->members_count)->toBe(1)->and($sync->last_removed)->toBe(2)->and($sync->last_added)->toBe(1)
        ->and(Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'customaudiences'))->values())->toHaveCount(1); // la audiencia se crea una sola vez
});

it('en modo estricto solo sube a quien dio consentimiento de anuncios', function () {
    $this->ws->forceFill(['settings' => ['consent_mode' => 'strict'] + ($this->ws->settings ?? [])])->save();
    $yes = audiencesMember($this, ['email' => 'si@ejemplo.com']);
    audiencesMember($this, ['email' => 'callado@ejemplo.com']);
    audiencesConsent($this, $yes, 'granted');
    $sync = audiencesSync($this, audiencesDestination($this, Platform::Meta, audiencesMeta()));
    Http::fake(['*customaudiences' => Http::response(['id' => '777']), '*/users' => Http::response(['num_received' => 1])]);

    $this->artisan('igr:audiences:sync', ['--sync' => true])->assertSuccessful();
    expect($sync->refresh()->members_count)->toBe(1)->and($sync->skipped_no_consent)->toBe(1);
    $data = Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/users'))->values()[0][0]->data()['payload']['data'];
    expect($data)->toBe([[hash('sha256', 'si@ejemplo.com'), '']]);
});

it('envía las altas por lotes', function () {
    config(['igr-audiences.batch_size.meta' => 2]);
    foreach (range(1, 5) as $i) {
        audiencesMember($this, ['email' => "persona{$i}@ejemplo.com"]);
    }
    $sync = audiencesSync($this, audiencesDestination($this, Platform::Meta, audiencesMeta()));
    Http::fake(['*customaudiences' => Http::response(['id' => '777']), '*/users' => Http::response(['num_received' => 2])]);
    $this->artisan('igr:audiences:sync', ['--sync' => true])->assertSuccessful();
    $calls = Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/users'))->values();
    expect($calls)->toHaveCount(3)->and(array_map(fn ($c) => count($c[0]->data()['payload']['data']), $calls->all()))->toBe([2, 2, 1])
        ->and($sync->refresh()->members_count)->toBe(5);
});

it('crea la lista de Customer Match y sube los miembros por la Data Manager API', function () {
    audiencesMember($this, ['email' => 'ana@ejemplo.com', 'phone' => '+573001234567']);
    $sync = audiencesSync($this, audiencesDestination($this, Platform::Google, [
        'ads_customer_id' => '123-456-7890', 'ads_login_customer_id' => '999-000-1111', 'ads_developer_token' => 'dev', 'oauth_client_id' => 'cid', 'oauth_client_secret' => 'sec', 'oauth_refresh_token' => 'ref',
    ]), 'lookalike_seed');
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29', 'expires_in' => 3600]),
        'googleads.googleapis.com/*/customers/1234567890/userLists:mutate' => Http::response(['results' => [['resourceName' => 'customers/1234567890/userLists/555']]]),
        'datamanager.googleapis.com/v1/audienceMembers:ingest' => Http::response(['requestId' => 'r1']),
    ]);
    $this->artisan('igr:audiences:sync', ['--sync' => true])->assertSuccessful();

    expect($sync->refresh()->platform_audience_id)->toBe('555')->and($sync->members_count)->toBe(1);
    $create = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'userLists:mutate'))->values()[0][0];
    expect($create->header('login-customer-id'))->toBe(['9990001111'])
        ->and($create->data()['operations'][0]['create']['crmBasedUserList'])->toBe(['uploadKeyType' => 'CONTACT_INFO']);
    $ingest = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'audienceMembers:ingest'))->values()[0][0]->data();
    expect($ingest['destinations'][0])->toBe(['operatingAccount' => ['accountType' => 'GOOGLE_ADS', 'accountId' => '1234567890'], 'productDestinationId' => '555'])
        ->and($ingest['audienceMembers'][0]['userData']['userIdentifiers'])->toBe([['emailAddress' => hash('sha256', 'ana@ejemplo.com')], ['phoneNumber' => hash('sha256', '+573001234567')]])
        ->and($ingest['encoding'])->toBe('HEX')->and($ingest['consent'])->toBe(['adUserData' => 'CONSENT_GRANTED', 'adPersonalization' => 'CONSENT_GRANTED']);
});

it('explica en español los errores de la plataforma y lo que falta en el destino', function () {
    audiencesMember($this, ['email' => 'ana@ejemplo.com']);
    $tiktok = audiencesSync($this, audiencesDestination($this, Platform::TikTok, ['advertiser_id' => '700', 'ads_access_token' => 'tt']));
    Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 40001, 'message' => 'Access token is invalid'])]);
    $this->artisan('igr:audiences:sync', ['--sync' => true])->assertSuccessful();
    expect($tiktok->refresh()->status)->toBe('error')->and($tiktok->last_error)->toContain('token de acceso de TikTok');

    ($this->in)(fn () => $tiktok->delete());
    $meta = audiencesSync($this, audiencesDestination($this, Platform::Meta, ['dataset_id' => '1', 'access_token' => 'x']));
    $this->artisan('igr:audiences:sync', ['--sync' => true])->assertSuccessful();
    expect($meta->refresh()->status)->toBe('error')->and($meta->last_error)->toContain('cuenta publicitaria');

    // Un límite temporal no rompe la audiencia: queda el mensaje y se reintenta.
    $ok = audiencesDestination($this, Platform::Meta, audiencesMeta());
    ($this->in)(fn () => $meta->forceFill(['destination_id' => $ok->id, 'status' => 'active', 'platform_audience_id' => '900'])->save());
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 17, 'message' => 'User request limit reached']], 400)]);
    $this->artisan('igr:audiences:sync', ['--sync' => true])->assertSuccessful();
    expect($meta->refresh()->status)->toBe('active')->and($meta->last_error)->toContain('limitó temporalmente');
});

it('saca a la persona de las audiencias en cuanto revoca el consentimiento de anuncios', function () {
    $this->useIngestInfrastructure();
    $ana = audiencesMember($this, ['email' => 'ana@ejemplo.com']);
    $sync = audiencesSync($this, audiencesDestination($this, Platform::Meta, audiencesMeta()));
    Http::fake(['*customaudiences' => Http::response(['id' => '777']), '*/users' => Http::response(['num_received' => 1])]);
    $this->artisan('igr:audiences:sync', ['--sync' => true])->assertSuccessful();
    expect($sync->refresh()->members_count)->toBe(1);

    audiencesConsent($this, $ana, 'revoked');
    $removed = Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/users') && $r->method() === 'DELETE')->values();
    expect($removed)->toHaveCount(1)->and($removed[0][0]->data()['payload']['data'])->toBe([[hash('sha256', 'ana@ejemplo.com'), '']])
        ->and(AudienceMember::query()->where('audience_sync_id', $sync->id)->count())->toBe(0)
        ->and($sync->refresh()->members_count)->toBe(0);
});

it('crea audiencias desde el panel con atajos, con permisos y sin ver las de otro espacio de trabajo', function () {
    Bus::fake();
    $meta = audiencesDestination($this, Platform::Meta, audiencesMeta());
    $tiktok = audiencesDestination($this, Platform::TikTok, ['pixel_code' => 'C1', 'access_token' => 't']);
    $index = $this->actingAs($this->owner)->getJson($this->api)->assertOk();
    expect($index->json('consent_rule'))->toBe('unless_revoked')
        ->and($index->json('destinations.1.missing'))->toContain('ID del anunciante')
        ->and($index->json('presets.*.key'))->toBe(['recent_buyers_exclusion', 'high_value_seed', 'at_risk_reactivation']);

    $created = $this->actingAs($this->owner)->postJson($this->api, ['destination_id' => $meta->publicId(), 'preset' => 'recent_buyers_exclusion'])->assertCreated();
    expect($created->json('purpose'))->toBe('exclusion')->and($created->json('segment.name'))->toBe('Compraron en los últimos 30 días')->and($created->json('status'))->toBe('pending');
    Bus::assertDispatched(App\Domain\Measurement\Audiences\Jobs\SyncAudienceJob::class);
    // El mismo segmento al mismo destino no se repite.
    $this->actingAs($this->owner)->postJson($this->api, ['destination_id' => $meta->publicId(), 'preset' => 'recent_buyers_exclusion'])->assertStatus(422);
    $seed = $this->actingAs($this->owner)->postJson($this->api, ['destination_id' => $tiktok->publicId(), 'preset' => 'high_value_seed'])->assertCreated();
    expect($seed->json('segment.name'))->toBe('Campeones')->and($seed->json('purpose'))->toBe('lookalike_seed');
    $custom = $this->actingAs($this->owner)->postJson($this->api, ['destination_id' => $meta->publicId(), 'segment_id' => $this->segment->publicId(), 'name' => 'VIP a Meta'])->assertCreated();

    $id = $custom->json('id');
    $this->actingAs($this->owner)->patchJson("{$this->api}/{$id}", ['status' => 'paused'])->assertOk()->assertJsonPath('status', 'paused')->assertJsonPath('next_sync_at', null);
    $this->actingAs($this->owner)->postJson("{$this->api}/{$id}/sync")->assertStatus(422);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson($this->api)->assertOk()->assertJsonCount(3, 'data');
    $this->actingAs($viewer)->postJson($this->api, ['destination_id' => $meta->publicId(), 'segment_id' => $this->segment->publicId()])->assertForbidden();
    $this->actingAs($viewer)->deleteJson("{$this->api}/{$id}")->assertForbidden();

    $intruder = $this->userWith2fa();
    $other = $this->workspaceFor($intruder, 'Otra tienda');
    $otherApi = '/api/w/'.$other->publicId().'/measurement/audiences';
    $this->actingAs($intruder)->getJson($this->api)->assertNotFound();
    expect($this->actingAs($intruder)->getJson($otherApi)->json('data'))->toBe([]);
    $this->actingAs($intruder)->deleteJson("{$otherApi}/{$id}")->assertNotFound();
    $this->actingAs($intruder)->postJson($otherApi, ['destination_id' => $meta->publicId(), 'segment_id' => $this->segment->publicId()])->assertStatus(422);

    $this->actingAs($this->owner)->deleteJson("{$this->api}/{$id}")->assertOk();
    expect(($this->in)(fn () => AudienceSync::query()->count()))->toBe(2);
});
