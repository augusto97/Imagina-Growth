<?php

use App\Domain\Measurement\Jobs\AttributeOrder;
use App\Domain\Measurement\Jobs\DeliverConversion;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Mail\InvitationMail;
use App\Domain\Workspaces\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
 * S15: Horizon exige la verificación en dos pasos, y los trabajos que llevan IP, identificadores de clic, correos,
 * tokens o textos van cifrados en la cola: así tampoco quedan en claro en `failed_jobs` ni en Horizon.
 */

/** Lo que de verdad queda guardado en Redis al encolar. */
function rawQueuedPayload(callable $push): string
{
    $queue = 'test-privacy-'.Str::lower(Str::random(8));
    $push($queue);
    $redis = Redis::connection((string) config('queue.connections.redis.connection', 'default'));
    $raw = implode("\n", (array) $redis->lrange('queues:'.$queue, 0, -1));
    $redis->del('queues:'.$queue);
    expect($raw)->not->toBe('');

    return $raw;
}

it('Horizon es solo para el superadmin con verificación en dos pasos', function () {
    $admin2fa = $this->userWith2fa(['is_platform_admin' => true]);
    $adminNo2fa = User::factory()->create(['is_platform_admin' => true]);
    $owner = $this->userWith2fa();
    expect(Gate::forUser($admin2fa)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($adminNo2fa)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('viewHorizon'))->toBeFalse();

    $this->actingAs($adminNo2fa)->get('/horizon/api/masters')->assertForbidden();
    $this->actingAs($owner)->get('/horizon/api/masters')->assertForbidden();
    $this->actingAs($admin2fa)->get('/horizon/api/masters')->assertOk();

    // Si la instalación no exige 2FA, basta con ser superadmin.
    config(['igr.require_two_factor' => false]);
    expect(Gate::forUser($adminNo2fa)->allows('viewHorizon'))->toBeTrue();
});

it('la conversión, la atribución y la invitación quedan cifradas en la cola', function () {
    $ws = $this->workspaceFor($owner = $this->userWith2fa());
    $conversion = rawQueuedPayload(fn (string $q) => Queue::connection('redis')->push(new DeliverConversion($ws->id, 'dst_1', 'meta_capi', [
        'event_name' => 'Purchase', 'user_data' => ['client_ip_address' => '190.85.12.34', 'client_user_agent' => 'Mozilla/5.0 Prueba'],
    ], ['source_event_id' => 'e1', 'source_event' => 'order.paid', 'conversion' => 'Purchase', 'contact_id' => null, 'match_keys' => [], 'first_attempt_at' => 1, 'attempt' => 1]), '', $q));
    expect($conversion)->not->toContain('190.85.12.34')->not->toContain('Mozilla/5.0 Prueba');

    $attribution = rawQueuedPayload(fn (string $q) => Queue::connection('redis')->push(new AttributeOrder($ws->id, 'site_1', '1234', [
        'last_touch' => ['click_ids' => ['gclid' => 'gclid-secreto-123']], 'anonymous_id' => 'anon-de-ana',
    ]), '', $q));
    expect($attribution)->not->toContain('gclid-secreto-123')->not->toContain('anon-de-ana');

    config(['queue.default' => 'redis']);
    $invite = rawQueuedPayload(fn (string $q) => Mail::to('nueva.persona@cliente.co')->queue((new InvitationMail($ws, $owner, 'token-de-invitacion-xyz', WorkspaceRole::Admin))->onQueue($q)));
    expect($invite)->not->toContain('token-de-invitacion-xyz')->not->toContain('nueva.persona@cliente.co');
});

it('todo trabajo encolado con datos personales en sus parámetros va cifrado', function () {
    // Nombres de parámetros que llevan IP, correos, teléfonos, identificadores de clic o de redes, tokens o textos.
    $sensitive = '/^(ip|email|phone|to|hints|payload|props|rows|token|recipient|recipientName|senderId|message|text|facts|body)$/';
    $missing = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (! str_contains($code, 'ShouldQueue') || preg_match('/^namespace ([^;]+);/m', $code, $ns) !== 1 || preg_match('/^(?:final |abstract )?class (\w+)/m', $code, $cls) !== 1) {
            continue;
        }
        $class = $ns[1].'\\'.$cls[1];
        if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class) || (new ReflectionClass($class))->getConstructor() === null) {
            continue;
        }
        foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
            $type = $param->getType();
            $isService = $type instanceof ReflectionNamedType && ! $type->isBuiltin() && ! str_contains($type->getName(), '\\Models\\');
            if (! $isService && preg_match($sensitive, $param->getName()) === 1 && ! is_subclass_of($class, ShouldBeEncrypted::class)) {
                $missing[] = $class.'::$'.$param->getName();
            }
        }
    }
    expect($missing)->toBe([]);
});
