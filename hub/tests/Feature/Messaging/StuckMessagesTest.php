<?php

use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Jobs\SendMessageJob;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Support\SesClientFactory;
use App\Support\Tenancy\CurrentWorkspace;
use Aws\SesV2\SesV2Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Messaging\Support\MessagingFixtures;

/*
 * F9: los mensajes que se quedan en `sending` (el proceso murió durante la llamada al proveedor) se resuelven por
 * antigüedad: marketing → fallido con motivo (sin duplicar); el resto → un reintento y, si vuelve a quedarse, fallido.
 */
uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
});

function stuckEmail($test, MessageKind $kind, string $key, int $minutesAgo): Message
{
    $result = app(CurrentWorkspace::class)->run($test->ws, fn () => app(MessageDispatcher::class)->dispatch(new OutboundMessage(
        $test->ws->id, $test->contact->id, Channel::Email, $kind, 'flow', sourceId: 'fl_01', templateId: 'tpl_email', idempotencyKey: $key,
    )));
    expect($result->status)->toBe(DispatchResult::QUEUED);
    // El proceso murió después de marcarlo `sending` y antes de guardar la respuesta del proveedor.
    DB::table('messages')->where('id', $result->messageId)->update(['status' => 'sending', 'sent_at' => null, 'provider_message_id' => null, 'updated_at' => now()->subMinutes($minutesAgo)]);

    return Message::withoutWorkspaceScope()->findOrFail($result->messageId);
}

it('marketing trabado en sending queda fallido con el motivo, sin reintentar; uno reciente no se toca', function () {
    $old = stuckEmail($this, MessageKind::Marketing, 'run:R1:n1:1', 20);
    $recent = stuckEmail($this, MessageKind::Transactional, 'order:9:confirm', 3);   // el tope diario de marketing ya se usó
    Queue::fake();

    $this->artisan('igr:messages:stuck')->assertSuccessful();

    $old->refresh();
    expect($old->status)->toBe('failed')->and($old->error_code)->toBe('stuck_sending')->and($old->failed_reason)->toContain('no se reintenta')
        ->and($recent->refresh()->status)->toBe('sending');
    Queue::assertNotPushed(SendMessageJob::class);
    $failed = $this->chRows($this->ws, "SELECT toJSONString(properties) AS p FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'message.failed'");
    expect($failed)->toHaveCount(1)->and(json_decode($failed[0]['p'], true)['error_code'])->toBe('stuck_sending');
});

it('un transaccional trabado se reintenta una vez y, si se vuelve a trabar, queda fallido', function () {
    $message = stuckEmail($this, MessageKind::Transactional, 'order:1234:confirm', 30);
    Queue::fake();

    $this->artisan('igr:messages:stuck')->assertSuccessful();
    $message->refresh();
    expect($message->status)->toBe('queued')->and($message->payload['stuck_recoveries'])->toBe(1);
    Queue::assertPushedOn('messages:email', SendMessageJob::class, fn (SendMessageJob $job) => $job->messageId === $message->id);

    // El reintento también se trabó.
    DB::table('messages')->where('id', $message->id)->update(['status' => 'sending', 'updated_at' => now()->subMinutes(20)]);
    $this->artisan('igr:messages:stuck')->assertSuccessful();
    expect($message->refresh()->status)->toBe('failed')->and($message->error_code)->toBe('stuck_sending');
    Queue::assertPushed(SendMessageJob::class, 1);
});

it('una excepción del driver deja el mensaje fallido con el motivo, no en sending', function () {
    app()->instance(SesClientFactory::class, new class extends SesClientFactory
    {
        public function make(): SesV2Client
        {
            throw new LogicException('el SDK se rompió');
        }
    });
    $result = app(CurrentWorkspace::class)->run($this->ws, fn () => app(MessageDispatcher::class)->dispatch(new OutboundMessage(
        $this->ws->id, $this->contact->id, Channel::Email, MessageKind::Transactional, 'flow', sourceId: 'fl_01', templateId: 'tpl_email', idempotencyKey: 'order:1:x',
    )));
    $message = Message::withoutWorkspaceScope()->findOrFail($result->messageId);
    expect($message->status)->toBe('failed')->and($message->error_code)->toBe('driver_error')->and($message->failed_reason)->toContain('LogicException');
});
