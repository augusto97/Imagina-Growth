<?php

use App\Analytics\Builders\KpiDailyBuilder;
use App\Analytics\Builders\SessionsBuilder;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->ws = $this->workspaceFor($this->userWith2fa());
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
    // Eventos del tracker por /v1/b (api-connector.md §4.2).
    $this->postBrowser = function (array $events): void {
        $body = json_encode(['k' => $this->site->public_key, 'events' => $events]);
        $this->call('POST', '/v1/b', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://tienda.co'], $body)->assertStatus(202)->assertJsonPath('rejected', []);
    };
});

it('arma sesiones cerradas desde los eventos con el canal de entrada y la compra', function () {
    $at = CarbonImmutable::now()->subHours(3);
    $id = ['anonymous_id' => 'anon-1', 'session_id' => 's_1'];
    $browser = ['source' => ['type' => 'browser', 'sdk' => 'tracker/0.1.0'], 'identity' => $id];
    $events = [
        $this->envelope($this->site, 'page.viewed', ['page_type' => 'product'], $browser + ['occurred_at' => $at->toIso8601String(), 'context' => ['page' => ['path' => '/producto/x/', 'referrer' => 'https://chatgpt.com/'], 'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) Safari/604.1']]),
        $this->envelope($this->site, 'product.viewed', ['product_id' => '88', 'name' => 'X', 'price' => '1.00', 'currency' => 'COP'], $browser + ['occurred_at' => $at->addMinute()->toIso8601String(), 'context' => ['page' => ['path' => '/producto/x/', 'referrer' => 'https://tienda.co/']]]),
        $this->envelope($this->site, 'cart.item_added', ['cart_token' => 'c_12345678', 'product_id' => '88', 'qty' => 1], ['identity' => $id, 'occurred_at' => $at->addMinutes(2)->toIso8601String()]),
        $this->envelope($this->site, 'order.created', ['order' => $this->orderSnapshot()], ['identity' => $id + ['email' => 'ana@ejemplo.com'], 'occurred_at' => $at->addMinutes(5)->toIso8601String()]),
    ];
    ($this->postBrowser)([$events[0], $events[1]]);
    $this->postSigned($this->site, $this->secret, [$events[2], $events[3]])->assertJsonPath('accepted', 2);
    $this->runWorker();

    app(SessionsBuilder::class)->build($this->ws, $at->subHour());
    $rows = $this->chRows($this->ws, 'SELECT session_id, channel, device_type, landing_path, pageviews, viewed_product, added_to_cart, purchased, contact_id, toString(revenue) AS revenue FROM sessions FINAL WHERE workspace_id = {workspace_id:UInt32}');
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['session_id' => 's_1', 'channel' => 'ai_assistants', 'device_type' => 'mobile', 'landing_path' => '/producto/x/',
            'pageviews' => 1, 'viewed_product' => 1, 'added_to_cart' => 1, 'purchased' => 1, 'revenue' => '282000'])
        ->and($rows[0]['contact_id'])->not->toBe('');

    // Reconstruir no duplica.
    app(SessionsBuilder::class)->build($this->ws, $at->subHour());
    expect($this->chRows($this->ws, 'SELECT count() AS c FROM sessions FINAL WHERE workspace_id = {workspace_id:UInt32}')[0]['c'])->toBe(1);

    app(KpiDailyBuilder::class)->build($this->ws, 2);
    app(KpiDailyBuilder::class)->build($this->ws, 2);
    $kpi = $this->chRows($this->ws, 'SELECT sum(sessions) AS s, sum(orders) AS o FROM kpi_daily WHERE workspace_id = {workspace_id:UInt32}');
    expect($kpi[0])->toMatchArray(['s' => 1, 'o' => 1]);
});

it('no cierra una sesión con actividad reciente', function () {
    $id = ['anonymous_id' => 'anon-2', 'session_id' => 's_2'];
    ($this->postBrowser)([
        $this->envelope($this->site, 'page.viewed', ['page_type' => 'home'], ['source' => ['type' => 'browser'], 'identity' => $id, 'occurred_at' => now()->subMinutes(5)->toIso8601String()]),
    ]);
    $this->runWorker();
    app(SessionsBuilder::class)->build($this->ws, now()->subHour());
    expect($this->chRows($this->ws, 'SELECT count() AS c FROM sessions FINAL WHERE workspace_id = {workspace_id:UInt32}')[0]['c'])->toBe(0);
});
