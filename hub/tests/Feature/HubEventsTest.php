<?php

use App\Ingest\Events\EventIngested;
use App\Ingest\HubEvents;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->ws = $this->workspaceFor($this->userWith2fa());
});

it('emite eventos del hub validados, en ClickHouse y como evento de dominio', function () {
    Event::fake([EventIngested::class]);
    $id = app(HubEvents::class)->emit($this->ws, 'segment.entered', ['segment_id' => 'sg_1', 'segment_name' => 'Campeones', 'is_system' => true], 'CONTACT1');

    $rows = $this->chRows($this->ws, 'SELECT event, contact_id, source_type, toString(event_id) AS id FROM events WHERE workspace_id = {workspace_id:UInt32}');
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['event' => 'segment.entered', 'contact_id' => 'CONTACT1', 'source_type' => 'hub', 'id' => $id]);
    Event::assertDispatched(EventIngested::class, fn (EventIngested $e) => $e->event === 'segment.entered' && $e->contactId === 'CONTACT1');
});

it('rechaza propiedades inválidas y eventos que no son del hub', function () {
    expect(fn () => app(HubEvents::class)->emit($this->ws, 'segment.entered', ['segment_name' => 'x'], null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(HubEvents::class)->emit($this->ws, 'order.paid', [], null))->toThrow(InvalidArgumentException::class);
});
