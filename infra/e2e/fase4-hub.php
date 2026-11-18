<?php

/*
 * Lado del hub de la prueba de la Fase 4 (infra/e2e/fase4.sh). Subcomandos:
 *
 *   php fase4-hub.php setup                         → dueña con 2FA y dos espacios de trabajo: «Pinturas Demo» (la tienda
 *                                                       demo de igr:seed-demo, para predicciones y el analista) y
 *                                                       «Pinturas La 14» (la tienda WooCommerce real, con WhatsApp hacia
 *                                                       la Graph API simulada y el agente de ventas en automático).
 *                                                       Imprime: ws_demo ws_tienda código
 *   php fase4-hub.php preds ws_…                    → resumen de prediction_runs (código 1 si los modelos no validaron)
 *   php fase4-hub.php report ws_…                   → el último informe semanal: origen (ai/fallback) y validación
 *   php fase4-hub.php ask ws_… "pregunta"           → «Pregúntale a tus datos» como la dueña (la misma acción del API)
 *   php fase4-hub.php inbound ws_… +57… "texto" id  → un WhatsApp entrante por el webhook firmado de Meta (HTTP al hub)
 *   php fase4-hub.php turn ws_… +57…                → el último turno del agente con esa persona y lo que se le envió
 *   php fase4-hub.php paylink ws_… +57… productoId  → la bandeja envía el enlace de pago (order.create_payment_link)
 *
 * Nada de datos personales en la salida: identificadores, conteos, montos y los textos que escribió el agente.
 */

declare(strict_types=1);

use App\Domain\Contacts\Models\Contact;
use App\Domain\Inbox\Actions\SendInboxMessage;
use App\Domain\Intelligence\Ai\Agent\Models\AgentSettings;
use App\Domain\Intelligence\Ai\Agent\Models\AgentTurn;
use App\Domain\Intelligence\Ai\Features\AskData\AskYourData;
use App\Domain\Intelligence\Ai\Features\Models\AiReport;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Sites\Actions\CreateConnectCode;
use App\Domain\Workspaces\Actions\CreateWorkspace;
use App\Domain\Workspaces\Data\WorkspaceData;
use App\Domain\Workspaces\Models\User;
use App\Support\Console\WorkspaceOption;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$hub = dirname(__DIR__, 2).'/hub';
require $hub.'/vendor/autoload.php';
$app = require $hub.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$cmd = $argv[1] ?? '';
$current = app(CurrentWorkspace::class);
$out = fn (array $data) => print(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

if ($cmd === 'setup') {
    $owner = User::create(['name' => 'Dueña F4', 'email' => getenv('OWNER_EMAIL') ?: 'duena.f4@tienda.test', 'password' => getenv('OWNER_PASS') ?: 'Clave-f4-2026']);
    $owner->forceFill(['two_factor_secret' => encrypt(getenv('TOTP_SECRET') ?: 'JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
    $demo = app(CreateWorkspace::class)->handle($owner, new WorkspaceData(name: 'Pinturas Demo', industry: 'ferreteria_pinturas'));
    $shop = app(CreateWorkspace::class)->handle($owner, new WorkspaceData(name: 'Pinturas La 14', industry: 'ferreteria_pinturas'));
    foreach ([$demo, $shop] as $ws) {
        $ws->forceFill(['country' => 'CO', 'timezone' => 'America/Bogota', 'currency' => 'COP'])->save();
    }
    $current->run($shop, function () use ($shop, $owner) {
        ChannelAccount::query()->create([
            'workspace_id' => $shop->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud', 'display_name' => 'WhatsApp de la tienda',
            'status' => 'active', 'quality_rating' => 'GREEN', 'is_default' => true, 'external_id' => '1111', 'identity' => '+57 300 000 0000',
            'config' => ['waba_id' => '2222', 'phone_number_id' => '1111', 'access_token' => 'EAAB-e2e-token'],
        ]);
        // Lo que la comerciante deja en «Bandeja → Agente de IA»: activo en automático, con sus políticas.
        AgentSettings::query()->create([
            'workspace_id' => $shop->id, 'enabled' => true, 'default_mode' => 'auto', 'max_steps' => 6, 'updated_by' => $owner->id,
            'scope' => 'Pinturas, ferretería y eléctricos de la tienda Pinturas y Ferretería La 14.',
            'tone' => 'Cercano y claro, de tú.',
            'policies' => [
                'shipping' => 'Envíos a todo Colombia en 2 a 4 días hábiles; en Manizales, el mismo día.',
                'payment_methods' => 'Tarjeta, PSE, Nequi y contraentrega.',
                'returns' => 'Cambios dentro de los 30 días con la factura.',
                'hours' => 'Lunes a sábado de 8:00 a 18:00.',
                'other' => '',
            ],
            'handoff_rules' => AgentSettings::defaultHandoff(),
            'discount_rules' => ['enabled' => false],
        ]);
    });
    $code = app(CreateConnectCode::class)->handle($shop, $owner, [])['code'];
    echo $demo->publicId(), ' ', $shop->publicId(), ' ', $code, "\n";
    exit(0);
}

$ws = WorkspaceOption::resolve($argv[2] ?? '')->firstOrFail();
$owner = User::query()->where('email', getenv('OWNER_EMAIL') ?: 'duena.f4@tienda.test')->firstOrFail();

exit($current->run($ws, function () use ($cmd, $ws, $argv, $owner, $out): int {
    $contactByPhone = fn (string $e164) => Contact::query()->where('phone_bidx', BlindIndex::phone($ws->id, $e164))->whereNull('merged_into_id')->first();

    switch ($cmd) {
        case 'preds':
            $runs = DB::table('prediction_runs')->where('workspace_id', $ws->id)->orderByDesc('trained_at')->get()->unique('model');
            $summary = [];
            foreach ($runs as $r) {
                $metrics = json_decode((string) $r->metrics, true) ?: [];
                $summary[$r->model] = ['status' => $r->status, 'metrics' => array_slice(array_filter($metrics, fn ($v) => is_scalar($v) || $v === null), 0, 12)];
            }
            $out($summary);
            // Validados contra la reserva: CLV, abandono y próxima compra entrenados y con sus métricas.
            $ok = true;
            foreach (['clv', 'churn', 'edno'] as $model) {
                $ok = $ok && ($summary[$model]['status'] ?? null) === 'completed' && ($summary[$model]['metrics'] ?? []) !== [];
            }

            return $ok ? 0 : 1;

        case 'report':
            $r = AiReport::query()->where('kind', 'weekly')->orderByDesc('updated_at')->firstOrFail();
            $out(['source' => $r->source, 'validation' => $r->validation, 'actions' => count((array) $r->actions), 'body' => mb_substr((string) $r->body_markdown, 0, 400)]);

            return 0;

        case 'ask':
            $q = app(AskYourData::class)->handle($ws, $owner, (string) ($argv[3] ?? '¿Cuánto vendí en los últimos 30 días?'));
            $out(['status' => $q->status, 'answer' => $q->answer, 'query' => $q->query, 'validation' => $q->validation]);

            return $q->status === 'answered' ? 0 : 1;

        case 'inbound':
            $from = ltrim((string) $argv[3], '+');
            $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => '2222', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1111', 'display_phone_number' => '573000000000'],
                'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Cliente E2E']]],
                'messages' => [['from' => $from, 'id' => (string) $argv[5], 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => (string) $argv[4]]]],
            ]]]]]];
            $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
            $res = Http::withBody($body, 'application/json')
                ->withHeaders(['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, (string) config('igr-messaging.meta.app_secret'))])
                ->post(rtrim((string) getenv('HUB'), '/').'/webhooks/meta');
            $out(['status' => $res->status()]);

            return $res->successful() ? 0 : 1;

        case 'turn':
            $contact = $contactByPhone((string) $argv[3]) ?? throw new RuntimeException('No existe ese contacto');
            $conversation = Conversation::query()->where('contact_id', $contact->id)->firstOrFail();
            $turn = AgentTurn::query()->where('conversation_id', $conversation->id)->orderByDesc('created_at')->first();
            $sent = Message::query()->where('contact_id', $contact->id)->where('direction', 'out')->orderBy('created_at')->get()
                ->map(fn (Message $m) => ['status' => $m->status, 'kind' => $m->kind instanceof BackedEnum ? $m->kind->value : $m->kind, 'text' => (string) ($m->payload['text'] ?? '')])->all();
            $out([
                'turn' => $turn === null ? null : [
                    'status' => $turn->status, 'handoff_reason' => $turn->handoff_reason, 'mode' => $turn->mode,
                    'tools' => array_map(fn ($c) => (string) ($c['name'] ?? '?'), (array) $turn->tool_calls),
                    'tool_calls' => $turn->tool_calls, 'validation' => $turn->validation,
                ],
                'conversation' => ['ai_mode' => $conversation->ai_mode, 'handoff_reason' => $conversation->handoff_reason, 'intent' => $conversation->intent],
                'sent' => $sent,
            ]);

            return 0;

        case 'paylink':
            $contact = $contactByPhone((string) $argv[3]) ?? throw new RuntimeException('No existe ese contacto');
            $conversation = Conversation::query()->where('contact_id', $contact->id)->firstOrFail();
            $result = app(SendInboxMessage::class)->handle($conversation, ['type' => 'payment_link', 'items' => [['product_id' => (string) $argv[4], 'qty' => 1]]], $owner->id);
            $message = Message::query()->where('contact_id', $contact->id)->where('direction', 'out')->orderByDesc('created_at')->first();
            $text = (string) ($message?->payload['text'] ?? '');
            preg_match('#https?://\S+#', $text, $m);
            $out(['result' => array_intersect_key($result, array_flip(['status', 'message_id'])), 'url' => $m[0] ?? null]);

            return isset($m[0]) ? 0 : 1;
    }
    fwrite(STDERR, "Subcomando desconocido: {$cmd}\n");

    return 2;
}));
