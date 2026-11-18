<?php

use App\Ingest\IngestStream;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->ws = $this->workspaceFor($this->userWith2fa());
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
});

function streamLength(): int
{
    $stream = app(IngestStream::class);
    $total = 0;
    for ($i = 0; $i < $stream->shards(); $i++) {
        $total += (int) $stream->connection()->command('xlen', [$stream->key($i)]);
    }

    return $total;
}

it('acepta un lote firmado y comprimido y lo deja en el stream (202)', function () {
    $events = [
        $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot()]),
        $this->envelope($this->site, 'catalog.product_deleted', ['product_id' => '88']),
    ];
    $this->postSigned($this->site, $this->secret, $events)->assertStatus(202)->assertExactJson(['accepted' => 2, 'rejected' => []]);
    expect(streamLength())->toBe(2);
});

it('rechaza firma inválida, vencida o de otro sitio', function () {
    $events = [$this->envelope($this->site, 'catalog.product_deleted', ['product_id' => '88'])];
    $this->postSigned($this->site, 'sk_otro', $events)->assertUnauthorized();
    $this->postSigned($this->site, $this->secret, $events, time() - 301)->assertUnauthorized();
    [$other, $otherSecret] = $this->connectedSite($this->ws, 'otra.co');
    $this->postSigned($other, $this->secret, $events)->assertUnauthorized();
    // Firmado por el otro sitio pero con eventos de este: site_mismatch.
    $this->postSigned($other, $otherSecret, $events)->assertStatus(202)->assertJsonPath('rejected.0.reason', 'site_mismatch');
    expect(streamLength())->toBe(0);
});

it('acepta el secreto anterior durante las 24 h de la rotación', function () {
    $this->site->forceFill(['previous_secret' => $this->secret, 'previous_secret_expires_at' => now()->addHours(24), 'secret' => 'sk_nuevo'])->save();
    $events = [$this->envelope($this->site, 'catalog.product_deleted', ['product_id' => '88'])];
    $this->postSigned($this->site, $this->secret, $events)->assertStatus(202);
    $this->travel(25)->hours();
    $this->postSigned($this->site, $this->secret, $events)->assertUnauthorized();
});

it('rechaza evento por evento lo que no cumple el sobre', function () {
    $good = $this->envelope($this->site, 'catalog.product_deleted', ['product_id' => '88']);
    $badVersion = array_replace($good, ['event_id' => (string) Str::uuid7(), 'schema_version' => 9]);
    $unknown = $this->envelope($this->site, 'order.inventado', []);
    $huge = $this->envelope($this->site, 'custom.enorme', ['x' => str_repeat('a', 40000)]);
    $browser = $this->envelope($this->site, 'page.viewed', [], ['source' => ['type' => 'browser']]);

    $res = $this->postSigned($this->site, $this->secret, [$good, $badVersion, $unknown, $huge, $browser])->assertStatus(202);
    expect($res->json('accepted'))->toBe(1)
        ->and(collect($res->json('rejected'))->pluck('reason')->all())->toBe(['envelope', 'unknown_event', 'too_large', 'envelope']);
});

it('rechaza los eventos que solo genera el hub (eventos.md §4.6)', function () {
    $events = [
        $this->envelope($this->site, 'segment.entered', ['segment_id' => 'sg_1']),
        $this->envelope($this->site, 'consent.granted', ['channel' => 'whatsapp', 'purpose' => 'marketing', 'source' => 'x']),
    ];
    $res = $this->postSigned($this->site, $this->secret, $events)->assertStatus(202);
    expect($res->json('accepted'))->toBe(0)
        ->and(collect($res->json('rejected'))->pluck('reason')->unique()->all())->toBe(['unknown_event']);
});

it('acepta eventos v1 y v2', function () {
    $v1 = $this->envelope($this->site, 'identify', ['method' => 'login'], ['schema_version' => 1]);
    $v2 = $this->envelope($this->site, 'identify', ['method' => 'login'], ['schema_version' => 2]);
    $this->postSigned($this->site, $this->secret, [$v1, $v2])->assertJsonPath('accepted', 2);
});

it('no acepta lotes de más de 500 eventos ni sitios desconectados', function () {
    $events = array_fill(0, 501, $this->envelope($this->site, 'catalog.product_deleted', ['product_id' => '88']));
    $this->postSigned($this->site, $this->secret, $events)->assertStatus(413);
    $this->site->forceFill(['status' => 'disconnected'])->save();
    $this->postSigned($this->site, $this->secret, [$events[0]])->assertForbidden();
});

it('recibe eventos del navegador con clave pública y Origin del sitio (api-connector.md §4.2)', function () {
    $event = $this->envelope($this->site, 'page.viewed', ['page_type' => 'home'], ['source' => ['type' => 'browser', 'sdk' => 'tracker/0.1.0'], 'identity' => ['anonymous_id' => 'c3b0f4a2']]);
    $body = json_encode(['k' => $this->site->public_key, 'events' => [$event]]);
    $call = fn (string $origin) => $this->call('POST', '/v1/b', [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'HTTP_ORIGIN' => $origin], $body);

    $call('https://evil.co')->assertForbidden();
    $call('https://www.tienda.co')->assertStatus(202)->assertJsonPath('accepted', 1)->assertHeader('Access-Control-Allow-Origin', 'https://www.tienda.co');

    $withEmail = $event;
    $withEmail['identity']['email'] = 'ana@ejemplo.com';
    $this->call('POST', '/v1/b', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://tienda.co'], json_encode(['k' => $this->site->public_key, 'events' => [$withEmail]]))
        ->assertJsonPath('rejected.0.reason', 'envelope');
});
