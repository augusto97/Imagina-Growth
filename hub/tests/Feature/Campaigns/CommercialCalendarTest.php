<?php

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Support\CommercialCalendar;
use App\Domain\Intelligence\Insights\InsightEngine;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Onboarding\Support\Verticals;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Calendario comercial por país (rediseño v2 §11.5, modulos.md M10): fechas por año y país, a quién aplica cada momento,
 * la campaña sugerida, las excepciones de Plataforma, el calendario del espacio de trabajo, la campaña en un clic y el
 * aviso en «Recomendaciones» unas semanas antes.
 */

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner, 'Clínica Sonrisa');
    $this->ws->forceFill(['country' => 'CO', 'timezone' => 'America/Bogota', 'vertical' => 'health', 'industry' => 'salud_bienestar'])->save();
});

function calendarDates(int $year, string $country): array
{
    return collect(CommercialCalendar::forYear($year, $country))->pluck('date', 'key')->all();
}

it('calcula las fechas que cambian cada año en cada país', function () {
    $co26 = calendarDates(2026, 'CO');
    $co27 = calendarDates(2027, 'CO');
    expect($co26)->toMatchArray([
        'dia_madre' => '2026-05-10', 'dia_padre' => '2026-06-21', 'amor_amistad' => '2026-09-19', 'black_friday' => '2026-11-27',
        'cyberlunes' => '2026-11-30', 'semana_santa' => '2026-03-29', 'dia_nino_co' => '2026-04-25', 'prima_junio' => '2026-06-30',
        'prima_diciembre' => '2026-12-20', 'navidad' => '2026-12-24', 'halloween' => '2026-10-31', 'regreso_clases_a' => '2026-01-19',
    ])->and($co27)->toMatchArray([
        // Segundo domingo de mayo, tercer domingo de junio, tercer sábado de septiembre y el viernes después del cuarto jueves de noviembre.
        'dia_madre' => '2027-05-09', 'dia_padre' => '2027-06-20', 'amor_amistad' => '2027-09-18', 'black_friday' => '2027-11-26',
        'cyberlunes' => '2027-11-29', 'semana_santa' => '2027-03-21', 'dia_nino_co' => '2027-04-24',
    ])->and($co26)->not->toHaveKey('hot_sale')->not->toHaveKey('dia_madre_mx');

    $mx26 = calendarDates(2026, 'MX');
    expect($mx26)->toMatchArray(['dia_madre_mx' => '2026-05-10', 'dia_nino_mx' => '2026-04-30', 'hot_sale' => '2026-05-25', 'buen_fin' => '2026-11-13'])
        ->and(calendarDates(2027, 'MX'))->toMatchArray(['dia_madre_mx' => '2027-05-10', 'buen_fin' => '2027-11-12', 'regreso_clases_mx' => '2027-08-30', 'hot_sale' => '2027-05-31'])
        ->and($mx26)->not->toHaveKey('amor_amistad')->not->toHaveKey('cyberlunes')->not->toHaveKey('prima_junio');

    $ve26 = calendarDates(2026, 'VE');
    expect($ve26)->toMatchArray(['dia_madre' => '2026-05-10', 'dia_nino_ve' => '2026-07-19', 'regreso_clases_ve' => '2026-09-16', 'declaracion_islr_ve' => '2026-03-01'])
        ->and(calendarDates(2028, 'VE'))->toMatchArray(['dia_madre' => '2028-05-14', 'dia_nino_ve' => '2028-07-16', 'semana_santa' => '2028-04-09'])
        ->and($ve26)->not->toHaveKey('hot_sale');

    // El aviso es unas semanas antes del envío sugerido.
    $mother = collect(CommercialCalendar::forYear(2026, 'CO'))->firstWhere('key', 'dia_madre');
    expect($mother['starts_communicating'])->toBe('2026-04-25')->and($mother['notice_from'])->toBe('2026-04-11')->and($mother['overridden'])->toBeFalse();
});

it('cada momento aplica a la tienda, a los servicios o a sus verticales, con la campaña más específica', function () {
    $all = collect([...CommercialCalendar::forYear(2026, 'CO'), ...CommercialCalendar::forYear(2026, 'MX'), ...CommercialCalendar::forYear(2026, 'VE')]);
    foreach ($all as $m) {
        expect($m['audiences'])->not->toBeEmpty($m['key']);
        foreach ($m['campaigns'] as $c) {
            expect(in_array($c['for'], ['store', 'services', ...Verticals::keys()], true))->toBeTrue($m['key'])
                ->and(TemplateLibrary::entry($c['template']))->not->toBeNull("{$m['key']}: {$c['template']}");
        }
        foreach ($m['audiences'] as $audience) {
            // Toda audiencia tiene una campaña que le sirve.
            $type = $audience === 'store' ? 'store' : 'services';
            expect(CommercialCalendar::campaignFor($m['campaigns'], $type, $audience === 'services' ? 'other' : $audience))->not->toBeNull("{$m['key']} / {$audience}");
        }
    }
    $mother = $all->firstWhere('key', 'dia_madre');
    expect(CommercialCalendar::appliesTo($mother['audiences'], 'services', 'health'))->toBeTrue()
        ->and(CommercialCalendar::appliesTo($mother['audiences'], 'services', 'b2b'))->toBeFalse()
        ->and(CommercialCalendar::campaignFor($mother['campaigns'], 'services', 'health')['copy']['headline'])->toBe('Consiente a mamá')
        ->and(CommercialCalendar::campaignFor($mother['campaigns'], 'both', 'health')['for'])->toBe('health')
        ->and(CommercialCalendar::campaignFor($mother['campaigns'], 'store', null)['for'])->toBe('store');
    $taxes = $all->firstWhere('key', 'declaracion_renta_co');
    expect(CommercialCalendar::appliesTo($taxes['audiences'], 'services', 'professional'))->toBeTrue()
        ->and(CommercialCalendar::appliesTo($taxes['audiences'], 'store', 'store'))->toBeFalse();
});

it('Plataforma corrige las fechas sin regla fija (Hot Sale, El Buen Fin) y las apaga por año, con auditoría', function () {
    $admin = $this->userWith2fa(['is_platform_admin' => true]);
    $url = '/api/platform/calendar/MX/hot_sale/2027';
    $this->actingAs($this->owner)->putJson($url, ['starts_on' => '2027-06-01'])->assertForbidden();

    $res = $this->actingAs($admin)->putJson($url, ['starts_on' => '2027-06-01', 'ends_on' => '2027-06-09', 'note' => 'Anuncio de la AMVO'])->assertOk();
    $hot = collect($res->json('events'))->firstWhere('key', 'hot_sale');
    expect($hot)->toMatchArray(['rule_date' => '2027-05-31', 'date' => '2027-06-01', 'ends' => '2027-06-09'])
        ->and($hot['override'])->toMatchArray(['starts_on' => '2027-06-01', 'note' => 'Anuncio de la AMVO']);
    $live = collect(CommercialCalendar::forYear(2027, 'MX'))->firstWhere('key', 'hot_sale');
    expect($live)->toMatchArray(['date' => '2027-06-01', 'ends' => '2027-06-09', 'starts_communicating' => '2027-05-25', 'overridden' => true])
        // Solo ese año y ese país.
        ->and(calendarDates(2026, 'MX')['hot_sale'])->toBe('2026-05-25');

    $this->actingAs($admin)->putJson('/api/platform/calendar/MX/buen_fin/2027', ['disabled' => true])->assertOk();
    expect(calendarDates(2027, 'MX'))->not->toHaveKey('buen_fin');
    $this->actingAs($admin)->putJson('/api/platform/calendar/MX/hot_sale/2027', ['starts_on' => '2028-01-01'])->assertUnprocessable();
    $this->actingAs($admin)->putJson('/api/platform/calendar/CO/hot_sale/2027', ['starts_on' => '2027-06-01'])->assertNotFound();
    $this->actingAs($admin)->putJson('/api/platform/calendar/MX/no_existe/2027', ['starts_on' => '2027-06-01'])->assertNotFound();

    $this->actingAs($admin)->deleteJson($url)->assertOk();
    expect(calendarDates(2027, 'MX')['hot_sale'])->toBe('2027-05-31')
        ->and(DB::table('audit_logs')->whereIn('action', ['platform.calendar_override_set', 'platform.calendar_override_removed'])->count())->toBe(3);
    expect($this->actingAs($admin)->getJson('/api/platform/calendar?country=VE&year=2026')->assertOk()->json('events.0.key'))->toBe('ano_nuevo');
});

it('el calendario del espacio de trabajo muestra lo de su país, tipo de negocio y vertical, con la campaña sugerida', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-20 09:00:00', 'America/Bogota'));
    $res = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/calendar?days=120")->assertOk();
    $keys = collect($res->json('data'))->pluck('key');
    expect($res->json())->toMatchArray(['country' => 'CO', 'business_type' => 'services', 'vertical' => 'health'])
        ->and($keys)->toContain('dia_madre', 'vacaciones_mitad_ano_co', 'prima_junio')
        ->not->toContain('hot_sale')->not->toContain('halloween')->not->toContain('declaracion_renta_co');
    $mother = collect($res->json('data'))->firstWhere('key', 'dia_madre');
    expect($mother)->toMatchArray(['date' => '2026-05-10', 'send_on' => '2026-04-25', 'days_left' => 20, 'campaign_id' => null])
        ->and($mother['campaign'])->toMatchArray(['for' => 'health', 'channel' => 'email', 'template_key' => 'gal_promotion', 'subject' => 'Regálale a mamá un momento para ella']);

    // Una tienda de México ve el Hot Sale y no las primas de Colombia.
    $store = $this->workspaceFor($this->owner, 'Tienda MX');
    $store->forceFill(['country' => 'MX', 'timezone' => 'America/Mexico_City', 'vertical' => 'store'])->save();
    $storeKeys = collect($this->actingAs($this->owner)->getJson("/api/w/{$store->publicId()}/calendar")->assertOk()->json('data'))->pluck('key');
    expect($storeKeys)->toContain('dia_madre_mx', 'hot_sale')->not->toContain('prima_junio')->not->toContain('dia_madre');
});

it('crea la campaña del momento en borrador, con la plantilla de la galería y sus textos, una sola vez', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-20 09:00:00', 'America/Bogota'));
    $url = "/api/w/{$this->ws->publicId()}/calendar/dia_madre/campaign";
    $first = $this->actingAs($this->owner)->postJson($url)->assertCreated();
    expect($first->json())->toMatchArray(['name' => 'Día de la Madre 2026', 'channel' => 'email', 'status' => 'draft', 'created' => true]);
    $campaign = app(CurrentWorkspace::class)->run($this->ws, fn () => Campaign::query()->sole());
    $template = app(CurrentWorkspace::class)->run($this->ws, fn () => Template::query()->findOrFail($campaign->template_id));
    expect($campaign->audience['all_contacts'])->toBeTrue()
        ->and($campaign->send_at?->toImmutable()->setTimezone('America/Bogota')->toDateTimeString())->toBe('2026-04-25 10:00:00')
        ->and($template->subject)->toBe('Regálale a mamá un momento para ella')->and($template->name)->toBe('Día de la Madre 2026 · correo')
        ->and($template->body_html)->toContain('Consiente a mamá');

    $again = $this->actingAs($this->owner)->postJson($url)->assertOk();
    expect($again->json('id'))->toBe($first->json('id'))->and($again->json('created'))->toBeFalse()
        ->and(collect($this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/calendar")->json('data'))->firstWhere('key', 'dia_madre')['campaign_id'])->toBe($first->json('id'));

    // No aplica a la vertical o ya pasó.
    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/calendar/declaracion_renta_co/campaign")->assertUnprocessable();
    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/calendar/no_existe/campaign")->assertNotFound();
});

it('avisa unas semanas antes con la campaña lista y deja de avisar cuando ya la creó', function () {
    $now = CarbonImmutable::parse('2026-04-15 03:00:00', 'America/Bogota');
    $this->travelTo($now);
    app(InsightEngine::class)->run($this->ws, $now, only: ['commercial_moment']);
    $insight = app(CurrentWorkspace::class)->run($this->ws, fn () => Insight::query()->where('detector_key', 'commercial_moment')->where('fingerprint', 'commercial_moment:dia_madre:2026')->sole());
    expect($insight->title)->toBe('En 25 días es Día de la Madre: tu campaña ya está lista para revisar')
        ->and($insight->facts)->toMatchArray(['moment' => 'dia_madre', 'days_left' => 25, 'send_on' => '2026-04-25', 'for' => 'health'])
        ->and($insight->recommended_action)->toMatchArray(['kind' => 'calendar_campaign', 'payload' => ['moment' => 'dia_madre', 'year' => 2026]])
        ->and($insight->estimated_impact_ws)->toBeNull();

    // Antes del aviso, nada; después de crear la campaña, nada.
    $act = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/insights/{$insight->publicId()}/act")->assertOk();
    expect($act->json('to'))->toStartWith('campanas/cmp_')->and($act->json('result'))->toBe('campaign_created');
    app(InsightEngine::class)->run($this->ws, $now->addDay(), only: ['commercial_moment']);
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => Insight::query()->where('fingerprint', 'commercial_moment:dia_madre:2026')->whereIn('status', ['new', 'seen'])->count()))->toBe(0);

    $early = CarbonImmutable::parse('2026-02-01 03:00:00', 'America/Bogota');
    $this->travelTo($early);
    app(InsightEngine::class)->run($this->ws, $early, only: ['commercial_moment']);
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => Insight::query()->where('fingerprint', 'commercial_moment:dia_madre:2026')->where('status', 'new')->count()))->toBe(0);
});

it('aislamiento y permisos: otro espacio de trabajo no ve el calendario ni crea campañas; un lector solo ve', function () {
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson("/api/w/{$this->ws->publicId()}/calendar")->assertOk();
    $this->actingAs($viewer)->postJson("/api/w/{$this->ws->publicId()}/calendar/dia_madre/campaign")->assertForbidden();
    $stranger = $this->userWith2fa();
    $this->workspaceFor($stranger, 'Otro');
    $this->actingAs($stranger)->getJson("/api/w/{$this->ws->publicId()}/calendar")->assertNotFound();
    $this->actingAs($stranger)->postJson("/api/w/{$this->ws->publicId()}/calendar/dia_madre/campaign")->assertNotFound();
    expect(DB::table('campaigns')->where('workspace_id', $this->ws->id)->count())->toBe(0);
});
