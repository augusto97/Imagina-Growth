<?php

declare(strict_types=1);

/*
 * Perfil de procesos de Horizon (docs/despliegue.md §7): en servidores con poca RAM, install.sh escribe
 * IGR_WORKERS_PROFILE=small y Horizon junta las colas en dos supervisores.
 */

/** @return array<string, mixed> */
function horizonConfigWithProfile(?string $profile): array
{
    $keys = ['IGR_WORKERS_PROFILE'];
    foreach ($keys as $key) {
        if ($profile === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $profile;
            putenv("{$key}={$profile}");
        }
    }
    try {
        return require base_path('config/horizon.php');
    } finally {
        unset($_ENV['IGR_WORKERS_PROFILE'], $_SERVER['IGR_WORKERS_PROFILE']);
        putenv('IGR_WORKERS_PROFILE');
    }
}

/**
 * @param  array<string, mixed>  $config
 * @return array<string, string> cola → conexión
 */
function horizonQueues(array $config): array
{
    $queues = [];
    foreach ($config['defaults'] as $supervisor) {
        foreach ($supervisor['queue'] as $queue) {
            $queues[$queue] = $supervisor['connection'];
        }
    }
    ksort($queues);

    return $queues;
}

it('el perfil pequeño atiende las mismas colas, por la misma conexión, que el estándar', function () {
    $standard = horizonConfigWithProfile(null);
    $small = horizonConfigWithProfile('small');

    expect(horizonQueues($small))->toBe(horizonQueues($standard));
});

it('el perfil pequeño usa 3 procesos de trabajo y no lo agranda ningún entorno', function () {
    $small = horizonConfigWithProfile('small');

    expect(array_sum(array_column($small['defaults'], 'maxProcesses')))->toBe(3)
        ->and($small['environments'])->toBe(['production' => [], 'local' => []]);
});

it('el timeout del supervisor pequeño es menor que el retry_after de su conexión', function () {
    $small = horizonConfigWithProfile('small');
    $retryAfter = ['redis' => (int) config('queue.connections.redis.retry_after'), 'redis-long' => (int) config('queue.connections.redis-long.retry_after')];

    foreach ($small['defaults'] as $name => $supervisor) {
        expect($supervisor['timeout'])->toBeLessThan($retryAfter[$supervisor['connection']], $name);
    }
});

it('sin la variable queda el perfil estándar', function () {
    $standard = horizonConfigWithProfile(null);

    expect($standard['defaults'])->toHaveKey('supervisor-messages')
        ->and($standard['environments']['production']['supervisor-messages']['maxProcesses'])->toBe(9);
});
