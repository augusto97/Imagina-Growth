<?php

require_once __DIR__.'/LeadsHelpers.php';

use App\Domain\Inbox\Events\InboundMessageReceived;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Services\Leads\Actions\ChangeLeadStage;
use App\Domain\Services\Leads\Listeners\ChatLeadListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    leadsSetUp($this);
    $this->ai = FakeAiGateway::install();
    $this->ai->enabled = false;
    $this->messages = FakeMessageDispatcher::install();
});

function leadsStage($test, string $to, ?string $value = null, ?string $reason = null, string $by = 'user')
{
    return leadsRun($test, fn () => app(ChangeLeadStage::class)->handle($test->ws, leadsOne($test), $to, $by, $test->owner->id, $value, $reason));
}

function leadsChannelAccount($test, string $channel = 'whatsapp'): string
{
    $id = strtoupper((string) Str::ulid());
    DB::table('channel_accounts')->insert(['id' => $id, 'workspace_id' => $test->ws->id, 'channel' => $channel, 'provider' => $channel === 'whatsapp' ? 'meta_cloud' : 'ses',
        'display_name' => 'Cuenta', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function leadsConversation($test, string $contactId, string $accountId, ?string $intent = null): Conversation
{
    return leadsRun($test, fn () => Conversation::query()->create(['workspace_id' => $test->ws->id, 'contact_id' => $contactId, 'channel_account_id' => $accountId, 'status' => 'open', 'intent' => $intent]));
}

it('mueve el lead por el pipeline: primera respuesta, ganado con valor y perdido con motivo, con sus eventos', function () {
    leadsForm($this, leadsContact($this));
    $lead = leadsStage($this, 'contacted');
    expect($lead->stage)->toBe('contacted')->and($lead->first_response_at)->not->toBeNull();

    $this->travel(5)->days();
    $lead = leadsStage($this, 'won', '2500000');
    expect($lead->won_at)->not->toBeNull()->and($lead->value_ws)->toBe('2500000.00');
    expect(array_map(fn ($p) => json_decode($p, true), leadsHubEvents($this, 'lead.stage_changed')))->toEqual([
        ['lead_id' => $lead->publicId(), 'from' => 'new', 'to' => 'contacted', 'by' => 'user'],
        ['lead_id' => $lead->publicId(), 'from' => 'contacted', 'to' => 'won', 'by' => 'user'],
    ]);
    expect(json_decode(leadsHubEvents($this, 'lead.won')[0], true))->toMatchArray(['lead_id' => $lead->publicId(), 'value' => '2500000.00', 'currency' => 'COP', 'days_to_win' => 5, 'service' => 'Asesoría laboral']);
    // Sin destinos de anuncios: queda en la historia que no hubo a dónde enviarla.
    expect(leadsActivities($this, $lead)->firstWhere('kind', 'conversion')->data['status'])->toBe('no_destination');

    $lead = leadsStage($this, 'lost', null, 'Eligió otra firma');
    expect($lead->won_at)->toBeNull()->and($lead->lost_reason)->toBe('Eligió otra firma')
        ->and(json_decode(leadsHubEvents($this, 'lead.lost')[0], true))->toEqual(['lead_id' => $lead->publicId(), 'reason' => 'Eligió otra firma']);

    expect(fn () => leadsStage($this, 'inventada'))->toThrow(ValidationException::class);
});

it('la primera respuesta desde la bandeja marca first_response_at; un cambio del sistema no', function () {
    $contact = leadsContact($this);
    leadsForm($this, $contact);
    leadsStage($this, 'qualified', null, null, 'system');
    expect(leadsOne($this)->first_response_at)->toBeNull();

    leadsRun($this, fn () => Message::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $contact->id, 'channel' => Channel::WhatsApp, 'direction' => 'out',
        'kind' => MessageKind::Service, 'source_type' => 'inbox', 'status' => 'queued', 'idempotency_key' => Str::random(20)]));
    $lead = leadsOne($this);
    expect($lead->first_response_at)->not->toBeNull()
        ->and(leadsActivities($this, $lead)->firstWhere('kind', 'message')->data)->toMatchArray(['first_response' => true, 'channel' => 'whatsapp']);
});

it('crea un lead de WhatsApp cuando la conversación tiene intención de compra, si está activado', function () {
    $contact = leadsContact($this, ['email' => null, 'email_bidx' => null, 'phone' => '+573005556677']);
    $wa = leadsChannelAccount($this);
    $conversation = leadsConversation($this, $contact->id, $wa);
    expect(leadsAll($this))->toHaveCount(0);

    leadsRun($this, fn () => $conversation->forceFill(['intent' => 'purchase'])->save());
    $lead = leadsOne($this);
    expect($lead->origin)->toBe('whatsapp')->and($lead->origin_ref)->toBe($conversation->id)
        ->and($lead->source)->toBe(['channel' => 'whatsapp'])
        ->and(collect($lead->score_reasons)->pluck('points', 'signal')->all())->toBe(['whatsapp_click' => 15]);

    // Otro entrante con la misma intención no duplica.
    app(ChatLeadListener::class)->inbound(new InboundMessageReceived($this->ws->id, 'MSG1', $conversation->id, $contact->id, false));
    expect(leadsAll($this))->toHaveCount(1);
});

it('no crea leads de WhatsApp si se apagó, si la intención no es de compra o si el canal no es WhatsApp', function () {
    $contact = leadsContact($this);
    $wa = leadsChannelAccount($this);
    $mail = leadsChannelAccount($this, 'email');

    $conversation = leadsConversation($this, $contact->id, $wa);
    leadsRun($this, fn () => $conversation->forceFill(['intent' => 'support'])->save());
    leadsRun($this, fn () => leadsConversation($this, $contact->id, $mail)->forceFill(['intent' => 'purchase'])->save());
    expect(leadsAll($this))->toHaveCount(0);

    leadsSettings($this, ['whatsapp_leads' => false]);
    leadsRun($this, fn () => $conversation->forceFill(['intent' => 'purchase'])->save());
    app(ChatLeadListener::class)->inbound(new InboundMessageReceived($this->ws->id, 'MSG2', $conversation->id, $contact->id, false));
    expect(leadsAll($this))->toHaveCount(0);
});
