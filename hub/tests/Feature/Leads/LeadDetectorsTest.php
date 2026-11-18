<?php

require_once __DIR__.'/LeadsHelpers.php';
require_once __DIR__.'/../Insights/InsightsHelpers.php';

use App\Domain\Intelligence\Insights\DetectorCatalog;
use Carbon\CarbonImmutable;

beforeEach(function () {
    leadsSetUp($this);
    $this->now = CarbonImmutable::now($this->ws->timezone);
});

function leadsInsight($test, string $key): ?object
{
    insightsRun($test, [$key]);

    return insightsOf($test)->where('detector_key', $key)->first();
}

it('los tres detectores de servicios están activos en el catálogo', function () {
    foreach (['leads_unanswered', 'form_low_conversion', 'lead_source_close'] as $key) {
        expect(DetectorCatalog::find($key)['class'])->not->toBeNull();
    }
});

it('#21 avisa de leads que esperaron más de 15 minutos, con el dinero en juego', function () {
    foreach (range(1, 4) as $i) {
        leadsRow($this, ['created_at' => now()->subDays(2)->subHours($i)]);
    }
    leadsRow($this, ['created_at' => now()->subDays(1), 'first_response_at' => now()->subDays(1)->addMinutes(5)]);
    // Historia para la tasa de cierre: 2 ganados de 4 con valor 2.000.000.
    foreach (range(1, 2) as $i) {
        leadsRow($this, ['created_at' => now()->subDays(60), 'stage' => 'won', 'won_at' => now()->subDays(40), 'value_ws' => '2000000', 'first_response_at' => now()->subDays(60)]);
    }

    $insight = leadsInsight($this, 'leads_unanswered');
    expect($insight)->not->toBeNull()
        ->and($insight->facts)->toMatchArray(['leads_7d' => 5, 'slow_over_15m' => 4, 'waiting_now' => 4])
        ->and((float) $insight->estimated_impact_ws)->toBeGreaterThan(0.0)
        ->and($insight->severity)->toBe('warning');
});

it('#21 no dice nada si todos los leads se respondieron a tiempo', function () {
    foreach (range(1, 4) as $i) {
        leadsRow($this, ['created_at' => now()->subDays(2), 'first_response_at' => now()->subDays(2)->addMinutes(3)]);
    }
    expect(leadsInsight($this, 'leads_unanswered'))->toBeNull();
});

it('#22 encuentra el formulario que convierte menos de la mitad del promedio', function () {
    leadsChMany($this, 'page.viewed', '/contacto/', 1000);
    leadsChMany($this, 'form.submitted', '/contacto/', 5);
    leadsChMany($this, 'page.viewed', '/cotiza/', 300);
    leadsChMany($this, 'form.submitted', '/cotiza/', 30);

    $insight = leadsInsight($this, 'form_low_conversion');
    expect($insight)->not->toBeNull()
        ->and($insight->facts)->toMatchArray(['path' => '/contacto/', 'views' => 1000, 'forms' => 5]);
});

it('#22 no dice nada si los formularios convierten parecido', function () {
    leadsChMany($this, 'page.viewed', '/contacto/', 400);
    leadsChMany($this, 'form.submitted', '/contacto/', 20);
    leadsChMany($this, 'page.viewed', '/cotiza/', 300);
    leadsChMany($this, 'form.submitted', '/cotiza/', 18);
    expect(leadsInsight($this, 'form_low_conversion'))->toBeNull();
});

it('#23 compara la fuente con más leads con la que más cierra', function () {
    foreach (range(1, 20) as $i) {
        leadsRow($this, ['source' => ['channel' => 'paid_meta'], 'created_at' => now()->subDays(20)] + ($i === 1 ? ['won_at' => now()->subDays(5), 'value_ws' => '1500000', 'stage' => 'won'] : []));
    }
    foreach (range(1, 8) as $i) {
        leadsRow($this, ['source' => ['channel' => 'organic_search'], 'created_at' => now()->subDays(20)] + ($i <= 4 ? ['won_at' => now()->subDays(5), 'value_ws' => '1500000', 'stage' => 'won'] : []));
    }
    $insight = leadsInsight($this, 'lead_source_close');
    expect($insight)->not->toBeNull()
        ->and($insight->facts)->toMatchArray(['volume_source' => 'paid_meta', 'volume_leads' => 20, 'volume_won' => 1, 'best_source' => 'organic_search', 'best_won' => 4])
        ->and($insight->title)->toContain('anuncios de Meta');
});

it('#23 no dice nada si la fuente con más leads es también la que más cierra', function () {
    foreach (range(1, 20) as $i) {
        leadsRow($this, ['source' => ['channel' => 'paid_meta'], 'created_at' => now()->subDays(20)] + ($i <= 8 ? ['won_at' => now()->subDays(5), 'stage' => 'won'] : []));
    }
    foreach (range(1, 8) as $i) {
        leadsRow($this, ['source' => ['channel' => 'organic_search'], 'created_at' => now()->subDays(20)] + ($i <= 2 ? ['won_at' => now()->subDays(5), 'stage' => 'won'] : []));
    }
    expect(leadsInsight($this, 'lead_source_close'))->toBeNull();
});
