<?php

use App\Domain\Flows\Actions\ChangeFlowStatus;
use App\Domain\Flows\Actions\EnrollContacts;
use App\Domain\Flows\Actions\PublishFlow;
use App\Domain\Flows\Actions\SaveFlow;
use App\Domain\Flows\Graph\Graph;
use App\Domain\Flows\Graph\GraphValidator;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Models\FlowRunStep;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Flows\Support\FlowFixtures;
use Tests\Feature\Flows\Support\PolicyDispatcherStub;

/*
 * «Despierta la base dormida» (auditoría §5.1 función 7): el nodo «Reactivar con IA»
 * escribe según la última conversación y las compras, solo sale a quien tiene
 * consentimiento de marketing en el canal y en horario legal (SendPolicy), fuera de la
 * ventana de 24 h solo con una plantilla de marketing aprobada (el texto va en su variable)
 * y el texto pasa por la validación de cifras y datos.
 */
uses(FlowFixtures::class);

beforeEach(function () {
    $this->flowWorld();
    // Martes 17 de noviembre de 2026, 10:00 en Bogotá: horario legal.
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
    $this->wa = ($this->in)(fn () => ChannelAccount::query()->where('channel', 'whatsapp')->firstOrFail());
    $this->template = strtoupper((string) Str::ulid());
    DB::table('templates')->insert(['id' => $this->template, 'workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'name' => 'reactivacion',
        'category' => 'marketing', 'library_key' => 'wa_reactivation', 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
    $this->stub = PolicyDispatcherStub::install();
});

function reactivateFlow($test, array $config = ['channels' => ['whatsapp', 'email']]): Flow
{
    return ($test->in)(function () use ($config) {
        $graph = ['nodes' => [['id' => 'trigger', 'type' => 'trigger', 'config' => []], ['id' => 'r', 'type' => 'ai_reactivate', 'config' => $config]],
            'edges' => [['from' => 'trigger', 'to' => 'r']]];
        $flow = app(SaveFlow::class)->handle(null, ['name' => 'Base dormida', 'trigger' => ['type' => 'manual'], 'reentry_policy' => ['mode' => 'always'], 'graph' => $graph])['flow'];
        app(PublishFlow::class)->handle($flow);
        app(ChangeFlowStatus::class)->handle($flow->refresh(), Flow::ACTIVE);

        return $flow->refresh();
    });
}

function reactivateRun($test, Flow $flow, $contact = null): FlowRun
{
    $contact ??= $test->contact;
    ($test->in)(fn () => app(EnrollContacts::class)->handle($flow, [$contact->id]));
    $test->artisan('igr:flows:tick')->assertSuccessful();

    return ($test->in)(fn () => FlowRun::query()->where('flow_id', $flow->id)->where('contact_id', $contact->id)->latest('created_at')->firstOrFail());
}

/** Conversación de hace 2 meses y un pedido pagado con sus productos. */
function reactivateHistory($test, $contact, bool $open = false): Conversation
{
    return ($test->in)(function () use ($test, $contact, $open) {
        $c = Conversation::query()->create(['workspace_id' => $test->ws->id, 'contact_id' => $contact->id, 'channel_account_id' => $test->wa->id, 'status' => 'resolved',
            'window_expires_at' => $open ? now()->addHours(5) : now()->subDays(60), 'last_inbound_at' => $open ? now()->subHours(19) : now()->subDays(61),
            'last_message_at' => $open ? now()->subHours(19) : now()->subDays(61)]);
        foreach ([['in', '¿Tienen pintura para exteriores que aguante lluvia? Mi correo es ana@ejemplo.com'], ['out', 'Sí, la Pintura Fachada Max.']] as $i => [$dir, $text]) {
            Message::query()->forceCreate(['workspace_id' => $test->ws->id, 'contact_id' => $contact->id, 'channel_account_id' => $test->wa->id, 'channel' => 'whatsapp',
                'provider' => 'meta_cloud', 'provider_message_id' => 'wamid.'.Str::random(8), 'direction' => $dir, 'kind' => 'service', 'source_type' => 'inbox',
                'status' => $dir === 'in' ? 'received' : 'sent', 'payload' => ['text' => $text], 'conversation_id' => $c->id, 'created_at' => now()->subDays(61)->addMinutes($i)]);
        }
        $order = strtoupper((string) Str::ulid());
        DB::table('orders')->insert(['id' => $order, 'workspace_id' => $test->ws->id, 'site_id' => $test->site->id, 'external_id' => (string) random_int(10000, 99999), 'contact_id' => $contact->id,
            'status' => 'completed', 'is_paid' => true, 'currency' => 'COP', 'total' => 282000, 'created_at' => now()->subDays(90), 'updated_at' => now()->subDays(90)]);
        DB::table('order_items')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $test->ws->id, 'order_id' => $order, 'external_product_id' => '88',
            'name' => 'Pintura Fachada Max 1 galón', 'qty' => 2, 'unit_price' => 141000, 'line_total' => 282000]);
        $contact->forceFill(['orders_count' => 1, 'last_order_at' => now()->subDays(90)])->save();

        return $c;
    });
}

it('escribe según la última conversación y las compras y, fuera de la ventana, sale en la plantilla de marketing con el texto en su variable', function () {
    reactivateHistory($this, $this->contact);
    $ai = FakeAiGateway::install()->replyJson([
        'resumen' => 'Preguntó por pintura para exteriores que aguante la lluvia.',
        'mensaje' => "Hola Ana, ¿cómo te fue con la Pintura Fachada Max?\nSi vas a retocar otra pared antes de las lluvias, te ayudo a elegir. ¿Te cuento?",
    ]);
    $run = reactivateRun($this, reactivateFlow($this));

    $facts = (string) $ai->requests[0]['request']->messages[0]['content'];
    expect($ai->requests[0]['request']->feature)->toBe('reactivation')
        ->and($facts)->toContain('pintura para exteriores')->toContain('Pintura Fachada Max 1 galón')->toContain('"dias_desde_la_ultima": 90')
        ->and($facts)->not->toContain('ana@ejemplo.com')->not->toContain('3001234567');
    $sent = array_values($this->stub->sent);
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['message']->channel->value)->toBe('whatsapp')->and($sent[0]['message']->kind->value)->toBe('marketing')
        ->and($sent[0]['message']->templateId)->toBe($this->template)
        // El valor de una variable no lleva saltos de línea (Meta los rechaza).
        ->and($sent[0]['message']->context['reactivacion']['mensaje'])->toBe('Hola Ana, ¿cómo te fue con la Pintura Fachada Max? Si vas a retocar otra pared antes de las lluvias, te ayudo a elegir. ¿Te cuento?');
    $step = ($this->in)(fn () => FlowRunStep::query()->where('run_id', $run->id)->where('node_id', 'r')->sole());
    expect($step->outcome)->toBe('sent')->and($step->details['source'])->toBe('ai')
        ->and(decrypt($step->details['summary']))->toContain('exteriores')
        ->and(json_encode($step->details))->not->toContain('exteriores');
});

it('con la ventana de 24 h abierta va como texto libre, y una cifra inventada o una promesa no autorizada se descartan', function () {
    reactivateHistory($this, $this->contact, open: true);
    FakeAiGateway::install()
        ->replyJson(['resumen' => 'Preguntó por pintura de exteriores.', 'mensaje' => 'Hola Ana, esta semana tenemos 30% en la Pintura Fachada Max.'])
        ->replyJson(['resumen' => 'Preguntó por pintura de exteriores.', 'mensaje' => 'Hola Ana, te damos un descuento especial en pintura de exteriores. ¿Te interesa?']);
    reactivateRun($this, reactivateFlow($this));
    $sent = array_values($this->stub->sent);
    $text = (string) $sent[0]['message']->context['text'];
    expect($sent[0]['message']->templateId)->toBeNull()->and($sent[0]['message']->kind->value)->toBe('marketing')
        ->and($text)->not->toContain('30%')->not->toContain('descuento')
        ->and($text)->toContain('Pinturas La 14')->toContain('Pintura Fachada Max 1 galón');

    // Con una oferta que puso el dueño, la IA sí puede usarla.
    $other = $this->flowContact('luis@ejemplo.com', '+573005550001');
    reactivateHistory($this, $other, open: true);
    FakeAiGateway::install()->replyJson(['resumen' => 'Preguntó por pintura.', 'mensaje' => 'Hola Ana, para tu próxima pared tienes 10% con el código VUELVE10. ¿Te ayudo?']);
    reactivateRun($this, reactivateFlow($this, ['channels' => ['whatsapp', 'email'], 'offer' => '10% en tu próxima compra con el código VUELVE10']), $other);
    expect((string) end($this->stub->sent)['message']->context['text'])->toContain('VUELVE10')->toContain('10%');
});

it('sin consentimiento no llama a la IA ni envía; en domingo se aplaza sin escribir y sale el lunes en horario legal', function () {
    $nobody = $this->flowContact('nadie@ejemplo.com', '+573005550009', []);
    $ai = FakeAiGateway::install();
    $run = reactivateRun($this, reactivateFlow($this), $nobody);
    expect($ai->requests)->toBe([])->and($this->stub->sent)->toBe([])
        ->and(($this->in)(fn () => FlowRunStep::query()->where('run_id', $run->id)->where('node_id', 'r')->value('outcome')))->toBe('denied');

    // Domingo: SendPolicy aplaza (Ley 2300) y no se gasta una llamada a la IA.
    $this->travelTo(CarbonImmutable::parse('2026-11-22 11:00:00', 'America/Bogota'));
    $sunday = $this->flowContact('domingo@ejemplo.com', '+573005550011');
    $flow = reactivateFlow($this, ['channels' => ['email']]);
    $run = reactivateRun($this, $flow, $sunday);
    expect($run->status)->toBe('waiting')->and($ai->requests)->toBe([])->and($this->stub->sent)->toBe([]);

    $ai->replyJson(['resumen' => '', 'mensaje' => 'Hola Ana, hace rato no hablamos. ¿Te ayudo con algo para tu casa?']);
    $this->travelTo(CarbonImmutable::parse('2026-11-23 07:05:00', 'America/Bogota'));
    $this->artisan('igr:flows:tick')->assertSuccessful();
    $sent = array_values($this->stub->sent);
    expect($sent)->toHaveCount(1)->and($sent[0]['message']->channel->value)->toBe('email')
        ->and($this->stub->sentAtBogota()[0])->toBe('2026-11-23 07:05')
        ->and(count($ai->requests))->toBe(1);
});

it('valida el nodo: canales, plantilla de marketing (no de utilidad), oferta y días; el grupo de control no recibe nada', function () {
    $utility = strtoupper((string) Str::ulid());
    DB::table('templates')->insert(['id' => $utility, 'workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'name' => 'aviso',
        'category' => 'utility', 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
    $errors = fn (array $config) => ($this->in)(fn () => app(GraphValidator::class)->validate(
        Graph::fromArray(['nodes' => [['id' => 'trigger', 'type' => 'trigger', 'config' => []], ['id' => 'r', 'type' => 'ai_reactivate', 'config' => $config]], 'edges' => [['from' => 'trigger', 'to' => 'r']]]), $this->ws->id));
    expect($errors(['channels' => ['sms']])['r'])->toContain('Elige por qué canales sale el mensaje: WhatsApp, correo o los dos.')
        ->and($errors(['channels' => ['whatsapp'], 'template_id' => 'tpl_'.$utility])['r'])->toContain('Elige una plantilla de WhatsApp de marketing aprobada.')
        ->and($errors(['channels' => ['email'], 'lookback_days' => 2])['r'])->toContain('La última conversación se busca entre 7 y 730 días atrás.')
        ->and($errors(['channels' => ['whatsapp', 'email'], 'template_id' => 'tpl_'.$this->template, 'offer' => '10% con VUELVE10', 'lookback_days' => 180]))->toBe([]);

    $flow = reactivateFlow($this);
    ($this->in)(fn () => $flow->forceFill(['holdout_pct' => 100])->save());
    $ai = FakeAiGateway::install();
    $run = reactivateRun($this, $flow->refresh());
    expect($ai->requests)->toBe([])->and($this->stub->sent)->toBe([])
        ->and(($this->in)(fn () => FlowRunStep::query()->where('run_id', $run->id)->where('node_id', 'r')->value('outcome')))->toBe('skipped_holdout');
});
