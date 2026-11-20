<?php

require_once __DIR__.'/LeadsHelpers.php';

use App\Domain\Intelligence\Ai\Support\NumberValidator;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Reports\Mail\WeeklyReportMail;
use App\Domain\Reports\SendWeeklyReport;
use App\Domain\Services\Leads\Actions\ChangeLeadStage;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Queries\LeadFunnel;
use App\Domain\Services\Leads\Support\FunnelNarrative;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/*
 * El embudo de leads del lunes (M18, auditoría §5.1 función 9): consultas → contactados →
 * calificados → propuestas → ganados, frente a la semana anterior, dónde se cae la venta
 * y por qué. En el tablero de leads y en el informe semanal por correo.
 */

beforeEach(function () {
    leadsSetUp($this);
    $this->ws->forceFill(['timezone' => 'America/Bogota', 'country' => 'CO'])->save();
    $this->ai = FakeAiGateway::install();
    $this->agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    $at = fn (string $when) => $this->travelTo(CarbonImmutable::parse($when, 'America/Bogota'));
    $move = fn (Lead $lead, string $to, string $by = 'user', ?string $reason = null) => leadsRun($this, fn () => app(ChangeLeadStage::class)->handle($this->ws, $lead->fresh(), $to, $by, null, $to === 'won' ? '2000000' : null, $reason));

    // Un lead viejo que nunca se movió (quieto en «Nuevo»).
    $at('2026-11-01 10:00');
    $this->stale = leadsRow($this);
    // Semana anterior (9 al 15 de noviembre): 2 consultas, una contactada al día siguiente.
    $at('2026-11-09 10:00');
    $this->l1 = leadsRow($this);
    $at('2026-11-10 10:00');
    $this->l2 = leadsRow($this);
    $move($this->l1, 'contacted');
    // Esta semana (16 al 22): 4 consultas nuevas.
    $at('2026-11-16 09:00');
    $l4 = leadsRow($this);
    $at('2026-11-16 09:30');
    $move($l4, 'contacted');                                  // primera respuesta en 30 min
    $move($this->l2, 'lost', 'user', 'precio ');              // se pierde desde «Nuevo»
    $at('2026-11-16 10:00');
    $this->l3 = leadsRow($this, ['owner_id' => $this->agent->id]);
    $at('2026-11-16 10:10');
    $move($this->l3, 'contacted');                            // primera respuesta en 10 min
    $at('2026-11-17 10:00');
    $l5 = leadsRow($this);
    $move($l5, 'qualified', 'system');                        // salta «Contactado»
    $move($this->l3, 'qualified');
    $move($this->l1, 'proposal');                             // de «Contactado» a «Propuesta»
    $at('2026-11-18 10:00');
    leadsRow($this);                                          // sin respuesta
    $move($this->l3, 'proposal');
    $move($l4, 'lost', 'user', 'Precio');
    $at('2026-11-19 10:00');
    $move($this->l3, 'won');
    $at('2026-11-23 08:00');                                  // lunes del informe
});

it('cuenta a dónde llegaron los leads en la semana, frente a la anterior, y la conversión por etapa', function () {
    $pipeline = leadsRun($this, fn () => Pipelines::default($this->ws->id));
    $f = leadsRun($this, fn () => app(LeadFunnel::class)->week($this->ws->id, $pipeline, CarbonImmutable::parse('2026-11-16', 'America/Bogota'), null, 7));

    expect(collect($f['stages'])->pluck('reached', 'key')->all())->toBe(['new' => 4, 'contacted' => 3, 'qualified' => 3, 'proposal' => 2, 'won' => 1])
        ->and(collect($f['stages'])->pluck('previous', 'key')->all())->toBe(['new' => 2, 'contacted' => 1, 'qualified' => 0, 'proposal' => 0, 'won' => 0])
        ->and(collect($f['stages'])->pluck('conversion', 'key')->all())->toBe(['new' => null, 'contacted' => 0.75, 'qualified' => 1.0, 'proposal' => 0.6667, 'won' => 0.5])
        ->and($f['stages'][1]['previous_conversion'])->toBe(0.5)->and($f['stages'][1]['change_points'])->toBe(25.0);
    // Dónde se cae: de 90 días de leads, el paso con menos conversión.
    expect($f['drop'])->toMatchArray(['from' => 'proposal', 'to' => 'won', 'conversion' => 0.5, 'entered' => 2, 'passed' => 1, 'cohort' => 7]);
    // Por qué: motivos (sin importar mayúsculas ni espacios) y de qué etapa salieron.
    expect($f['lost'])->toMatchArray(['count' => 2, 'previous' => 0, 'reasons' => [['reason' => 'precio', 'count' => 2]]])
        ->and(collect($f['lost']['by_stage'])->pluck('count', 'stage')->all())->toEqualCanonicalizing(['new' => 1, 'contacted' => 1]);
    expect($f['stalled'])->toHaveCount(1)->and($f['stalled'][0])->toMatchArray(['stage' => 'new', 'count' => 1, 'max_days' => 21, 'buckets' => ['up_to_14' => 0, 'from_15_to_30' => 1, 'over_30' => 0]]);
    expect($f['first_response']['week'])->toBe(['leads' => 4, 'answered' => 2, 'median_seconds' => 1200, 'within_15m_rate' => 0.25])
        ->and($f['first_response']['previous']['median_seconds'])->toBe(86400)
        ->and($f['won'])->toBe(['week' => ['count' => 1, 'value' => '2000000.00'], 'previous' => ['count' => 0, 'value' => '0.00']]);
});

it('en el tablero: la conclusión de la IA solo con cifras de los hechos; el vendedor ve lo suyo; otro espacio no ve nada', function () {
    $api = "/api/w/{$this->ws->publicId()}/leads/funnel";
    $this->ai->replyJson(['conclusion' => 'La venta se cae en Propuesta: solo el 30 % cierra.'])
        ->replyJson(['conclusion' => 'Cierras el 30 % de las propuestas.']);
    $res = $this->actingAs($this->owner)->getJson($api)->assertOk();
    expect($res->json('week'))->toBe(['from' => '2026-11-16', 'to' => '2026-11-22'])
        ->and($res->json('stages.0.reached'))->toBe(4)
        ->and($res->json('conclusion.source'))->toBe('fallback')
        ->and($res->json('conclusion.text'))->not->toContain('30 %')->toContain('Propuesta');

    $this->ai->replyJson(['conclusion' => 'Llegaron 4 consultas y la venta se cae entre Propuesta y Ganado: pasa el 50 % de los leads de los últimos 90 días. Revisa el precio, el motivo de 2 pérdidas.']);
    $res = $this->actingAs($this->owner)->getJson($api.'?week=2026-11-18')->assertOk();
    expect($res->json('conclusion.source'))->toBe('ai')->and($res->json('conclusion.text'))->toContain('50 %');
    expect(end($this->ai->requests)['request']->feature)->toBe('lead_funnel');

    // El rol agente solo ve el embudo de sus leads.
    $mine = $this->actingAs($this->agent)->getJson($api)->assertOk();
    expect($mine->json('stages.0.reached'))->toBe(1)->and($mine->json('stages.4.reached'))->toBe(1)->and($mine->json('lost.count'))->toBe(0);

    $other = $this->userWith2fa();
    $otherWs = $this->workspaceFor($other, 'Otra');
    $pipeline = leadsRun($this, fn () => Pipelines::default($this->ws->id));
    $this->actingAs($other)->getJson("/api/w/{$otherWs->publicId()}/leads/funnel?pipeline={$pipeline->publicId()}")->assertNotFound();
    $this->actingAs($other)->getJson("/api/w/{$otherWs->publicId()}/leads/funnel")->assertOk()->assertJsonPath('stages.0.reached', 0)->assertJsonPath('lost.count', 0);
});

it('va en el informe semanal por correo con dónde se cae la venta y por qué', function () {
    Mail::fake();
    $this->ai->enabled = false;
    $result = app(SendWeeklyReport::class)->handle($this->ws);
    expect($result['status'])->toBe('sent');
    $mail = null;
    Mail::assertQueued(WeeklyReportMail::class, function (WeeklyReportMail $m) use (&$mail) {
        $mail = $m;

        return true;
    });
    expect($mail->facts['leads'])->toHaveCount(1)->and($mail->facts['leads'][0]['conclusion']['source'])->toBe('fallback');
    $html = $mail->render();
    expect($html)->toContain('Tu embudo de leads')->toContain('Dónde se cae la venta')->toContain('Propuesta')->toContain('precio (2)')
        ->toContain('Quietos más de 7 días');
});

it('la conclusión sin IA se arma solo con los hechos', function () {
    $pipeline = leadsRun($this, fn () => Pipelines::default($this->ws->id));
    $f = leadsRun($this, fn () => app(LeadFunnel::class)->week($this->ws->id, $pipeline, CarbonImmutable::parse('2026-11-16', 'America/Bogota')));
    $facts = FunnelNarrative::facts($f, 'COP');
    $text = FunnelNarrative::fallback($facts);
    expect($text)->toContain('4 consultas')->toContain('«Propuesta» y «Ganado»')->toContain('50 %')->toContain('«precio» (2)');
    expect(app(NumberValidator::class)->validate($text, $facts)->ok())->toBeTrue();
});
