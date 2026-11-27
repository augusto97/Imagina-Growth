<?php

require_once __DIR__.'/../Leads/LeadsHelpers.php';

use App\Domain\Connections\Support\ConnectionAlerts;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Notices\Actions\NotifyTeam;
use App\Domain\Notices\Data\TeamNotice;
use App\Domain\Notices\Models\Notice;
use App\Domain\Notices\Models\NoticePreference;
use App\Domain\Notices\Realtime\NoticeCreated;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/*
 * Centro de avisos de la app y «Mis avisos» (lote 0.4.0, docs/avisos.md §2): cada persona ve solo lo suyo, en su
 * espacio de trabajo; leads, conexiones y conversaciones también llegan a la campana.
 */

beforeEach(function () {
    leadsSetUp($this);
    Mail::fake();
    $ai = FakeAiGateway::install();
    $ai->enabled = false;
    FakeMessageDispatcher::install();
    $this->agent = $this->addMember($this->ws, WorkspaceRole::Agent);
});

function noticesSend($test, TeamNotice $notice): int
{
    return leadsRun($test, fn () => app(NotifyTeam::class)->handle($test->ws, $notice));
}

function noticesSample(array $attrs = []): TeamNotice
{
    return TeamNotice::fromArray($attrs + ['kind' => 'conversation.assigned', 'group' => 'conversations', 'title' => 'Te asignaron una conversación',
        'body' => null, 'path' => 'bandeja/cnv_1', 'dedupe' => 'prueba:'.uniqid()]);
}

it('cada persona ve, cuenta y marca solo sus avisos, en tiempo real por su canal privado', function () {
    Event::fake([NoticeCreated::class]);
    expect(noticesSend($this, noticesSample(['userIds' => [$this->agent->id]])))->toBe(1);
    Event::assertDispatched(NoticeCreated::class, fn (NoticeCreated $e) => $e->broadcastOn()[0]->name === 'private-workspace.'.$this->ws->publicId().'.notices.'.$this->agent->publicId());

    $base = "/api/w/{$this->ws->publicId()}/notices";
    $mine = $this->actingAs($this->agent)->getJson($base)->assertOk();
    expect($mine->json('unread'))->toBe(1)->and($mine->json('data.0'))->toMatchArray(['kind' => 'conversation.assigned', 'title' => 'Te asignaron una conversación', 'read' => false])
        ->and($mine->json('data.0.url'))->toBe('/w/'.$this->ws->publicId().'/bandeja/cnv_1');
    // El dueño no ve los avisos del agente (las conversaciones son de quien las tiene asignadas).
    $this->actingAs($this->owner)->getJson($base)->assertOk()->assertJsonPath('unread', 0)->assertJsonCount(0, 'data');
    $this->actingAs($this->owner)->postJson($base.'/read', ['ids' => [$mine->json('data.0.id')]])->assertOk();
    expect($this->actingAs($this->agent)->getJson($base.'/unread')->json('unread'))->toBe(1);

    $this->actingAs($this->agent)->postJson($base.'/read', ['ids' => [$mine->json('data.0.id')]])->assertOk()->assertJsonPath('unread', 0);
    // Otro espacio de trabajo no existe para quien no es miembro.
    $other = $this->workspaceFor($this->userWith2fa(), 'Otro negocio');
    $this->actingAs($this->agent)->getJson("/api/w/{$other->publicId()}/notices")->assertNotFound();
});

it('el título y el detalle quedan cifrados en la base', function () {
    noticesSend($this, noticesSample(['userIds' => [$this->agent->id], 'title' => 'Nueva cita: Ana Ruiz', 'body' => 'Ana Ruiz agendó']));
    $raw = DB::table('notices')->first();
    expect($raw->title)->not->toContain('Ana')->and($raw->body)->not->toContain('Ana');
});

it('una persona recibe cada aviso una sola vez aunque el trabajo se repita', function () {
    $notice = noticesSample(['userIds' => [$this->agent->id], 'dedupe' => 'cita:1:booked']);
    noticesSend($this, $notice);
    noticesSend($this, $notice);
    expect(leadsRun($this, fn () => Notice::query()->where('user_id', $this->agent->id)->count()))->toBe(1);
});

it('un lead nuevo llega a la campana de los dueños aunque el aviso por WhatsApp o correo esté apagado', function () {
    $contact = leadsContact($this);
    leadsForm($this, $contact);
    $lead = leadsOne($this);

    $notice = leadsRun($this, fn () => Notice::query()->where('user_id', $this->owner->id)->where('kind', 'like', 'lead.%')->firstOrFail());
    expect($notice->title)->toStartWith('Lead ')->and($notice->url)->toEndWith('/leads?lead='.$lead->publicId());
    // El agente sin el lead asignado no lo recibe (solo lo suyo).
    expect(leadsRun($this, fn () => Notice::query()->where('user_id', $this->agent->id)->count()))->toBe(0);

    $rows = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/leads/{$lead->publicId()}/notices")->assertOk()->json('data');
    expect(collect($rows)->pluck('channel')->all())->toContain('in_app');
    $this->actingAs($this->agent)->getJson("/api/w/{$this->ws->publicId()}/leads/{$lead->publicId()}/notices")->assertNotFound();
});

it('una conexión que se cae avisa en la campana de dueños y administradores', function () {
    $account = leadsRun($this, fn () => ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud',
        'display_name' => 'WhatsApp', 'identity' => '+57 300 000 0000', 'status' => 'disconnected', 'is_default' => true]));
    leadsRun($this, fn () => app(ConnectionAlerts::class)->channelDisconnected($account));

    $kinds = leadsRun($this, fn () => Notice::query()->pluck('kind', 'user_id')->all());
    expect($kinds)->toHaveKey($this->owner->id)->not->toHaveKey($this->agent->id)
        ->and(array_values($kinds))->toBe(['connection.alert']);
});

it('«Mis avisos»: un agente solo puede recibir lo suyo y guarda su WhatsApp cifrado', function () {
    $base = "/api/w/{$this->ws->publicId()}/notices/preferences";
    $this->actingAs($this->agent)->getJson($base)->assertOk()
        ->assertJsonPath('can_choose_scope', false)->assertJsonPath('settings.appointments.scope', 'mine')
        ->assertJsonPath('settings.connections.in_app', false);
    $this->actingAs($this->agent)->putJson($base, ['settings' => ['appointments' => ['scope' => 'all', 'whatsapp' => true]], 'phone' => '+57 300 111 2233'])
        ->assertOk()->assertJsonPath('settings.appointments.scope', 'mine')->assertJsonPath('settings.appointments.whatsapp', true)
        ->assertJsonPath('phone', '+57 300 111 2233');
    $this->actingAs($this->agent)->putJson($base, ['phone' => 'llámame'])->assertUnprocessable()->assertJsonValidationErrors('phone');
    expect(DB::table('notice_preferences')->value('phone'))->not->toContain('300');
    expect(leadsRun($this, fn () => NoticePreference::query()->where('user_id', $this->agent->id)->firstOrFail()->phone))->toBe('+57 300 111 2233');

    // El dueño sí elige «de todo el negocio» o «solo lo mío».
    $this->actingAs($this->owner)->putJson($base, ['settings' => ['appointments' => ['scope' => 'mine']]])->assertOk()
        ->assertJsonPath('can_choose_scope', true)->assertJsonPath('settings.appointments.scope', 'mine');
});
