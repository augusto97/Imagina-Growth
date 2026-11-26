<?php

use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowVersion;
use App\Domain\Intelligence\Ai\Agent\Models\AgentSettings;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyReward;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Measurement\Audiences\Models\AudienceSync;
use App\Domain\Measurement\Enums\DestinationMode;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Recommendations\Models\CommerceSettings;
use App\Domain\Referrals\Models\ReferralProgram;
use App\Domain\Reviews\Support\ReviewSettings;
use App\Domain\Services\Leads\Models\LeadQualificationSet;
use App\Domain\Strategies\Models\WorkspaceStrategy;
use App\Domain\Surveys\Models\SurveyDefinition;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Strategies\Support\StrategyFixtures;

uses(StrategyFixtures::class);

/*
 * Activar y pausar cada estrategia de punta a punta con su bloque real (docs/diseno/rediseno-v2.md §6): la estrategia
 * solo orquesta las acciones de cada módulo. Idempotente, con auditoría y sin duplicar nada al reanudar.
 */

beforeEach(function () {
    $this->strategyWorld();
    FakeAiGateway::install();
});

function activate($test, string $key, array $body = [], int $status = 200): array
{
    return $test->actingAs($test->owner)->postJson($test->strategies("/{$key}/activate"), $body)->assertStatus($status)->json();
}

function pauseStrategy($test, string $key): array
{
    return $test->actingAs($test->owner)->postJson($test->strategies("/{$key}/pause"))->assertOk()->json();
}

function audits($test, string $action): int
{
    return DB::table('audit_logs')->where('workspace_id', $test->ws->id)->where('action', $action)->count();
}

dataset('estrategias de flujos', [
    'carrito abandonado' => ['abandoned_cart', 'abandoned_cart'],
    'checkout abandonado' => ['abandoned_checkout', 'abandoned_checkout'],
    'pago pendiente' => ['payment_pending', 'payment_pending'],
    'contraentrega' => ['cod_confirm', 'cod_confirm'],
    'navegación abandonada' => ['browse_abandonment', 'browse_abandonment'],
    'segunda compra' => ['second_purchase', 'second_purchase'],
    'reposición' => ['replenishment', 'replenishment'],
    'clientes en riesgo' => ['winback', 'winback'],
    'reactivar con IA' => ['ai_reactivate', 'ai_reactivate'],
    'cumpleaños' => ['birthday', 'birthday'],
    'contactos de servicios' => ['lead_response', 'service_lead'],
    'renovación' => ['renewal', 'renewal'],
]);

it('activa, pausa y reanuda con su flujo de la biblioteca sin duplicarlo', function (string $key, string $flowKey) {
    $first = activate($this, $key);
    expect($first['status'])->toBe('active')->and($first['activated'])->toBeTrue()
        ->and($first['manage_to'])->toStartWith('flujos/fl_')
        ->and($first['steps'])->toHaveCount(3)
        ->and($first['preview']['kind'])->toBe('messages');
    $flows = $this->inWs(fn () => Flow::query()->where('template_key', $flowKey)->get());
    expect($flows)->toHaveCount(1)->and($flows[0]->status)->toBe('active')
        ->and(audits($this, 'strategy.activated'))->toBe(1);

    // Otra vez: no hace nada.
    $again = activate($this, $key);
    expect($again['activated'])->toBeFalse()->and(audits($this, 'strategy.activated'))->toBe(1);

    $paused = pauseStrategy($this, $key);
    expect($paused['status'])->toBe('paused')->and($paused['paused'])->toBeTrue()
        ->and($this->inWs(fn () => Flow::query()->where('template_key', $flowKey)->value('status')))->toBe('paused')
        ->and(pauseStrategy($this, $key)['paused'])->toBeFalse()
        ->and(audits($this, 'strategy.paused'))->toBe(1);

    expect(activate($this, $key)['status'])->toBe('active')
        ->and($this->inWs(fn () => Flow::query()->where('template_key', $flowKey)->pluck('id')->all()))->toBe([$flows[0]->id])
        ->and($this->inWs(fn () => WorkspaceStrategy::query()->where('strategy_key', $key)->first())->refList('flows'))->toBe([$flows[0]->id]);
})->with('estrategias de flujos');

it('con WhatsApp sin plantillas aprobadas activa solo por correo; con ellas, con WhatsApp', function () {
    $this->channel('whatsapp');
    $detail = $this->actingAs($this->owner)->getJson($this->strategies('/abandoned_cart'))->assertOk()->json();
    $templates = collect($detail['requirements'])->firstWhere('key', 'wa_templates');
    expect($templates['met'])->toBeFalse()->and($templates['required'])->toBeFalse()->and($detail['status'])->toBe('available');

    activate($this, 'abandoned_cart');
    $graph = $this->inWs(fn () => FlowVersion::query()->whereKey(Flow::query()->where('template_key', 'abandoned_cart')->value('current_version_id'))->value('graph'));
    expect(collect($graph['nodes'])->pluck('type')->all())->not->toContain('send_whatsapp');

    $this->waTemplate('wa_abandoned_checkout');
    activate($this, 'abandoned_checkout');
    $graph = $this->inWs(fn () => FlowVersion::query()->whereKey(Flow::query()->where('template_key', 'abandoned_checkout')->value('current_version_id'))->value('graph'));
    expect(collect($graph['nodes'])->pluck('type')->all())->toContain('send_whatsapp');
});

it('solo con WhatsApp y sin la plantilla aprobada pide configurarla en lugar de activar', function () {
    DB::table('channel_accounts')->where('workspace_id', $this->ws->id)->delete();
    $this->channel('whatsapp');
    $res = activate($this, 'abandoned_cart', [], 422);
    expect($res['error'])->toBe('needs_setup')
        ->and(collect($res['requirements'])->pluck('key')->all())->toContain('wa_templates')
        ->and($res['to'])->toBe('canales/plantillas-whatsapp')
        ->and($this->inWs(fn () => Flow::query()->count()))->toBe(0);
});

it('puntos y niveles: crea el programa con niveles, una recompensa y sus flujos de apoyo', function () {
    $res = activate($this, 'loyalty_points');
    $program = $this->inWs(fn () => LoyaltyProgram::query()->first());
    expect($res['status'])->toBe('active')->and($program->status)->toBe('active')
        ->and($program->earn_rules['per_amount']['amount'])->toBe('1000.00')
        ->and($this->inWs(fn () => LoyaltyTier::query()->orderBy('rank')->pluck('name')->all()))->toBe(['Plata', 'Oro'])
        ->and($this->inWs(fn () => LoyaltyReward::query()->where('status', 'active')->count()))->toBe(1)
        ->and($this->inWs(fn () => Flow::query()->whereIn('template_key', ['vip_welcome', 'points_expiring'])->where('status', 'active')->count()))->toBe(2)
        ->and(collect($res['parts'])->pluck('status')->all())->toBe(['on', 'on', 'on']);

    pauseStrategy($this, 'loyalty_points');
    expect($this->inWs(fn () => LoyaltyProgram::query()->value('status')))->toBe('paused')
        ->and($this->inWs(fn () => Flow::query()->where('template_key', 'vip_welcome')->value('status')))->toBe('paused');
    activate($this, 'loyalty_points');
    expect($this->inWs(fn () => LoyaltyTier::query()->count()))->toBe(2)->and($this->inWs(fn () => LoyaltyReward::query()->count()))->toBe(1);
});

it('referidos: prende el programa con los valores recomendados y el flujo para invitar', function () {
    $res = activate($this, 'referrals');
    $program = $this->inWs(fn () => ReferralProgram::query()->first());
    expect($res['status'])->toBe('active')->and($program->status)->toBe('active')
        ->and($program->referrer_reward['type'])->toBe('wallet')
        ->and($program->friend_reward['kind'])->toBe('percent')
        ->and($this->inWs(fn () => Flow::query()->where('template_key', 'referral_invite')->value('status')))->toBe('active');
    pauseStrategy($this, 'referrals');
    expect($this->inWs(fn () => ReferralProgram::query()->value('status')))->toBe('paused');
});

it('popup de bienvenida: crea el widget con el correo (sin WhatsApp) y el cupón, y la bienvenida', function () {
    $res = activate($this, 'welcome_popup');
    $widget = $this->inWs(fn () => OnsiteWidget::query()->first());
    expect($res['status'])->toBe('active')->and($widget->kind)->toBe('popup')->and($widget->status)->toBe('active')
        ->and($widget->site_id)->toBe($this->site->id)
        ->and(collect($widget->content['fields'])->pluck('key')->all())->toContain('email')
        ->and($widget->consent_config['channels'])->toBe(['email'])
        ->and($widget->coupon_config['enabled'])->toBeTrue()
        ->and($this->inWs(fn () => Flow::query()->where('template_key', 'welcome')->value('status')))->toBe('active');
    pauseStrategy($this, 'welcome_popup');
    expect($widget->refresh()->status)->toBe('paused');
    activate($this, 'welcome_popup');
    expect($this->inWs(fn () => OnsiteWidget::query()->count()))->toBe(1)->and($widget->refresh()->status)->toBe('active');
});

it('botón de WhatsApp: pide el número y lo toma de la activación o de la cuenta conectada', function () {
    $res = activate($this, 'whatsapp_button', [], 422);
    expect(collect($res['requirements'])->pluck('key')->all())->toBe(['wa_number']);

    activate($this, 'whatsapp_button', ['wa_number' => '+57 301 555 1234']);
    $widget = $this->inWs(fn () => OnsiteWidget::query()->where('kind', 'wa_widget')->first());
    expect($widget->status)->toBe('active')->and($widget->content['wa']['number'])->toBe('573015551234');
});

it('quiz de productos: crea el quiz de ejemplo activo', function () {
    activate($this, 'product_quiz');
    $widget = $this->inWs(fn () => OnsiteWidget::query()->where('kind', 'quiz')->with('quiz')->first());
    expect($widget->status)->toBe('active')->and($widget->quiz->questions)->not->toBeEmpty();
});

it('reseñas, NPS y Google: encienden los ajustes del módulo de reseñas', function () {
    activate($this, 'review_request');
    $settings = ReviewSettings::for($this->ws->refresh());
    expect($settings['request']['enabled'])->toBeTrue()->and($settings['request']['channels'])->toBe(['email']);
    pauseStrategy($this, 'review_request');
    expect(ReviewSettings::for($this->ws->refresh())['request']['enabled'])->toBeFalse()
        ->and($this->actingAs($this->owner)->getJson($this->strategies('/review_request'))->json('status'))->toBe('paused');

    activate($this, 'nps');
    expect($this->inWs(fn () => SurveyDefinition::query()->where('kind', 'nps')->value('status')))->toBe('active');

    expect(activate($this, 'google_reviews', [], 422)['requirements'][0]['key'])->toBe('google_url');
    activate($this, 'google_reviews', ['google_review_url' => 'https://g.page/r/pinturas/review']);
    expect(ReviewSettings::for($this->ws->refresh())['google_review_url'])->toBe('https://g.page/r/pinturas/review');
    pauseStrategy($this, 'google_reviews');
    expect(ReviewSettings::for($this->ws->refresh())['google_review_url'])->toBeNull();
    // Reanudar recuerda el enlace.
    activate($this, 'google_reviews');
    expect(ReviewSettings::for($this->ws->refresh())['google_review_url'])->toBe('https://g.page/r/pinturas/review');
});

it('avisos de stock: vienen prendidos en una tienda y se pausan y reanudan', function () {
    expect($this->actingAs($this->owner)->getJson($this->strategies('/back_in_stock'))->json('status'))->toBe('active');
    pauseStrategy($this, 'back_in_stock');
    expect($this->inWs(fn () => CommerceSettings::current()->watchSettings()['enabled']))->toBeFalse();
    activate($this, 'back_in_stock');
    expect($this->inWs(fn () => CommerceSettings::current()->watchSettings()['enabled']))->toBeTrue();
});

it('agente de ventas y fuera de horario usan el mismo agente con distinto horario', function () {
    expect(collect(activate($this, 'ai_sales_agent', [], 422)['requirements'])->pluck('key')->all())->toBe(['whatsapp']);
    $this->channel('whatsapp');
    activate($this, 'ai_sales_agent');
    $settings = $this->inWs(fn () => AgentSettings::current($this->ws->id));
    expect($settings->enabled)->toBeTrue()->and($settings->default_mode)->toBe('auto')->and($settings->handoff_rules['hours']['mode'])->toBe('always');

    activate($this, 'after_hours');
    $settings = $this->inWs(fn () => AgentSettings::current($this->ws->id));
    expect($settings->handoff_rules['hours'])->toMatchArray(['mode' => 'out_of_hours', 'start' => '08:00', 'end' => '18:00'])
        ->and($this->actingAs($this->owner)->getJson($this->strategies('/ai_sales_agent'))->json('status'))->toBe('available');
    pauseStrategy($this, 'after_hours');
    expect($this->inWs(fn () => AgentSettings::current($this->ws->id))->enabled)->toBeFalse();
});

it('sin IA disponible, las estrategias de IA piden activarla', function () {
    FakeAiGateway::install()->enabled = false;
    $this->channel('whatsapp');
    expect(collect(activate($this, 'ai_sales_agent', [], 422)['requirements'])->pluck('key')->all())->toBe(['ai'])
        ->and(collect(activate($this, 'ai_reactivate', [], 422)['requirements'])->pluck('key')->all())->toBe(['ai']);
});

it('bandeja con asignación: necesita un canal de conversaciones y cambia la asignación automática', function () {
    expect(activate($this, 'inbox_assignment', [], 422)['requirements'][0]['key'])->toBe('inbox_channel');
    $this->channel('whatsapp');
    expect($this->actingAs($this->owner)->getJson($this->strategies('/inbox_assignment'))->json('status'))->toBe('active');
    pauseStrategy($this, 'inbox_assignment');
    expect($this->ws->refresh()->setting('inbox.auto_assign'))->toBeFalse();
    activate($this, 'inbox_assignment');
    expect($this->ws->refresh()->setting('inbox.auto_assign'))->toBeTrue();
});

it('anuncios: reanuda los destinos en pausa y crea las audiencias recomendadas', function () {
    Bus::fake();
    expect(activate($this, 'ads_conversions', [], 422)['requirements'][0]['key'])->toBe('ad_destination');
    $destination = $this->inWs(fn () => AdDestination::query()->create(['workspace_id' => $this->ws->id, 'platform' => Platform::Meta, 'name' => 'Meta',
        'config' => ['dataset_id' => '123', 'access_token' => 'x'], 'mode' => DestinationMode::ServerOnly, 'status' => 'paused']));
    expect($this->actingAs($this->owner)->getJson($this->strategies('/ads_conversions'))->json('status'))->toBe('paused');
    activate($this, 'ads_conversions');
    expect($destination->refresh()->status)->toBe('active');

    activate($this, 'ads_audiences');
    expect($this->inWs(fn () => AudienceSync::query()->pluck('purpose')->sort()->values()->all()))->toBe(['lookalike_seed', 'target']);
    pauseStrategy($this, 'ads_audiences');
    expect($this->inWs(fn () => AudienceSync::query()->where('status', 'paused')->count()))->toBe(2);
    activate($this, 'ads_audiences');
    expect($this->inWs(fn () => AudienceSync::query()->count()))->toBe(2);
});

it('servicios: calificación en el chat, agenda con recordatorios y cotizador', function () {
    expect(activate($this, 'chat_qualification', [], 422)['requirements'][0]['key'])->toBe('agent_on');
    $this->channel('whatsapp');
    activate($this, 'ai_sales_agent');
    activate($this, 'chat_qualification');
    $set = $this->inWs(fn () => LeadQualificationSet::query()->first());
    expect($set->active)->toBeTrue()->and(count($set->questions))->toBe(3);

    expect(activate($this, 'appointment_reminders', [], 422)['requirements'][0]['key'])->toBe('booking_service');
    DB::table('booking_services')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'name' => 'Asesoría', 'slug' => 'asesoria',
        'duration_min' => 30, 'buffer_min' => 0, 'location' => 'online', 'min_notice_hours' => 2, 'max_days_ahead' => 30, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    BookingSettings::save($this->ws->refresh(), ['reminder_hours' => [48]]);
    expect($this->actingAs($this->owner)->getJson($this->strategies('/appointment_reminders'))->json('status'))->toBe('active');
    pauseStrategy($this, 'appointment_reminders');
    expect(BookingSettings::for($this->ws->refresh())['reminder_hours'])->toBe([]);
    activate($this, 'appointment_reminders');
    expect(BookingSettings::for($this->ws->refresh())['reminder_hours'])->toBe([48]);

    expect(activate($this, 'quote_calculator', [], 422)['requirements'][0]['key'])->toBe('quote_calculator');
    $calculator = $this->inWs(fn () => QuoteCalculator::query()->create(['workspace_id' => $this->ws->id, 'name' => 'Pintura', 'kind' => 'area', 'definition' => ['fields' => []], 'status' => 'paused']));
    activate($this, 'quote_calculator');
    expect($calculator->refresh()->status)->toBe('active');
});
