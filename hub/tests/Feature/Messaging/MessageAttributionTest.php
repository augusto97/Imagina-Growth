<?php

use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Measurement\Jobs\AttributeOrder;
use App\Domain\Messaging\Attribution\MessageAttributor;
use App\Domain\Messaging\Models\Message;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Tests\Feature\Messaging\Support\MessagingFixtures;

uses(MessagingFixtures::class);

/*
 * Paso 2 de AttributeOrder (hub/CLAUDE.md §9): último mensaje con clic en la
 * ventana (5 días); si no hay y se cuentan aperturas, lectura o apertura sin MPP
 * en 1 día. Una apertura automática de Apple (MPP) nunca atribuye.
 */

beforeEach(function () {
    $this->setUpMessaging();
    [$this->site] = $this->connectedSite($this->ws);
    $this->at = CarbonImmutable::parse('2026-11-17 15:00:00', 'America/Bogota');
});

function sentMessage($test, array $attrs, ?string $contactId = null): Message
{
    return app(CurrentWorkspace::class)->run($test->ws, function () use ($test, $attrs, $contactId) {
        $m = new Message;
        $m->forceFill($attrs + [
            'workspace_id' => $test->ws->id, 'contact_id' => $contactId ?? $test->contact->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud',
            'direction' => 'out', 'kind' => 'marketing', 'source_type' => 'flow', 'status' => 'delivered', 'queued_at' => $test->at->subDays(3),
        ])->save();

        return $m;
    });
}

function paidOrder($test, string $external = '1001'): Order
{
    return app(CurrentWorkspace::class)->run($test->ws, function () use ($test, $external) {
        $o = new Order;
        $o->forceFill(['workspace_id' => $test->ws->id, 'site_id' => $test->site->id, 'external_id' => $external, 'status' => 'processing', 'currency' => 'COP',
            'contact_id' => $test->contact->id, 'is_paid' => true, 'total' => '150000', 'created_at' => $test->at, 'updated_at' => $test->at])->save();

        return $o;
    });
}

function attribute($test, Order $order): ?Message
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => app(MessageAttributor::class)->attribute($test->ws, $order));
}

it('atribuye al último mensaje con clic dentro de 5 días (el más reciente gana) con su flujo o campaña', function () {
    $old = sentMessage($this, ['clicked_at' => $this->at->subDays(4), 'flow_id' => 'FLOWVIEJO00000000000000000']);
    $recent = sentMessage($this, ['clicked_at' => $this->at->subHours(2), 'source_type' => 'campaign', 'campaign_id' => 'CAMPANA000000000000000000A']);
    sentMessage($this, ['clicked_at' => $this->at->addHour()]); // después del pedido: no cuenta
    $order = paidOrder($this);
    expect(attribute($this, $order)?->id)->toBe($recent->id);
    expect($order->fresh()->only(['attributed_message_id', 'attributed_flow_id', 'attributed_campaign_id']))
        ->toBe(['attributed_message_id' => $recent->id, 'attributed_flow_id' => null, 'attributed_campaign_id' => 'CAMPANA000000000000000000A']);
    expect($old->id)->not->toBe($recent->id);
});

it('un clic de hace 6 días no atribuye; las lecturas solo cuentan si se activan, y MPP nunca', function () {
    sentMessage($this, ['clicked_at' => $this->at->subDays(6)]);
    $read = sentMessage($this, ['read_at' => $this->at->subHours(10)]);
    sentMessage($this, ['channel' => 'email', 'provider' => 'ses', 'opened_at' => null, 'payload' => ['mpp_open' => true]]);
    $order = paidOrder($this);
    expect(attribute($this, $order))->toBeNull()->and($order->fresh()->attributed_message_id)->toBeNull();

    $this->ws->forceFill(['settings' => ['attribution' => ['click_days' => 5, 'open_days' => 1, 'count_opens' => true]] + $this->ws->settings])->save();
    expect(attribute($this, $order)?->id)->toBe($read->id);
});

it('cuenta los mensajes de un contacto fusionado y corre dentro de AttributeOrder', function () {
    $absorbed = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'first_name' => 'Ana R.', 'merged_into_id' => $this->contact->id]));
    $message = sentMessage($this, ['clicked_at' => $this->at->subDay()], $absorbed->id);
    $order = paidOrder($this, '2002');
    app(CurrentWorkspace::class)->run($this->ws, fn () => app()->call([new AttributeOrder($this->ws->id, $this->site->id, '2002'), 'handle']));
    expect($order->fresh()->attributed_message_id)->toBe($message->id);
});
