<?php

use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Flows\Library\FlowLibrary;
use App\Domain\Onboarding\Support\Verticals;
use App\Domain\Strategies\Blocks\FlowBlock;
use App\Domain\Strategies\StrategyRegistry;
use App\Domain\Strategies\Support\Requirements;
use App\Domain\Strategies\Support\StrategyPotential;
use App\Support\Tenancy\CurrentWorkspace;
use Tests\Feature\Strategies\Support\StrategyFixtures;

uses(StrategyFixtures::class);

/*
 * El registro declarativo de estrategias (docs/diseno/rediseno-v2.md §6): están todas las de la tabla, cada una con
 * sus textos completos, requisitos conocidos, módulos del plan que existen, un potencial calculable y bloques reales.
 */

it('tiene todas las estrategias de la tabla del §6 y las 21 a 35 del §11.4, por objetivo y con claves únicas', function () {
    $expected = [
        'recover_sales' => ['abandoned_cart', 'abandoned_checkout', 'payment_pending', 'cod_confirm', 'browse_abandonment'],
        'sell_again' => ['second_purchase', 'replenishment', 'winback', 'ai_reactivate', 'back_in_stock', 'periodic_service', 'services_reactivation', 'cross_sell'],
        'loyalty' => ['loyalty_points', 'birthday', 'referrals', 'first_purchase_anniversary', 'new_client_welcome'],
        'capture' => ['welcome_popup', 'whatsapp_button', 'product_quiz'],
        'reputation' => ['review_request', 'nps', 'google_reviews', 'post_service', 'delivery_check'],
        'attention' => ['ai_sales_agent', 'inbox_assignment', 'after_hours'],
        'ads' => ['ads_conversions', 'ads_audiences'],
        'services' => ['service_lead', 'lead_nurture', 'quote_followup', 'chat_qualification', 'appointment_reminders', 'appointment_confirmation', 'no_show_recovery',
            'event_registration', 'enrollment_followup', 'property_interest', 'lost_lead_revival', 'renewal', 'quote_calculator'],
    ];
    $byGoal = [];
    foreach (StrategyRegistry::all() as $definition) {
        $byGoal[$definition->goal][] = $definition->key;
    }
    expect($byGoal)->toBe($expected)
        ->and(StrategyRegistry::keys())->toHaveCount(count(array_unique(StrategyRegistry::keys())))
        ->and(array_keys($byGoal))->toBe(StrategyRegistry::GOALS)
        ->and(array_diff(StrategyRegistry::EXTENDED, StrategyRegistry::keys()))->toBe([])
        ->and(StrategyRegistry::EXTENDED)->toHaveCount(15);
});

it('la biblioteca ampliada queda «Próximamente» hasta que su flujo exista en FlowLibrary, y entonces se activa sola', function () {
    foreach (StrategyRegistry::EXTENDED as $key) {
        $definition = StrategyRegistry::find($key);
        $primary = $definition->primary();
        expect($primary)->toBeInstanceOf(FlowBlock::class)
            ->and($primary->libraryKey)->toBe($key, 'La clave de la estrategia es la del flujo de la biblioteca (§11.4)')
            // Disponible exactamente cuando el flujo está en la biblioteca: así se prende sola al integrarse.
            ->and(StrategyRegistry::available($definition))->toBe(is_array(FlowLibrary::find($key)['graph'] ?? null));
    }
    expect((new FlowBlock('abandoned_cart'))->available())->toBeTrue()
        ->and((new FlowBlock('no_existe_todavia'))->available())->toBeFalse();
});

it('cada estrategia tiene nombre, promesa y cómo funciona en 3 pasos, también en su versión de servicios', function () {
    foreach (StrategyRegistry::all() as $definition) {
        $text = __('strategies.items.'.$definition->key);
        expect($text)->toBeArray("Faltan los textos de {$definition->key}")
            ->and($text['name'] ?? '')->not->toBe('')
            ->and(mb_strlen($text['promise'] ?? ''))->toBeGreaterThan(20)
            ->and($text['steps'] ?? [])->toHaveCount(3);
        if (isset($text['services'])) {
            expect($text['services']['promise'] ?? '')->not->toBe('')
                ->and($text['services']['steps'] ?? [])->toHaveCount(3);
        }
        foreach (StrategyRegistry::GOALS as $goal) {
            expect(__('strategies.goals.'.$goal.'.name'))->not->toBe('strategies.goals.'.$goal.'.name');
        }
    }
});

it('los textos regionales cambian lo que se dice distinto en México y Venezuela', function () {
    expect(__('strategies.items.cod_confirm.name', [], 'es'))->toContain('contraentrega')
        ->and(__('strategies.items.cod_confirm.name', [], 'es_MX'))->toContain('pago contra entrega')
        ->and(__('strategies.items.cod_confirm.steps', [], 'es_VE')[1])->toBe(__('strategies.items.cod_confirm.steps', [], 'es')[1])
        ->and(__('strategies.items.payment_pending.promise', [], 'es_MX'))->toContain('OXXO')
        ->and(__('strategies.items.payment_pending.promise', [], 'es_VE'))->toContain('Pago Móvil');
});

it('requisitos, módulos, permisos y potenciales son conocidos; los flujos existen en la biblioteca', function () {
    $this->strategyWorld();
    $potentials = app(CurrentWorkspace::class)->run($this->ws, fn () => app(StrategyPotential::class)->forWorkspace($this->ws));
    $abilities = ['manageInbox', 'manageServices', 'manageWorkspace'];
    foreach (StrategyRegistry::all() as $definition) {
        expect(array_diff($definition->requires, Requirements::KEYS))->toBe([], $definition->key)
            ->and(array_diff($definition->modules, PlanCatalog::modules()))->toBe([], $definition->key)
            ->and(array_diff($definition->abilities, $abilities))->toBe([], $definition->key)
            ->and(in_array($definition->audience, ['store', 'services', 'both'], true))->toBeTrue()
            ->and($definition->blocks)->not->toBeEmpty();
        if ($definition->potential !== null) {
            expect(array_key_exists($definition->potential, $potentials))->toBeTrue("Potencial desconocido: {$definition->potential}");
        }
        foreach ($definition->blocks as $block) {
            if ($block instanceof FlowBlock && ! in_array($block->libraryKey, StrategyRegistry::EXTENDED, true)) {
                expect(FlowLibrary::find($block->libraryKey)['graph'] ?? null)->toBeArray("{$definition->key}: {$block->libraryKey}");
            }
        }
        if ($definition->verticals !== null) {
            expect(array_diff($definition->verticals, Verticals::keys()))->toBe([], $definition->key);
        }
        expect(array_diff(array_keys($definition->recommendedFor), Verticals::keys()))->toBe([], $definition->key);
        foreach ($definition->exclusive as $other) {
            expect(StrategyRegistry::find($other))->not->toBeNull();
        }
    }
    // Los requisitos tienen sus textos.
    foreach ([...Requirements::KEYS, 'module', 'wa_templates'] as $key) {
        expect(__("strategies.requirements.{$key}.label"))->not->toBe("strategies.requirements.{$key}.label")
            ->and(__("strategies.requirements.{$key}.missing"))->not->toBe("strategies.requirements.{$key}.missing");
    }
});

it('cada vertical tiene sus recomendadas, que le sirven, y las del §11.4 en el orden pedido', function () {
    foreach (Verticals::keys() as $vertical) {
        $ranked = StrategyRegistry::rankedFor($vertical);
        expect(count($ranked))->toBeGreaterThanOrEqual(3, "{$vertical} necesita al menos 3 recomendadas");
        foreach ($ranked as $key) {
            $definition = StrategyRegistry::find($key);
            expect(in_array(Verticals::businessType($vertical), $definition->businessTypes(), true))->toBeTrue("{$key} no sirve a {$vertical}")
                ->and($definition->verticals === null || in_array($vertical, $definition->verticals, true))->toBeTrue("{$key} no es de {$vertical}");
        }
    }
    expect(array_slice(StrategyRegistry::rankedFor('health'), 0, 3))->toBe(['appointment_confirmation', 'no_show_recovery', 'periodic_service'])
        ->and(array_slice(StrategyRegistry::rankedFor('professional'), 0, 3))->toBe(['service_lead', 'quote_followup', 'lead_nurture'])
        ->and(array_slice(StrategyRegistry::rankedFor('real_estate'), 0, 3))->toBe(['service_lead', 'property_interest', 'lost_lead_revival'])
        ->and(array_slice(StrategyRegistry::rankedFor('education'), 0, 3))->toBe(['event_registration', 'enrollment_followup', 'lead_nurture'])
        ->and(array_slice(StrategyRegistry::rankedFor('store'), 0, 4))->toBe(['abandoned_cart', 'cod_confirm', 'payment_pending', 'second_purchase']);
    // El flujo «Reactivar con IA» lo crean las estrategias, no la biblioteca de Flujos (los 35 de M09).
    expect(FlowLibrary::all())->toHaveCount(35)->and(FlowLibrary::find('ai_reactivate'))->not->toBeNull();
});
