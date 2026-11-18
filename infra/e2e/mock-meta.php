<?php
/*
 * Graph API de Meta simulada para la prueba de la Fase 1 (php -S 127.0.0.1:8091 mock-meta.php).
 * Guarda cada POST /{version}/{dataset_id}/events en META_LOG (una línea JSON por petición)
 * y responde como la Conversions API. GET /_log devuelve lo recibido.
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
http_response_code(404);
echo '{"error":{"message":"ruta no simulada","type":"OAuthException","code":803}}';
