<?php

use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/QuoteFixtures.php';

/*
 * Constructor del cotizador en el panel (M18): `manageServices` crea y cambia,
 * `useLeads` ve y prueba; las cotizaciones con datos enmascarados según el rol;
 * aislamiento entre espacios de trabajo.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site] = $this->connectedSite($this->ws);
    quoteFakeLeads();
    $this->base = "/api/w/{$this->ws->publicId()}/quotes";
});

it('quien administra crea, publica y prueba una calculadora; marketing solo la ve', function () {
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);

    $c = $this->actingAs($this->owner)->postJson("{$this->base}/calculators", [
        'name' => 'Pintura de interiores', 'kind' => 'area', 'definition' => quoteAreaDefinition(), 'site_id' => $this->site->publicId(),
    ])->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('capture.required', true)
        ->assertJsonPath('site_id', $this->site->publicId())->assertJsonPath('definition.fields.0.unit_price', 12000)->json();
    expect($c['shortcode'])->toBe('[igr_quote id="'.$c['id'].'"]');

    $this->actingAs($this->owner)->patchJson("{$this->base}/calculators/{$c['id']}", ['status' => 'active', 'capture' => ['require' => 'phone', 'whatsapp_number' => '+57 300']])
        ->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('capture.require', 'phone')->assertJsonPath('capture.whatsapp_number', null);
    $this->actingAs($this->owner)->patchJson("{$this->base}/calculators/{$c['id']}", ['definition' => ['fields' => []]])
        ->assertUnprocessable()->assertJsonValidationErrors('definition.fields');
    $this->actingAs($this->owner)->postJson("{$this->base}/calculators", ['name' => 'X', 'kind' => 'otro', 'definition' => []])->assertUnprocessable();

    $this->actingAs($marketing)->postJson("{$this->base}/calculators/{$c['id']}/preview", ['answers' => ['area' => 40, 'tipo' => 'esmalte', 'extras' => ['techo']]])
        ->assertOk()->assertJsonPath('mid', '763000.00');
    // Prueba con una definición sin guardar (el constructor en vivo).
    $this->actingAs($marketing)->postJson("{$this->base}/calculators/{$c['id']}/preview", ['answers' => ['area' => 10, 'tipo' => 'vinilo'], 'definition' => quoteAreaDefinition(['minimum' => 0, 'range_pct' => 0, 'base_price' => 0])])
        ->assertOk()->assertJsonPath('mid', '120000.00');
    $this->actingAs($marketing)->postJson("{$this->base}/calculators/{$c['id']}/preview", ['answers' => ['area' => 1]])->assertUnprocessable();
    $this->actingAs($marketing)->getJson("{$this->base}/calculators")->assertOk()->assertJsonPath('can_manage', false)->assertJsonCount(1, 'data');
    $this->actingAs($marketing)->patchJson("{$this->base}/calculators/{$c['id']}", ['status' => 'paused'])->assertForbidden();
    $this->actingAs($viewer)->getJson("{$this->base}/calculators")->assertForbidden();

    expect(DB::table('audit_logs')->where('workspace_id', $this->ws->id)->pluck('action')->all())->toContain('quote_calculator.created', 'quote_calculator.updated');
});

it('lista las cotizaciones con estadísticas y datos enmascarados para quien no exporta', function () {
    $calc = quoteCalculator($this->ws);
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $this->call('POST', "/p/{$this->site->public_key}/quotes/{$calc->publicId()}/estimate", [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode([
        'answers' => ['area' => 40, 'tipo' => 'vinilo'], 'fields' => ['first_name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '3001234567'],
    ]))->assertOk();

    $this->actingAs($this->owner)->getJson("{$this->base}/requests")->assertOk()->assertJsonPath('data.0.contact.email', 'ana@example.com')
        ->assertJsonPath('data.0.calculator', 'Pintura de interiores')->assertJsonPath('data.0.lead_id', fn ($v) => str_starts_with((string) $v, 'ld_'));
    $masked = $this->actingAs($marketing)->getJson("{$this->base}/requests?calculator={$calc->publicId()}")->assertOk()->json('data.0.contact.email');
    expect($masked)->not->toBe('ana@example.com');
    $this->actingAs($this->owner)->getJson("{$this->base}/calculators")->assertJsonPath('data.0.stats.quotes_30d', 1)->assertJsonPath('data.0.stats.leads_30d', 1);

    // Con cotizaciones se pausa en vez de borrarse.
    $this->actingAs($this->owner)->deleteJson("{$this->base}/calculators/{$calc->publicId()}")->assertNoContent();
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => QuoteCalculator::query()->find($calc->id)?->status))->toBe('paused');
});

it('otro espacio de trabajo no ve ni toca las calculadoras', function () {
    $calc = quoteCalculator($this->ws);
    $other = $this->userWith2fa();
    $otherWs = $this->workspaceFor($other, 'Otro');
    $this->actingAs($other)->getJson("/api/w/{$otherWs->publicId()}/quotes/calculators")->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($other)->getJson("/api/w/{$otherWs->publicId()}/quotes/calculators/{$calc->publicId()}")->assertNotFound();
    $this->actingAs($other)->deleteJson("/api/w/{$otherWs->publicId()}/quotes/calculators/{$calc->publicId()}")->assertNotFound();
    $this->actingAs($other)->getJson("{$this->base}/calculators")->assertNotFound();
    app(CurrentWorkspace::class)->run($otherWs, fn () => expect(QuoteCalculator::query()->count())->toBe(0));
});
