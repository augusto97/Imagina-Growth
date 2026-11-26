<?php

use App\Domain\Flows\Library\FlowLibrary;
use App\Domain\Flows\Library\LibraryCatalog;
use App\Domain\Flows\Models\Flow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Flows\Support\FlowFixtures;

/*
 * Mensajes → Automatizaciones (rediseño v2, frente de Mensajes): la galería de «Nueva automatización» por objetivo y tipo
 * de negocio, la vista previa del recorrido con sus mensajes y el resultado del mes de cada automatización en la lista.
 */

uses(FlowFixtures::class);

beforeEach(function () {
    $this->flowWorld();
    $this->fakeCoupons();
});

it('cada flujo de la biblioteca tiene objetivo y audiencia, y los de una estrategia la enlazan', function () {
    foreach ([...FlowLibrary::all(), ...FlowLibrary::extras()] as $flow) {
        expect(LibraryCatalog::GOALS)->toContain(LibraryCatalog::goal($flow['key']))
            ->and(['store', 'services', 'both'])->toContain(LibraryCatalog::audience($flow['key']));
    }
    $library = collect($this->actingAs($this->owner)->getJson($this->api('/library'))->assertOk()->json('data'))->keyBy('key');
    expect($library['abandoned_cart'])->toMatchArray(['goal' => 'recover_sales', 'audience' => 'store', 'strategy' => 'abandoned_cart', 'channels' => ['whatsapp', 'email'], 'messages' => 4])
        ->and($library['service_lead'])->toMatchArray(['goal' => 'convert_leads', 'audience' => 'services', 'strategy' => 'service_lead'])
        ->and($library['appointment_confirmation']['goal'])->toBe('appointments')
        ->and($library['property_interest']['verticals'])->toBe(['real_estate'])
        // Los que apoyan una estrategia de varios bloques llevan a ella (VIP → Puntos y niveles).
        ->and($library['vip_welcome']['strategy'])->toBe('loyalty_points')
        ->and($library['post_purchase']['strategy'])->toBeNull()
        ->and($library['abandoned_cart']['goal_event'])->toBe('order.paid');

    // La vertical ordena: una clínica ve primero la confirmación de citas.
    $this->ws->forceFill(['vertical' => 'health'])->save();
    $health = collect($this->actingAs($this->owner)->getJson($this->api('/library'))->json('data'))->keyBy('key');
    expect($health['appointment_confirmation']['rank'])->toBe(1)->and($health['abandoned_cart']['rank'])->toBeNull();
});

it('muestra el recorrido y los mensajes de un flujo listo antes de crearlo', function () {
    $res = $this->actingAs($this->owner)->getJson($this->api('/library/appointment_confirmation'))->assertOk();
    $messages = collect($res->json('messages'))->keyBy('template_key');
    expect($res->json('graph.nodes'))->not->toBeEmpty()
        ->and($res->json('trigger.event'))->toBe('appointment.booked')
        ->and($messages['appointment_confirm']['channel'])->toBe('email')
        ->and($messages['appointment_confirm']['subject'])->not->toContain('{{')
        ->and($messages['wa_appointment_confirm']['buttons'])->toBe(['Confirmo', 'Reprogramar'])
        ->and($messages['wa_appointment_confirm']['text'])->toContain('cita')->not->toContain('{{')
        ->and($messages['wa_appointment_confirm']['kind'])->toBe('utility');

    // Sin WhatsApp conectado el recorrido ya viene sin sus pasos.
    DB::table('channel_accounts')->where('workspace_id', $this->ws->id)->where('channel', 'whatsapp')->update(['status' => 'disconnected']);
    $email = $this->actingAs($this->owner)->getJson($this->api('/library/appointment_confirmation'))->assertOk();
    expect(collect($email->json('messages'))->pluck('channel')->unique()->values()->all())->toBe(['email'])->and($email->json('whatsapp_connected'))->toBeFalse();

    $this->actingAs($this->owner)->getJson($this->api('/library/no_existe'))->assertNotFound();
    // Los que hace su módulo no tienen recorrido propio.
    expect($this->actingAs($this->owner)->getJson($this->api('/library/nps'))->assertOk()->json('graph'))->toBeNull();
});

it('la lista dice el resultado del mes de cada automatización y su grupo de control', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'America/Bogota'));
    $id = $this->actingAs($this->owner)->postJson($this->api('/library/abandoned_cart'), ['activate' => true])->assertCreated()->json('id');
    $flow = Flow::findByPublicId($id);
    $run = fn (bool $holdout, ?string $exit) => DB::table('flow_runs')->insert([
        'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'flow_id' => $flow->id, 'flow_version_id' => $flow->current_version_id,
        'contact_id' => $this->contact->id, 'status' => $exit === null ? 'waiting' : 'completed', 'is_holdout' => $holdout, 'exit_reason' => $exit,
        'trigger_key' => 'event:'.Str::ulid(), 'context' => '{}', 'started_at' => now()->subDays(3), 'ended_at' => $exit === null ? null : now()->subDay(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $run(false, 'goal_reached');
    $run(false, 'goal_reached');
    $run(false, null);
    $run(true, null);
    DB::table('orders')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'external_id' => '77', 'contact_id' => $this->contact->id,
        'status' => 'processing', 'is_paid' => true, 'paid_at' => now()->subDay(), 'currency' => 'COP', 'total' => 180000, 'total_ws' => 180000,
        'attributed_flow_id' => $flow->id, 'created_at' => now()->subDay(), 'updated_at' => now()]);

    $res = $this->actingAs($this->owner)->getJson($this->api())->assertOk();
    $row = collect($res->json('data'))->firstWhere('id', $id);
    expect($row['month'])->toMatchArray(['revenue' => '180000.00', 'orders' => 1, 'conversions' => 2])
        ->and($row['month']['control'])->toMatchArray(['treatment_n' => 3, 'holdout_n' => 1, 'enough' => false, 'difference' => null])
        ->and($row['strategy'])->toBe('abandoned_cart')
        ->and($res->json('meta.month'))->toBe(['from' => '2026-11-01', 'to' => '2026-11-30'])
        ->and($res->json('meta.currency'))->toBe('COP');
});
