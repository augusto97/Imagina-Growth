<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Symfony\Component\Finder\Finder;

/*
 * Un job cuyo timeout supera el retry_after de su conexión se ejecuta dos veces: el worker lo da por perdido y
 * otro lo toma mientras el primero sigue (mensajes y reportes duplicados). Las colas `analytics` las atiende la
 * conexión `redis-long`; el resto, `redis` (config/horizon.php).
 */
it('ningún job dura más que el retry_after de la conexión que lo atiende', function () {
    $config = require dirname(__DIR__, 3).'/config/queue.php';
    $short = (int) $config['connections']['redis']['retry_after'];
    $long = (int) $config['connections']['redis-long']['retry_after'];
    $checked = 0;
    $offenders = [];

    foreach (Finder::create()->files()->in(dirname(__DIR__, 3).'/app')->name('*.php')->contains('ShouldQueue') as $file) {
        $source = $file->getContents();
        if (! preg_match('/^namespace\s+([^;]+);/m', $source, $ns) || ! preg_match('/^(?:final\s+|abstract\s+)*class\s+(\w+)/m', $source, $cls)) {
            continue;
        }
        $class = $ns[1].'\\'.$cls[1];
        if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
            continue;
        }
        $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;
        if (! is_int($timeout)) {
            continue;
        }
        $checked++;
        $isLong = str_contains($source, "onQueue('analytics')") || str_contains($source, "'redis-long'");
        $limit = $isLong ? $long : $short;
        if ($timeout >= $limit) {
            $offenders[] = "{$class} ({$timeout} s ≥ {$limit} s)";
        }
    }

    expect($checked)->toBeGreaterThan(10)->and($offenders)->toBe([]);
});
