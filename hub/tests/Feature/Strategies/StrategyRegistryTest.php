<?php

use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Flows\Library\FlowLibrary;
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

it('tiene todas las estrategias de la tabla del §6, por objetivo y con claves únicas', function () {
    $expected = [
        'recover_sales' => ['abandoned_cart', 'abandoned_checkout', 'payment_pending', 'cod_confirm', 'browse_abandonment'],
        'sell_again' => ['second_purchase', 'replenishment', 'winback', 'ai_reactivate', 'back_in_stock'],
        'loyalty' => ['loyalty_points', 'birthday', 'referrals'],
        'capture' => ['welcome_popup', 'whatsapp_button', 'product_quiz'],
        'reputation' => ['review_request', 'nps', 'google_reviews'],
        'attention' => ['ai_sales_agent', 'inbox_assignment', 'after_hours'],
        'ads' => ['ads_conversions', 'ads_audiences'],
        'services' => ['lead_response', 'chat_qualification', 'appointment_reminders', 'renewal', 'quote_calculator'],
    ];
    $byGoal = [];
    foreach (StrategyRegistry::all() as $definition) {
        $byGoal[$definition->goal][] = $definition->key;
    }
    expect($byGoal)->toBe($expected)
        ->and(StrategyRegistry::keys())->toHaveCount(count(array_unique(StrategyRegistry::keys())))
        ->and(array_keys($byGoal))->toBe(StrategyRegistry::GOALS);
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
            if ($block instanceof FlowBlock) {
                expect(FlowLibrary::find($block->libraryKey)['graph'] ?? null)->toBeArray("{$definition->key}: {$block->libraryKey}");
            }
        }
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

it('las recomendadas del asistente existen y sirven a su tipo de negocio', function () {
    foreach (StrategyRegistry::RECOMMENDED as $type => $keys) {
        foreach ($keys as $key) {
            $definition = StrategyRegistry::find($key);
            expect($definition)->not->toBeNull()
                ->and($type === 'both' || in_array($definition->audience, [$type, 'both'], true))->toBeTrue("{$key} no sirve a {$type}");
        }
    }
    // El flujo «Reactivar con IA» lo crean las estrategias, no la biblioteca de Flujos (que sigue con los 20 de M09).
    expect(FlowLibrary::all())->toHaveCount(20)->and(FlowLibrary::find('ai_reactivate'))->not->toBeNull();
});
