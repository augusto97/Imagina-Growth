<?php

use App\Domain\Flows\Library\FlowLibrary;
use App\Domain\Onboarding\Support\Verticals;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Domain\Services\Verticals\ApplyVerticalDefaults;
use App\Domain\Services\Verticals\VerticalCatalog;
use App\Domain\Strategies\StrategyRegistry;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Perfiles de vertical (rediseno-v2.md §11.1, modulos.md M18 «Verticales»): el registro, ApplyVerticalDefaults
 * (idempotente, no pisa lo que el usuario cambió) y el endpoint de solo lectura.
 */

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner, 'Clínica Sonrisa');
});

function verticalPipeline($test): ?LeadPipeline
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => LeadPipeline::query()->where('is_default', true)->first());
}

it('cada vertical del asistente tiene un embudo válido, flujos y plantillas que existen en las bibliotecas', function () {
    // Las claves son las del asistente (config/igr-verticals.php), no un registro aparte.
    expect(VerticalCatalog::keys())->toBe(Verticals::keys())
        ->and(VerticalCatalog::keys())->toBe(['store', 'professional', 'health', 'education', 'real_estate', 'hospitality', 'automotive', 'b2b', 'other']);
    $flows = array_column([...FlowLibrary::all(), ...FlowLibrary::extras()], 'key');
    foreach (VerticalCatalog::keys() as $key) {
        $p = VerticalCatalog::find($key);
        expect($p)->not->toBeNull()->and($p['name'])->toBe(__("onboarding.verticals.{$key}.name"))->and($p['terms']['client'])->not->toBe('')
            ->and($p['business_type'])->toBe(Verticals::businessType($key))
            // Las recomendadas son las de Estrategias, en su orden.
            ->and($p['strategies'])->toBe(StrategyRegistry::rankedFor($key))->and($p['flows'])->not->toBeEmpty();
        expect(array_diff($p['flows'], $flows))->toBe([], "{$key}: flujos que no existen")
            ->and(array_diff($p['templates'], TemplateLibrary::keys()))->toBe([], "{$key}: plantillas que no existen");
        if ($key === 'store') {
            expect($p['pipeline'])->toBeNull()->and($p['business_type'])->toBe('store');

            continue;
        }
        $stages = $p['pipeline']['stages'];
        $kinds = array_count_values(array_column($stages, 'kind'));
        expect($stages[0]['key'])->toBe('new', $key)
            ->and($kinds['won'] ?? 0)->toBe(1, $key)->and($kinds['lost'] ?? 0)->toBe(1, $key)->and($kinds['open'] ?? 0)->toBeGreaterThanOrEqual(1)
            ->and(array_column(array_filter($stages, fn ($s) => $s['kind'] === 'won'), 'key'))->toBe(['won'])
            ->and(array_column(array_filter($stages, fn ($s) => $s['kind'] === 'lost'), 'key'))->toBe(['lost'])
            ->and(count($stages))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(12)
            ->and(count(array_unique(array_column($stages, 'key'))))->toBe(count($stages));
        foreach ($stages as $s) {
            expect(preg_match('/^[a-z][a-z0-9_]{0,31}$/', $s['key']))->toBe(1);
        }
    }
    // Los de la tabla §11.1.
    expect(array_column(VerticalCatalog::stages('health'), 'label'))->toBe(['Nuevo', 'Contactado', 'Cita agendada', 'Asistió', 'Paciente', 'No continuó'])
        ->and(array_column(VerticalCatalog::stages('b2b'), 'label'))->toBe(['Nuevo', 'Calificado', 'Reunión', 'Propuesta', 'Negociación', 'Ganado', 'Perdido'])
        ->and(VerticalCatalog::find('health')['terms']['client'])->toBe('paciente')
        ->and(VerticalCatalog::find('education')['terms']['client'])->toBe('estudiante');
});

it('el endpoint lista los perfiles y dice el vertical del espacio de trabajo (deducido de la industria si no lo eligió)', function () {
    $this->ws->forceFill(['industry' => 'salud_bienestar'])->save();
    $res = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/verticals")->assertOk();
    expect($res->json('data'))->toHaveCount(9)
        ->and($res->json('current'))->toMatchArray(['vertical' => 'health', 'business_type' => 'services', 'saved' => false])
        ->and(collect($res->json('data'))->firstWhere('key', 'health')['pipeline']['name'])->toBe('Pacientes');

    $this->ws->forceFill(['vertical' => 'real_estate'])->save();
    expect($this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/verticals")->json('current'))
        ->toMatchArray(['vertical' => 'real_estate', 'business_type' => 'services', 'saved' => true]);
});

it('elegir la vertical en Configuración o en el asistente deja sus valores por defecto', function () {
    $this->actingAs($this->owner)->patchJson("/api/w/{$this->ws->publicId()}", ['vertical' => 'health'])->assertOk();
    expect(verticalPipeline($this)->name)->toBe('Pacientes')
        ->and($this->ws->fresh()->industry)->toBe('salud_bienestar')
        ->and($this->ws->fresh()->setting('vertical_defaults.vertical'))->toBe('health');

    // En el asistente, a otra vertical: el embudo de fábrica cambia (nadie lo tocó).
    $this->actingAs($this->owner)->putJson("/api/w/{$this->ws->publicId()}/onboarding", ['vertical' => 'education'])->assertOk();
    expect(verticalPipeline($this)->name)->toBe('Inscripciones')
        ->and(app(CurrentWorkspace::class)->run($this->ws, fn () => LeadPipeline::query()->count()))->toBe(1)
        ->and($this->ws->fresh()->setting('vertical_defaults.vertical'))->toBe('education');
});

it('aplicar crea el embudo del vertical y es idempotente; cambiar de vertical cambia un embudo de fábrica', function () {
    $url = "/api/w/{$this->ws->publicId()}/verticals";
    $first = $this->actingAs($this->owner)->postJson("{$url}/health/apply")->assertOk();
    expect($first->json())->toMatchArray(['vertical' => 'health', 'pipeline' => 'created', 'industry' => 'set'])
        ->and(array_column(verticalPipeline($this)->stages, 'key'))->toBe(['new', 'contacted', 'appointment', 'attended', 'won', 'lost'])
        ->and(verticalPipeline($this)->name)->toBe('Pacientes')
        ->and($this->ws->fresh()->industry)->toBe('salud_bienestar')
        ->and($this->ws->fresh()->setting('vertical_defaults.vertical'))->toBe('health');

    expect($this->actingAs($this->owner)->postJson("{$url}/health/apply")->assertOk()->json())->toMatchArray(['pipeline' => 'unchanged', 'industry' => 'unchanged']);
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => LeadPipeline::query()->count()))->toBe(1);

    // Nadie lo tocó: elegir otro vertical lo cambia (la industria ya elegida se respeta).
    $other = $this->actingAs($this->owner)->postJson("{$url}/professional/apply")->assertOk();
    expect($other->json())->toMatchArray(['pipeline' => 'updated', 'industry' => 'kept'])
        ->and(array_column(verticalPipeline($this)->stages, 'key'))->toBe(['new', 'contacted', 'meeting', 'proposal', 'won', 'lost'])
        ->and(verticalPipeline($this)->name)->toBe('Clientes potenciales')
        ->and($this->ws->fresh()->industry)->toBe('salud_bienestar');
    expect(DB::table('audit_logs')->where('action', 'workspace.vertical_defaults_applied')->count())->toBe(2);

    // La tienda no tiene embudo: no lo toca.
    expect(app(ApplyVerticalDefaults::class)->handle($this->ws->fresh(), 'store'))->toMatchArray(['pipeline' => 'not_applicable', 'industry' => 'not_applicable']);
});

it('no pisa un embudo que el usuario personalizó ni deja leads en etapas que ya no existen', function () {
    $url = "/api/w/{$this->ws->publicId()}/verticals";
    // El simple de siempre (sin vertical), con un lead en «Calificado».
    $simple = app(CurrentWorkspace::class)->run($this->ws, fn () => Pipelines::default($this->ws->id));
    expect(array_column($simple->stages, 'key'))->toBe(['new', 'contacted', 'qualified', 'proposal', 'won', 'lost']);
    app(CurrentWorkspace::class)->run($this->ws, fn () => Lead::query()->create([
        'workspace_id' => $this->ws->id, 'pipeline_id' => $simple->id, 'contact_id' => (string) Str::ulid(), 'stage' => 'qualified', 'origin' => 'form',
    ]));
    expect($this->actingAs($this->owner)->postJson("{$url}/health/apply")->json('pipeline'))->toBe('kept_in_use')
        ->and(array_column(verticalPipeline($this)->stages, 'key'))->toContain('qualified');

    // Personalizado (otra etiqueta): queda como está.
    app(CurrentWorkspace::class)->run($this->ws, fn () => Lead::query()->update(['stage' => 'new']));
    $custom = $simple->stages;
    $custom[1]['label'] = 'Llamado';
    app(CurrentWorkspace::class)->run($this->ws, fn () => $simple->forceFill(['stages' => $custom])->save());
    expect($this->actingAs($this->owner)->postJson("{$url}/health/apply")->json('pipeline'))->toBe('kept_custom')
        ->and(verticalPipeline($this)->stages[1]['label'])->toBe('Llamado');
});

it('el primer lead de un espacio de trabajo con vertical nace en el embudo del vertical', function () {
    $this->ws->forceFill(['vertical' => 'education'])->save();
    $pipeline = app(CurrentWorkspace::class)->run($this->ws, fn () => Pipelines::default($this->ws->id));
    expect($pipeline->name)->toBe('Inscripciones')
        ->and(array_column($pipeline->stages, 'label'))->toBe(['Interesado', 'Información enviada', 'Visita o clase de prueba', 'Inscrito', 'No se inscribió'])
        ->and(Pipelines::firstOpen($pipeline))->toBe('new');
});

it('permisos y aislamiento: un lector ve pero no aplica, y otro espacio de trabajo no existe', function () {
    $url = "/api/w/{$this->ws->publicId()}/verticals";
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson($url)->assertOk();
    $this->actingAs($viewer)->postJson("{$url}/health/apply")->assertForbidden();
    $this->actingAs($this->owner)->postJson("{$url}/no_existe/apply")->assertNotFound();

    $stranger = $this->userWith2fa();
    $theirs = $this->workspaceFor($stranger, 'Otro negocio');
    $this->actingAs($stranger)->getJson($url)->assertNotFound();
    $this->actingAs($stranger)->postJson("{$url}/health/apply")->assertNotFound();
    $this->actingAs($this->owner)->postJson("/api/w/{$theirs->publicId()}/verticals/health/apply")->assertNotFound();
    expect(verticalPipeline($this))->toBeNull();
});
