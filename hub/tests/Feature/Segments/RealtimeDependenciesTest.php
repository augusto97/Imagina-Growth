<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Segments\Actions\EvaluateContactSegments;
use App\Domain\Segments\Actions\RecomputeSegment;
use App\Domain\Segments\Jobs\EvaluateContactSegmentsJob;
use App\Domain\Segments\Listeners\QueueSegmentEvaluation;
use App\Domain\Segments\Models\Segment;
use App\Domain\Segments\Support\DependencyIndex;
use App\Domain\Segments\Support\PendingSegmentEvaluation;
use App\Ingest\Events\EventIngested;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
 * R8 de la auditoría: un evento de un contacto solo reevalúa los segmentos que dependen de ese tipo de evento
 * (antes, con que uno dependiera, se reevaluaban todos: ~17 por cada page.viewed).
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->ws = $this->workspaceFor($this->userWith2fa());
    [$this->site] = $this->connectedSite($this->ws);
    $ws = $this->ws;
    $this->ana = app(CurrentWorkspace::class)->run($ws, fn () => Contact::query()->create(['workspace_id' => $ws->id, 'lifecycle_stage' => 'customer', 'city' => 'Manizales', 'last_seen_at' => now()]));
    $this->seg = fn (string $name, array $condition) => app(CurrentWorkspace::class)->run($ws, function () use ($ws, $name, $condition) {
        $segment = Segment::query()->create(['workspace_id' => $ws->id, 'name' => $name, 'kind' => Segment::KIND_DYNAMIC, 'definition' => ['op' => 'and', 'conditions' => [$condition]]]);
        app(RecomputeSegment::class)->handle($segment, false);

        return $segment->fresh();
    });
    $this->visits = ($this->seg)('Visitó la tienda', ['type' => 'event', 'event' => 'page.viewed', 'filters' => [], 'operator' => 'ever']);
    $this->product = ($this->seg)('Vio el 88', ['type' => 'event', 'event' => 'product.viewed', 'filters' => [['prop' => 'product_id', 'operator' => 'eq', 'value' => '88']], 'operator' => 'ever']);
    $this->city = ($this->seg)('Manizales', ['type' => 'attribute', 'field' => 'city', 'operator' => 'eq', 'value' => 'Manizales']);
    DependencyIndex::forget($ws->id);
    Redis::connection()->command('del', [PendingSegmentEvaluation::key($ws->id, $this->ana->id)]);
});

function ingested($test, string $event): EventIngested
{
    return new EventIngested($test->ws->id, $test->site->id, $event, (string) Str::uuid7(), $test->ana->id, ['event' => $event, 'properties' => []], null, null);
}

function pendingFor($test): array
{
    $members = Redis::connection()->command('smembers', [PendingSegmentEvaluation::key($test->ws->id, $test->ana->id)]);
    $members = is_array($members) ? $members : [];
    sort($members);

    return $members;
}

it('anota solo los segmentos que dependen del evento y no encola nada si ninguno depende', function () {
    Queue::fake([EvaluateContactSegmentsJob::class]);
    $listener = app(QueueSegmentEvaluation::class);

    $listener->handle(ingested($this, 'page.viewed'));
    expect(pendingFor($this))->toBe([$this->visits->id]);
    $listener->handle(ingested($this, 'product.viewed'));
    $expected = [$this->visits->id, $this->product->id];
    sort($expected);
    expect(pendingFor($this))->toBe($expected);
    Queue::assertPushed(EvaluateContactSegmentsJob::class, fn (EvaluateContactSegmentsJob $job) => $job->usesPending && $job->contactId === $this->ana->id);

    Queue::fake([EvaluateContactSegmentsJob::class]);
    $listener->handle(ingested($this, 'search.performed'));
    Queue::assertNothingPushed();
});

it('el job reevalúa solo los anotados: page.viewed no toca el segmento por ciudad', function () {
    // Ana cumple «Manizales», pero se le quitó la membresía: si el job lo reevaluara, la volvería a meter.
    DB::table('segment_memberships')->where('segment_id', $this->city->id)->delete();
    PendingSegmentEvaluation::add($this->ws->id, $this->ana->id, [$this->visits->id]);

    app(CurrentWorkspace::class)->run($this->ws, fn () => (new EvaluateContactSegmentsJob($this->ws->id, $this->ana->id, true))->handle(app(EvaluateContactSegments::class)));
    expect(DB::table('segment_memberships')->where('segment_id', $this->city->id)->count())->toBe(0)
        ->and(pendingFor($this))->toBe([]);

    // Otro job del mismo contacto que llega después ya no tiene nada que hacer.
    app(CurrentWorkspace::class)->run($this->ws, fn () => (new EvaluateContactSegmentsJob($this->ws->id, $this->ana->id, true))->handle(app(EvaluateContactSegments::class)));
    expect(DB::table('segment_memberships')->where('segment_id', $this->city->id)->count())->toBe(0);

    // Un evento de perfil sí reevalúa el de ciudad.
    Queue::fake([EvaluateContactSegmentsJob::class]);
    app(QueueSegmentEvaluation::class)->handle(ingested($this, 'customer.updated'));
    expect(pendingFor($this))->toBe([$this->city->id]);
    Queue::pushed(EvaluateContactSegmentsJob::class)->each(fn (EvaluateContactSegmentsJob $job) => app(CurrentWorkspace::class)->run($this->ws, fn () => $job->handle(app(EvaluateContactSegments::class))));
    expect(DB::table('segment_memberships')->where('segment_id', $this->city->id)->where('contact_id', $this->ana->id)->count())->toBe(1);
});

it('un job encolado antes del cambio (sin segmentos anotados) reevalúa todos, como antes', function () {
    DB::table('segment_memberships')->where('segment_id', $this->city->id)->delete();
    app(CurrentWorkspace::class)->run($this->ws, fn () => (new EvaluateContactSegmentsJob($this->ws->id, $this->ana->id))->handle(app(EvaluateContactSegments::class)));
    expect(DB::table('segment_memberships')->where('segment_id', $this->city->id)->where('contact_id', $this->ana->id)->count())->toBe(1);
});

it('con el índice en caché el listener no consulta la base', function () {
    Queue::fake([EvaluateContactSegmentsJob::class]);
    app(CurrentWorkspace::class)->run($this->ws, fn () => DependencyIndex::load($this->ws->id));
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(QueueSegmentEvaluation::class)->handle(ingested($this, 'page.viewed'));
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

it('aislamiento: lo anotado de un contacto vive bajo el prefijo de su espacio de trabajo', function () {
    $other = $this->workspaceFor($this->userWith2fa(['email' => 'otra@agencia.co']), 'Otra');
    PendingSegmentEvaluation::add($other->id, $this->ana->id, ['SEGMENTO_DE_OTRO']);
    expect(PendingSegmentEvaluation::take($this->ws->id, $this->ana->id))->toBe([])
        ->and(PendingSegmentEvaluation::take($other->id, $this->ana->id))->toBe(['SEGMENTO_DE_OTRO']);
});
