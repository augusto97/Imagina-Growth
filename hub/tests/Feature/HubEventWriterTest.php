<?php

use App\Analytics\Ch;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\TrackedLink;
use App\Ingest\HubEvents;
use App\Ingest\HubEventWriter;
use App\Ingest\Jobs\WriteHubEventRows;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Messaging\Support\MessagingFixtures;

/*
 * R4: los eventos del hub no esperan a ClickHouse dentro de una petición web y nunca se pierden si ClickHouse
 * falla: van a la cola (`WriteHubEventRows`, cifrado) y se escriben después.
 */
uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    Http::fake(['graph.test/*' => Http::response(['messages' => [['id' => 'wamid.W1']]])]);
});

function hubSegmentEvents(int $n): array
{
    return array_map(fn (int $i) => ['event' => 'segment.entered', 'properties' => ['segment_id' => 'sg_'.$i, 'segment_name' => 'Campeones', 'is_system' => true], 'contact_id' => 'C'.$i], range(1, $n));
}

function clickHouseDown(): void
{
    config(['igr.clickhouse.url' => 'http://127.0.0.1:1']);
    app()->forgetInstance(Ch::class);
}

it('dentro de una petición web los eventos van a la cola en lotes y la cola los escribe en ClickHouse', function () {
    config(['igr.hub_events.write' => 'queue']);
    Queue::fake();
    app(HubEvents::class)->emitMany($this->ws, hubSegmentEvents(1201));

    Queue::assertPushedOn('projections', WriteHubEventRows::class);
    Queue::assertPushed(WriteHubEventRows::class, 3);
    expect((int) $this->chRows($this->ws, 'SELECT count() AS n FROM events WHERE workspace_id = {workspace_id:UInt32}')[0]['n'])->toBe(0)
        ->and(new WriteHubEventRows([]))->toBeInstanceOf(ShouldBeEncrypted::class);

    // El trabajo los escribe (y un reintento no duplica: la tabla reemplaza por event_id).
    foreach (Queue::pushed(WriteHubEventRows::class) as $job) {
        $job->handle(app(Ch::class));
    }
    Queue::pushed(WriteHubEventRows::class)->first()->handle(app(Ch::class));
    expect((int) $this->chRows($this->ws, 'SELECT count() AS n FROM events FINAL WHERE workspace_id = {workspace_id:UInt32}')[0]['n'])->toBe(1201);
});

it('en consola escribe en el momento y, si ClickHouse no responde, deja los eventos en la cola sin fallar', function () {
    expect(app(HubEventWriter::class)->deferred())->toBeFalse();
    Queue::fake();
    clickHouseDown();
    $id = app(HubEvents::class)->emit($this->ws, 'segment.entered', ['segment_id' => 'sg_1', 'segment_name' => 'Campeones', 'is_system' => true], 'C1');

    expect($id)->toBeString();
    Queue::assertPushed(WriteHubEventRows::class, fn (WriteHubEventRows $job) => count($job->rows) === 1 && $job->rows[0]['event_id'] === $id);
});

it('un clic en un enlace rastreado redirige aunque ClickHouse esté caído, y el clic se guarda después', function () {
    $result = app(CurrentWorkspace::class)->run($this->ws, fn () => app(MessageDispatcher::class)->dispatch(new OutboundMessage(
        $this->ws->id, $this->contact->id, Channel::WhatsApp, MessageKind::Marketing, 'flow', templateId: 'tpl_wa',
    )));
    $link = TrackedLink::withoutWorkspaceScope()->where('message_id', $result->messageId)->firstOrFail();
    Queue::fake();
    clickHouseDown();

    $this->get('/c/'.$link->code, ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14)'])->assertStatus(302);
    Queue::assertPushed(WriteHubEventRows::class, fn (WriteHubEventRows $job) => collect($job->rows)->contains('event', 'message.clicked'));
});
