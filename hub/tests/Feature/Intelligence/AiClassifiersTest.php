<?php

use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Inbox\Contracts\InboundClassifier;
use App\Domain\Inbox\Events\InboundMessageReceived;
use App\Domain\Intelligence\Ai\Features\Classifiers\AiInboundClassifier;
use App\Domain\Intelligence\Ai\Features\Classifiers\ClassifyInboundMessage;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Reviews\Contracts\ReviewClassifier;
use App\Domain\Reviews\Models\Review;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Support\MessagingFixtures;

uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    Http::fake(['graph.test/*' => Http::response(['messages' => [['id' => 'wamid.CONFIRM']]])]);
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
});

/** Un entrante de WhatsApp de Ana con su conversación, y el evento que lo anuncia. */
function analystInbound($test, string $text): InboundMessageReceived
{
    return ($test->in)(function () use ($test, $text) {
        $conversation = Conversation::query()->firstOrCreate(['channel_account_id' => $test->wa->id, 'contact_id' => $test->contact->id],
            ['workspace_id' => $test->ws->id, 'status' => 'open', 'labels' => [], 'last_inbound_at' => now(), 'window_expires_at' => now()->addDay()]);
        $m = new Message;
        $m->forceFill([
            'workspace_id' => $test->ws->id, 'contact_id' => $test->contact->id, 'channel_account_id' => $test->wa->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud',
            'provider_message_id' => 'wamid.'.uniqid(), 'direction' => 'in', 'kind' => 'service', 'source_type' => 'inbox', 'status' => 'received',
            'payload' => ['type' => 'text', 'text' => $text], 'queued_at' => now(), 'conversation_id' => $conversation->id,
        ])->save();

        return new InboundMessageReceived($test->ws->id, $m->id, $conversation->id, $test->contact->id, false);
    });
}

function analystWaConsent($test): ?string
{
    return ($test->in)(fn () => ContactConsent::query()->where('contact_id', $test->contact->id)->where('channel', 'whatsapp')->value('status'));
}

it('la IA lee una baja con confianza alta: revoca el WhatsApp por el mismo camino que las palabras y lo confirma', function () {
    expect(app(InboundClassifier::class))->toBeInstanceOf(AiInboundClassifier::class);
    $ai = FakeAiGateway::install()->replyJson(['intent' => 'optout', 'sentiment' => 'negative', 'confidence' => 0.93]);
    $event = analystInbound($this, 'Por favor ya no me manden más cosas a este número');
    app(ClassifyInboundMessage::class)->handle($event);

    expect(analystWaConsent($this))->toBe('revoked')
        ->and(($this->in)(fn () => ContactConsent::query()->where('contact_id', $this->contact->id)->where('channel', 'email')->value('status')))->toBe('granted')
        ->and(($this->in)(fn () => Conversation::query()->find($event->conversationId)?->intent))->toBe('optout')
        ->and(DB::table('consent_events')->where('contact_id', $this->contact->id)->where('action', 'revoked')->value('evidence'))->toContain('"detected_by":"ai"')
        ->and(($this->in)(fn () => Message::query()->where('direction', 'out')->where('source_type', 'system')->count()))->toBe(1)
        ->and($ai->requests[0]['request']->feature)->toBe('inbound_classifier');
});

it('con poca confianza nunca revoca: marca la conversación para que la revise una persona', function () {
    FakeAiGateway::install()->replyJson(['intent' => 'optout', 'sentiment' => 'neutral', 'confidence' => 0.55]);
    $event = analystInbound($this, 'mmm no sé, déjenme pensarlo');
    app(ClassifyInboundMessage::class)->handle($event);

    $conversation = ($this->in)(fn () => Conversation::query()->find($event->conversationId));
    expect(analystWaConsent($this))->toBe('granted')
        ->and($conversation?->labels)->toContain(ClassifyInboundMessage::REVIEW_LABEL)
        ->and(DB::table('conversation_events')->where('conversation_id', $event->conversationId)->where('actor', 'ai')->count())->toBe(1);
});

it('compra, queja o soporte quedan como intención; sin IA o si falla, clasifican las palabras y nunca revocan', function () {
    FakeAiGateway::install()->replyJson(['intent' => 'purchase', 'sentiment' => 'positive', 'confidence' => 0.9]);
    $event = analystInbound($this, '¿Tienen la pintura azul de galón?');
    app(ClassifyInboundMessage::class)->handle($event);
    expect(($this->in)(fn () => Conversation::query()->find($event->conversationId)?->intent))->toBe('purchase');

    FakeAiGateway::install()->failWith('provider');
    $c = app(InboundClassifier::class)->classify($this->ws->id, 'Pésimo servicio, mi pedido nunca llegó');
    expect($c->source)->toBe('heuristic')->and($c->intent)->toBe('complaint');

    $ai = FakeAiGateway::install();
    $ai->enabled = false;
    expect(app(InboundClassifier::class)->classify($this->ws->id, '¿cuánto cuesta?')->source)->toBe('heuristic')->and($ai->requests)->toBe([]);

    // Una palabra de baja ya la resolvió InboundHandler: el oyente no hace nada.
    $keyword = analystInbound($this, 'BAJA');
    app(ClassifyInboundMessage::class)->handle(new InboundMessageReceived($keyword->workspaceId, $keyword->messageId, $keyword->conversationId, $keyword->contactId, true));
    expect($ai->requests)->toBe([]);
});

it('la moderación de reseñas con IA suma banderas a las heurísticas y sin IA quedan solo las heurísticas', function () {
    FakeAiGateway::install()->replyJson(['offensive' => true, 'spam' => false, 'personal_data' => false, 'motivo' => 'Insulta al vendedor.']);
    $r = ($this->in)(fn () => app(ReviewClassifier::class)->classify('Malo', 'El vendedor es un idiota, escríbanme a ana@ejemplo.com'));
    expect($r['flags'])->toContain('offensive', 'personal_data')->and($r['engine'])->toBe('ai+heuristic');

    $ai = FakeAiGateway::install();
    $ai->enabled = false;
    $h = ($this->in)(fn () => app(ReviewClassifier::class)->classify('Bien', 'Me gustó mucho, buen cubrimiento.'));
    expect($h['flags'])->toBe([])->and($h['engine'])->not->toBe('ai+heuristic');
});

it('resume las reseñas publicadas de un producto sin nombres, solo con 3 o más', function () {
    [$site] = $this->connectedSite($this->ws);
    $mk = fn (int $rating, string $body, string $status = 'published') => ($this->in)(fn () => Review::query()->create([
        'workspace_id' => $this->ws->id, 'site_id' => $site->id, 'external_product_id' => '88', 'rating' => $rating, 'body' => $body,
        'author_name' => 'Ana R.', 'status' => $status, 'published_at' => now(), 'source' => 'request',
    ]));
    $mk(5, 'Cubre muy bien.');
    $mk(4, 'Buen color, demoró 3 días.');
    $url = "/api/w/{$this->ws->publicId()}/ai/review-summary?product_id=88";
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('available', false)->assertJsonPath('count', 2);

    $mk(2, 'Llegó golpeada.');
    $mk(1, 'Nunca la publiquen', 'pending');
    $ai = FakeAiGateway::install()->replyJson(['resumen' => 'Gusta el cubrimiento; algunas llegan golpeadas (3 reseñas).', 'lo_bueno' => ['Cubre muy bien'], 'lo_malo' => ['Empaque']]);
    $res = $this->actingAs($this->owner)->getJson($url)->assertOk();
    expect($res->json('available'))->toBeTrue()->and($res->json('source'))->toBe('ai')->and($res->json('count'))->toBe(3)
        ->and($res->json('positives'))->toBe(['Cubre muy bien'])
        ->and(json_encode($ai->requests[0]['request']->messages))->not->toContain('Ana R.')->not->toContain('Nunca la publiquen');

    $viewer = $this->addMember($this->ws, WorkspaceRole::Agent, true);
    $this->actingAs($viewer)->getJson($url)->assertForbidden();
});

it('el uso de IA del mes se ve frente al tope', function () {
    FakeAiGateway::install()->replyJson(['intent' => 'other', 'sentiment' => 'neutral', 'confidence' => 0.9]);
    app(ClassifyInboundMessage::class)->handle(analystInbound($this, 'hola'));
    $res = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/ai/usage")->assertOk();
    expect($res->json('features.0.feature'))->toBe('inbound_classifier')->and($res->json('features.0.requests'))->toBe(1)
        ->and($res->json('budget_usd'))->toEqual(config('igr-ai.monthly_budget_usd'))->and($res->json('spent_usd'))->toBeGreaterThan(0);
});
