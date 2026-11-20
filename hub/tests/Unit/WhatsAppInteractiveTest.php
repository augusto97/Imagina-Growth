<?php

use App\Domain\Bookings\Chat\ChatBookingIds;
use App\Domain\Messaging\Support\WhatsAppInteractive;
use Carbon\CarbonImmutable;

/*
 * Botones y listas de WhatsApp con los límites de la Cloud API (verificados el 20 nov 2026):
 * lista con 10 filas en total, títulos de 24 y descripciones de 72; botones hasta 3 con
 * títulos de 20 y únicos. Los IDs no se recortan.
 */

it('la lista respeta las 10 filas en total y recorta títulos, descripciones y el botón', function () {
    $rows = array_map(fn ($i) => ['id' => "row{$i}", 'title' => str_repeat('x', 30), 'description' => str_repeat('y', 90)], range(1, 8));
    $list = WhatsAppInteractive::list(str_repeat('b', 5000), 'Ver todos los horarios libres', [
        ['title' => 'una sección con un título larguísimo', 'rows' => $rows],
        ['title' => 'otra', 'rows' => $rows],
    ]);
    $all = collect($list['action']['sections'])->flatMap(fn ($s) => $s['rows']);
    expect($all)->toHaveCount(10)
        ->and($all->every(fn ($r) => mb_strlen($r['title']) <= 24 && mb_strlen($r['description']) <= 72))->toBeTrue()
        ->and(mb_strlen($list['action']['button']))->toBeLessThanOrEqual(20)
        ->and(mb_strlen($list['action']['sections'][0]['title']))->toBeLessThanOrEqual(24)
        ->and(mb_strlen($list['body']['text']))->toBeLessThanOrEqual(4096)
        ->and(WhatsAppInteractive::valid($list))->toBeTrue();
});

it('los botones son hasta 3, con títulos únicos de hasta 20; el texto lleva las opciones', function () {
    $b = WhatsAppInteractive::buttons('¿Confirmas?', [['id' => 'a', 'title' => 'Sí, cancelar mi cita de hoy'], ['id' => 'b', 'title' => 'No'], ['id' => 'c', 'title' => 'Tal vez'], ['id' => 'd', 'title' => 'Otro']]);
    expect($b['action']['buttons'])->toHaveCount(3)->and(mb_strlen($b['action']['buttons'][0]['reply']['title']))->toBeLessThanOrEqual(20)
        ->and(WhatsAppInteractive::asText(WhatsAppInteractive::withBody($b, 'Elige:')))->toStartWith('Elige:')->toContain('• No');
    expect(fn () => WhatsAppInteractive::buttons('x', [['id' => 'a', 'title' => 'Igual'], ['id' => 'b', 'title' => 'Igual']]))->toThrow(InvalidArgumentException::class);
    expect(fn () => WhatsAppInteractive::buttons('x', [['id' => str_repeat('z', 300), 'title' => 'Ok']]))->toThrow(InvalidArgumentException::class);
});

it('los IDs de la agenda van y vuelven; uno mal formado no se acepta', function () {
    $start = CarbonImmutable::parse('2026-11-23 10:00', 'America/Bogota');
    $id = ChatBookingIds::book('01JDZZZZZZZZZZZZZZZZZZZZZZ', $start);
    expect(mb_strlen($id))->toBeLessThanOrEqual(WhatsAppInteractive::ROW_ID)
        ->and(ChatBookingIds::parse($id))->toMatchArray(['action' => 'book', 'id' => '01JDZZZZZZZZZZZZZZZZZZZZZZ'])
        ->and(ChatBookingIds::parse($id)['start']->equalTo($start))->toBeTrue()
        ->and(ChatBookingIds::parse(ChatBookingIds::cancel('01JDZZZZZZZZZZZZZZZZZZZZZZ'))['action'])->toBe('cancel')
        ->and(ChatBookingIds::parse('bk1:c:01JDZZZZZZZZZZZZZZZZZZZZZZ:1764000000'))->toBeNull()
        ->and(ChatBookingIds::parse('bk1:b:no-es-un-id:1764000000'))->toBeNull()
        ->and(ChatBookingIds::parse('appt_confirm'))->toBeNull();
});
