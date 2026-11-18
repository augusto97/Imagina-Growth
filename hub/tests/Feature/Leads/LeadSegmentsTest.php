<?php

require_once __DIR__.'/LeadsHelpers.php';

use App\Domain\Segments\Support\DependencyIndex;

beforeEach(function () {
    leadsSetUp($this);
    $this->base = "/api/w/{$this->ws->publicId()}/segments";
});

function leadsPreview($test, array $condition)
{
    return $test->actingAs($test->owner)->postJson($test->base.'/preview', ['definition' => ['op' => 'and', 'conditions' => [$condition]]]);
}

it('segmenta por leads: abierto, etapa, temperatura, puntaje, servicio, fuente, valor y fechas', function () {
    $hot = leadsRow($this, ['temperature' => 'hot', 'score' => 75, 'service' => 'Asesoría laboral', 'source' => ['channel' => 'paid_meta']]);
    $won = leadsRow($this, ['stage' => 'won', 'won_at' => now()->subDays(3), 'value_ws' => '3000000', 'service' => 'Pensión']);
    leadsContact($this, ['email' => 'nunca@empresa.co']); // nunca fue lead

    $count = fn (array $c) => leadsPreview($this, ['type' => 'lead'] + $c)->assertOk()->json('count');
    expect($count(['field' => 'has_open_lead', 'operator' => 'is_true']))->toBe(1)
        ->and($count(['field' => 'has_open_lead', 'operator' => 'is_false']))->toBe(2)
        ->and($count(['field' => 'has_lead', 'operator' => 'is_true']))->toBe(2)
        ->and($count(['field' => 'temperature', 'operator' => 'in', 'value' => ['hot']]))->toBe(1)
        ->and($count(['field' => 'score', 'operator' => 'gte', 'value' => 70]))->toBe(1)
        ->and($count(['field' => 'service', 'operator' => 'contains', 'value' => 'laboral']))->toBe(1)
        ->and($count(['field' => 'source', 'operator' => 'eq', 'value' => 'paid_meta']))->toBe(1)
        ->and($count(['field' => 'stage', 'operator' => 'eq', 'value' => 'won']))->toBe(1)
        ->and($count(['field' => 'value', 'operator' => 'gte', 'value' => 1000000]))->toBe(1)
        ->and($count(['field' => 'won_at', 'operator' => 'within_last_days', 'value' => 7]))->toBe(1);
    expect($hot->contact_id)->not->toBe($won->contact_id);
});

it('valida los campos y operadores de leads y la agenda sigue bloqueada', function () {
    leadsPreview($this, ['type' => 'lead', 'field' => 'inventado', 'operator' => 'eq', 'value' => 1])->assertUnprocessable();
    leadsPreview($this, ['type' => 'lead', 'field' => 'temperature', 'operator' => 'in', 'value' => ['tibiecito']])->assertUnprocessable();
    leadsPreview($this, ['type' => 'lead', 'field' => 'score', 'operator' => 'contains', 'value' => 'x'])->assertUnprocessable();
    leadsPreview($this, ['type' => 'service'])->assertUnprocessable();
    $this->actingAs($this->owner)->getJson($this->base.'/options')->assertOk()
        ->assertJsonPath('lead_fields.0.key', 'has_open_lead');
});

it('los eventos lead.* recalculan en tiempo real los segmentos que usan leads', function () {
    $index = [['id' => 'x', 'events' => [], 'kinds' => ['lead']]];
    expect(DependencyIndex::isRelevant($index, 'lead.stage_changed'))->toBeTrue()
        ->and(DependencyIndex::isRelevant([['id' => 'y', 'events' => [], 'kinds' => ['attribute']]], 'lead.won'))->toBeFalse();
});
