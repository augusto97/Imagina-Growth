<?php

use App\Domain\Campaigns\Actions\CampaignSender;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Models\CampaignRecipient;
use App\Domain\Campaigns\Queries\CampaignReport;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Campaigns\CampaignFixtures;

uses(CampaignFixtures::class);

beforeEach(function () {
    $this->campaignWorkspace();
    $this->fake = FakeMessageDispatcher::install();
});

function campaignBogota(string $at): CarbonImmutable
{
    return CarbonImmutable::parse($at, 'America/Bogota');
}

function campaignTick($test): void
{
    $test->artisan('igr:campaigns:tick', ['--sync' => true])->assertSuccessful();
}

/** @return array<string, CampaignRecipient> por contact_id */
function campaignRecipients($test, Campaign $campaign): array
{
    return $test->inWs(fn () => CampaignRecipient::query()->where('campaign_id', $campaign->id)->get()->keyBy('contact_id')->all());
}

it('una campaña programada para el domingo sale el lunes a las 7:00 en Colombia, como marketing y con su clave de idempotencia', function () {
    $this->travelTo(campaignBogota('2026-11-21 16:00:00'));   // sábado
    $ana = $this->contact('Ana');
    $campaign = $this->readyCampaign([$this->segmentOf('VIP', [$ana])], ['send_mode' => 'scheduled', 'send_at' => campaignBogota('2026-11-22 10:00:00')->toIso8601String()]);
    $this->actingAs($this->owner)->postJson($this->api("/{$campaign->publicId()}/schedule"))->assertOk();

    $this->travelTo(campaignBogota('2026-11-22 10:00:30'));   // domingo: se materializa pero no sale
    campaignTick($this);
    $r = campaignRecipients($this, $campaign)[$ana->id];
    expect($r->scheduled_for->setTimezone('America/Bogota')->format('Y-m-d H:i'))->toBe('2026-11-23 07:00')
        ->and($this->fake->dispatched)->toBeEmpty()
        ->and($campaign->fresh()->status)->toBe('sending');

    $this->travelTo(campaignBogota('2026-11-23 07:00:10'));
    campaignTick($this);
    expect($this->fake->dispatched)->toHaveCount(1);
    $message = $this->fake->dispatched[0];
    expect($message->kind)->toBe(MessageKind::Marketing)
        ->and($message->sourceType)->toBe('campaign')
        ->and($message->campaignId)->toBe($campaign->id)
        ->and($message->idempotencyKey)->toBe("campaign:{$campaign->id}:{$ana->id}")
        ->and($message->templateId)->toBe($campaign->fresh()->template_id);
    expect(campaignRecipients($this, $campaign)[$ana->id]->status)->toBe('sent')
        ->and($campaign->fresh()->status)->toBe('sent');
});

it('por zona horaria del contacto y a la mejor hora: cada uno a su hora local, dentro de la ventana', function () {
    $this->travelTo(campaignBogota('2026-11-23 08:00:00'));   // lunes
    $bogota = $this->contact('Ana');
    $mexico = $this->contact('Lupita', attrs: ['country' => 'MX', 'timezone' => 'America/Mexico_City']);
    $night = $this->contact('Noctámbula', attrs: ['preferred_hour' => 22]);
    $afternoon = $this->contact('Tarde', attrs: ['preferred_hour' => 15]);
    $segment = $this->segmentOf('Todos', [$bogota, $mexico, $night, $afternoon]);

    $tz = $this->readyCampaign([$segment], ['send_mode' => 'contact_timezone', 'send_at' => campaignBogota('2026-11-24 10:00:00')->toIso8601String()]);
    $this->actingAs($this->owner)->postJson($this->api("/{$tz->publicId()}/schedule"))->assertOk();
    $best = $this->readyCampaign([$segment], ['send_mode' => 'best_time', 'send_at' => campaignBogota('2026-11-24 10:00:00')->toIso8601String()]);
    $this->actingAs($this->owner)->postJson($this->api("/{$best->publicId()}/schedule"))->assertOk();
    campaignTick($this);   // faltan menos de 26 h: se materializan

    $local = fn (CampaignRecipient $r, string $zone) => $r->scheduled_for->setTimezone($zone)->format('Y-m-d H:i');
    $tzR = campaignRecipients($this, $tz);
    expect($local($tzR[$bogota->id], 'America/Bogota'))->toBe('2026-11-24 10:00')
        ->and($local($tzR[$mexico->id], 'America/Mexico_City'))->toBe('2026-11-24 10:00');
    $bestR = campaignRecipients($this, $best);
    // Prefiere las 22:00, fuera de la ventana: pasa al día siguiente a las 7:00. Sin hora preferida, la de la campaña.
    expect($local($bestR[$afternoon->id], 'America/Bogota'))->toBe('2026-11-24 15:00')
        ->and($local($bestR[$night->id], 'America/Bogota'))->toBe('2026-11-25 07:00')
        ->and($local($bestR[$bogota->id], 'America/Bogota'))->toBe('2026-11-24 10:00');
    expect($this->fake->dispatched)->toBeEmpty();
});

it('reenviar el lote no duplica mensajes, lo aplazado se reintenta en su hora y lo negado queda con su motivo', function () {
    $this->travelTo(campaignBogota('2026-11-24 09:00:00'));   // martes
    [$ana, $luis, $marta] = [$this->contact('Ana'), $this->contact('Luis'), $this->contact('Marta')];
    $campaign = $this->readyCampaign([$this->segmentOf('VIP', [$ana, $luis, $marta])]);
    $until = campaignBogota('2026-11-25 07:00:00');
    $this->fake->respondWith(fn (OutboundMessage $m) => match ($m->contactId) {
        $luis->id => DispatchResult::deferred($until->utc(), 'frequency_day'),
        $marta->id => DispatchResult::denied('no_consent'),
        default => null,
    });
    $this->actingAs($this->owner)->postJson($this->api("/{$campaign->publicId()}/schedule"))->assertOk();
    campaignTick($this);
    campaignTick($this);
    $sender = app(CampaignSender::class);
    $this->inWs(fn () => $sender->sendDue($this->ws, $campaign->fresh()));

    $r = campaignRecipients($this, $campaign);
    expect($this->fake->dispatched)->toHaveCount(1)
        ->and($r[$ana->id]->status)->toBe('sent')
        ->and($r[$luis->id]->status)->toBe('deferred')->and($r[$luis->id]->reason)->toBe('frequency_day')
        ->and($r[$luis->id]->scheduled_for->equalTo($until))->toBeTrue()
        ->and($r[$marta->id]->status)->toBe('denied')->and($r[$marta->id]->reason)->toBe('no_consent')
        ->and($campaign->fresh()->status)->toBe('sending');

    // Aunque un destinatario volviera a «pendiente», la clave de idempotencia evita el segundo envío.
    DB::table('campaign_recipients')->where('contact_id', $ana->id)->update(['status' => 'pending']);
    $this->inWs(fn () => $sender->sendDue($this->ws, $campaign->fresh()));
    expect($this->fake->dispatched)->toHaveCount(1)->and(campaignRecipients($this, $campaign)[$ana->id]->status)->toBe('sent');

    $this->fake->respondWith(null);
    $this->travelTo($until->addMinute());
    campaignTick($this);
    expect($this->fake->dispatched)->toHaveCount(2)
        ->and(campaignRecipients($this, $campaign)[$luis->id]->status)->toBe('sent')
        ->and($campaign->fresh()->status)->toBe('sent');
});

it('el grupo de control nunca recibe mensajes y el reporte mide el incremento con intervalo de confianza', function () {
    $this->travelTo(campaignBogota('2026-11-24 09:00:00'));
    $contacts = [];
    for ($i = 0; $i < 40; $i++) {
        $contacts[] = $this->contact('C'.$i);
    }
    $campaign = $this->readyCampaign([$this->segmentOf('Todos', $contacts)], ['holdout_pct' => 30]);
    $this->actingAs($this->owner)->postJson($this->api("/{$campaign->publicId()}/schedule"))->assertOk();
    campaignTick($this);

    $recipients = collect(campaignRecipients($this, $campaign));
    $holdout = $recipients->where('is_holdout', true);
    expect($holdout->count())->toBeGreaterThan(3)->toBeLessThan(25)
        ->and($holdout->every(fn ($r) => $r->status === 'skipped' && $r->reason === 'holdout'))->toBeTrue()
        ->and(count($this->fake->dispatched))->toBe(40 - $holdout->count())
        ->and(collect($this->fake->dispatched)->pluck('contactId')->intersect($holdout->keys())->all())->toBe([]);
    // El sorteo es determinista: materializar de nuevo no cambia nada.
    $this->inWs(fn () => app(CampaignSender::class)->materialize($this->ws, $campaign->fresh()));
    expect(collect(campaignRecipients($this, $campaign))->where('is_holdout', true)->keys()->all())->toBe($holdout->keys()->all());

    // La mitad de los que recibieron compra en la ventana; nadie del control.
    foreach ($recipients->where('is_holdout', false)->take(15) as $r) {
        DB::table('orders')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => 'S1', 'external_id' => (string) $r->id, 'contact_id' => $r->contact_id, 'status' => 'processing', 'is_paid' => true, 'currency' => 'COP', 'total' => 50000, 'total_ws' => 50000, 'attributed_campaign_id' => $campaign->id, 'created_at' => now()->addDay(), 'updated_at' => now()]);
    }
    $report = $this->inWs(fn () => app(CampaignReport::class)->handle($this->ws, $campaign->fresh()));
    expect($report['recipients']['holdout'])->toBe($holdout->count())
        ->and($report['revenue'])->toBe(['orders' => 15, 'total' => 750000.0])
        ->and($report['uplift']['control']['rate'])->toBe(0.0)
        ->and($report['uplift']['lift'])->toBeGreaterThan(0.3)
        ->and($report['uplift']['significant'])->toBeTrue()
        ->and($report['uplift']['incremental_revenue'])->toBe(750000.0);
});

it('A/B: la muestra recibe las variantes y, pasadas las horas de espera, el resto recibe la ganadora por clics', function () {
    $this->travelTo(campaignBogota('2026-11-24 09:00:00'));
    $contacts = [];
    for ($i = 0; $i < 60; $i++) {
        $contacts[] = $this->contact('C'.$i);
    }
    $campaign = $this->readyCampaign([$this->segmentOf('Todos', $contacts)]);
    [$a, $b] = [$this->template('whatsapp', 'A'), $this->template('whatsapp', 'B')];
    $this->actingAs($this->owner)->patchJson($this->api("/{$campaign->publicId()}"), [
        'variants' => [['key' => 'A', 'template_id' => $a], ['key' => 'B', 'template_id' => $b]],
        'ab_config' => ['sample_pct' => 50, 'metric' => 'clicks', 'wait_hours' => 2],
    ])->assertOk();
    $this->actingAs($this->owner)->postJson($this->api("/{$campaign->publicId()}/schedule"))->assertOk();
    campaignTick($this);

    $recipients = collect(campaignRecipients($this, $campaign));
    $sample = $recipients->where('status', 'sent');
    $held = $recipients->where('status', 'held');
    expect($sample->count() + $held->count())->toBe(60)
        ->and($sample->pluck('variant')->unique()->sort()->values()->all())->toBe(['A', 'B'])
        ->and($held->every(fn ($r) => $r->variant === null))->toBeTrue()
        ->and(count($this->fake->dispatched))->toBe($sample->count());
    // Los mensajes de la variante B reciben clics.
    foreach ($sample as $r) {
        DB::table('messages')->insert(['id' => $r->message_id, 'workspace_id' => $this->ws->id, 'contact_id' => $r->contact_id, 'channel' => 'whatsapp', 'direction' => 'out', 'kind' => 'marketing', 'source_type' => 'campaign', 'campaign_id' => $campaign->id, 'status' => 'delivered', 'clicked_at' => $r->variant === 'B' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->travelTo(campaignBogota('2026-11-24 10:30:00'));   // antes de las 2 h: aún no
    campaignTick($this);
    expect($campaign->fresh()->winner_variant)->toBeNull();
    $this->travelTo(campaignBogota('2026-11-24 11:01:00'));
    campaignTick($this);
    $fresh = $campaign->fresh();
    $rest = collect(campaignRecipients($this, $campaign))->only($held->keys()->all());
    expect($fresh->winner_variant)->toBe('B')
        ->and($fresh->stats['ab']['scores']['B']['click_rate'])->toEqual(1)
        ->and($rest->every(fn ($r) => $r->status === 'sent' && $r->variant === 'B'))->toBeTrue()
        ->and(count($this->fake->dispatched))->toBe(60)
        ->and(collect($this->fake->dispatched)->slice($sample->count())->every(fn (OutboundMessage $m) => $m->templateId === $fresh->templateFor('B')))->toBeTrue()
        ->and($fresh->status)->toBe('sent');
});

it('pausar detiene los envíos, reanudar los retoma y cancelar descarta lo pendiente', function () {
    $this->travelTo(campaignBogota('2026-11-21 16:00:00'));   // sábado tarde: todo queda para el lunes
    $contacts = [$this->contact('Ana'), $this->contact('Luis')];
    $campaign = $this->readyCampaign([$this->segmentOf('VIP', $contacts)]);
    $id = $campaign->publicId();
    $this->actingAs($this->owner)->postJson($this->api("/$id/schedule"))->assertOk()->assertJsonPath('status', 'sending');
    $this->actingAs($this->owner)->postJson($this->api("/$id/pause"))->assertOk()->assertJsonPath('status', 'paused');
    $this->travelTo(campaignBogota('2026-11-23 08:00:00'));
    campaignTick($this);
    expect($this->fake->dispatched)->toBeEmpty();
    $this->actingAs($this->owner)->postJson($this->api("/$id/resume"))->assertOk()->assertJsonPath('status', 'sending');
    $this->actingAs($this->owner)->postJson($this->api("/$id/cancel"))->assertOk()->assertJsonPath('status', 'cancelled');
    campaignTick($this);
    expect($this->fake->dispatched)->toBeEmpty()
        ->and(collect(campaignRecipients($this, $campaign))->every(fn ($r) => $r->status === 'skipped' && $r->reason === 'cancelled'))->toBeTrue();
    $this->actingAs($this->owner)->postJson($this->api("/$id/resume"))->assertUnprocessable();
    expect(DB::table('audit_logs')->whereIn('action', ['campaign.paused', 'campaign.resumed', 'campaign.cancelled'])->count())->toBe(3);
});
