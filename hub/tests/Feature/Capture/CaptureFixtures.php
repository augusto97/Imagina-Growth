<?php

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Capture\Models\LandingPage;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Events\EventIngested;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/*
 * Ayudas de las pruebas de la captación sin tienda. Todas llevan el prefijo `capture` (las pruebas de Pest comparten
 * el espacio de nombres global).
 */

/** Crea la página por la API (como la SPA), con los cambios dados, y la publica. */
function capturePublishedPage(object $test, array $changes = [], string $name = 'Clínica Sonrisa'): LandingPage
{
    $id = (string) $test->actingAs($test->owner)->postJson("{$test->api}/capture/pages", ['name' => $name])->assertCreated()->json('id');
    if ($changes !== []) {
        $test->actingAs($test->owner)->patchJson("{$test->api}/capture/pages/{$id}", $changes)->assertOk();
    }
    $test->actingAs($test->owner)->postJson("{$test->api}/capture/pages/{$id}/publish")->assertOk()->assertJsonPath('status', 'published');

    return captureIn($test->ws, fn () => LandingPage::findByPublicId($id));
}

/**
 * @template T
 *
 * @param  callable(): T  $fn
 * @return T
 */
function captureIn(Workspace $ws, callable $fn): mixed
{
    return app(CurrentWorkspace::class)->run($ws, $fn);
}

/** El token del formulario que pintó la página. */
function captureToken(TestResponse $page): string
{
    preg_match('/name="t" value="([^"]+)"/', (string) $page->getContent(), $m);

    return (string) ($m[1] ?? '');
}

/** Abre la página, espera como una persona y envía el formulario. */
function captureSubmit(object $test, LandingPage $page, array $form, string $query = '', int $wait = 5): TestResponse
{
    $html = $test->get('/p/'.$page->slug.$query)->assertOk();
    $test->travel($wait)->seconds();
    $hidden = [];
    preg_match_all('/<input type="hidden" name="([a-z_]+)" value="([^"]*)">/', (string) $html->getContent(), $m, PREG_SET_ORDER);
    foreach ($m as $match) {
        $hidden[$match[1]] = html_entity_decode($match[2]);
    }

    return $test->post('/p/'.$page->slug, $form + $hidden);
}

/** Guarda los eventos que se publican (para revisar form.submitted y lead.created sin apagar los listeners). */
function captureEvents(): ArrayObject
{
    $events = new ArrayObject;
    Event::listen(EventIngested::class, function (EventIngested $e) use ($events): void {
        $events[] = $e;
    });

    return $events;
}

/** @param list<string> $deny */
function captureDenyModules(array $deny): void
{
    app()->instance(Entitlements::class, new class($deny) implements Entitlements
    {
        /** @param list<string> $deny */
        public function __construct(private array $deny) {}

        public function allows(Workspace $workspace, string $module): bool
        {
            return ! in_array($module, $this->deny, true);
        }

        public function limit(Workspace $workspace, string $key): ?int
        {
            return null;
        }

        public function planFor(Workspace $workspace): ?string
        {
            return 'tienda_inicio';
        }
    });
}
