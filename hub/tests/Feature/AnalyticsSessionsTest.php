<?php

use App\Analytics\Builders\KpiDailyBuilder;
use App\Analytics\Builders\SessionsBuilder;
use App\Analytics\Ch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

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

it('R7: volver a pasar por la ventana solo reinserta la sesión que cambió', function () {
    $at = CarbonImmutable::now()->subHours(3);
    $id = ['anonymous_id' => 'anon-3', 'session_id' => 's_3'];
    $browser = fn (string $event, array $props, int $minutes) => $this->envelope($this->site, $event, $props, ['source' => ['type' => 'browser', 'sdk' => 'tracker/0.1.0'], 'identity' => $id, 'occurred_at' => $at->addMinutes($minutes)->toIso8601String()]);
    ($this->postBrowser)([$browser('page.viewed', ['page_type' => 'home'], 0), $browser('page.viewed', ['page_type' => 'product'], 2)]);
    $this->runWorker();
    $raw = fn () => (int) $this->chRows($this->ws, 'SELECT count() AS c FROM sessions WHERE workspace_id = {workspace_id:UInt32}')[0]['c'];
    // Sin fusiones de fondo: count() sin FINAL es lo que se escribió.
    app(Ch::class)->statement('SYSTEM STOP MERGES sessions');
    try {
        // El programador corre cada 10 minutos con la ventana de 180: la misma sesión cerrada se vuelve a ver.
        foreach ([0, 10, 20] as $shift) {
            app(SessionsBuilder::class)->build($this->ws, $at->subMinutes(60 - $shift), $at->addMinutes(60 + $shift));
        }
        expect($raw())->toBe(1);

        // Llega tarde un clic de esa sesión: la sesión cambió y se escribe otra vez (una sola).
        ($this->postBrowser)([$browser('cta.clicked', ['kind' => 'whatsapp', 'target' => 'https://wa.me/573001234567', 'label' => 'WhatsApp'], 3)]);
        $this->runWorker();
        app(SessionsBuilder::class)->build($this->ws, $at->subMinutes(30), $at->addMinutes(90));
        app(SessionsBuilder::class)->build($this->ws, $at->subMinutes(20), $at->addMinutes(100));
        expect($raw())->toBe(2);
    } finally {
        app(Ch::class)->statement('SYSTEM START MERGES sessions');
    }
    expect($this->chRows($this->ws, 'SELECT pageviews, cta_clicks FROM sessions FINAL WHERE workspace_id = {workspace_id:UInt32}'))->toBe([['pageviews' => 2, 'cta_clicks' => 1]]);
});

it('F12: después de armar las sesiones, los KPI de ayer y hoy quedan al día sin recalcular lo que no cambió', function () {
    $at = CarbonImmutable::now()->subHours(3);
    $view = fn (string $session, string $anon, int $minutes) => $this->envelope($this->site, 'page.viewed', ['page_type' => 'home'],
        ['source' => ['type' => 'browser', 'sdk' => 'tracker/0.1.0'], 'identity' => ['anonymous_id' => $anon, 'session_id' => $session], 'occurred_at' => $at->addMinutes($minutes)->toIso8601String()]);
    $tz = $this->ws->timezone ?: 'UTC';
    $kpi = fn () => (int) $this->chRows($this->ws, 'SELECT sum(sessions) AS s FROM kpi_daily WHERE workspace_id = {workspace_id:UInt32} AND date >= {d:Date}',
        ['d' => CarbonImmutable::now($tz)->subDay()->toDateString()])[0]['s'];
    // Un día viejo que solo el nocturno toca: la puesta al día no lo borra.
    $old = CarbonImmutable::now($tz)->subDays(5)->toDateString();
    app(Ch::class)->insert('kpi_daily', [['workspace_id' => $this->ws->ch_id, 'date' => $old, 'channel' => 'direct', 'device_type' => 'desktop', 'sessions' => 7,
        'users' => 7, 'product_views' => 0, 'add_to_carts' => 0, 'checkouts' => 0, 'orders' => 0, 'revenue' => '0', 'new_customers' => 0, 'returning_customers' => 0]]);

    ($this->postBrowser)([$view('s_k1', 'anon-k1', 0), $view('s_k1', 'anon-k1', 1)]);
    $this->runWorker();
    // Antes, kpi_daily solo se llenaba de noche: el tablero (que lee de ahí) no veía las sesiones de hoy.
    $this->artisan('igr:sessions:build', ['--workspace' => $this->ws->publicId(), '--no-kpi' => true])->assertSuccessful();
    expect($kpi())->toBe(0);

    $this->artisan('igr:sessions:build', ['--workspace' => $this->ws->publicId()])->assertSuccessful();
    expect($kpi())->toBe(1);
    // Sin cambios no se rehace nada.
    expect(app(KpiDailyBuilder::class)->refreshRecent($this->ws))->toBeFalse();

    // Otra sesión cerrada: la siguiente corrida la suma.
    ($this->postBrowser)([$view('s_k2', 'anon-k2', 5)]);
    $this->runWorker();
    $this->artisan('igr:sessions:build', ['--workspace' => $this->ws->publicId()])->assertSuccessful();
    $sessions = (int) $this->chRows($this->ws, 'SELECT count() AS c FROM sessions FINAL WHERE workspace_id = {workspace_id:UInt32}')[0]['c'];
    expect($sessions)->toBe(2)->and($kpi())->toBe(2)
        ->and((int) $this->chRows($this->ws, 'SELECT sum(sessions) AS s FROM kpi_daily WHERE workspace_id = {workspace_id:UInt32} AND date = {d:Date}', ['d' => $old])[0]['s'])->toBe(7);

    // Con la reconstrucción nocturna corriendo (candado tomado), la de cada 10 minutos no se mete: no se suma dos veces.
    $lock = Cache::lock('ws:'.$this->ws->id.':kpi_daily', 60);
    expect($lock->get())->toBeTrue();
    ($this->postBrowser)([$view('s_k3', 'anon-k3', 8)]);
    $this->runWorker();
    app(SessionsBuilder::class)->build($this->ws, $at->subHour());
    expect(app(KpiDailyBuilder::class)->refreshRecent($this->ws))->toBeFalse()->and($kpi())->toBe(2);
    $lock->release();
    expect(app(KpiDailyBuilder::class)->refreshRecent($this->ws))->toBeTrue()->and($kpi())->toBe(3);
});
