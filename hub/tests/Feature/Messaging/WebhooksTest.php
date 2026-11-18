<?php

use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\Suppression;
use App\Domain\Messaging\Models\WaTemplate;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Support\MessagingFixtures;

uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    Http::fake(['graph.test/*' => Http::response(['messages' => [['id' => 'wamid.CONFIRM']]])]);
});

/** Un mensaje saliente ya enviado al contacto. */
function outbound($test, string $provider, string $providerId, string $channel = 'whatsapp', string $kind = 'marketing'): Message
{
    return app(CurrentWorkspace::class)->run($test->ws, function () use ($test, $provider, $providerId, $channel, $kind) {
        $m = new Message;
        $m->forceFill([
            'workspace_id' => $test->ws->id, 'contact_id' => $test->contact->id, 'channel_account_id' => $channel === 'email' ? $test->email->id : $test->wa->id,
            'channel' => $channel, 'provider' => $provider, 'provider_message_id' => $providerId, 'direction' => 'out', 'kind' => $kind,
            'source_type' => 'flow', 'source_id' => 'fl_1', 'status' => 'sent', 'queued_at' => now()->subMinute(), 'sent_at' => now()->subMinute(), 'payload' => ['to' => 'x'],
        ])->save();

        return $m;
    });
}

function metaPost($test, array $payload)
{
    $body = json_encode($payload);

    return $test->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'),
    ], $body);
}

function metaChange(string $field, array $value): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '2222', 'changes' => [['field' => $field, 'value' => $value]]]]];
}

function inboundText(string $from, string $text, string $id = 'wamid.IN1', ?string $context = null, array $extra = []): array
{
    return metaChange('messages', ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1111'],
        'contacts' => [['wa_id' => ltrim($from, '+'), 'profile' => ['name' => 'Ana']]],
        'messages' => [array_filter(['from' => ltrim($from, '+'), 'id' => $id, 'timestamp' => (string) now()->getTimestamp(), 'type' => 'text', 'text' => ['body' => $text], 'context' => $context ? ['id' => $context] : null] + $extra)]]);
}

function hubEvents($test, string $event): array
{
    return $test->chRows($test->ws, 'SELECT toJSONString(properties) AS p, contact_id FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = {e:String}', ['e' => $event]);
}

it('verifica la suscripción del webhook de Meta y rechaza firmas inválidas', function () {
    $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=verificame&hub.challenge=123abc')->assertOk()->assertSee('123abc');
    $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=otro&hub.challenge=1')->assertForbidden();
    $this->call('POST', '/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=malo'], '{}')->assertForbidden();
});

it('aplica entregado y leído una sola vez, aunque Meta repita el webhook o llegue tarde', function () {
    $message = outbound($this, 'meta_cloud', 'wamid.OUT1');
    $status = fn (string $s) => metaChange('messages', ['metadata' => ['phone_number_id' => '1111'], 'statuses' => [['id' => 'wamid.OUT1', 'status' => $s, 'timestamp' => (string) now()->getTimestamp()]]]);
    metaPost($this, $status('delivered'))->assertOk();
    metaPost($this, $status('read'))->assertOk();
    metaPost($this, $status('read'))->assertOk();
    metaPost($this, $status('delivered'))->assertOk();
    $fresh = Message::withoutWorkspaceScope()->find($message->id);
    expect($fresh->status)->toBe('read')->and($fresh->delivered_at)->not->toBeNull()->and($fresh->read_at)->not->toBeNull()
        ->and(hubEvents($this, 'message.delivered'))->toHaveCount(1)
        ->and(hubEvents($this, 'message.read'))->toHaveCount(1);
});

it('«BAJA» por WhatsApp revoca el marketing al instante, abre la ventana de 24 h y responde; el evento no guarda el texto', function () {
    $last = outbound($this, 'meta_cloud', 'wamid.OUT2');
    metaPost($this, inboundText('+573001234567', '  Baja, por favor!! '))->assertOk();
    app(CurrentWorkspace::class)->run($this->ws, function () use ($last) {
        expect(ContactConsent::query()->where('contact_id', $this->contact->id)->where('channel', 'whatsapp')->value('status'))->toBe('revoked')
            ->and(ContactConsent::query()->where('contact_id', $this->contact->id)->where('channel', 'email')->value('status'))->toBe('granted')
            ->and(Conversation::query()->where('contact_id', $this->contact->id)->first()?->window_expires_at?->greaterThan(now()->addHours(23)))->toBeTrue()
            ->and(Message::query()->find($last->id)->replied_at)->not->toBeNull();
        // La confirmación es un mensaje de servicio dentro de la ventana.
        expect(Message::query()->where('direction', 'out')->where('source_type', 'system')->value('kind'))->toBe(MessageKind::Service);
    });
    $inbound = hubEvents($this, 'whatsapp.inbound');
    expect($inbound)->toHaveCount(1)->and($inbound[0]['p'])->toContain('"keyword":"baja"')->not->toContain('por favor')
        ->and($inbound[0]['contact_id'])->toBe($this->contact->id)
        ->and(hubEvents($this, 'message.unsubscribed')[0]['p'])->toContain('"via":"keyword"');
    // Un mensaje largo que solo menciona la palabra no es una baja.
    metaPost($this, inboundText('+573001234567', 'no más dudas, ya hice el pago gracias', 'wamid.IN2'))->assertOk();
    $all = hubEvents($this, 'whatsapp.inbound');
    expect($all)->toHaveCount(2)->and(array_filter($all, fn ($r) => str_contains($r['p'], 'keyword')))->toHaveCount(1);
});

it('el botón «Confirmo» marca la respuesta al mensaje original con su payload', function () {
    $original = outbound($this, 'meta_cloud', 'wamid.COD1', kind: 'utility');
    $payload = metaChange('messages', ['metadata' => ['phone_number_id' => '1111'], 'messages' => [[
        'from' => '573001234567', 'id' => 'wamid.BTN1', 'timestamp' => (string) now()->getTimestamp(), 'type' => 'button',
        'button' => ['payload' => 'cod_confirm', 'text' => 'Confirmo'], 'context' => ['id' => 'wamid.COD1'],
    ]]]);
    metaPost($this, $payload)->assertOk();
    metaPost($this, $payload)->assertOk(); // reintento de Meta: no duplica
    expect(Message::withoutWorkspaceScope()->find($original->id)->replied_at)->not->toBeNull();
    $replied = hubEvents($this, 'message.replied');
    expect($replied)->toHaveCount(1)->and($replied[0]['p'])->toContain('"button_payload":"cod_confirm"')->toContain('"message_id":"msg_'.$original->id.'"');
});

it('un número nuevo con «ref:» del sitio crea el contacto y lo vincula con el visitante del clic', function () {
    [$site] = $this->connectedSite($this->ws);
    $click = $this->envelope($site, 'cta.clicked', ['kind' => 'whatsapp', 'target' => 'https://wa.me/573000000000', 'label' => 'Escríbenos', 'wa_ref' => 'K7Q2X'],
        ['schema_version' => 2, 'source' => ['type' => 'browser'], 'identity' => ['anonymous_id' => 'anon-wa-1']]);
    $this->call('POST', '/v1/b', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://tienda.co'], json_encode(['k' => $site->public_key, 'events' => [$click]]))->assertStatus(202);
    $this->runWorker();
    metaPost($this, inboundText('+573105550000', 'Hola, me interesa la pintura (ref: K7Q2X)', 'wamid.NEW1', null, ['referral' => ['source_type' => 'ad', 'source_id' => '1202', 'ctwa_clid' => 'ARAkLkA8']]))->assertOk();
    app(CurrentWorkspace::class)->run($this->ws, function () {
        $contact = Contact::query()->where('phone_bidx', BlindIndex::phone($this->ws->id, '+573105550000'))->firstOrFail();
        expect($contact->getAttribute('lifecycle_stage'))->toBe('lead')
            ->and(ContactIdentity::query()->where('type', 'anonymous_id')->where('value', 'anon-wa-1')->value('contact_id'))->toBe($contact->id);
    });
    expect(hubEvents($this, 'whatsapp.inbound')[0]['p'])->toContain('"wa_ref":"K7Q2X"')->toContain('"ctwa_clid":"ARAkLkA8"')->not->toContain('pintura');
});

it('actualiza el estado de las plantillas y la calidad del número', function () {
    app(CurrentWorkspace::class)->run($this->ws, fn () => WaTemplate::query()->create(['workspace_id' => $this->ws->id, 'channel_account_id' => $this->wa->id, 'name' => 'bienvenida', 'language' => 'es', 'category' => 'MARKETING', 'status' => 'PENDING', 'components' => [], 'meta_template_id' => '987']));
    metaPost($this, metaChange('message_template_status_update', ['event' => 'APPROVED', 'message_template_id' => 987, 'message_template_name' => 'bienvenida', 'message_template_language' => 'es', 'reason' => 'NONE']))->assertOk();
    metaPost($this, metaChange('phone_number_quality_update', ['display_phone_number' => '57 300 0000000', 'event' => 'FLAGGED', 'current_limit' => 'TIER_1K']))->assertOk();
    expect(WaTemplate::withoutWorkspaceScope()->where('meta_template_id', '987')->value('status'))->toBe('APPROVED');
    $wa = ChannelAccount::withoutWorkspaceScope()->find($this->wa->id);
    expect($wa->quality_rating)->toBe('YELLOW')->and($wa->messaging_limit_tier)->toBe('TIER_1K');
});

/** Mensaje de SNS firmado con un certificado propio servido en la URL de AWS (falsa). */
function snsMessage(array $fields): array
{
    static $key = null, $pem = null;
    if ($key === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 1), $pem);
    }
    Http::fake(['sns.us-east-1.amazonaws.com/*' => Http::response($pem), 'graph.test/*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);
    $msg = $fields + ['MessageId' => 'sns-1', 'Timestamp' => now()->toIso8601String(), 'TopicArn' => 'arn:aws:sns:us-east-1:1:igr', 'SignatureVersion' => '1',
        'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-test.pem'];
    $order = $msg['Type'] === 'Notification' ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'] : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
    $data = '';
    foreach ($order as $f) {
        if (isset($msg[$f])) {
            $data .= $f."\n".$msg[$f]."\n";
        }
    }
    openssl_sign($data, $sig, $key, OPENSSL_ALGO_SHA1);
    $msg['Signature'] = base64_encode($sig);

    return $msg;
}

function sesEvent($test, array $event)
{
    return $test->call('POST', '/webhooks/ses', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode(snsMessage(['Type' => 'Notification', 'Message' => json_encode($event)])));
}

it('SES por SNS: firma verificada, entregas, aperturas sin MPP, rebotes duros y quejas a la supresión', function () {
    $m = outbound($this, 'ses', 'ses-001', 'email');
    $this->call('POST', '/webhooks/ses', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode(['Type' => 'Notification', 'Message' => '{}', 'Signature' => 'x', 'SigningCertURL' => 'https://evil.test/c.pem']))->assertForbidden();

    sesEvent($this, ['eventType' => 'Delivery', 'mail' => ['messageId' => 'ses-001'], 'delivery' => ['timestamp' => now()->toIso8601String()]])->assertOk();
    sesEvent($this, ['eventType' => 'Open', 'mail' => ['messageId' => 'ses-001'], 'open' => ['timestamp' => now()->toIso8601String(), 'ipAddress' => '17.58.1.2', 'userAgent' => 'Mozilla/5.0']])->assertOk();
    expect(Message::withoutWorkspaceScope()->find($m->id)->opened_at)->toBeNull();
    sesEvent($this, ['eventType' => 'Open', 'mail' => ['messageId' => 'ses-001'], 'open' => ['timestamp' => now()->toIso8601String(), 'ipAddress' => '190.1.2.3', 'userAgent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)']])->assertOk();
    $fresh = Message::withoutWorkspaceScope()->find($m->id);
    expect($fresh->status)->toBe('delivered')->and($fresh->opened_at)->not->toBeNull();
    expect(array_map(fn ($r) => str_contains($r['p'], '"is_mpp":true'), hubEvents($this, 'message.opened')))->toEqualCanonicalizing([true, false]);

    sesEvent($this, ['eventType' => 'Bounce', 'mail' => ['messageId' => 'ses-001'], 'bounce' => ['bounceType' => 'Permanent', 'bounceSubType' => 'General', 'bouncedRecipients' => [['emailAddress' => 'ana@ejemplo.com']]]])->assertOk();
    sesEvent($this, ['eventType' => 'Complaint', 'mail' => ['messageId' => 'ses-001'], 'complaint' => ['complainedRecipients' => [['emailAddress' => 'ana@ejemplo.com']]]])->assertOk();
    app(CurrentWorkspace::class)->run($this->ws, function () {
        $bidx = BlindIndex::email($this->ws->id, 'ana@ejemplo.com');
        expect(Suppression::query()->where('value_bidx', $bidx)->pluck('reason')->all())->toBe(['bounce'])
            ->and(ContactConsent::query()->where('contact_id', $this->contact->id)->where('channel', 'email')->value('status'))->toBe('revoked');
    });
    expect(hubEvents($this, 'message.bounced'))->toHaveCount(1)->and(hubEvents($this, 'message.complained'))->toHaveCount(1)
        ->and(hubEvents($this, 'message.unsubscribed')[0]['p'])->toContain('"via":"complaint"');
});

it('SES por SNS: confirma la suscripción visitando la URL de AWS', function () {
    $msg = snsMessage(['Type' => 'SubscriptionConfirmation', 'Message' => 'Confirma', 'Token' => 't', 'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&Token=t']);
    $this->call('POST', '/webhooks/ses', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($msg))->assertOk();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'Action=ConfirmSubscription'));
});

it('Zender con su secreto y Twilio con su firma; sin ellos, 403', function () {
    [$zender, $twilio] = app(CurrentWorkspace::class)->run($this->ws, fn () => [
        ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'zender', 'display_name' => 'Z', 'status' => 'active', 'external_id' => 'dev1',
            'config' => ['base_url' => 'https://zender.test', 'api_secret' => 'a', 'account_id' => 'dev1', 'webhook_secret' => 'zz-secret']]),
        ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'sms', 'provider' => 'twilio', 'display_name' => 'SMS', 'status' => 'active', 'external_id' => '+15005550006',
            'config' => ['account_sid' => 'AC'.str_repeat('a', 32), 'auth_token' => 'twilio-token-123456', 'from' => '+15005550006']]),
    ]);
    $this->post('/webhooks/zender/'.$zender->publicId(), ['secret' => 'malo', 'type' => 'whatsapp', 'data' => ['phone' => '573001234567', 'message' => 'Hola']])->assertForbidden();
    $this->post('/webhooks/zender/'.$zender->publicId(), ['secret' => 'zz-secret', 'type' => 'whatsapp', 'data' => ['id' => 'z1', 'phone' => '573001234567', 'message' => 'Hola']])->assertOk();
    expect(Message::withoutWorkspaceScope()->where('provider', 'zender')->where('direction', 'in')->count())->toBe(1);

    $url = 'http://localhost/webhooks/twilio/'.$twilio->publicId();
    $params = ['MessageSid' => 'SM1', 'From' => '+573001234567', 'Body' => 'STOP', 'SmsStatus' => 'received', 'MessageStatus' => 'received'];
    $sign = function (array $p) use ($url) {
        ksort($p);
        $data = $url;
        foreach ($p as $k => $v) {
            $data .= $k.$v;
        }

        return base64_encode(hash_hmac('sha1', $data, 'twilio-token-123456', true));
    };
    $this->post($url, $params, ['X-Twilio-Signature' => 'malo'])->assertForbidden();
    $this->post($url, $params, ['X-Twilio-Signature' => $sign($params)])->assertOk();
    app(CurrentWorkspace::class)->run($this->ws, fn () => expect(ContactConsent::query()->where('contact_id', $this->contact->id)->where('channel', 'sms')->value('status'))->toBe('revoked'));
});
