<?php

/*
 * Lado del hub de la prueba de la Fase 2 (infra/e2e/fase2.sh). Subcomandos:
 *
 *   php fase2-hub.php setup                 → dueña con 2FA, espacio de trabajo, correo por SMTP (buzón local),
 *                                              datos del negocio y el flujo «Carrito abandonado» de la biblioteca activo,
 *                                              con sus esperas en 1 minuto (versión publicada).
 *                                              Imprime: ws_… código flujo
 *   php fase2-hub.php reloj ws_… correo     → zona horaria de la clienta: la de Bogotá si la ventana legal está abierta
 *                                              ahora; si no, una donde lo esté (el paso de política se prueba aparte).
 *   php fase2-hub.php enlace ws_… correo    → el enlace rastreado del correo que lleva al carrito restaurado
 *   php fase2-hub.php estado ws_… correo    → resumen de una línea del run y los mensajes
 *   php fase2-hub.php check ws_… correo pedido → comprobaciones finales (código 1 si algo falla)
 *
 * Nada de datos personales en la salida: solo identificadores y conteos.
 */

declare(strict_types=1);

use App\Analytics\Ch;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Order;
use App\Domain\Consent\Policy\LegalWindow;
use App\Domain\Consent\Policy\PolicyDecision;
use App\Domain\Consent\Policy\PolicyRequest;
use App\Domain\Consent\Policy\SendPolicy;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Actions\CreateFlowFromLibrary;
use App\Domain\Flows\Actions\PublishFlow;
use App\Domain\Flows\Actions\SaveFlow;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Models\FlowVersion;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Sites\Actions\CreateConnectCode;
use App\Domain\Workspaces\Actions\CreateWorkspace;
use App\Domain\Workspaces\Data\WorkspaceData;
use App\Domain\Workspaces\Models\User;
use App\Support\Console\WorkspaceOption;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$hub = dirname(__DIR__, 2).'/hub';
require $hub.'/vendor/autoload.php';
$app = require $hub.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$cmd = $argv[1] ?? '';
$current = app(CurrentWorkspace::class);
$contactFor = function (string $email, $ws): ?Contact {
    return Contact::query()->where('email_bidx', BlindIndex::email($ws->id, $email))->whereNull('merged_into_id')->first();
};

if ($cmd === 'setup') {
    $owner = User::create(['name' => 'Dueña F2', 'email' => getenv('OWNER_EMAIL') ?: 'duena.f2@tienda.test', 'password' => getenv('OWNER_PASS') ?: 'Clave-f2-2026']);
    $owner->forceFill(['two_factor_secret' => encrypt(getenv('TOTP_SECRET') ?: 'JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
    $ws = app(CreateWorkspace::class)->handle($owner, new WorkspaceData(name: 'Pinturas La 14'));
    $current->run($ws, function () use ($ws, $owner, &$flowId, &$issues) {
        $settings = $ws->settings ?? [];
        $settings['business_profile'] = ['name' => 'Pinturas y Ferretería La 14', 'address' => 'Calle 23 # 21-41, Manizales, Caldas', 'contact' => 'hola@pinturasla14.test · 606 884 0000', 'whatsapp' => '573001234567', 'logo_url' => ''];
        $ws->forceFill(['settings' => $settings])->save();
        ChannelAccount::query()->create([
            'workspace_id' => $ws->id, 'channel' => 'email', 'provider' => 'smtp', 'display_name' => 'Correo de la tienda',
            'identity' => 'pinturasla14.test', 'status' => 'active', 'is_default' => true,
            'config' => ['host' => '127.0.0.1', 'port' => (int) (getenv('SMTP_PORT') ?: 2526), 'from_email' => 'hola@pinturasla14.test', 'from_name' => 'Pinturas La 14'],
        ]);
        $result = app(CreateFlowFromLibrary::class)->handle('abandoned_cart', true, $owner->id);
        $flow = $result['flow'];
        $flowId = $flow->publicId();
        $issues = $result['issues'];
        if (! $result['activated']) {
            fwrite(STDERR, 'El flujo no se activó: '.json_encode($issues, JSON_UNESCAPED_UNICODE)."\n");
            exit(1);
        }
        // Como lo haría la comerciante en el constructor: esperas de 1 minuto para la prueba (1 h, 4 h y 24 h
        // en la biblioteca), guardar y publicar una versión nueva.
        $graph = FlowVersion::query()->findOrFail($flow->current_version_id)->graph;
        foreach ($graph['nodes'] as &$node) {
            if ($node['type'] === 'wait' && ($node['config']['mode'] ?? null) === 'duration') {
                $node['config']['duration'] = ['value' => 1, 'unit' => 'minutes'];
            }
        }
        unset($node);
        app(SaveFlow::class)->handle($flow, ['graph' => $graph], $owner->id);
        $published = app(PublishFlow::class)->handle($flow->fresh(), $owner->id);
        fwrite(STDERR, 'Esperas de 1 minuto publicadas: '.json_encode(array_keys($published), JSON_UNESCAPED_UNICODE)."\n");
    });
    $code = app(CreateConnectCode::class)->handle($ws, $owner, [])['code'];
    echo $ws->publicId(), ' ', $code, ' ', $flowId, "\n";
    exit(0);
}

$ws = WorkspaceOption::resolve($argv[2] ?? '')->firstOrFail();

exit($current->run($ws, function () use ($cmd, $ws, $argv, $contactFor): int {
    $email = $argv[3] ?? '';
    switch ($cmd) {
        case 'reloj':
            $contact = $contactFor($email, $ws) ?? throw new RuntimeException('No existe la clienta');
            $now = CarbonImmutable::now();
            $zones = ['America/Bogota', 'Asia/Tokyo', 'Europe/Madrid', 'Asia/Kolkata', 'Pacific/Auckland', 'America/Los_Angeles', 'Asia/Dubai'];
            foreach ($zones as $tz) {
                if (LegalWindow::isOpen('CO', $now, $tz) && LegalWindow::isOpen('CO', $now->addHour(), $tz)) {
                    $contact->forceFill(['country' => 'CO', 'timezone' => $tz])->save();
                    echo $tz, "\n";

                    return 0;
                }
            }
            fwrite(STDERR, "No hay ninguna zona con la ventana abierta ahora\n");

            return 1;

        case 'enlace':
            $contact = $contactFor($email, $ws) ?? throw new RuntimeException('No existe la clienta');
            $link = DB::table('tracked_links')->where('workspace_id', $ws->id)->where('contact_id', $contact->id)
                ->where('url', 'like', '%igr_cart=%')->orderByDesc('created_at')->first();
            if ($link === null) {
                return 1;
            }
            echo rtrim((string) config('igr-messaging.links_url'), '/').'/c/'.$link->code, "\n";

            return 0;

        case 'estado':
            $contact = $contactFor($email, $ws);
            $run = $contact !== null ? FlowRun::query()->where('contact_id', $contact->id)->latest('started_at')->first() : null;
            $msgs = $contact !== null ? DB::table('messages')->where('contact_id', $contact->id)->get(['channel', 'status', 'policy_reason']) : collect();
            echo json_encode([
                'run' => $run?->only(['status', 'current_node_id', 'exit_reason']) + ['next_run_at' => $run?->next_run_at?->toIso8601String()],
                'messages' => $msgs->map(fn ($m) => $m->channel.':'.$m->status.($m->policy_reason ? '('.$m->policy_reason.')' : ''))->all(),
                'carts' => $contact !== null ? Cart::query()->where('contact_id', $contact->id)->pluck('status')->all() : [],
            ], JSON_UNESCAPED_UNICODE), "\n";

            return 0;

        case 'check':
            return fase2Check($ws, $contactFor($email, $ws), (string) ($argv[4] ?? ''));
    }
    fwrite(STDERR, "Subcomando desconocido\n");

    return 2;
}));

function fase2Check($ws, ?Contact $contact, string $orderExternalId): int
{
    $failures = 0;
    $check = function (string $label, bool $ok, string $detail = '') use (&$failures): void {
        printf("%s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
        $failures += $ok ? 0 : 1;
    };
    $check('La clienta existe (índice ciego del correo)', $contact !== null);
    if ($contact === null) {
        return 1;
    }
    $ch = Ch::forWorkspace($ws);
    $flow = Flow::query()->where('template_key', 'abandoned_cart')->firstOrFail();

    // 1. Carrito: abandonado y después recuperado con el pedido del enlace.
    $order = Order::query()->where('external_id', $orderExternalId)->first();
    $check('El pedido de la recuperación está en el hub y pagado', $order !== null && (bool) $order->is_paid, 'pedido #'.$orderExternalId);
    $abandoned = Cart::query()->where('contact_id', $contact->id)->whereNotNull('abandoned_at')->first();
    $check('El carrito se marcó abandonado (job de carritos)', $abandoned !== null);
    $check('… y quedó recuperado con ese pedido', $abandoned !== null && $abandoned->status === 'recovered' && $order !== null && $abandoned->recovered_order_id === $order->id, $abandoned?->status ?? '—');
    $restored = Cart::query()->whereNotNull('restored_from_id')->where('restored_from_id', $abandoned?->id)->first();
    $check('El carrito nuevo salió del enlace de restauración (restored_from)', $restored !== null);
    $events = array_column($ch->select("SELECT event, count() AS n FROM events WHERE workspace_id = {workspace_id:UInt32} AND contact_id = {c:String} AND event IN ('cart.abandoned','flow.entered','flow.exited','message.queued','message.sent','message.clicked') GROUP BY event", ['c' => $contact->id]), 'n', 'event');
    $check('cart.abandoned en la historia de la clienta', (int) ($events['cart.abandoned'] ?? 0) === 1);

    // 2. Flujo: entró, envió el correo y salió por la meta (order.paid).
    $run = FlowRun::query()->where('flow_id', $flow->id)->where('contact_id', $contact->id)->first();
    $check('Entró al flujo «Carrito abandonado» de la biblioteca', $run !== null && (int) ($events['flow.entered'] ?? 0) >= 1);
    $check('… y salió por la meta al pagar (goal_reached)', $run !== null && $run->exit_reason === 'goal_reached', ($run->status ?? '—').' / '.($run->exit_reason ?? '—'));
    $steps = $run !== null ? DB::table('flow_run_steps')->where('run_id', $run->id)->pluck('outcome', 'node_id')->all() : [];
    $check('Pasos del run registrados una sola vez por nodo', $steps !== [], json_encode($steps) ?: '');

    // 3. Mensaje: un correo de marketing enviado por SMTP, con su clic.
    $messages = DB::table('messages')->where('contact_id', $contact->id)->get();
    $email = $messages->firstWhere('channel', 'email');
    $check('Un solo mensaje de marketing (la frecuencia de Colombia: 1 al día)', $messages->count() === 1, $messages->count().' mensajes');
    $check('El correo salió (SMTP) desde el flujo', $email !== null && in_array($email->status, ['sent', 'delivered'], true) && $email->flow_id === $flow->id, ($email->status ?? '—'));
    $check('… con el clic registrado', $email !== null && $email->clicked_at !== null);
    $check('message.queued, message.sent y message.clicked en ClickHouse', (int) ($events['message.queued'] ?? 0) >= 1 && (int) ($events['message.sent'] ?? 0) >= 1 && (int) ($events['message.clicked'] ?? 0) >= 1, json_encode($events) ?: '');
    $link = DB::table('tracked_links')->where('message_id', $email->id ?? '-')->where('url', 'like', '%igr_cart=%')->first();
    $check('El enlace rastreado del carrito tiene su clic', $link !== null && (int) $link->clicks >= 1);

    // 4. Atribución: el pedido recuperado queda en el correo y el flujo.
    $check('El pedido quedó atribuido al correo (último clic)', $order !== null && $email !== null && $order->attributed_message_id === $email->id);
    $check('… y al flujo', $order !== null && $order->attributed_flow_id === $flow->id);
    $check('Canal del último toque: el correo de Imagina Growth', $order !== null && $order->channel_last === 'igr_email', $order->channel_last ?? '—');

    // 5. Política de envío: con la hora de Bogotá, un domingo o un festivo se aplaza.
    $policy = app(SendPolicy::class);
    $bogota = (clone $contact)->forceFill(['timezone' => 'America/Bogota']);
    foreach (['domingo' => '2026-11-22 10:00', 'festivo (8 de diciembre)' => '2026-12-08 10:00', 'sábado 15:01' => '2026-11-21 15:01'] as $label => $at) {
        $d = $policy->check(new PolicyRequest($ws, $bogota, Channel::Email, MessageKind::Marketing, 'flow', null, CarbonImmutable::parse($at, 'America/Bogota')));
        $check("Política: {$label} en Bogotá se aplaza", $d->deferred(), ($d->reason ?? '').' → '.($d->until?->setTimezone('America/Bogota')->format('D Y-m-d H:i') ?? '—'));
    }
    $check('Política: los motivos se explican en español', PolicyDecision::label(PolicyDecision::SUNDAY) !== PolicyDecision::SUNDAY);

    echo $failures === 0 ? "\nComprobaciones de la Fase 2: todo en verde.\n" : "\nComprobaciones de la Fase 2: {$failures} fallaron.\n";

    return $failures === 0 ? 0 : 1;
}
