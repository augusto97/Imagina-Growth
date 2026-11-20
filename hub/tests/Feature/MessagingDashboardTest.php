<?php

use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Ingest\HubEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * F11 (docs/auditoria-2026-11.md): el tablero de mensajería con datos reales de `messages` y sus eventos, por canal
 * y por flujo o campaña, y las ventas atribuidas.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    config(['igr.analytics.cache' => false]);
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
    $this->url = "/api/w/{$this->ws->publicId()}/analytics/messaging";
    $this->flow = (string) Str::ulid();
    DB::table('flows')->insert([
        'id' => $this->flow, 'workspace_id' => $this->ws->id, 'name' => 'Carrito abandonado', 'status' => 'active', 'template_key' => 'abandoned_cart',
        'trigger' => json_encode(['type' => 'event']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->campaign = (string) Str::ulid();
    DB::table('campaigns')->insert([
        'id' => $this->campaign, 'workspace_id' => $this->ws->id, 'name' => 'Black Friday', 'channel' => 'email', 'status' => 'sent',
        'audience' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
    ]);
});

/** Un mensaje saliente con su estado y sus marcas de tiempo. */
function outMessage(string $workspaceId, array $attrs): string
{
    $id = (string) Str::ulid();
    DB::table('messages')->insert(array_replace([
        'id' => $id, 'workspace_id' => $workspaceId, 'contact_id' => (string) Str::ulid(), 'channel' => 'whatsapp', 'direction' => 'out',
        'kind' => 'marketing', 'source_type' => 'flow', 'status' => 'sent', 'sent_at' => now(), 'created_at' => now()->subHour(), 'updated_at' => now(),
    ], $attrs));

    return $id;
}

it('cuenta enviados, entregados, leídos, clics, respuestas, rebotes, bajas y ventas por canal y por flujo o campaña', function () {
    $ws = $this->ws->id;
    $wa1 = outMessage($ws, ['flow_id' => $this->flow, 'status' => 'read', 'delivered_at' => now(), 'read_at' => now(), 'clicked_at' => now()]);
    outMessage($ws, ['flow_id' => $this->flow, 'status' => 'delivered', 'delivered_at' => now(), 'replied_at' => now()]);
    outMessage($ws, ['flow_id' => $this->flow, 'status' => 'failed', 'sent_at' => null]);
    $em1 = outMessage($ws, ['channel' => 'email', 'source_type' => 'campaign', 'campaign_id' => $this->campaign, 'status' => 'delivered', 'delivered_at' => now(), 'opened_at' => now()]);
    outMessage($ws, ['channel' => 'email', 'source_type' => 'campaign', 'campaign_id' => $this->campaign, 'status' => 'bounced']);
    // Lo que nunca salió no cuenta: negado por la política o del grupo de control.
    outMessage($ws, ['channel' => 'email', 'source_type' => 'campaign', 'campaign_id' => $this->campaign, 'status' => 'denied', 'sent_at' => null]);
    outMessage($ws, ['flow_id' => $this->flow, 'status' => 'skipped', 'sent_at' => null]);
    // Fuera del periodo.
    outMessage($ws, ['flow_id' => $this->flow, 'created_at' => now()->subDays(90)]);

    $hub = app(HubEvents::class);
    $base = ['kind' => 'marketing', 'source_type' => 'campaign', 'campaign_id' => $this->campaign];
    $hub->emit($this->ws, 'message.opened', $base + ['message_id' => 'msg_'.$em1, 'channel' => 'email', 'is_mpp' => false], null);
    $hub->emit($this->ws, 'message.opened', $base + ['message_id' => 'msg_x', 'channel' => 'email', 'is_mpp' => true], null);  // Apple MPP: no cuenta
    $hub->emit($this->ws, 'message.unsubscribed', $base + ['message_id' => 'msg_'.$em1, 'channel' => 'email', 'scope' => 'channel', 'via' => 'link'], null);

    // Una venta atribuida al WhatsApp del flujo.
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['created_at' => now()->toIso8601String(), 'paid_at' => now()->toIso8601String()])])]);
    $this->runWorker();
    DB::table('orders')->where('workspace_id', $ws)->update(['attributed_message_id' => $wa1, 'attributed_flow_id' => $this->flow]);

    $data = $this->actingAs($this->owner)->getJson($this->url.'?compare=none')->assertOk()->json('data');

    expect($data['available'])->toBeTrue()->and($data['empty'])->toBeFalse()->and($data['opens_exclude_mpp'])->toBeTrue()
        ->and($data['totals'])->toMatchArray([
            // El rebote sí salió (el proveedor lo aceptó y después rebotó); el fallido no.
            'total' => 5, 'sent' => 4, 'delivered' => 3, 'read' => 1, 'opened' => 1, 'clicked' => 1, 'replied' => 1,
            'unsubscribed' => 1, 'bounced' => 1, 'failed' => 1, 'orders' => 1, 'revenue' => 282000,
        ]);

    $channels = collect($data['by_channel'])->keyBy('channel');
    expect($channels['whatsapp'])->toMatchArray(['sent' => 2, 'read' => 1, 'failed' => 1, 'orders' => 1, 'revenue' => 282000])
        ->and($channels['email'])->toMatchArray(['sent' => 2, 'opened' => 1, 'bounced' => 1, 'unsubscribed' => 1, 'orders' => 0]);

    $sources = collect($data['by_source'])->keyBy('id');
    expect($sources['fl_'.$this->flow])->toMatchArray(['type' => 'flow', 'name' => 'Carrito abandonado', 'channels' => ['whatsapp'], 'sent' => 2, 'clicked' => 1, 'revenue' => 282000])
        ->and($sources['cmp_'.$this->campaign])->toMatchArray(['type' => 'campaign', 'name' => 'Black Friday', 'opened' => 1, 'unsubscribed' => 1]);
    expect(array_sum(array_column($data['series'], 'sent')))->toBe(4);
});

it('sin mensajes responde vacío y no mezcla espacios de trabajo', function () {
    $other = $this->workspaceFor($owner2 = $this->userWith2fa(), 'Otra tienda');
    outMessage($other->id, ['status' => 'delivered', 'delivered_at' => now()]);

    $data = $this->actingAs($this->owner)->getJson($this->url)->assertOk()->json('data');
    expect($data['empty'])->toBeTrue()->and($data['totals']['sent'])->toBe(0);

    $theirs = $this->actingAs($owner2)->getJson("/api/w/{$other->publicId()}/analytics/messaging")->assertOk()->json('data');
    expect($theirs['totals']['sent'])->toBe(1);

    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    $this->actingAs($agent)->getJson($this->url)->assertForbidden();
});
