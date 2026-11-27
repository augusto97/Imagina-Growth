<?php

use App\Domain\Bookings\Jobs\SyncAppointmentCalendar;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Support\AppointmentNoticeLog;
use App\Domain\Bookings\Support\BookingNoticeSetup;
use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Jobs\SendMessageJob;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Message;
use App\Domain\Notices\Mail\PlatformTransactionalMail;
use App\Domain\Notices\Mail\TeamNoticeMail;
use App\Domain\Notices\Models\Notice;
use App\Domain\Notices\Models\NoticeLog;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Bookings/BookingFixtures.php';

/*
 * Avisos de las citas (lote 0.4.0, docs/avisos.md): al cliente con el correo de la plataforma como respaldo, lo que no
 * salió y por qué, y al equipo por el centro de avisos y el correo de la plataforma.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    Queue::fake([SyncAppointmentCalendar::class]);
    Mail::fake();
    config(['mail.default' => 'array', 'mail.from.address' => 'no-responder@imagina.test', 'mail.from.name' => 'Imagina Growth']);
    $this->travelTo(CarbonImmutable::parse('2026-11-30 07:00', 'America/Bogota'));
    $this->owner = $this->userWith2fa(['name' => 'Dueña', 'email' => 'duena@clinica.co']);
    $this->ws = $this->workspaceFor($this->owner, 'Clínica Sonrisa');
    $this->ws->forceFill(['timezone' => 'America/Bogota', 'country' => 'CO'])->save();
    [$this->site] = $this->connectedSite($this->ws);
    bookingFakeLeads();
    bookingService($this->ws);
    bookingHours($this->ws);
    $this->book = function (array $data = []) {
        $pk = $this->site->public_key;
        $res = $this->post("/p/{$pk}/reservar/valoracion", $data + ['start' => '2026-11-30T15:00:00Z', 'name' => 'Ana Ruiz', 'phone' => '300 123 4567']);
        $res->assertStatus(303);

        return [(string) $res->headers->get('Location'), bookingIn($this->ws, fn () => Appointment::query()->latest('created_at')->firstOrFail())];
    };
});

function noticeLogFor(object $test, Appointment $a, string $audience): array
{
    return bookingIn($test->ws, fn () => NoticeLog::query()->where('subject_id', $a->id)->where('audience', $audience)->orderBy('created_at')->get()
        ->map(fn (NoticeLog $l) => [$l->event, $l->channel, $l->status, $l->reason])->all());
}

it('sin correo del negocio, la confirmación sale por el correo de la plataforma con el nombre del negocio y respuestas al dueño', function () {
    [$location, $appointment] = ($this->book)(['email' => 'ana@correo.co']);

    $message = bookingIn($this->ws, fn () => Message::query()->where('idempotency_key', 'like', 'appt:%:confirmation:%:email')->firstOrFail());
    expect($message->provider)->toBe('platform_mail')->and($message->channel_account_id)->toBeNull()
        ->and($message->status)->toBe('sent')->and($message->kind->value)->toBe('transactional');
    Mail::assertSent(PlatformTransactionalMail::class, fn (PlatformTransactionalMail $m) => $m->hasTo('ana@correo.co')
        && $m->fromName === 'Clínica Sonrisa' && $m->replyToAddress === 'duena@clinica.co' && str_contains($m->subjectLine, 'Valoración inicial'));

    expect(noticeLogFor($this, $appointment, 'customer'))->toBe([['confirmation', 'email', 'sent', null]])
        ->and(bookingIn($this->ws, fn () => AppointmentNoticeLog::deliveredVia($appointment, ['confirmation'])))->toBe('email');
    $this->get($location)->assertOk()->assertSee('Te enviamos los detalles por correo')->assertDontSee('por WhatsApp o correo');
});

it('si no hay por dónde avisar al cliente lo deja dicho y la página no promete un mensaje', function () {
    [$location, $appointment] = ($this->book)();

    expect(noticeLogFor($this, $appointment, 'customer'))->toBe([
        ['confirmation', 'whatsapp', 'skipped', 'no_whatsapp_channel'],
        ['confirmation', 'email', 'skipped', 'no_email'],
    ]);
    $this->get($location)->assertOk()->assertSee('Guarda este enlace')->assertDontSee('Te enviamos');
    // Al negocio sí le llega: el centro de avisos y el correo de la plataforma.
    $notice = bookingIn($this->ws, fn () => Notice::query()->where('user_id', $this->owner->id)->firstOrFail());
    expect($notice->kind)->toBe('appointment.booked')->and($notice->title)->toBe('Nueva cita: Valoración inicial')
        ->and($notice->body)->toContain('Ana Ruiz')->toContain('en la página de reservas')
        ->and($notice->url)->toContain('/agenda?fecha=2026-11-30&cita='.$appointment->publicId());
    Mail::assertSent(TeamNoticeMail::class, fn (TeamNoticeMail $m) => $m->hasTo('duena@clinica.co') && $m->title === 'Nueva cita: Valoración inicial');
    expect(noticeLogFor($this, $appointment, 'team'))->toBe([['booked', 'in_app', 'sent', null], ['booked', 'email', 'sent', null]]);
});

it('con el correo propio conectado usa esa cuenta y no el de la plataforma', function () {
    Queue::fake([SyncAppointmentCalendar::class, SendMessageJob::class]);
    bookingIn($this->ws, fn () => ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'email', 'provider' => 'smtp',
        'display_name' => 'Correo', 'identity' => 'hola@clinica.co', 'status' => 'active', 'is_default' => true, 'config' => ['host' => 'smtp.clinica.co']]));
    [, $appointment] = ($this->book)(['email' => 'ana@correo.co']);

    $message = bookingIn($this->ws, fn () => Message::query()->where('idempotency_key', 'like', 'appt:%:confirmation:%:email')->firstOrFail());
    expect($message->provider)->toBe('smtp')->and(noticeLogFor($this, $appointment, 'customer'))->toBe([['confirmation', 'email', 'queued', null]]);
    Mail::assertNotSent(PlatformTransactionalMail::class);
});

it('un plan sin correo por la plataforma no usa el respaldo y dice por qué', function () {
    $contact = bookingContact($this->ws);
    config(['igr-plans.default_plan' => 'diagnostico']);
    $result = bookingIn($this->ws, fn () => app(MessageDispatcher::class)->dispatch(new OutboundMessage(
        workspaceId: $this->ws->id, contactId: $contact->id, channel: Channel::Email, kind: MessageKind::Transactional, sourceType: 'system',
        context: ['subject' => 'Tu cita', 'text' => 'Hola'], idempotencyKey: 'appt:x:confirmation:1:email',
    )));
    expect($result->status)->toBe('denied')->and($result->reason)->toBe('channel_unavailable')
        ->and(bookingIn($this->ws, fn () => BookingNoticeSetup::for($this->ws))['customer']['email'])->toBe('plan');
});

it('el respaldo nunca lleva marketing', function () {
    $contact = bookingContact($this->ws);
    $result = bookingIn($this->ws, fn () => app(MessageDispatcher::class)->dispatch(new OutboundMessage(
        workspaceId: $this->ws->id, contactId: $contact->id, channel: Channel::Email, kind: MessageKind::Marketing, sourceType: 'campaign',
        context: ['subject' => 'Promo', 'text' => 'Hola'], idempotencyKey: 'promo:1',
    )));
    expect($result->status)->toBe('denied')->and($result->reason)->toBe('channel_unavailable');
});

it('explica por qué no salió el WhatsApp según cómo está el negocio', function () {
    $contact = bookingContact($this->ws);
    $noPhone = bookingContact($this->ws, ['phone' => null, 'email' => 'otro@correo.co']);
    $reason = fn (?Contact $c) => bookingIn($this->ws, fn () => AppointmentNoticeLog::whatsappReason($this->ws, $c, 'confirmation'));
    expect($reason($noPhone))->toBe('no_phone')->and($reason($contact))->toBe('no_whatsapp_channel');

    $account = bookingIn($this->ws, fn () => ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud',
        'display_name' => 'WhatsApp', 'identity' => '+57 300 000 0000', 'status' => 'active', 'is_default' => true]));
    expect($reason($contact))->toBe('no_whatsapp_template')
        ->and(bookingIn($this->ws, fn () => BookingNoticeSetup::for($this->ws))['customer']['whatsapp'])->toBe('no_template');
    bookingIn($this->ws, fn () => BookingSettings::save($this->ws, ['whatsapp_templates' => ['confirmation' => 'tpl_01JD000000000000000000000X']]));
    expect($reason($contact))->toBe('whatsapp_not_sent')
        ->and(bookingIn($this->ws, fn () => BookingNoticeSetup::for($this->ws))['customer']['whatsapp'])->toBe('ready');
    $account->forceFill(['provider' => 'imagina_whats'])->save();
    expect(bookingIn($this->ws, fn () => BookingNoticeSetup::for($this->ws))['customer']['whatsapp'])->toBe('unofficial');
});

it('«Así avisamos» dice qué falta y a quién del equipo le llega', function () {
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    bookingStaff($this->ws, ['user_id' => $agent->id, 'name' => 'Laura Gómez']);
    bookingStaff($this->ws, ['name' => 'Pedro sin usuario', 'email' => 'pedro@clinica.co']);
    config(['mail.from.address' => 'hello@example.com']);

    $setup = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/bookings/notices/setup")->assertOk()->json();
    expect($setup['customer']['whatsapp'])->toBe('none')->and($setup['customer']['email'])->toBe('platform_off')
        ->and($setup['fixes'])->toContain('nothing_reaches')->toContain('connect_whatsapp')->toContain('platform_mail')
        ->and(collect($setup['team']['people'])->pluck('name')->all())->toContain('Dueña')->toContain($agent->name)->toContain('Pedro sin usuario');

    config(['mail.from.address' => 'no-responder@imagina.test']);
    $setup = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/bookings/notices/setup")->assertOk()->json();
    expect($setup['customer']['email'])->toBe('platform')->and($setup['customer']['reply_to'])->toBe('duena@clinica.co')
        ->and($setup['fixes'])->toContain('require_email')->not->toContain('nothing_reaches');
});

it('avisa a quien atiende la cita aunque no sea dueño, a su correo si no usa el panel, y no a quien la agendó desde el panel', function () {
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    $admin = $this->addMember($this->ws, WorkspaceRole::Admin, true);
    $laura = bookingStaff($this->ws, ['user_id' => $agent->id, 'name' => 'Laura Gómez']);
    bookingHours($this->ws, $laura->id);
    $contact = bookingContact($this->ws);
    $service = bookingIn($this->ws, fn () => BookingService::query()->firstOrFail());

    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/bookings/appointments", [
        'contact_id' => $contact->publicId(), 'service_id' => $service->publicId(), 'start' => '2026-11-30T15:00:00Z', 'staff_id' => $laura->publicId(),
    ])->assertCreated();

    $who = bookingIn($this->ws, fn () => Notice::query()->pluck('user_id')->all());
    expect($who)->toContain($agent->id)->toContain($admin->id)->not->toContain($this->owner->id);

    // Pedro no usa el panel: le llega por correo cuando atiende él.
    $pedro = bookingStaff($this->ws, ['name' => 'Pedro', 'email' => 'pedro@clinica.co']);
    bookingHours($this->ws, $pedro->id);
    ($this->book)(['persona' => $pedro->publicId(), 'start' => '2026-11-30T16:00:00Z']);
    Mail::assertSent(TeamNoticeMail::class, fn (TeamNoticeMail $m) => $m->hasTo('pedro@clinica.co'));
});

it('respeta «Mis avisos»: sin el centro de avisos ni el correo no le llega nada', function () {
    $this->actingAs($this->owner)->putJson("/api/w/{$this->ws->publicId()}/notices/preferences", [
        'settings' => ['appointments' => ['in_app' => false, 'email' => false]],
    ])->assertOk()->assertJsonPath('settings.appointments.in_app', false)->assertJsonPath('settings.appointments.scope', 'all');
    ($this->book)();

    expect(bookingIn($this->ws, fn () => Notice::query()->count()))->toBe(0);
    Mail::assertNotSent(TeamNoticeMail::class);
});

it('avisa al equipo cuando el cliente cancela o mueve la cita desde su enlace', function () {
    [$location] = ($this->book)();
    $this->post(strtok($location, '?').'/cancelar')->assertStatus(303);

    $kinds = bookingIn($this->ws, fn () => Notice::query()->where('user_id', $this->owner->id)->orderBy('created_at')->pluck('kind')->all());
    expect($kinds)->toBe(['appointment.booked', 'appointment.cancelled']);
    $cancelled = bookingIn($this->ws, fn () => Notice::query()->where('kind', 'appointment.cancelled')->firstOrFail());
    expect($cancelled->body)->toContain('Ana Ruiz canceló su cita');
});

it('la sección «Avisos» de la cita junta lo del cliente y lo del equipo con el estado del mensaje', function () {
    [, $appointment] = ($this->book)(['email' => 'ana@correo.co']);

    $rows = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/bookings/appointments/{$appointment->publicId()}/notices")->assertOk()->json('data');
    expect(collect($rows)->map(fn ($r) => [$r['audience'], $r['channel'], $r['status']])->all())->toBe([
        ['customer', 'email', 'sent'], ['team', 'in_app', 'sent'], ['team', 'email', 'sent'],
    ])->and($rows[0]['recipient'])->toBe('a**@c*****.co')->and($rows[1]['recipient'])->toBe('Dueña');

    // Un mensaje que después falla se ve como fallido, con el motivo.
    bookingIn($this->ws, fn () => Message::query()->where('provider', 'platform_mail')->update(['status' => 'failed', 'error_code' => 'platform_mail', 'failed_reason' => 'Buzón lleno']));
    $rows = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/bookings/appointments/{$appointment->publicId()}/notices")->assertOk()->json('data');
    expect($rows[0])->toMatchArray(['status' => 'failed', 'reason' => 'platform_mail', 'detail' => 'Buzón lleno']);
});
