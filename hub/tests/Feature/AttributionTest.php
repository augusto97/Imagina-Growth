<?php

use App\Analytics\Ch;
use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->ws = $this->workspaceFor($this->userWith2fa());
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
});

/** Visitas del tracker escritas directo en ClickHouse. */
function measurementBrowserVisit($test, string $anon, string $session, string $at, string $channel, array $utm = []): void
{
    app(Ch::class)->insert('events', [[
        'workspace_id' => $test->ws->ch_id, 'site_id' => $test->site->ch_id, 'event_id' => (string) Str::uuid7(), 'event' => 'page.viewed',
        'occurred_at' => CarbonImmutable::parse($at)->utc()->format('Y-m-d H:i:s.v'), 'received_at' => now()->utc()->format('Y-m-d H:i:s.v'),
        'source_type' => 'browser', 'anonymous_id' => $anon, 'session_id' => $session, 'contact_id' => '', 'channel' => $channel,
        'utm_source' => $utm['source'] ?? '', 'utm_medium' => $utm['medium'] ?? '', 'utm_campaign' => $utm['campaign'] ?? '', 'revenue' => '0', 'properties' => new stdClass,
    ]]);
}

function measurementInWs($test, callable $fn)
{
    return app(CurrentWorkspace::class)->run($test->ws, $fn);
}

it('atribuye primer y último contacto con las visitas del tracker (último no directo)', function () {
    // El pedido vincula anon-1 con el contacto (identity_map) al procesarse.
    measurementBrowserVisit($this, 'anon-1', 's1', '2026-11-01T10:00:00-05:00', 'paid_meta', ['source' => 'facebook', 'medium' => 'paid_social', 'campaign' => 'nov']);
    measurementBrowserVisit($this, 'anon-1', 's2', '2026-11-10T10:00:00-05:00', 'organic_search');
    measurementBrowserVisit($this, 'anon-1', 's3', '2026-11-17T17:50:00-05:00', 'direct');
    measurementBrowserVisit($this, 'anon-1', 's0', '2026-09-01T10:00:00-05:00', 'email'); // fuera de la ventana de 30 días

    $paid = $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot()], ['identity' => ['anonymous_id' => 'anon-1'], 'occurred_at' => '2026-11-17T18:05:00-05:00']);
    $this->postSigned($this->site, $this->secret, [$paid]);
    $this->runWorker(); // con la cola sync, AttributeOrder corre en el acto

    $order = measurementInWs($this, fn () => Order::query()->firstOrFail());
    expect($order->getAttribute('channel_first'))->toBe('paid_meta')
        ->and($order->getAttribute('channel_last'))->toBe('organic_search')
        ->and($order->getAttribute('utm_last'))->toBeNull();
    $contact = measurementInWs($this, fn () => Contact::query()->firstOrFail());
    expect($contact->getAttribute('first_source')['channel'])->toBe('email')       // primera visita conocida, sin ventana
        ->and($contact->getAttribute('first_order_source')['channel'])->toBe('paid_meta');
});

it('usa el contexto del pedido (cookie _igr_src) si no hay visitas del tracker', function () {
    $paid = $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['wc_attribution' => null])], ['context' => [
        'first_touch' => ['utm' => ['source' => 'google', 'medium' => 'cpc'], 'click_ids' => ['gclid' => 'abc']],
        'last_touch' => ['referrer' => 'https://chatgpt.com/'],
    ]]);
    $this->postSigned($this->site, $this->secret, [$paid]);
    $this->runWorker();
    $order = measurementInWs($this, fn () => Order::query()->firstOrFail());
    expect($order->getAttribute('channel_first'))->toBe('paid_google')->and($order->getAttribute('channel_last'))->toBe('ai_assistants');
});

it('atribuye los pedidos importados con la atribución nativa de WooCommerce', function () {
    $orders = [
        ['order_id' => '1', 'wc_attribution' => ['source_type' => 'utm', 'utm_source' => 'facebook', 'utm_medium' => 'paid_social', 'utm_campaign' => 'black']],
        ['order_id' => '2', 'wc_attribution' => ['source_type' => 'organic', 'utm_source' => 'google']],
        ['order_id' => '3', 'wc_attribution' => ['source_type' => 'referral', 'utm_source' => 'l.instagram.com']],
        ['order_id' => '4', 'wc_attribution' => ['source_type' => 'typein', 'utm_source' => '(direct)']],
        ['order_id' => '5', 'wc_attribution' => ['source_type' => 'admin']],
        ['order_id' => '6', 'wc_attribution' => ['source_type' => 'referral', 'utm_source' => 'chatgpt.com']],
    ];
    $events = array_map(fn ($o) => $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot($o + ['customer_ref' => null])], ['source' => ['type' => 'import']]), $orders);
    $this->postSigned($this->site, $this->secret, $events);
    $this->runWorker();

    $this->artisan('igr:attribution:backfill')->assertSuccessful();
    $channels = measurementInWs($this, fn () => Order::query()->orderBy('external_id')->pluck('channel_last', 'external_id')->all());
    expect($channels)->toBe(['1' => 'paid_meta', '2' => 'organic_search', '3' => 'organic_social', '4' => 'direct', '5' => null, '6' => 'ai_assistants']);
    expect(measurementInWs($this, fn () => Order::query()->where('external_id', '1')->first()->getAttribute('utm_last')))->toBe(['source' => 'facebook', 'medium' => 'paid_social', 'campaign' => 'black']);
});

it('cuenta la visita que llegó en el mismo segundo en que se creó el pedido (WooCommerce guarda segundos)', function () {
    measurementBrowserVisit($this, 'anon-1', 's1', '2026-11-10T10:00:00-05:00', 'organic_search');
    // El pedido se creó a las 18:01:00 (sin milisegundos) y la visita desde el correo empezó a las 18:01:00.400.
    measurementBrowserVisit($this, 'anon-1', 's2', '2026-11-17T18:01:00.400-05:00', 'igr_email', ['source' => 'igr', 'medium' => 'email', 'campaign' => 'carrito-abandonado']);
    $paid = $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot()], ['identity' => ['anonymous_id' => 'anon-1'], 'occurred_at' => '2026-11-17T18:05:00-05:00']);
    $this->postSigned($this->site, $this->secret, [$paid]);
    $this->runWorker();

    $order = measurementInWs($this, fn () => Order::query()->firstOrFail());
    expect($order->getAttribute('channel_last'))->toBe('igr_email')
        ->and($order->getAttribute('utm_last'))->toBe(['source' => 'igr', 'medium' => 'email', 'campaign' => 'carrito-abandonado']);
});
