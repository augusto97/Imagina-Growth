<?php

use App\Analytics\Ch;
use App\Ingest\IngestStream;
use App\Support\Monitor\SystemProbe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
 * growth:monitor (docs/despliegue.md §6): avisa a Better Stack solo de lo que está bien.
 */

/** Sonda con un estado fijo para cada prueba. */
function monitorProbe(array $state = []): SystemProbe
{
    $state += [
        'deps' => ['mariadb' => true, 'clickhouse' => true, 'redis' => true],
        'streams' => [['shard' => 0, 'length' => 10, 'pending' => 2, 'lag' => 3, 'oldest_seconds' => 4]],
        'horizon' => 'running',
        'queues' => ['default' => 5, 'messages:email' => 0],
    ];

    return new class(app(Ch::class), app(IngestStream::class), $state) extends SystemProbe
    {
        public function __construct(Ch $ch, IngestStream $stream, private array $state)
        {
            parent::__construct($ch, $stream);
        }

        public function dependencies(): array
        {
            return $this->state['deps'];
        }

        public function streams(): ?array
        {
            return $this->state['streams'];
        }

        public function horizon(): string
        {
            return $this->state['horizon'];
        }

        public function queues(): ?array
        {
            return $this->state['queues'];
        }
    };
}

function monitorRun($test, array $state = [], bool $ping = true): array
{
    app()->instance(SystemProbe::class, monitorProbe($state));
    $exit = Artisan::call('growth:monitor', $ping ? ['--ping' => true] : []);
    $out = trim(Artisan::output());

    return [$exit, json_decode((string) last(explode("\n", $out)), true)];
}

beforeEach(function () {
    config([
        'igr-monitor.heartbeats.health' => 'https://uptime.betterstack.test/api/v1/heartbeat/HEALTH',
        'igr-monitor.heartbeats.streams' => 'https://uptime.betterstack.test/api/v1/heartbeat/STREAMS',
        'igr-monitor.heartbeats.queues' => 'https://uptime.betterstack.test/api/v1/heartbeat/QUEUES',
        'igr-monitor.max_stream_lag' => 5000,
        'igr-monitor.max_stream_age_seconds' => 120,
        'igr-monitor.max_queue_backlog' => 10000,
    ]);
    Http::fake(['uptime.betterstack.test/*' => Http::response('OK')]);
});

it('con todo bien imprime el estado y avisa a los tres heartbeats', function () {
    [$exit, $json] = monitorRun($this);

    expect($exit)->toBe(0)
        ->and($json['status'])->toBe('ok')
        ->and($json['streams']['backlog'])->toBe(5)
        ->and($json['queues']['backlog'])->toBe(5)
        ->and($json['verdicts'])->toBe(['health' => true, 'streams' => true, 'queues' => true])
        ->and($json['pinged'])->toBe(['health', 'streams', 'queues']);
    Http::assertSentCount(3);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/STREAMS'));
});

it('si los streams se atrasan no avisa a ese heartbeat (Better Stack alerta) y sale con error', function () {
    [$exit, $json] = monitorRun($this, ['streams' => [
        ['shard' => 0, 'length' => 9000, 'pending' => 100, 'lag' => 4000, 'oldest_seconds' => 30],
        ['shard' => 1, 'length' => 2000, 'pending' => 0, 'lag' => 1000, 'oldest_seconds' => 10],
    ]]);

    expect($exit)->toBe(1)->and($json['status'])->toBe('degraded')
        ->and($json['streams']['backlog'])->toBe(5100)
        ->and($json['verdicts']['streams'])->toBeFalse()
        ->and($json['pinged'])->toBe(['health', 'queues']);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'STREAMS'));
});

it('un evento sin procesar demasiado viejo también cuenta como atraso', function () {
    [, $json] = monitorRun($this, ['streams' => [['shard' => 0, 'length' => 3, 'pending' => 1, 'lag' => 0, 'oldest_seconds' => 600]]]);

    expect($json['verdicts']['streams'])->toBeFalse()->and($json['streams']['oldest_seconds'])->toBe(600);
});

it('con Horizon detenido o pausado, o las colas acumuladas, no avisa al de colas', function (string $horizon, array $queues) {
    [, $json] = monitorRun($this, ['horizon' => $horizon, 'queues' => $queues]);

    expect($json['verdicts']['queues'])->toBeFalse()->and($json['pinged'])->not->toContain('queues');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'QUEUES'));
})->with([
    'inactivo' => ['inactive', ['default' => 0]],
    'pausado' => ['paused', ['default' => 0]],
    'acumuladas' => ['running', ['default' => 9000, 'ai' => 1500]],
]);

it('si Redis no responde no avisa a la salud ni a los streams', function () {
    [$exit, $json] = monitorRun($this, ['deps' => ['mariadb' => true, 'clickhouse' => true, 'redis' => false], 'streams' => null]);

    expect($exit)->toBe(1)
        ->and($json['verdicts'])->toMatchArray(['health' => false, 'streams' => false])
        ->and($json['pinged'])->toBe(['queues']);
});

it('sin --ping o sin URLs configuradas no llama a Better Stack', function () {
    monitorRun($this, [], ping: false);
    config(['igr-monitor.heartbeats.health' => null, 'igr-monitor.heartbeats.streams' => '', 'igr-monitor.heartbeats.queues' => null]);
    [$exit, $json] = monitorRun($this);

    expect($exit)->toBe(0)->and($json['pinged'])->toBe([]);
    Http::assertNothingSent();
});

it('si Better Stack no responde no falla la medición', function () {
    Http::fake(['uptime.betterstack.test/*' => Http::response('', 500)]);
    [$exit, $json] = monitorRun($this);

    expect($exit)->toBe(0)->and($json['status'])->toBe('ok');
});

it('la sonda real mide pendientes, atraso y antigüedad del stream de ingesta en Redis', function () {
    $this->useIngestInfrastructure();
    config(['igr.ingest.shards' => 2]);
    $stream = app(IngestStream::class);
    /** @var Redis $redis */
    $redis = $stream->connection()->client();
    $group = (string) config('igr.ingest.consumer_group');
    $key = $stream->key(0);
    // Un evento de hace 5 minutos y cuatro de ahora; el grupo lee dos (quedan pendientes) y tres sin entregar.
    $old = (int) floor(microtime(true) * 1000) - 300_000;
    $redis->xAdd($key, $old.'-0', ['e' => '1']);
    for ($i = 0; $i < 4; $i++) {
        $redis->xAdd($key, '*', ['e' => (string) ($i + 2)]);
    }
    $redis->xGroup('CREATE', $key, $group, '0');
    $redis->xReadGroup($group, 'monitor-test', [$key => '>'], 2);
    // El shard 1 tiene eventos y todavía no tiene grupo: todo cuenta como atraso.
    $redis->xAdd($stream->key(1), '*', ['e' => 'x']);

    $shards = app(SystemProbe::class)->streams();

    expect($shards)->toHaveCount(2)
        ->and($shards[0])->toMatchArray(['shard' => 0, 'length' => 5, 'pending' => 2, 'lag' => 3])
        ->and($shards[0]['oldest_seconds'])->toBeGreaterThanOrEqual(299)
        ->and($shards[1])->toMatchArray(['shard' => 1, 'length' => 1, 'pending' => 0, 'lag' => 1]);
    expect(app(SystemProbe::class)->dependencies())->toBe(['mariadb' => true, 'clickhouse' => true, 'redis' => true])
        ->and(app(SystemProbe::class)->horizon())->toBe('inactive')
        ->and(app(SystemProbe::class)->queues())->toHaveKey('messages:whatsapp');
});
