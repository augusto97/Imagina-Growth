<?php

use App\Domain\Consent\Policy\PolicyRequest;
use App\Domain\Consent\Policy\SendPolicy;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\Suppression;
use App\Domain\Messaging\Models\WaTemplate;
use App\Domain\Messaging\Support\DnsResolver;
use App\Domain\Messaging\Support\SesClientFactory;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Messaging\Support\MessagingFixtures;

uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    $this->api = "/api/w/{$this->ws->publicId()}";
});

function dnsAllOk(): void
{
    app()->instance(DnsResolver::class, new class extends DnsResolver
    {
        public function cname(string $name): array
        {
            return [explode('._domainkey.', $name)[0].'.dkim.amazonses.com'];
        }

        public function txt(string $name): array
        {
            return str_starts_with($name, '_dmarc.') ? ['v=DMARC1; p=none'] : ['v=spf1 include:amazonses.com ~all'];
        }
    });
}

it('el correo por SES pide los registros DKIM, SPF y DMARC y se activa al verificarlos', function () {
    $ses = $this->fakeSes();
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing, true);
    $this->actingAs($marketing)->postJson("{$this->api}/channels/email", ['provider' => 'ses', 'from_email' => 'hola@pinturas.co', 'from_name' => 'Pinturas'])->assertForbidden();

    // SES devuelve los tokens DKIM al crear la identidad.
    app()->instance(SesClientFactory::class, new class extends SesClientFactory
    {
        public function make(): SesV2Client
        {
            $h = new MockHandler;
            $h->append(fn (CommandInterface $c) => new Result($c->getName() === 'CreateEmailIdentity'
                ? ['DkimAttributes' => ['Tokens' => ['tok1', 'tok2', 'tok3'], 'Status' => 'PENDING']]
                : ['DkimAttributes' => ['Tokens' => ['tok1', 'tok2', 'tok3'], 'Status' => 'SUCCESS']]));

            return new SesV2Client(['version' => '2019-09-27', 'region' => 'us-east-1', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $h]);
        }
    });
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/channels/email", ['provider' => 'ses', 'from_email' => 'hola@pinturas.co', 'from_name' => 'Pinturas La 14'])->assertCreated();
    expect($res->json('data.status'))->toBe('pending')
        ->and(collect($res->json('dns'))->where('purpose', 'dkim')->pluck('name')->all())->toBe(['tok1._domainkey.pinturas.co', 'tok2._domainkey.pinturas.co', 'tok3._domainkey.pinturas.co'])
        ->and(collect($res->json('dns'))->firstWhere('purpose', 'dmarc')['name'])->toBe('_dmarc.pinturas.co');

    // Sin los registros, sigue pendiente; con ellos y SES en SUCCESS, se activa y empieza el calentamiento.
    $id = $res->json('data.id');
    app()->instance(DnsResolver::class, new class extends DnsResolver
    {
        public function cname(string $name): array
        {
            return [];
        }

        public function txt(string $name): array
        {
            return [];
        }
    });
    expect($this->actingAs($this->owner)->getJson("{$this->api}/channels/{$id}/dns")->json('verified'))->toBeFalse();
    dnsAllOk();
    $ok = $this->actingAs($this->owner)->getJson("{$this->api}/channels/{$id}/dns")->assertOk();
    expect($ok->json('verified'))->toBeTrue()->and($ok->json('data.status'))->toBe('active')
        ->and($ok->json('data.warmup.day'))->toBe(1)->and($ok->json('data.warmup.limit'))->toBe(50);
});

it('conecta WhatsApp oficial a mano o por Embedded Signup, sincroniza plantillas y no repite el número', function () {
    Http::fake([
        'graph.test/v24.0/oauth/access_token*' => Http::response(['access_token' => 'EAAB-negocio']),
        'graph.test/v24.0/333333/subscribed_apps' => Http::response(['success' => true]),
        'graph.test/v24.0/444444*' => Http::response(['display_phone_number' => '+57 301 4444444', 'verified_name' => 'Pinturas', 'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_1K']),
        'graph.test/v24.0/333333/message_templates*' => Http::response(['data' => [['id' => '55', 'name' => 'bienvenida', 'language' => 'es', 'category' => 'MARKETING', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}']]]]]),
        'graph.test/v24.0/555555*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 401),
    ]);
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/channels/whatsapp/embedded-signup", ['code' => 'abc', 'waba_id' => '333333', 'phone_number_id' => '444444'])->assertCreated();
    expect($res->json('data'))->toMatchArray(['provider' => 'meta_cloud', 'status' => 'active', 'identity' => '+57 301 4444444', 'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_1K'])
        ->and($res->getContent())->not->toContain('EAAB-negocio');
    expect(WaTemplate::withoutWorkspaceScope()->where('name', 'bienvenida')->value('status'))->toBe('APPROVED');

    $this->actingAs($this->owner)->postJson("{$this->api}/channels/whatsapp/manual", ['waba_id' => '333333', 'phone_number_id' => '444444', 'access_token' => str_repeat('x', 30)])->assertUnprocessable()->assertJsonValidationErrors('phone_number_id');
    $this->actingAs($this->owner)->postJson("{$this->api}/channels/whatsapp/manual", ['waba_id' => '333333', 'phone_number_id' => '555555', 'access_token' => str_repeat('x', 30)])->assertUnprocessable()->assertJsonValidationErrors('access_token');
});

it('Zender: solo el dueño, aceptando el riesgo, con topes fijos y nunca como canal de marketing por defecto', function () {
    $admin = $this->addMember($this->ws, WorkspaceRole::Admin, true);
    $body = ['base_url' => 'https://zender.cliente.co', 'api_secret' => 's', 'account_id' => 'dev9', 'phone' => '3005551212'];
    $this->actingAs($admin)->postJson("{$this->api}/channels/whatsapp/zender", $body + ['accept_risk' => true])->assertForbidden();
    $this->actingAs($this->owner)->postJson("{$this->api}/channels/whatsapp/zender", $body)->assertUnprocessable()->assertJsonValidationErrors('accept_risk');
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/channels/whatsapp/zender", $body + ['accept_risk' => true])->assertCreated();
    expect($res->json('data.caps'))->toBe(['hourly' => 30, 'daily' => 200])->and($res->json('data.is_default'))->toBeFalse()
        ->and($res->json('data.risk_accepted_at'))->not->toBeNull()->and(strlen((string) $res->json('webhook_secret')))->toBe(40)
        ->and(DB::table('audit_logs')->where('action', 'channel.zender_risk_accepted')->exists())->toBeTrue();
});

it('SMS por Twilio, lista sin secretos, cambia la cuenta por defecto, desconecta y envía una prueba', function () {
    $this->actingAs($this->owner)->postJson("{$this->api}/channels/sms/twilio", ['account_sid' => 'AC'.str_repeat('b', 32), 'auth_token' => 'secreto-de-twilio-1', 'from' => '3001112222'])->assertCreated()
        ->assertJsonPath('data.identity', '+57 300 1112222');
    $list = $this->actingAs($this->owner)->getJson("{$this->api}/channels")->assertOk();
    expect($list->getContent())->not->toContain('secreto-de-twilio-1')->not->toContain('EAAB-token-de-prueba')
        ->and(collect($list->json('data'))->pluck('provider')->sort()->values()->all())->toBe(['meta_cloud', 'ses', 'twilio']);

    $second = app(CurrentWorkspace::class)->run($this->ws, fn () => ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud', 'display_name' => 'Otro', 'status' => 'active', 'config' => ['phone_number_id' => '9', 'access_token' => 't']]));
    $this->actingAs($this->owner)->patchJson("{$this->api}/channels/{$second->publicId()}", ['is_default' => true])->assertOk();
    expect(ChannelAccount::withoutWorkspaceScope()->find($this->wa->id)->is_default)->toBeFalse();
    $this->actingAs($this->owner)->deleteJson("{$this->api}/channels/{$second->publicId()}")->assertOk();
    $gone = ChannelAccount::withoutWorkspaceScope()->find($second->id);
    expect($gone->status)->toBe('disconnected')->and($gone->credential('access_token'))->toBeNull();

    // Prueba por correo un domingo: el modo prueba no tiene ventana.
    $this->travelTo(CarbonImmutable::parse('2026-11-22 21:00:00', 'America/Bogota'));
    $test = $this->actingAs($this->owner)->postJson("{$this->api}/channels/{$this->email->publicId()}/test", ['to' => 'dueno@pinturas.co'])->assertOk();
    expect($test->json('status'))->toBe('queued');
    $this->actingAs($this->owner)->postJson("{$this->api}/channels/{$this->email->publicId()}/test", ['to' => 'no-es-correo'])->assertUnprocessable();
});

it('plantillas de WhatsApp: advierte el marketing disfrazado de utilidad, valida variables y las envía a Meta', function () {
    Http::fake([
        'graph.test/v24.0/2222/message_templates*' => Http::response(['id' => '777', 'status' => 'PENDING', 'category' => 'UTILITY']),
        'graph.test/v24.0/777' => Http::response(['success' => true]),
    ]);
    $base = ['account' => $this->wa->publicId(), 'name' => 'confirmar_pedido', 'language' => 'es', 'category' => 'UTILITY',
        'body' => ['text' => 'Hola {{1}}, ¿confirmas tu pedido {{2}}? Aprovecha 10% de descuento', 'examples' => ['Ana', '1234']],
        'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Confirmo'], ['type' => 'URL', 'text' => 'Ver pedido', 'tracked' => true]]];
    $this->actingAs($this->owner)->postJson("{$this->api}/wa-templates", $base)->assertUnprocessable()->assertJsonValidationErrors('category');
    $this->actingAs($this->owner)->postJson("{$this->api}/wa-templates", ['body' => ['text' => 'Hola {{2}}', 'examples' => ['a', 'b']]] + $base)->assertUnprocessable()->assertJsonValidationErrors('body.text');
    $this->actingAs($this->owner)->postJson("{$this->api}/wa-templates", ['body' => ['text' => 'Hola {{1}} y {{2}}', 'examples' => ['Ana']]] + $base)->assertUnprocessable()->assertJsonValidationErrors('body.examples');

    $res = $this->actingAs($this->owner)->postJson("{$this->api}/wa-templates", $base + ['acknowledge_category_warning' => true])->assertCreated();
    expect($res->json('data.status'))->toBe('PENDING');
    $buttons = collect($res->json('data.components'))->firstWhere('type', 'BUTTONS')['buttons'];
    expect($buttons[1]['url'])->toBe('https://go.test/c/{{1}}');
    $submitted = array_values(array_filter(Http::recorded()->all(), fn ($p) => str_contains($p[0]->url(), 'message_templates')))[0][0];
    expect($submitted->data()['components'][0]['example']['body_text'])->toBe([['Ana', '1234']]);

    $this->actingAs($this->owner)->putJson("{$this->api}/wa-templates/{$res->json('data.id')}", ['body' => ['text' => '¿Confirmas tu pedido {{1}}?', 'examples' => ['1234']]])->assertOk()->assertJsonPath('data.status', 'PENDING');
    expect(collect(Http::recorded()->all())->contains(fn ($p) => str_ends_with($p[0]->url(), '/777')))->toBeTrue();
    $this->actingAs($this->owner)->getJson("{$this->api}/wa-templates?account={$this->wa->publicId()}")->assertOk()->assertJsonCount(1, 'data');
});

it('supresión manual, quejas que no se quitan e importación del RNE que frena el marketing por WhatsApp y SMS', function () {
    $this->actingAs($this->owner)->postJson("{$this->api}/suppressions", ['channel' => 'email', 'value' => 'ana@ejemplo.com'])->assertCreated();
    $list = $this->actingAs($this->owner)->getJson("{$this->api}/suppressions?q=ana@ejemplo.com")->assertOk();
    expect($list->json('data.0.contact.value'))->toBe('a**@e******.com')->and($list->json('data.0.reason'))->toBe('manual');
    $this->actingAs($this->owner)->deleteJson("{$this->api}/suppressions/{$list->json('data.0.id')}")->assertOk();
    $complaint = app(CurrentWorkspace::class)->run($this->ws, function () {
        Suppression::add($this->ws->id, 'email', BlindIndex::email($this->ws->id, 'otra@x.co'), 'complaint');

        return Suppression::query()->where('reason', 'complaint')->value('id');
    });
    $this->actingAs($this->owner)->deleteJson("{$this->api}/suppressions/{$complaint}")->assertUnprocessable();

    $csv = "numero\n3001234567\n+57 310 555 0000\nno-es-numero\n";
    $res = $this->actingAs($this->owner)->post("{$this->api}/suppressions/registry", ['country' => 'CO', 'file' => UploadedFile::fake()->createWithContent('rne.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
    expect($res->json())->toBe(['rows' => 2, 'added' => 4]);
    app(CurrentWorkspace::class)->run($this->ws, function () {
        $decision = app(SendPolicy::class)->check(new PolicyRequest($this->ws, $this->contact->fresh(), Channel::WhatsApp, MessageKind::Marketing, 'campaign', $this->wa, CarbonImmutable::now()));
        expect($decision->reason)->toBe('excluded_registry');
    });
    expect(DB::table('excluded_registry_imports')->where('workspace_id', $this->ws->id)->value('rows'))->toBe(2);
});

it('la pestaña Mensajes del perfil lista los envíos con su flujo y respeta el acceso', function () {
    $flowId = strtoupper((string) Str::ulid());
    DB::table('flows')->insert(['id' => $flowId, 'workspace_id' => $this->ws->id, 'name' => 'Carrito abandonado', 'status' => 'active', 'trigger' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    app(CurrentWorkspace::class)->run($this->ws, function () use ($flowId) {
        $m = new Message;
        $m->forceFill(['workspace_id' => $this->ws->id, 'contact_id' => $this->contact->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud', 'direction' => 'out', 'kind' => 'marketing',
            'source_type' => 'flow', 'flow_id' => $flowId, 'status' => 'read', 'payload' => ['wa' => ['name' => 'carrito_abandonado']], 'queued_at' => now()])->save();
    });
    $url = "{$this->api}/contacts/{$this->contact->publicId()}/messages";
    $res = $this->actingAs($this->owner)->getJson($url)->assertOk();
    expect($res->json('data.0'))->toMatchArray(['channel' => 'whatsapp', 'source_name' => 'Carrito abandonado', 'status' => 'read', 'preview' => 'carrito_abandonado']);
    $this->actingAs($this->addMember($this->ws, WorkspaceRole::Agent, true))->getJson($url)->assertNotFound();
});
