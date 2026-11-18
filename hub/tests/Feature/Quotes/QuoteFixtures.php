<?php

use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Quotes\Support\QuoteCapture;
use App\Domain\Quotes\Support\QuoteEngine;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Str;

/*
 * Ayudas de las pruebas del cotizador (M18), con el prefijo `quote`.
 */

/** Pintura de interiores por m²: el ejemplo «por área» del constructor. */
function quoteAreaDefinition(array $overrides = []): array
{
    return array_replace([
        'base_price' => 50000, 'minimum' => 300000, 'range_pct' => 10, 'round_to' => 1000,
        'note' => 'Incluye materiales.', 'internal_notes' => 'Margen 35 %, no mostrar',
        'fields' => [
            ['key' => 'area', 'label' => 'Área a pintar', 'type' => 'number', 'unit' => 'm²', 'min' => 5, 'max' => 2000, 'unit_price' => 12000],
            ['key' => 'tipo', 'label' => 'Tipo de pintura', 'type' => 'select', 'options' => [
                ['value' => 'vinilo', 'label' => 'Vinilo', 'price' => 0, 'multiplier' => 1],
                ['value' => 'esmalte', 'label' => 'Esmalte', 'price' => 0, 'multiplier' => 1.25],
            ]],
            ['key' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'options' => [
                ['value' => 'techo', 'label' => 'Techo', 'price' => 80000],
                ['value' => 'resanes', 'label' => 'Resanes', 'price' => 120000],
            ]],
        ],
    ], $overrides);
}

function quoteCalculator(Workspace $ws, array $attrs = []): QuoteCalculator
{
    $kind = $attrs['kind'] ?? 'area';

    return app(CurrentWorkspace::class)->run($ws, fn () => QuoteCalculator::query()->create(array_replace([
        'workspace_id' => $ws->id, 'name' => 'Pintura de interiores', 'kind' => $kind, 'status' => 'active',
        'capture' => QuoteCapture::defaults(),
    ], $attrs, ['definition' => QuoteEngine::normalize($attrs['definition'] ?? quoteAreaDefinition(), $kind)])));
}

/** LeadIntake de prueba: guarda lo que recibe y devuelve un ld_… */
function quoteFakeLeads(): object
{
    $fake = new class implements LeadIntake
    {
        /** @var list<LeadIntakeInput> */
        public array $captured = [];

        public function capture(LeadIntakeInput $input): ?string
        {
            $this->captured[] = $input;

            return 'ld_'.strtoupper((string) Str::ulid());
        }
    };
    app()->instance(LeadIntake::class, $fake);

    return $fake;
}
