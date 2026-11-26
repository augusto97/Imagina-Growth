<?php

require_once __DIR__.'/../Leads/LeadsHelpers.php';
require_once __DIR__.'/../Bookings/BookingFixtures.php';

use App\Domain\Bookings\Models\Appointment;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Segments\Models\Segment;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Clientes → Personas (modulos.md M03): filtros por grupo y temperatura, canales con consentimiento, lead y próxima cita
 * en la fila, exportar a CSV y agregar a un grupo fijo, con el acceso del rol y sin salirse del espacio de trabajo.
 */

beforeEach(function () {
    leadsSetUp($this);
    $this->base = "/api/w/{$this->ws->publicId()}/contacts";
    $this->ana = leadsContact($this, ['orders_count' => 2, 'revenue_total_ws' => 500000, 'last_seen_at' => now()]);
    $this->luis = leadsContact($this, ['email' => 'luis@correo.co', 'first_name' => 'Luis', 'last_name' => 'Pérez', 'city' => 'Cali', 'last_seen_at' => now()->subDay()]);
    leadsRun($this, fn () => DB::table('contact_consents')->insert([
        'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => $this->ana->id, 'channel' => 'whatsapp',
        'purpose' => 'marketing', 'status' => 'granted', 'source' => 'admin', 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]));
});

it('cada fila trae los canales con consentimiento y, para servicios, el lead abierto y la próxima cita', function () {
    $lead = leadsRun($this, fn () => Lead::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $this->luis->id, 'stage' => 'contacted', 'origin' => 'landing',
        'score' => 80, 'temperature' => 'hot', 'value_ws' => '900000', 'service' => 'Diseño de sonrisa', 'score_reasons' => [], 'source' => ['channel' => 'paid_meta'], 'last_activity_at' => now()]));
    $service = bookingService($this->ws);
    leadsRun($this, fn () => Appointment::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $this->luis->id, 'service_id' => $service->id,
        'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour(), 'status' => 'confirmed', 'channel' => 'manual', 'manage_token' => Str::random(40)]));

    $rows = collect($this->actingAs($this->owner)->getJson($this->base.'?services=1')->assertOk()->json('data'))->keyBy('name');
    expect($rows['Ana Ruiz']['consents'])->toBe(['whatsapp'])
        ->and($rows['Ana Ruiz']['lead'])->toBeNull()
        ->and($rows['Luis Pérez']['lead']['id'])->toBe($lead->publicId())
        ->and($rows['Luis Pérez']['lead']['stage_label'])->toBe('Contactado')
        ->and($rows['Luis Pérez']['lead']['temperature'])->toBe('hot')
        ->and($rows['Luis Pérez']['lead']['answered'])->toBeFalse()
        ->and($rows['Luis Pérez']['next_appointment_at'])->not->toBeNull();
    // Sin `services`, la fila de una tienda no carga leads ni citas.
    expect($this->actingAs($this->owner)->getJson($this->base)->json('data.0'))->not->toHaveKey('lead');

    $names = fn (string $q) => collect($this->actingAs($this->owner)->getJson($this->base.'?'.$q)->assertOk()->json('data'))->pluck('name')->all();
    expect($names('temperature=hot'))->toBe(['Luis Pérez'])
        ->and($names('temperature=cold'))->toBe([])
        ->and($names('consent=whatsapp'))->toBe(['Ana Ruiz'])
        ->and($names('sort=revenue'))->toBe(['Ana Ruiz', 'Luis Pérez']);
});

it('filtra por grupo de clientes y un grupo ajeno deja la lista vacía', function () {
    $segment = leadsRun($this, fn () => Segment::query()->create(['workspace_id' => $this->ws->id, 'name' => 'VIP', 'kind' => Segment::KIND_STATIC, 'is_system' => false]));
    DB::table('segment_memberships')->insert(['segment_id' => $segment->id, 'contact_id' => $this->luis->id, 'workspace_id' => $this->ws->id, 'entered_at' => now()]);
    $this->actingAs($this->owner)->getJson($this->base.'?segment='.$segment->publicId())->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Luis Pérez');

    $other = $this->workspaceFor($owner2 = $this->userWith2fa(), 'Otra');
    $theirs = app(CurrentWorkspace::class)->run($other, fn () => Segment::query()->create(['workspace_id' => $other->id, 'name' => 'Ajeno', 'kind' => Segment::KIND_STATIC, 'is_system' => false]));
    $this->actingAs($this->owner)->getJson($this->base.'?segment='.$theirs->publicId())->assertOk()->assertJsonPath('meta.total', 0);
});

it('agrega las personas elegidas a un grupo nuevo o a uno fijo existente, nunca a uno con condiciones', function () {
    $res = $this->actingAs($this->owner)->postJson($this->base.'/group', ['ids' => [$this->ana->publicId(), $this->luis->publicId()], 'name' => 'Para llamar'])
        ->assertOk()->assertJsonPath('added', 2)->assertJsonPath('segment.member_count', 2)->assertJsonPath('segment.kind', 'static');
    $segmentId = $res->json('segment.id');

    // Otra vez con una que ya estaba: solo entra la nueva.
    $carla = leadsContact($this, ['email' => 'carla@correo.co', 'first_name' => 'Carla']);
    $this->actingAs($this->owner)->postJson($this->base.'/group', ['ids' => [$this->ana->publicId(), $carla->publicId()], 'segment_id' => $segmentId])
        ->assertOk()->assertJsonPath('added', 1)->assertJsonPath('segment.member_count', 3);

    // «Todas las que cumplen los filtros».
    $this->actingAs($this->owner)->postJson($this->base.'/group', ['all' => true, 'city' => 'Cali', 'name' => 'Cali'])->assertOk()->assertJsonPath('added', 1);

    $dynamic = leadsRun($this, fn () => Segment::query()->where('is_system', true)->first());
    $this->actingAs($this->owner)->postJson($this->base.'/group', ['ids' => [$this->ana->publicId()], 'segment_id' => $dynamic?->publicId()])->assertUnprocessable()->assertJsonValidationErrors('segment_id');
    $this->actingAs($this->owner)->postJson($this->base.'/group', ['ids' => ['ct_NOEXISTE'], 'name' => 'Nadie'])->assertUnprocessable()->assertJsonValidationErrors('ids');
    expect(DB::table('audit_logs')->where('workspace_id', $this->ws->id)->where('action', 'segment.members_added')->count())->toBe(1);

    // Ni el lector ni el agente arman grupos.
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->postJson($this->base.'/group', ['ids' => [$this->ana->publicId()], 'name' => 'X'])->assertForbidden();
});

it('exporta a CSV las elegidas o las que cumplen los filtros, con auditoría y solo con permiso', function () {
    $csv = $this->actingAs($this->owner)->get($this->base.'/export?all=1&consent=whatsapp')->assertOk()->streamedContent();
    expect($csv)->toContain('ana@ruizabogados.co')->not->toContain('luis@correo.co');
    $csv = $this->actingAs($this->owner)->get($this->base.'/export?ids[]='.$this->luis->publicId())->assertOk()->streamedContent();
    expect($csv)->toContain('luis@correo.co')->not->toContain('ana@ruizabogados.co');
    expect(DB::table('audit_logs')->where('workspace_id', $this->ws->id)->where('action', 'contacts.exported')->count())->toBe(2);

    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $this->actingAs($marketing)->get($this->base.'/export?all=1')->assertForbidden();
});

it('lo de servicios de una persona: leads con su etapa, citas (próximas primero), cotizaciones y totales', function () {
    leadsRun($this, fn () => Lead::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $this->ana->id, 'stage' => 'won', 'origin' => 'booking',
        'score' => 60, 'temperature' => 'warm', 'value_ws' => '1200000', 'won_at' => now()->subDays(3), 'score_reasons' => [], 'source' => [], 'last_activity_at' => now()]));
    $service = bookingService($this->ws);
    foreach ([[-10, 'completed'], [-5, 'no_show'], [3, 'booked']] as [$days, $status]) {
        leadsRun($this, fn () => Appointment::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $this->ana->id, 'service_id' => $service->id,
            'starts_at' => now()->addDays($days), 'ends_at' => now()->addDays($days)->addHour(), 'status' => $status, 'channel' => 'page', 'manage_token' => Str::random(40)]));
    }

    $res = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/services/contacts/{$this->ana->publicId()}")->assertOk()
        ->assertJsonPath('leads.0.stage_label', 'Ganado')->assertJsonPath('leads.0.stage_kind', 'won')
        ->assertJsonPath('appointments.0.status', 'booked')->assertJsonPath('appointments.0.service', 'Valoración inicial')
        ->assertJsonPath('totals.won_value', '1200000.00')->assertJsonPath('totals.appointments_completed', 1)
        ->assertJsonPath('totals.appointments_no_show', 1)->assertJsonPath('totals.appointments_upcoming', 1);
    expect($res->json('appointments'))->toHaveCount(3);

    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson("/api/w/{$this->ws->publicId()}/services/contacts/{$this->ana->publicId()}")->assertForbidden();
    // Una persona de otro espacio de trabajo no existe aquí.
    $other = $this->workspaceFor($this->userWith2fa(), 'Otra');
    $stranger = app(CurrentWorkspace::class)->run($other, fn () => Contact::query()->create(['workspace_id' => $other->id, 'email' => 'x@y.co',
        'email_bidx' => BlindIndex::email($other->id, 'x@y.co')]));
    $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/services/contacts/{$stranger->publicId()}")->assertNotFound();
});
