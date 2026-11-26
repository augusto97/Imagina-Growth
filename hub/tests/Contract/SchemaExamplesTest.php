<?php

use App\Ingest\Schema\SchemaRegistry;

/*
 * Pruebas de contrato (hub/CLAUDE.md §17): cada esquema acepta sus ejemplos
 * válidos y rechaza los inválidos. El connector y el tracker validan sus
 * eventos reales contra estos mismos archivos.
 */

function schemaFiles(): array
{
    $files = glob(dirname(__DIR__, 2).'/resources/schemas/events/*.json');

    return array_values(array_filter($files, fn ($f) => ! str_starts_with(basename($f), '_')));
}

it('cada esquema de evento acepta sus examples y rechaza sus x-invalid-examples', function (string $file) {
    $registry = app(SchemaRegistry::class);
    $schema = json_decode(file_get_contents($file));
    $event = basename($file, '.json');
    $name = $event === 'custom' ? 'custom.prueba' : $event;

    expect($schema->examples ?? [])->not->toBeEmpty("{$event} no tiene ejemplos válidos");
    expect($schema->{'x-invalid-examples'} ?? [])->not->toBeEmpty("{$event} no tiene ejemplos inválidos");

    foreach ($schema->examples as $i => $example) {
        expect($registry->validateProperties($name, $example))->toBeNull("{$event} ejemplo válido #{$i}");
    }
    foreach ($schema->{'x-invalid-examples'} as $i => $example) {
        expect($registry->validateProperties($name, $example))->not->toBeNull("{$event} ejemplo inválido #{$i} pasó");
    }
})->with(fn () => array_combine(array_map('basename', schemaFiles()), schemaFiles()));

it('el ejemplo completo de sobre es válido', function () {
    $example = json_decode(file_get_contents(resource_path('schemas/events/_envelope.example.json')));
    $registry = app(SchemaRegistry::class);
    expect($registry->validateEnvelope($example))->toBeNull()
        ->and($registry->validateProperties($example->event, $example->properties))->toBeNull();
});

it('un evento de navegador con correo o teléfono no pasa el sobre (eventos.md §2)', function () {
    $example = json_decode(file_get_contents(resource_path('schemas/events/_envelope.example.json')));
    $example->source->type = 'browser';
    expect(app(SchemaRegistry::class)->validateEnvelope($example))->not->toBeNull();
    $example->identity->email = null;
    $example->identity->phone = null;
    expect(app(SchemaRegistry::class)->validateEnvelope($example))->toBeNull();
});

it('el sobre rechaza campos desconocidos, versión distinta e IDs mal formados', function (string $path, mixed $value) {
    $example = json_decode(file_get_contents(resource_path('schemas/events/_envelope.example.json')), true);
    data_set($example, $path, $value);
    expect(app(SchemaRegistry::class)->validateEnvelope(json_decode(json_encode($example))))->not->toBeNull();
})->with([
    ['schema_version', 9],
    ['event_id', 'no-es-uuid'],
    ['source.site_id', 'st_123'],
    ['source.type', 'otro'],
    ['identity.cedula', '123'],
    ['extra', true],
    ['occurred_at', 'ayer'],
]);

it('conoce el catálogo de eventos de eventos.md §4', function () {
    $registry = app(SchemaRegistry::class);
    expect($registry->isKnownEvent('order.paid'))->toBeTrue()
        ->and($registry->isKnownEvent('catalog.category_deleted'))->toBeTrue()
        ->and($registry->isKnownEvent('custom.cotizacion_vista'))->toBeTrue()
        ->and($registry->isKnownEvent('order.inventado'))->toBeFalse()
        ->and(count($registry->knownEvents()))->toBeGreaterThanOrEqual(42);
});
