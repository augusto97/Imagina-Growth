<?php

/*
 * Comprobaciones del hub para infra/e2e/fase1.sh. Recibe el espacio de trabajo y
 * el JSON que imprimió fase1-browser.cjs, y revisa lo que debió pasar en el hub.
 *
 *   php fase1-check.php ws_… /ruta/browser.json
 *
 * Termina con código 1 si algo falla. Imprime solo identificadores y conteos
 * (nada de datos personales en claro, CLAUDE.md §6).
 */

declare(strict_types=1);

use App\Analytics\Ch;
use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Contacts\Queries\ProfileQuery;
use App\Domain\Privacy\Actions\ExportContactData;
use App\Domain\Segments\Models\Segment;
use App\Domain\Sites\Models\Site;
use App\Support\Console\WorkspaceOption;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$hub = dirname(__DIR__, 2).'/hub';
require $hub.'/vendor/autoload.php';
$app = require $hub.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$wsId, $browserFile] = [$argv[1] ?? '', $argv[2] ?? ''];
$browser = json_decode((string) file_get_contents($browserFile), true, flags: JSON_THROW_ON_ERROR);
$failures = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    printf("%s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
    $failures += $ok ? 0 : 1;
};

$ws = WorkspaceOption::resolve($wsId)->firstOrFail();
app(CurrentWorkspace::class)->run($ws, function () use ($ws, $browser, $check): void {
    $site = Site::query()->firstOrFail();
    $ch = Ch::forWorkspace($ws);

    // 1. Pedido y atribución.
    $order = Order::query()->where('site_id', $site->id)->where('external_id', $browser['order']['orderId'])->first();
    $check('El pedido de la visitante está en el hub', $order !== null, 'pedido #'.$browser['order']['orderId']);
    if ($order === null) {
        return;
    }
    $check('Está pagado (tarjeta)', (bool) $order->is_paid);
    $check('Atribuido al anuncio de Meta (último toque)', $order->channel_last === 'paid_meta', 'channel_last='.($order->channel_last ?? 'null'));
    $check('Total igual al de igrOrder', number_format((float) $order->total, 2, '.', '') === number_format((float) $browser['igrOrder']['value'], 2, '.', ''), $order->total.' '.$order->currency);

    // 2. Identidad: la cookie del navegador quedó en el mismo contacto que el pedido.
    $contact = Contact::query()->where('email_bidx', BlindIndex::email($ws->id, $browser['email']))->whereNull('merged_into_id')->first();
    $check('Contacto encontrado por índice ciego del correo', $contact !== null);
    if ($contact === null) {
        return;
    }
    $check('El pedido pertenece a ese contacto', $order->contact_id === $contact->id);
    $anon = ContactIdentity::query()->where('type', 'anonymous_id')->where('value', $browser['aid'])->value('contact_id');
    $check('La visita anónima (_igr_aid) se vinculó al contacto', $anon === $contact->id, 'identidad anonymous_id → '.($anon ?? 'ninguno'));
    $check('El contacto quedó como cliente con 1 pedido', $contact->lifecycle_stage === 'customer' && (int) $contact->orders_count >= 1, $contact->lifecycle_stage.' · '.$contact->orders_count.' pedidos');

    // 3. Eventos del navegador en ClickHouse, sin datos personales.
    $browserEvents = $ch->select(
        "SELECT event, count() AS n FROM events WHERE workspace_id = {workspace_id:UInt32} AND anonymous_id = {aid:String} AND source_type = 'browser' GROUP BY event ORDER BY event",
        ['aid' => $browser['aid']],
    );
    $byEvent = array_column($browserEvents, 'n', 'event');
    $check('page.viewed del tracker en ClickHouse', (int) ($byEvent['page.viewed'] ?? 0) >= 4, json_encode($byEvent, JSON_UNESCAPED_UNICODE) ?: '');
    $check('search.performed del tracker', (int) ($byEvent['search.performed'] ?? 0) >= 1);
    // Por event_id: la primera vista (antes de aceptar el banner) llega sin anonymous_id, como debe.
    $pvIds = $ch->select(
        "SELECT toString(event_id) AS id FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'page.viewed' AND has({ids:Array(String)}, toString(event_id))",
        ['ids' => ExportContactData::chArray($browser['pixel']['pageViewEventIds'])],
    );
    $pvIds = array_column($pvIds, 'id');
    $missing = array_diff($browser['pixel']['pageViewEventIds'], $pvIds);
    $check('Cada PageView del píxel tiene un page.viewed con el mismo event_id', $browser['pixel']['pageViewEventIds'] !== [] && $missing === [], count($browser['pixel']['pageViewEventIds']).' PageView');
    $pii = (int) $ch->value(
        "SELECT count() FROM events WHERE workspace_id = {workspace_id:UInt32} AND positionCaseInsensitive(toJSONString(properties), {email:String}) > 0",
        ['email' => $browser['email']],
    );
    $check('Ningún evento guarda el correo en claro', $pii === 0);

    // 4. Perfil 360: línea de tiempo con navegación, carrito, pedido, encuesta y consentimiento.
    $timeline = app(ProfileQuery::class)->timeline($ws, $contact, [], null, 200)['data'];
    $events = array_unique(array_column($timeline, 'event'));
    foreach (['page.viewed', 'cart.item_added', 'checkout.started', 'order.created', 'order.paid', 'survey.answered', 'consent.granted'] as $e) {
        $check("Línea de tiempo del perfil: {$e}", in_array($e, $events, true));
    }
    $header = app(ProfileQuery::class)->header($ws, $contact);
    $check('Cabecera del perfil con los consentimientos', isset($header['consents']['whatsapp.marketing']) || str_contains((string) json_encode($header), 'whatsapp'));

    // 5. Encuesta «¿cómo nos conociste?».
    $survey = DB::table('survey_responses')->where('workspace_id', $ws->id)->where('site_id', $site->id)->where('external_order_id', $browser['order']['orderId'])->first();
    $check('Respuesta de la encuesta ligada al pedido', $survey !== null && $survey->answer === 'instagram', $survey->answer ?? 'sin respuesta');
    $check('… y al contacto', $survey !== null && $survey->contact_id === $contact->id);

    // 6. Consentimiento del checkout con su evidencia.
    $consents = DB::table('contact_consents')->where('workspace_id', $ws->id)->where('contact_id', $contact->id)->where('purpose', 'marketing')->pluck('status', 'channel')->all();
    $check('WhatsApp y correo de marketing concedidos en el checkout', ($consents['whatsapp'] ?? null) === 'granted' && ($consents['email'] ?? null) === 'granted', json_encode($consents) ?: '');
    $evidence = DB::table('consent_events')->where('workspace_id', $ws->id)->where('contact_id', $contact->id)->where('channel', 'whatsapp')->first();
    $proof = json_decode((string) ($evidence->evidence ?? '{}'), true) ?: [];
    $check('Evidencia del consentimiento (texto legal y huella de IP)', $evidence !== null && $evidence->legal_text_version !== null && ! empty($proof['ip_hash']), 'versión '.($evidence->legal_text_version ?? '—'));

    // 7. Conversión a Meta: mismo event_id que el píxel (deduplicación).
    $paidEventId = (string) $ch->value(
        "SELECT toString(event_id) FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'order.paid' AND order_id = {order:String} LIMIT 1",
        ['order' => (string) $browser['order']['orderId']],
    );
    $check('El píxel usó el event_id del order.paid del servidor', $paidEventId !== '' && $paidEventId === $browser['pixel']['purchaseEventId'], $browser['pixel']['purchaseEventId'] ?? '');
    $check('El píxel disparó Purchase una sola vez (recargar no repite)', (int) $browser['pixel']['purchases'] === 1);
    $delivery = $ch->select(
        "SELECT status, http_status, match_keys FROM conversion_deliveries WHERE workspace_id = {workspace_id:UInt32} AND platform = 'meta' AND event_id = toUUID({id:String}) ORDER BY delivered_at DESC LIMIT 1",
        ['id' => $browser['pixel']['purchaseEventId']],
    );
    $check('Entrega a la Conversions API registrada como enviada', ($delivery[0]['status'] ?? '') === 'delivered', json_encode($delivery[0] ?? []) ?: '');
    $meta = json_decode((string) @file_get_contents('http://127.0.0.1:'.(getenv('META_PORT') ?: '8091').'/_log'), true) ?: [];
    $sent = null;
    foreach ($meta as $req) {
        foreach ((array) ($req['body']['data'] ?? []) as $ev) {
            if (($ev['event_name'] ?? '') === 'Purchase' && ($ev['event_id'] ?? '') === $browser['pixel']['purchaseEventId']) {
                $sent = $ev + ['_dataset' => $req['dataset_id'], '_auth' => $req['authorized']];
            }
        }
    }
    $check('Meta recibió Purchase con el mismo event_id que el píxel', $sent !== null);
    if ($sent !== null) {
        $user = (array) ($sent['user_data'] ?? []);
        $check('… con el dataset y el token del destino', $sent['_dataset'] === '1234567890' && $sent['_auth'] === true);
        $check('… con fbc del clic en el anuncio (fbclid) y fbp', str_contains((string) ($user['fbc'] ?? ''), 'IwAR0e2eFase1Click') && ! empty($user['fbp']), 'fbc='.(isset($user['fbc']) ? 'sí' : 'no').' fbp='.(isset($user['fbp']) ? 'sí' : 'no'));
        $check('… con correo y teléfono en SHA-256, nunca en claro', isset($user['em'][0], $user['ph'][0]) && preg_match('/^[0-9a-f]{64}$/', (string) $user['em'][0]) === 1 && ! str_contains((string) json_encode($sent), $browser['email']));
        $check('… y el mismo valor y moneda que el píxel', abs((float) ($sent['custom_data']['value'] ?? 0) - (float) $browser['pixel']['purchaseValue']) < 0.01 && ($sent['custom_data']['currency'] ?? '') === $browser['pixel']['purchaseCurrency'], ($sent['custom_data']['value'] ?? '?').' '.($sent['custom_data']['currency'] ?? '?'));
    }

    // 8. Segmentos y RFM.
    $segments = Segment::query()->whereIn('id', DB::table('segment_memberships')->where('contact_id', $contact->id)->pluck('segment_id'))->pluck('name')->all();
    $check('El contacto entró a segmentos de la biblioteca', $segments !== [], implode(', ', $segments));
    $entered = (int) $ch->value(
        "SELECT count() FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'segment.entered' AND contact_id = {c:String}",
        ['c' => $contact->id],
    );
    $check('… y quedó el evento segment.entered en su historia', $entered >= 1, $entered.' eventos');
    $rfm = DB::table('contacts')->where('workspace_id', $ws->id)->whereNotNull('rfm_segment')->count();
    $check('RFM calculado para los compradores', $rfm > 0, $rfm.' contactos con segmento RFM');
});

echo $failures === 0 ? "\nComprobaciones del hub: todo en verde.\n" : "\nComprobaciones del hub: {$failures} fallaron.\n";
exit($failures === 0 ? 0 : 1);
