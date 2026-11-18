<?php

use App\Analytics\Ch;
use App\Domain\Commerce\Contracts\CouponIssuer;
use App\Domain\Commerce\Data\CouponSpec;
use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Commerce\Models\Order;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Queries\WidgetStats;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Estadísticas por widget y variante, atribución de captación (paso 4) y A/B con ganador automático.
beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
    $this->base = "/api/w/{$this->ws->publicId()}/onsite";
    $ws = $this->ws;
    app()->instance(CouponIssuer::class, new class($ws) implements CouponIssuer
    {
        public function __construct(private $ws) {}

        public function issue(CouponSpec $spec): CouponIssued
        {
            return CouponIssued::query()->create([
                'workspace_id' => $this->ws->id, 'site_id' => $spec->siteId, 'code' => 'BIENVENIDA-'.Str::upper(Str::random(6)), 'contact_id' => $spec->contactId,
                'source_type' => 'widget', 'source_id' => $spec->sourceId, 'kind' => $spec->kind, 'amount' => $spec->amount, 'status' => 'active', 'expires_at' => now()->addWeek(),
            ]);
        }
    });
    $id = $this->actingAs($this->owner)->postJson("{$this->base}/widgets", ['name' => 'Popup A/B', 'kind' => 'popup'])->json('id');
    $this->actingAs($this->owner)->patchJson("{$this->base}/widgets/{$id}", ['variants' => [
        ['weight' => 50, 'content' => ['title' => 'A', 'cta' => 'Quiero', 'success' => 'Tu cupón: {{codigo}}', 'fields' => [['key' => 'email', 'required' => true]]]],
        ['weight' => 50, 'content' => ['title' => 'B', 'cta' => 'Dame', 'success' => 'Tu cupón: {{codigo}}', 'fields' => [['key' => 'email', 'required' => true]]]],
    ], 'consent_config' => ['channels' => ['email']], 'ab_auto_winner' => true])->assertOk();
    $this->actingAs($this->owner)->postJson("{$this->base}/widgets/{$id}/activate")->assertOk();
    $this->widgetId = $id;
    $this->widget = app(CurrentWorkspace::class)->run($this->ws, fn () => OnsiteWidget::findByPublicId($id));
});

/** Vistas del widget como las manda el tracker (widget.shown), directo a ClickHouse. */
function onsiteViews($test, string $variant, int $count, ?CarbonImmutable $at = null): void
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'workspace_id' => $test->ws->ch_id, 'site_id' => $test->site->ch_id, 'event_id' => (string) Str::uuid7(), 'event' => 'widget.shown',
            'occurred_at' => ($at ?? CarbonImmutable::now()->subHour())->utc()->format('Y-m-d H:i:s.v'), 'received_at' => now()->utc()->format('Y-m-d H:i:s.v'),
            'source_type' => 'browser', 'anonymous_id' => "anon-{$variant}-{$i}", 'session_id' => "s-{$variant}-{$i}", 'contact_id' => '', 'revenue' => '0',
            'properties' => ['widget_id' => $test->widgetId, 'variant' => $variant, 'kind' => 'popup'],
        ];
    }
    app(Ch::class)->insert('events', $rows);
}

function onsiteCaptures($test, string $variant, int $count, bool $known = false): void
{
    for ($i = 0; $i < $count; $i++) {
        DB::table('widget_captures')->insert([
            'workspace_id' => $test->ws->id, 'widget_id' => $test->widget->id, 'variant' => $variant, 'contact_id' => strtoupper((string) Str::ulid()),
            'was_known' => $known, 'captured_at' => now()->subMinutes(30),
        ]);
    }
}

it('atribuye el pedido al widget que capturó al contacto en los 7 días anteriores y lo suma a sus ingresos', function () {
    $this->call('POST', "/p/{$this->site->public_key}/widgets/{$this->widgetId}/submit", [], [], [], ['CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => '181.1.1.1'],
        json_encode(['anonymous_id' => 'anon-ana', 'variant' => 'b', 'fields' => ['email' => 'ana@ejemplo.com'], 'consents' => ['email' => true]]))->assertOk();

    $now = now()->toIso8601String();
    $paid = $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['created_at' => $now, 'paid_at' => $now])], ['identity' => ['anonymous_id' => 'anon-ana']]);
    $this->postSigned($this->site, $this->secret, [$paid])->assertAccepted();
    $this->runWorker();

    $order = app(CurrentWorkspace::class)->run($this->ws, fn () => Order::query()->firstOrFail());
    expect($order->getAttribute('attributed_widget_id'))->toBe($this->widget->id)->and($order->getAttribute('attributed_widget_variant'))->toBe('b');

    onsiteViews($this, 'a', 30);
    onsiteViews($this, 'b', 20);
    $stats = $this->actingAs($this->owner)->getJson("{$this->base}/widgets/{$this->widgetId}/stats?days=7")->assertOk();
    expect($stats->json('totals'))->toMatchArray(['views' => 50, 'visitors' => 50, 'submissions' => 1, 'new_contacts' => 1, 'orders' => 1, 'buyers' => 1, 'revenue' => '282000.00', 'conversion_rate' => 1])
        ->and($stats->json('variants.0'))->toMatchArray(['key' => 'a', 'views' => 30, 'submissions' => 0, 'revenue' => '0.00'])
        ->and($stats->json('variants.1'))->toMatchArray(['key' => 'b', 'views' => 20, 'submissions' => 1, 'orders' => 1, 'submit_rate' => 0.05])
        ->and($stats->json('daily'))->toHaveCount(7)
        ->and(collect($stats->json('daily'))->sum('submissions'))->toBe(1)
        ->and(collect($stats->json('daily'))->sum('views'))->toBe(50)
        ->and($stats->json('currency'))->toBe('COP');
    $list = $this->actingAs($this->owner)->getJson("{$this->base}/widgets")->assertOk();
    expect($list->json('data.0.stats'))->toMatchArray(['views' => 50, 'submissions' => 1, 'revenue' => '282000.00']);
    $captures = $this->actingAs($this->owner)->getJson("{$this->base}/widgets/{$this->widgetId}/captures")->assertOk();
    expect($captures->json('data.0.contact.email'))->not->toBe('ana@ejemplo.com')->and($captures->json('data.0.variant'))->toBe('b')->and($captures->json('data.0.coupon'))->toStartWith('BIENVENIDA-');
});

it('no atribuye capturas de hace más de 7 días ni pedidos sin contacto', function () {
    $this->call('POST', "/p/{$this->site->public_key}/widgets/{$this->widgetId}/submit", [], [], [], ['CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => '181.1.1.1'],
        json_encode(['anonymous_id' => 'anon-ana', 'fields' => ['email' => 'ana@ejemplo.com']]))->assertOk();
    DB::table('widget_captures')->update(['captured_at' => now()->subDays(8)]);
    $now = now()->toIso8601String();
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['created_at' => $now, 'paid_at' => $now])])]);
    $this->runWorker();
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => Order::query()->firstOrFail()->getAttribute('attributed_widget_id')))->toBeNull();
});

it('compara las variantes y el ganador automático se queda con todo el tráfico', function () {
    onsiteViews($this, 'a', 200);
    onsiteViews($this, 'b', 200);
    onsiteCaptures($this, 'a', 40);
    onsiteCaptures($this, 'b', 12, true);
    $stats = $this->actingAs($this->owner)->getJson("{$this->base}/widgets/{$this->widgetId}/stats")->assertOk();
    expect($stats->json('ab'))->toMatchArray(['leader' => 'a', 'runner_up' => 'b', 'significant' => true, 'enough_data' => true])
        ->and($stats->json('ab.confidence'))->toBeGreaterThan(0.99)
        ->and($stats->json('variants.1'))->toMatchArray(['known_contacts' => 12, 'new_contacts' => 0]);

    $this->artisan('igr:onsite:ab-winners')->expectsOutputToContain('Ganadoras elegidas: 1')->assertSuccessful();
    $detail = $this->actingAs($this->owner)->getJson("{$this->base}/widgets/{$this->widgetId}")->json();
    expect(array_column($detail['variants'], 'weight'))->toBe([100, 0])->and($detail['ab']['winner']['key'])->toBe('a');
    $public = collect($this->getJson("/p/{$this->site->public_key}/widgets.json?page_type=home")->json('widgets'))->firstWhere('id', $this->widgetId);
    expect(array_column($public['variants'], 'weight'))->toBe([100, 0]);
    // Ya decidido: no vuelve a elegir.
    $this->artisan('igr:onsite:ab-winners')->expectsOutputToContain('Ganadoras elegidas: 0')->assertSuccessful();
});

it('con pocos datos no declara ganadora', function () {
    $ab = WidgetStats::abTest([
        ['key' => 'a', 'views' => 50, 'submissions' => 10],
        ['key' => 'b', 'views' => 50, 'submissions' => 2],
    ]);
    expect($ab['leader'])->toBe('a')->and($ab['enough_data'])->toBeFalse()->and($ab['significant'])->toBeFalse();
    expect(WidgetStats::abTest([['key' => 'a', 'views' => 0, 'submissions' => 0], ['key' => 'b', 'views' => 0, 'submissions' => 0]])['leader'])->toBeNull();
});
