<?php
/*
 * Graph API de Meta simulada (php -S 127.0.0.1:8091 mock-meta.php): la Conversions API de la
 * Fase 1 (POST /{version}/{dataset_id}/events) y, desde la Fase 4, los mensajes de WhatsApp
 * (POST /{version}/{phone_number_id}/messages). Desde la Fase 5 también simula a Google: el token de
 * OAuth (POST /google/token) y la Data Manager API (POST /google/v1/events:ingest), para las
 * conversiones offline de leads ganados. Guarda cada petición en META_LOG (una línea JSON) y
 * GET /_log devuelve lo recibido.
 */
declare(strict_types=1);

$log  = getenv('META_LOG') ?: sys_get_temp_dir().'/igr-mock-meta.jsonl';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
header('Content-Type: application/json');

if ($path === '/_log') {
    $lines = is_file($log) ? array_filter(explode("\n", (string) file_get_contents($log))) : [];
    echo '['.implode(',', $lines).']';

    return;
}
if ($path === '/_reset') {
    @unlink($log);
    echo '{"ok":true}';

    return;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && preg_match('#^/v[\d.]+/(\d+)/events$#', $path, $m) === 1) {
    $raw  = (string) file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (! is_array($body)) {
        parse_str($raw, $body);
        if (isset($body['data']) && is_string($body['data'])) {
            $body['data'] = json_decode($body['data'], true);
        }
    }
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    file_put_contents($log, json_encode([
        'dataset_id' => $m[1],
        'authorized' => $auth !== '' || isset($body['access_token']),
        'body' => $body,
    ], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['events_received' => count((array) ($body['data'] ?? [])), 'messages' => [], 'fbtrace_id' => 'E2E'.bin2hex(random_bytes(4))]);

    return;
}
// Fase 4: mensajes de WhatsApp de la Cloud API (POST /{version}/{phone_number_id}/messages).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && preg_match('#^/v[\d.]+/(\d+)/(messages|marketing_messages)$#', $path, $m) === 1) {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $wamid = 'wamid.E2E'.bin2hex(random_bytes(6));
    file_put_contents($log, json_encode([
        'kind' => 'whatsapp', 'phone_number_id' => $m[1], 'path' => $m[2], 'wamid' => $wamid,
        'authorized' => str_starts_with($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'Bearer '),
        'body' => $body,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => (string) ($body['to'] ?? '')]], 'messages' => [['id' => $wamid]]]);

    return;
}
// Fase 5: Google OAuth (refresh_token → access_token) y Data Manager API (events:ingest).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $path === '/google/token') {
    parse_str((string) file_get_contents('php://input'), $form);
    $ok = ($form['grant_type'] ?? '') === 'refresh_token' && ($form['refresh_token'] ?? '') !== '';
    http_response_code($ok ? 200 : 400);
    echo json_encode($ok ? ['access_token' => 'ya29.e2e', 'expires_in' => 3599, 'token_type' => 'Bearer'] : ['error' => 'invalid_grant']);

    return;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $path === '/google/v1/events:ingest') {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    file_put_contents($log, json_encode([
        'kind' => 'google_data_manager', 'authorized' => ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer ya29.e2e', 'body' => $body,
    ], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['requestId' => 'e2e-'.bin2hex(random_bytes(6))]);

    return;
}
http_response_code(404);
echo '{"error":{"message":"ruta no simulada","type":"OAuthException","code":803}}';
