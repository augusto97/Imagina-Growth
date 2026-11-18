<?php

use App\Domain\Segments\Definition\DefinitionValidator;
use App\Domain\Segments\Definition\InvalidDefinition;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->ws = $this->workspaceFor($this->userWith2fa());
    app(CurrentWorkspace::class)->set($this->ws);
});

function segErrors(array $definition): array
{
    try {
        app(DefinitionValidator::class)->validate($definition);
    } catch (InvalidDefinition $e) {
        return $e->errors;
    }

    return [];
}

it('acepta el AST de hub/CLAUDE.md §6 y lo normaliza', function () {
    $ast = app(DefinitionValidator::class)->validate([
        'op' => 'and',
        'conditions' => [
            ['type' => 'attribute', 'field' => 'city', 'operator' => 'in', 'value' => ['Manizales', 'Pereira']],
            ['type' => 'purchase', 'scope' => 'category', 'ids' => ['12'], 'operator' => 'count_gte', 'value' => 2, 'window' => ['kind' => 'last_days', 'days' => 365]],
            ['op' => 'or', 'conditions' => [
                ['type' => 'event', 'event' => 'product.viewed', 'filters' => [['prop' => 'product_id', 'operator' => 'eq', 'value' => '88']], 'operator' => 'count_gte', 'value' => 2, 'window' => ['kind' => 'last_days', 'days' => 7]],
                ['type' => 'prediction', 'field' => 'churn_band', 'operator' => 'eq', 'value' => 'high'],
            ]],
            ['type' => 'consent', 'channel' => 'whatsapp', 'purpose' => 'marketing', 'status' => 'granted'],
        ],
    ]);
    expect($ast['conditions'][2]['conditions'][0])->toMatchArray(['measure' => 'events', 'operator' => 'count_gte', 'value' => 2]);
});

it('explica en español qué está mal y dónde', function (array $definition, string $path, string $fragment) {
    $errors = segErrors($definition);
    expect($errors)->toHaveKey($path)->and($errors[$path])->toContain($fragment);
})->with([
    'sin condiciones' => [['op' => 'and', 'conditions' => []], 'conditions', 'al menos una condición'],
    'operador inválido' => [['op' => 'and', 'conditions' => [['type' => 'attribute', 'field' => 'city', 'operator' => 'gt', 'value' => 3]]], 'conditions.0.operator', 'Operador inválido'],
    'atributo desconocido' => [['op' => 'and', 'conditions' => [['type' => 'attribute', 'field' => 'cedula', 'operator' => 'eq', 'value' => '1']]], 'conditions.0.field', 'Atributo desconocido'],
    'evento inexistente' => [['op' => 'and', 'conditions' => [['type' => 'event', 'event' => 'order.inventado', 'operator' => 'ever']]], 'conditions.0.event', 'no existe'],
    'propiedad con inyección' => [['op' => 'and', 'conditions' => [['type' => 'event', 'event' => 'page.viewed', 'filters' => [['prop' => "x') OR 1=1 --", 'value' => 'a']], 'operator' => 'ever']]], 'conditions.0.filters.0.prop', 'Propiedad inválida'],
    'dato de citas desconocido' => [['op' => 'and', 'conditions' => [['type' => 'service', 'field' => 'inventado', 'operator' => 'eq']]], 'conditions.0.field', 'Dato de citas desconocido'],
    'valor fuera de la lista' => [['op' => 'and', 'conditions' => [['type' => 'prediction', 'field' => 'rfm_segment', 'operator' => 'eq', 'value' => 'vip']]], 'conditions.0.value', 'no es válido'],
    'ventana inválida' => [['op' => 'and', 'conditions' => [['type' => 'purchase', 'scope' => 'any', 'operator' => 'ever', 'window' => ['kind' => 'last_days', 'days' => 0]]]], 'conditions.0.window.days', 'entre 1 y 3.650'],
    'categorías vacías' => [['op' => 'and', 'conditions' => [['type' => 'purchase', 'scope' => 'category', 'ids' => [], 'operator' => 'ever']]], 'conditions.0.ids', 'al menos una opción'],
]);

it('no deja usar atributos personalizados sensibles (cumplimiento.md §2)', function () {
    DB::table('custom_attribute_defs')->insert([
        ['id' => strtoupper((string) str()->ulid()), 'workspace_id' => $this->ws->id, 'key' => 'mascota', 'label' => 'Mascota', 'type' => 'text', 'is_sensitive' => false, 'created_at' => now(), 'updated_at' => now()],
        ['id' => strtoupper((string) str()->ulid()), 'workspace_id' => $this->ws->id, 'key' => 'salud', 'label' => 'Salud', 'type' => 'text', 'is_sensitive' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    expect(segErrors(['op' => 'and', 'conditions' => [['type' => 'attribute', 'field' => 'custom.mascota', 'operator' => 'eq', 'value' => 'perro']]]))->toBe([])
        ->and(segErrors(['op' => 'and', 'conditions' => [['type' => 'attribute', 'field' => 'custom.salud', 'operator' => 'eq', 'value' => 'x']]])['conditions.0.field'])->toContain('sensible');
});

it('limita la profundidad y la cantidad de condiciones', function () {
    $deep = ['type' => 'attribute', 'field' => 'city', 'operator' => 'is_set'];
    for ($i = 0; $i < 5; $i++) {
        $deep = ['op' => 'or', 'conditions' => [$deep]];
    }
    expect(segErrors(['op' => 'and', 'conditions' => [$deep]]))->not->toBe([]);
    $many = array_fill(0, 41, ['type' => 'attribute', 'field' => 'city', 'operator' => 'is_set']);
    expect(segErrors(['op' => 'and', 'conditions' => $many]))->toHaveKey('');
});
