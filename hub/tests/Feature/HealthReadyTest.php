<?php

use App\Analytics\Ch;
use App\Support\AppVersion;
use Illuminate\Support\Facades\Redis;

/*
 * docs/despliegue.md §4: el actualizador exige status ok y la versión instalada.
 */

it('responde lista con la versión instalada y las dos instancias de Redis', function () {
    config(['app.version' => '1.4.2']);
    $this->getJson('/health/ready')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('version', '1.4.2')
        ->assertJsonPath('checks.mariadb', true)
        ->assertJsonPath('checks.clickhouse', true)
        ->assertJsonPath('checks.redis_queue', true)
        ->assertJsonPath('checks.redis_cache', true);
});

it('responde 503 si una dependencia no contesta, sin dejar de decir la versión', function () {
    config(['app.version' => '1.4.2', 'igr.clickhouse.url' => 'http://127.0.0.1:1']);
    app()->forgetInstance(Ch::class);
    $this->getJson('/health/ready')->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('version', '1.4.2')
        ->assertJsonPath('checks.clickhouse', false);
});

it('responde 503 si la instancia de caché de Redis no contesta', function () {
    config(['database.redis.cache.port' => 1]);
    Redis::purge('cache');
    $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('checks.redis_cache', false);
});

it('responde en cualquier host, sin pasar por la SPA', function () {
    $this->getJson('http://otro-dominio.test/health/ready')->assertOk()->assertJsonPath('status', 'ok');
});

it('lee la versión del archivo VERSION y usa «dev» si falta o no es válida', function () {
    $dir = sys_get_temp_dir().'/igr-version-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/VERSION", "0.3.1\n");
    expect(AppVersion::fromFile("{$dir}/VERSION"))->toBe('0.3.1');
    file_put_contents("{$dir}/VERSION", "1.0.0-rc.1\n");
    expect(AppVersion::fromFile("{$dir}/VERSION"))->toBe('1.0.0-rc.1');
    file_put_contents("{$dir}/VERSION", "<?php echo 1;\n");
    expect(AppVersion::fromFile("{$dir}/VERSION"))->toBe('dev')
        ->and(AppVersion::fromFile("{$dir}/no-existe"))->toBe('dev');
    unlink("{$dir}/VERSION");
    rmdir($dir);
    // En el repositorio, la del VERSION de la raíz.
    expect(AppVersion::fromFile(base_path('../VERSION')))->toBe(trim((string) file_get_contents(base_path('../VERSION'))));
});

it('la caché usa su propia instancia de Redis si se configura, y la de las colas si no', function () {
    $resolve = function (array $env): array {
        foreach (['REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'REDIS_CACHE_HOST', 'REDIS_CACHE_PORT', 'REDIS_CACHE_PASSWORD'] as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        foreach ($env as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
        $config = require base_path('config/database.php');

        return [$config['redis']['default'], $config['redis']['cache']];
    };
    $before = ['REDIS_HOST' => getenv('REDIS_HOST'), 'REDIS_PORT' => getenv('REDIS_PORT')];

    [$default, $cache] = $resolve(['REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '6379', 'REDIS_CACHE_HOST' => '127.0.0.1', 'REDIS_CACHE_PORT' => '6380', 'REDIS_CACHE_PASSWORD' => 'c-secret']);
    expect($default['port'])->toBe('6379')->and($cache['port'])->toBe('6380')->and($cache['password'])->toBe('c-secret');

    [$default, $cache] = $resolve(['REDIS_HOST' => '10.0.0.5', 'REDIS_PORT' => '6390', 'REDIS_PASSWORD' => 'q-secret']);
    expect($cache['host'])->toBe('10.0.0.5')->and($cache['port'])->toBe('6390')->and($cache['password'])->toBe('q-secret');

    foreach ($before as $k => $v) {
        $v === false ? putenv($k) : putenv("{$k}={$v}");
    }
});
