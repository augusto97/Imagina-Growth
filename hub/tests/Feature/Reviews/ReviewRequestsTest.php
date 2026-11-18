<?php

use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Reviews\Actions\SendReviewRequests;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Models\ReviewRequest;
use App\Domain\Reviews\Support\ReviewToken;
use App\Domain\Templates\Models\Template;
use Carbon\CarbonImmutable;
use Tests\Feature\Reviews\Support\ReviewsFixtures;

/*
 * M15: solicitud automática de reseña tras la entrega (o el pago) con los días
 * de la categoría, envío con canal de respaldo por MessageDispatcher, aplazamiento
 * por la política y review.requested.
 */

uses(ReviewsFixtures::class);

beforeEach(function () {
    $this->reviewsSetUp();
    $this->fake = FakeMessageDispatcher::install();
});

function reviewsWaTemplate($test): Template
{
    return $test->reviewsInWs(fn () => Template::query()->create([
        'workspace_id' => $test->ws->id, 'channel' => 'whatsapp', 'name' => 'Pedir reseña WA', 'category' => 'marketing', 'status' => 'ready',
        'variables' => ['body' => ['{{ contacto.nombre }}'], 'button' => '{{ enlaces.resena }}5'],
    ]));
}

it('programa una sola solicitud al entregarse el pedido, con los días de la categoría que más espera', function () {
    $this->reviewsEnable(['days' => 5, 'category_days' => [['category_id' => '12', 'days' => 10], ['category_id' => '15', 'days' => 3]]]);
    $before = CarbonImmutable::now();
    $order = $this->reviewsOrder();

    $requests = $this->reviewsInWs(fn () => ReviewRequest::query()->get());
    expect($requests)->toHaveCount(1);
    $request = $requests->first();
    expect($request->order_id)->toBe($order->id)->and($request->contact_id)->toBe($order->contact_id)
        ->and($request->status)->toBe('scheduled')->and($request->products)->toBe(['88', '91'])
        ->and($request->kind)->toBe('marketing')
        ->and($request->scheduled_for->diffInDays($before->addDays(10), true))->toBeLessThan(0.01);

    // Otro order.completed del mismo pedido no crea otra.
    $snapshot = $this->orderSnapshot(['order_id' => '1234', 'number' => '1234', 'status' => 'completed', 'coupons' => []]);
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'order.completed', ['order_id' => '1234', 'order' => $snapshot])]);
    $this->runWorker();
    expect($this->reviewsInWs(fn () => ReviewRequest::query()->count()))->toBe(1);
});

it('no programa nada si está apagado, si el disparador es otro o si ya reseñó todo', function () {
    $this->reviewsOrder('2000');
    expect($this->reviewsInWs(fn () => ReviewRequest::query()->count()))->toBe(0);

    $this->reviewsEnable(['trigger' => 'paid']);
    $order = $this->reviewsOrder('2001', completed: false, withRoller: false);
    expect($this->reviewsInWs(fn () => ReviewRequest::query()->where('order_id', $order->id)->count()))->toBe(1);

    // Ya reseñó la pintura: un pedido nuevo solo con la pintura no genera solicitud.
    $this->reviewsInWs(fn () => Review::query()->create([
        'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'external_product_id' => '88', 'contact_id' => $order->contact_id,
        'rating' => 5, 'status' => 'published', 'source' => 'request', 'verified_buyer' => true,
    ]));
    $again = $this->reviewsOrder('2002', completed: false, withRoller: false);
    expect($this->reviewsInWs(fn () => ReviewRequest::query()->where('order_id', $again->id)->count()))->toBe(0);
});

it('envía por WhatsApp y, si la política lo niega, cae a correo con la plantilla de la biblioteca; emite review.requested', function () {
    $wa = reviewsWaTemplate($this);
    $this->reviewsEnable(['days' => 0, 'channels' => ['whatsapp', 'email'], 'whatsapp_template_id' => $wa->publicId()]);
    $this->reviewsOrder();
    $this->fake->respondWith(fn (OutboundMessage $m) => $m->channel === Channel::WhatsApp ? DispatchResult::denied('no_consent') : null);

    $result = app(SendReviewRequests::class)->handle(CarbonImmutable::now()->addMinute());
    expect($result['sent'])->toBe(1);

    expect($this->fake->dispatched)->toHaveCount(1);
    $msg = $this->fake->dispatched[0];
    $request = $this->reviewsInWs(fn () => ReviewRequest::query()->firstOrFail());
    expect($msg->channel)->toBe(Channel::Email)->and($msg->kind)->toBe(MessageKind::Marketing)
        ->and($msg->idempotencyKey)->toBe('review_request:'.$request->id.':email')
        ->and($msg->context['enlaces']['resena'])->toBe(ReviewToken::reviewUrl($request).'?nota=')
        ->and($msg->context['pedido']['numero'])->toBe('1234')
        ->and($msg->context['producto']['nombre'])->toBe('Pintura Azul 1 galón');
    $template = $this->reviewsInWs(fn () => Template::query()->whereKey($msg->templateId)->first());
    expect($template->library_key)->toBe('review_request');
    expect($request->status)->toBe('sent')->and($request->channel)->toBe('email')->and($request->message_id)->not->toBeNull();

    $rows = $this->chRows($this->ws, "SELECT contact_id, toJSONString(properties) AS p FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'review.requested'");
    expect($rows)->toHaveCount(1);
    expect(json_decode($rows[0]['p'], true))->toMatchArray(['request_id' => $request->publicId(), 'order_id' => '1234', 'channel' => 'email', 'products' => ['88', '91']]);
});

it('si la política aplaza, la solicitud espera hasta `until` y se reintenta con la misma clave', function () {
    $this->reviewsEnable(['days' => 0]);
    $this->reviewsOrder();
    $until = CarbonImmutable::now()->addHours(14)->startOfMinute();
    $this->fake->respondWith(fn () => DispatchResult::deferred($until, 'quiet_hours'));

    expect(app(SendReviewRequests::class)->handle(CarbonImmutable::now()->addMinute())['deferred'])->toBe(1);
    $request = $this->reviewsInWs(fn () => ReviewRequest::query()->firstOrFail());
    expect($request->status)->toBe('scheduled')->and($request->scheduled_for->equalTo($until))->toBeTrue();

    // Antes de `until` no se intenta; después sí, con la misma clave.
    expect(app(SendReviewRequests::class)->handle(CarbonImmutable::now()->addHours(2))['deferred'])->toBe(0);
    $this->fake->respondWith(null);
    expect(app(SendReviewRequests::class)->handle($until->addMinute())['sent'])->toBe(1);
    expect($this->fake->dispatched[0]->idempotencyKey)->toBe('review_request:'.$request->id.':email');
});

it('omite la solicitud si ningún canal sirve, si el pedido se canceló o si ya reseñó todo', function () {
    $this->reviewsEnable(['days' => 0, 'channels' => ['whatsapp']]);   // sin plantilla de WhatsApp
    $this->reviewsOrder();
    app(SendReviewRequests::class)->handle(CarbonImmutable::now()->addMinute());
    $request = $this->reviewsInWs(fn () => ReviewRequest::query()->firstOrFail());
    expect($request->status)->toBe('skipped')->and($request->skip_reason)->toBe('no_template');
    expect($this->fake->dispatched)->toBe([]);

    $this->reviewsEnable(['days' => 0]);
    $order = $this->reviewsOrder('3000', withRoller: false);
    $this->reviewsInWs(fn () => Review::query()->create([
        'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'external_product_id' => '88', 'contact_id' => $order->contact_id,
        'rating' => 4, 'status' => 'pending', 'source' => 'request', 'verified_buyer' => true,
    ]));
    app(SendReviewRequests::class)->handle(CarbonImmutable::now()->addMinute());
    expect($this->reviewsInWs(fn () => ReviewRequest::query()->where('order_id', $order->id)->value('skip_reason')))->toBe('already_reviewed');
});
