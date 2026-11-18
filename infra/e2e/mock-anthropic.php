<?php
/*
 * API de Anthropic simulada para la prueba de la Fase 4 (php -S 127.0.0.1:8097 mock-anthropic.php).
 *
 * NO es un modelo: es un guion que responde como la Messages API (POST /v1/messages, también con
 * ?beta=true) para probar de punta a punta lo que hace el hub alrededor del modelo: el SDK oficial,
 * el bucle de herramientas, los comandos firmados a la tienda, la validación de cifras y los
 * respaldos. La prueba con el modelo real es `php artisan igr:agent:eval`, pendiente de una clave.
 *
 * El guion sale del pedido mismo:
 *   - Agente de ventas (herramientas get_product/search_products): busca el producto, consulta
 *     get_product y escribe el precio y el stock COPIANDO lo que devolvió la herramienta. Con el
 *     escenario «invent» escribe un precio inventado. Preguntas fuera del negocio: se niega sin
 *     herramientas. «lo quiero comprar»: busca y arma el carrito con create_cart_link.
 *   - «Pregúntale a tus datos» (herramienta run_metric_query): pide los ingresos de los últimos 30
 *     días y responde con el total que devolvió la consulta.
 *   - Analista semanal (salida estructurada con «resumen»): cita cifras de los hechos del pedido;
 *     con «invent», una cifra que no está en los hechos.
 *   - Clasificador (salida con «intent»): intención por palabras.
 *   - Cualquier otra salida estructurada: un JSON mínimo válido sin cifras.
 *
 * Escenario: el archivo MOCK_AI_DIR/scenario ("normal" o "invent"). Cada pedido y su respuesta
 * quedan en MOCK_AI_DIR/requests.jsonl. GET /_log los devuelve; POST /_scenario/{nombre} lo cambia.
 */
declare(strict_types=1);

$dir = getenv('MOCK_AI_DIR') ?: sys_get_temp_dir().'/igr-mock-ai';
@mkdir($dir, 0777, true);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
header('Content-Type: application/json');

if ($path === '/_log') {
    $lines = is_file("$dir/requests.jsonl") ? array_filter(explode("\n", (string) file_get_contents("$dir/requests.jsonl"))) : [];
    echo '['.implode(',', $lines).']';

    return;
}
if (preg_match('#^/_scenario/(normal|invent)$#', $path, $m) === 1) {
    file_put_contents("$dir/scenario", $m[1]);
    echo json_encode(['scenario' => $m[1]]);

    return;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $path !== '/v1/messages') {
    http_response_code(404);
    echo json_encode(['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'ruta no simulada']]);

    return;
}
if (! str_starts_with($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'Bearer ') && ($_SERVER['HTTP_X_API_KEY'] ?? '') === '') {
    http_response_code(401);
    echo json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'sin clave']]);

    return;
}

$req = json_decode((string) file_get_contents('php://input'), true) ?: [];
$scenario = trim((string) @file_get_contents("$dir/scenario")) ?: 'normal';
$tools = array_column((array) ($req['tools'] ?? []), null, 'name');
$schema = $req['output_config']['format']['schema'] ?? null;
$messages = (array) ($req['messages'] ?? []);

/** Texto de un mensaje (cadena o bloques de texto). */
$textOf = function (array $m): string {
    $c = $m['content'] ?? '';
    if (is_string($c)) {
        return $c;
    }
    $out = '';
    foreach ((array) $c as $b) {
        if (($b['type'] ?? '') === 'text') {
            $out .= (string) ($b['text'] ?? '');
        }
    }

    return $out;
};
/** Resultados de herramientas desde el último mensaje del cliente (los de este turno). */
$turnResults = function () use ($messages, $textOf): array {
    $results = [];
    $calls = [];
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        $m = $messages[$i];
        $blocks = is_array($m['content'] ?? null) ? $m['content'] : [];
        $hasResult = false;
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'tool_result') {
                $hasResult = true;
                $content = $b['content'] ?? '';
                if (is_array($content)) {
                    $content = implode('', array_map(fn ($x) => (string) ($x['text'] ?? ''), $content));
                }
                $results[(string) $b['tool_use_id']] = json_decode((string) $content, true);
            }
            if (($b['type'] ?? '') === 'tool_use') {
                $calls[(string) $b['id']] = (string) $b['name'];
            }
        }
        if (($m['role'] ?? '') === 'user' && ! $hasResult && trim($textOf($m)) !== '') {
            break;
        }
    }
    $byName = [];
    foreach ($results as $id => $data) {
        $byName[$calls[$id] ?? '?'][] = $data;
    }

    return $byName;
};
/** El último texto del cliente (no los resultados de herramientas ni las correcciones del hub). */
$customer = function () use ($messages, $textOf): string {
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        $m = $messages[$i];
        $t = trim($textOf($m));
        if (($m['role'] ?? '') === 'user' && $t !== '' && ! str_starts_with($t, 'Estas cifras') && ! str_starts_with($t, 'Antes de decir')) {
            return $t;
        }
    }

    return '';
};
$money = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');
$id = fn (string $p) => $p.'_'.bin2hex(random_bytes(8));
$toolUse = fn (string $name, array $input) => ['content' => [['type' => 'tool_use', 'id' => $id('toolu'), 'name' => $name, 'input' => $input]], 'stop' => 'tool_use'];
$text = fn (string $t) => ['content' => [['type' => 'text', 'text' => $t]], 'stop' => 'end_turn'];

/** Hojas numéricas de un arreglo (para citar hechos). */
$numbers = function (array $data) {
    $out = [];
    array_walk_recursive($data, function ($v, $k) use (&$out) {
        if ((is_int($v) || is_float($v)) && ! is_bool($v)) {
            $out[(string) $k] = $v;
        }
    });

    return $out;
};
$fmt = function ($v): string {
    if (is_float($v) && floor($v) !== $v) {
        return number_format($v, 1, ',', '.');
    }

    return number_format((float) $v, 0, ',', '.');
};

$answer = null;
if (isset($tools['get_product'])) {
    // ── Agente de ventas ─────────────────────────────────────────────────────────
    $ask = mb_strtolower($customer());
    $done = $turnResults();
    $query = trim((string) preg_replace('/^.*?(pintura[^?¿,.]*?)(\s+(y|tienen|hay|me)\b.*)?[?.!]*$/u', '$1', $ask));
    if (preg_match('/capital|chiste|f[uú]tbol|presidente|tarea/u', $ask) === 1) {
        $answer = $text('Solo puedo ayudarte con los productos, pedidos y compras de la tienda. ¿Buscas algún producto en especial?');
    } elseif (! isset($done['search_products'])) {
        $answer = $toolUse('search_products', ['query' => $query !== '' ? $query : 'pintura', 'limit' => 3]);
    } else {
        $found = (array) (($done['search_products'][0]['products'] ?? [])[0] ?? []);
        $pid = (string) ($found['product_id'] ?? '');
        $wantsToBuy = preg_match('/comprar|lo quiero|me lo llevo/u', $ask) === 1;
        if ($pid === '') {
            $answer = $text('No encontré ese producto en el catálogo. ¿Me das más detalles?');
        } elseif ($wantsToBuy && ! isset($done['create_cart_link'])) {
            $answer = $toolUse('create_cart_link', ['items' => [['product_id' => $pid, 'variation_id' => null, 'qty' => 1]], 'apply_discount' => false]);
        } elseif ($wantsToBuy) {
            $cart = (array) $done['create_cart_link'][0];
            $answer = $text('¡Listo! Te armé el carrito con '.(string) ($cart['items'][0]['name'] ?? 'el producto').' por '.$money($cart['subtotal'] ?? 0).'. Págalo aquí: '.(string) ($cart['url'] ?? ''));
        } elseif (! isset($done['get_product'])) {
            $answer = $toolUse('get_product', ['product_id' => $pid, 'search' => null]);
        } else {
            $p = (array) (($done['get_product'][0]['products'] ?? [])[0] ?? []);
            $price = $scenario === 'invent' ? (float) ($p['price'] ?? 0) + 13000 : (float) ($p['price'] ?? 0);
            $stock = $p['stock_qty'] ?? null;
            $answer = $text('La '.(string) ($p['name'] ?? 'pintura').' cuesta '.$money($price)
                .($stock !== null ? ' y tenemos '.(int) $stock.' unidades disponibles.' : ' y está disponible.'));
        }
    }
} elseif (isset($tools['run_metric_query'])) {
    // ── «Pregúntale a tus datos» ────────────────────────────────────────────────
    $done = $turnResults();
    if (! isset($done['run_metric_query'])) {
        $enum = (array) ($tools['run_metric_query']['input_schema']['properties']['metrics']['items']['enum'] ?? []);
        $metric = in_array('revenue', $enum, true) ? 'revenue' : (string) ($enum[0] ?? 'revenue');
        $answer = $toolUse('run_metric_query', ['metrics' => [$metric], 'dimensions' => [], 'from' => gmdate('Y-m-d', strtotime('-30 days')),
            'to' => gmdate('Y-m-d', strtotime('-1 day')), 'compare' => 'none', 'granularity' => 'auto', 'channel' => null, 'device' => null, 'city' => null, 'limit' => 10]);
    } else {
        $result = (array) $done['run_metric_query'][0];
        $nums = $numbers((array) ($result['totals'] ?? $result));
        $value = $nums['revenue'] ?? (array_values($nums)[0] ?? 0);
        $answer = $text('En los últimos 30 días vendiste '.$money($value).'.'.($scenario === 'invent' ? ' Eso es 47 % más que el mes anterior.' : ''));
    }
} elseif (is_array($schema) && isset($schema['properties']['resumen'])) {
    // ── Analista semanal ────────────────────────────────────────────────────────
    $user = '';
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'user') {
            $user = $textOf($m);
            break;
        }
    }
    $facts = json_decode((string) substr($user, (int) strpos($user, '{')), true) ?: [];
    $nums = array_values(array_filter($numbers($facts), fn ($v) => abs((float) $v) >= 1));
    $cite = array_slice($nums, 0, 3);
    $resumen = 'Esta semana los indicadores principales fueron '.implode(', ', array_map($fmt, $cite)).'.';
    if ($scenario === 'invent') {
        $resumen .= ' Además vendiste $987.654.321 por WhatsApp.';
    }
    $answer = ['content' => [['type' => 'text', 'text' => json_encode([
        'resumen' => $resumen,
        'por_que' => 'Los hallazgos de la semana no alcanzan para explicar las causas.',
        'acciones' => [
            ['titulo' => 'Revisa los hallazgos', 'detalle' => 'Atiende los de mayor impacto.', 'tipo' => 'ruta', 'destino' => 'hallazgos'],
            ['titulo' => 'Activa la recuperación de carritos', 'detalle' => 'Escribe a quien dejó el carrito.', 'tipo' => 'flujo', 'destino' => 'abandoned_cart'],
            ['titulo' => 'Mira tus segmentos', 'detalle' => 'Revisa quién está en riesgo.', 'tipo' => 'ruta', 'destino' => 'segmentos'],
        ],
    ], JSON_UNESCAPED_UNICODE)]], 'stop' => 'end_turn'];
} elseif (is_array($schema) && isset($schema['properties']['intent'])) {
    // ── Clasificador de entrantes ───────────────────────────────────────────────
    $t = mb_strtolower($textOf((array) end($messages)));
    $intent = preg_match('/no me escriban|borren mi n[uú]mero|baja/u', $t) === 1 ? 'optout'
        : (preg_match('/queja|reclamo|p[eé]simo/u', $t) === 1 ? 'complaint'
        : (preg_match('/cu[aá]nto|precio|stock|comprar|quiero/u', $t) === 1 ? 'purchase' : 'other'));
    $answer = ['content' => [['type' => 'text', 'text' => json_encode(['intent' => $intent, 'sentiment' => 'neutral', 'confidence' => 0.92])]], 'stop' => 'end_turn'];
} elseif (is_array($schema)) {
    // ── Cualquier otra salida estructurada: lo mínimo válido, sin cifras ───────────
    $fill = function (array $s) use (&$fill) {
        $type = is_array($s['type'] ?? null) ? $s['type'][0] : ($s['type'] ?? 'string');

        return match (true) {
            isset($s['enum']) => $s['enum'][0],
            $type === 'object' => (object) array_map($fill, (array) ($s['properties'] ?? [])),
            $type === 'array' => [],
            $type === 'integer', $type === 'number' => 0,
            $type === 'boolean' => false,
            $type === 'null' => null,
            default => 'Texto de prueba.',
        };
    };
    $answer = ['content' => [['type' => 'text', 'text' => json_encode($fill($schema), JSON_UNESCAPED_UNICODE)]], 'stop' => 'end_turn'];
} else {
    $answer = $text('Resumen de prueba sin cifras.');
}

$response = [
    'id' => $id('msg'), 'type' => 'message', 'role' => 'assistant', 'model' => (string) ($req['model'] ?? 'claude-sonnet-5-5'),
    'content' => $answer['content'], 'stop_reason' => $answer['stop'], 'stop_sequence' => null,
    'usage' => ['input_tokens' => 900, 'output_tokens' => 120, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
];
file_put_contents("$dir/requests.jsonl", json_encode([
    'at' => gmdate('c'), 'scenario' => $scenario, 'model' => $req['model'] ?? null, 'tools' => array_keys($tools),
    'schema' => is_array($schema) ? array_keys((array) ($schema['properties'] ?? [])) : null,
    'beta' => $_SERVER['HTTP_ANTHROPIC_BETA'] ?? null, 'fallbacks' => $req['fallbacks'] ?? null,
    'cache_control' => $req['system'][0]['cache_control'] ?? null,
    'messages' => $messages, 'response' => $response['content'], 'stop' => $response['stop_reason'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
