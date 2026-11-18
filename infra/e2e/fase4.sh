#!/usr/bin/env bash
# Prueba de punta a punta de la Fase 4 (CLAUDE.md §5, «Inteligencia avanzada e IA»):
#   «las predicciones se validan contra un periodo de reserva y la app muestra su precisión,
#    ninguna cifra de un texto de IA falta en los hechos (prueba automática), y el agente
#    resuelve preguntas de catálogo sin inventar precios ni stock».
#
# IMPORTANTE: aquí no hay clave de Anthropic. El hub habla con una API de Anthropic SIMULADA
# (mock-anthropic.php): un guion que responde como la Messages API. La prueba demuestra todo lo
# que hace el hub alrededor del modelo (el SDK, el bucle de herramientas, los comandos firmados a la
# tienda real, la validación de cifras, los traspasos y los respaldos sin IA), NO la calidad de un
# modelo real. Con IGR_ANTHROPIC_API_KEY, la prueba con el modelo real es
# `php artisan igr:agent:eval --workspace=ws_…` (pendiente).
#
#   A. Predicciones: la tienda demo (igr:seed-demo: 2.000 clientes, 10.000 pedidos, 24 meses) en un hub
#      aislado, el servicio de predicciones (hub/ml) y igr:predictions:train: BG/NBD, Gamma-Gamma y la
#      próxima compra se validan contra los últimos 90 días; la página de predicciones lo muestra.
#   B. Cifras: el analista semanal cita los hechos (origen «ai»); si inventa una cifra dos veces, se usa
#      la plantilla sin IA (origen «fallback»). «Pregúntale a tus datos» responde con el total de la consulta.
#      Además corren las pruebas automáticas que lo exigen (Ai, Intelligence, Agent, Copilot).
#   C. Agente: la tienda WooCommerce real conectada, WhatsApp hacia la Graph API simulada y el agente en
#      automático. Una persona pregunta precio y stock: el agente consulta la tienda (product.lookup firmado)
#      y responde exactamente lo que dice la tienda. Si «el modelo» inventa el precio, no se envía y pasa a
#      una persona. Una pregunta fuera del negocio no usa herramientas. «Quiero comprarlo»: carrito
#      restaurable; y desde la bandeja, el enlace de pago (order.create_payment_link); los dos se abren.
#   D. Panel: predicciones, «Pregúntale», informes, la bandeja con la conversación y la revisión del agente.
#
# Variables: WP="wp --allow-root --path=/ruta/wp"  WP_DIR=/ruta/wp  (obligatorias)
#   ML_PYTHON=/ruta/venv/bin/python (con hub/ml/requirements.txt)  HUB_PORT=8010  LOGS=/carpeta
#   DB_ROOT="docker exec igr-mariadb mariadb -uroot -proot"
set -euo pipefail
E2E=$(cd "$(dirname "$0")" && pwd)
cd "$E2E/../../hub"
HUB_DIR=$(pwd)

: "${WP:?define WP con el comando de WP-CLI de la tienda de prueba}"
: "${WP_DIR:?define WP_DIR con la ruta de WordPress}"
ML_PYTHON=${ML_PYTHON:-python3}
HUB_PORT=${HUB_PORT:-8010}
SMTP_PORT=${SMTP_PORT:-2526}
AI_PORT=${AI_PORT:-8097}
META_PORT=${META_PORT:-8091}
ML_PORT=${ML_PORT:-8095}
DB_ROOT=${DB_ROOT:-docker exec igr-mariadb mariadb -uroot -proot}
LOGS=${LOGS:-$(mktemp -d)}
mkdir -p "$LOGS/buzon" "$LOGS/capturas" "$LOGS/ai"
HUB="http://127.0.0.1:${HUB_PORT}"
export HUB APP_URL="$HUB" IGR_APP_URL="$HUB" IGR_INGEST_URL="$HUB" IGR_LINKS_URL="$HUB"
export DB_DATABASE=igr_e2e4 CLICKHOUSE_DATABASE=igr_e2e4 REDIS_INGEST_DB=10 REDIS_DB=11 REDIS_CACHE_DB=12
export QUEUE_CONNECTION=redis CACHE_STORE=redis SESSION_DRIVER=database SANCTUM_STATEFUL_DOMAINS="127.0.0.1:${HUB_PORT}"
export SMTP_PORT MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT="$SMTP_PORT" MAIL_SCHEME=smtp MAIL_ENCRYPTION=null MAIL_USERNAME= MAIL_PASSWORD=
export IGR_ANTHROPIC_API_KEY=sk-e2e-simulada IGR_ANTHROPIC_BASE_URL="http://127.0.0.1:${AI_PORT}" MOCK_AI_DIR="$LOGS/ai"
export IGR_META_GRAPH_URL="http://127.0.0.1:${META_PORT}" IGR_META_APP_SECRET=e2e-meta-secret META_LOG="$LOGS/meta.jsonl"
export IGR_ML_URL="http://127.0.0.1:${ML_PORT}" IGR_ML_TOKEN=e2e-ml-token
export OWNER_EMAIL=duena.f4@tienda.test OWNER_PASS=Clave-f4-2026 TOTP_SECRET=JBSWY3DPEHPK3PXP
STAMP=$(date +%s)
PHONE_1="+57300${STAMP: -7}"
PHONE_2="+57301${STAMP: -7}"
PHONE_3="+57302${STAMP: -7}"
PRODUCT_ID=${PRODUCT_ID:-16}

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
fail() { printf '\033[31m✗ %s\033[0m\n' "$*"; exit 1; }
ok() { printf '\033[32m✓\033[0m %s\n' "$*"; }
STORE_STATE=""
cleanup() {
    kill $(jobs -p) 2>/dev/null || true
    if [ -n "$STORE_STATE" ]; then
        $WP option update woocommerce_coming_soon "$STORE_STATE" >/dev/null 2>&1 || true
        $WP igr disconnect >/dev/null 2>&1 || true
        # Ya desconectada: se borran los pedidos y cupones de la prueba sin dejar nada en el outbox.
        $WP eval 'foreach (wc_get_orders(array("limit" => -1, "created_via" => "imagina-growth", "status" => array_keys(wc_get_order_statuses()))) as $o) { if (in_array($o->get_status(), array("processing", "completed", "on-hold"), true)) { wc_increase_stock_levels($o); } $o->delete(true); }
            foreach (get_posts(array("post_type" => "shop_coupon", "meta_key" => "igr_coupon", "numberposts" => -1, "post_status" => "any")) as $c) { wp_delete_post($c->ID, true); }' >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT
wait_http() { for _ in $(seq 1 240); do curl -fsS "$1" >/dev/null 2>&1 && return 0; sleep 0.5; done; fail "no responde $1"; }
hubphp() { php "$E2E/fase4-hub.php" "$@"; }
json() { python3 -c "import json,sys; d=json.loads(sys.stdin.read().strip().splitlines()[-1]); print($1)"; }
wait_hub() {
    until php artisan tinker --execute='
$s = app(App\Ingest\IngestStream::class); $r = $s->connection()->client(); $p = 0; $lag = 0;
for ($i = 0; $i < $s->shards(); $i++) { $x = $r->xPending($s->key($i), config("igr.ingest.consumer_group")); $p += is_array($x) ? (int) ($x[0] ?? 0) : 0; foreach ((array) $r->xInfo("GROUPS", $s->key($i)) as $g) { $lag += (int) ($g["lag"] ?? 0); } }
echo ($p + $lag) === 0 ? "listo" : "pendientes=".($p + $lag);' | tail -1 | grep -q listo; do sleep 2; done
    until [ "$(php artisan tinker --execute='$q = Illuminate\Support\Facades\Queue::connection("redis"); $n = 0; foreach (["projections","triggers","flows","messages:email","messages:whatsapp","default","analytics","ai"] as $c) { $n += $q->size($c); } echo $n;' | tail -1)" = "0" ]; do sleep 2; done
}
flush_store() { IGR_HUB_URL="$HUB" $WP igr flush --all >/dev/null 2>&1 || true; }
settle() { flush_store; wait_hub; sleep 2; flush_store; wait_hub; }
scenario() { curl -fsS -X POST "http://127.0.0.1:${AI_PORT}/_scenario/$1" >/dev/null; }
# Espera a que el agente termine el turno de esa persona (el turno queda en ai_agent_turns).
agent_turn() {
    for _ in $(seq 1 60); do
        wait_hub
        local out
        out=$(hubphp turn "$WS_SHOP" "$1" 2>/dev/null | tail -1 || true)
        if [ -n "$out" ] && [ "$(echo "$out" | json 'd["turn"] is not None and d["turn"]["status"] != "failed"' 2>/dev/null)" = "True" ]; then
            echo "$out"
            return 0
        fi
        sleep 2
    done
    hubphp turn "$WS_SHOP" "$1" | tail -1
}

step "Hub aislado: bases nuevas"
$DB_ROOT -e "DROP DATABASE IF EXISTS igr_e2e4; CREATE DATABASE igr_e2e4; GRANT ALL ON igr_e2e4.* TO 'igr'@'%';"
php artisan migrate --force -q
php artisan igr:clickhouse:migrate --fresh -q
for db in 10 11 12; do redis-cli -n $db flushdb >/dev/null; done
read -r WS_DEMO WS_SHOP CODE < <(hubphp setup | tail -1)
echo "Tienda demo: $WS_DEMO · Tienda real: $WS_SHOP"

step "A. Predicciones: la tienda demo (2.000 clientes, 10.000 pedidos, 24 meses) en el hub"
php artisan igr:seed-demo --workspace="$WS_DEMO" --process | tail -3
php artisan queue:work --queue=projections,triggers,default,analytics --stop-when-empty --sleep=1 -q
(cd "$HUB_DIR/ml" && IGR_ML_TOKEN="$IGR_ML_TOKEN" "$ML_PYTHON" -m uvicorn app.main:app --host 127.0.0.1 --port "$ML_PORT" >"$LOGS/ml.log" 2>&1) &
wait_http "http://127.0.0.1:${ML_PORT}/health"
php artisan igr:predictions:train --workspace="$WS_DEMO" | tail -12
hubphp preds "$WS_DEMO" | tee "$LOGS/predicciones.json" | python3 -m json.tool | head -60 \
    || true
hubphp preds "$WS_DEMO" >/dev/null || fail "Los modelos no se validaron contra la reserva"
ok "CLV, abandono y próxima compra entrenados y validados contra los últimos 90 días"

step "Procesos del hub, Graph API y Anthropic simuladas, buzón SMTP"
php -S 127.0.0.1:"$AI_PORT" "$E2E/mock-anthropic.php" >"$LOGS/mock-ai.log" 2>&1 &
php -S 127.0.0.1:"$META_PORT" "$E2E/mock-meta.php" >"$LOGS/mock-meta.log" 2>&1 &
python3 "$E2E/smtp-sink.py" 127.0.0.1 "$SMTP_PORT" "$LOGS/buzon" >"$LOGS/smtp.log" 2>&1 &
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port="$HUB_PORT" >"$LOGS/serve.log" 2>&1 &
php artisan igr:ingest:work >"$LOGS/ingest.log" 2>&1 &
php artisan queue:work --queue=projections,triggers,flows,ai,messages:whatsapp,messages:email,default,analytics --sleep=1 --tries=3 >"$LOGS/queue.log" 2>&1 &
wait_http "$HUB/health"
scenario normal

step "B. El analista semanal cita los hechos; si inventa una cifra dos veces, plantilla sin IA"
php artisan igr:report:weekly --workspace="$WS_DEMO" --force -q
REPORT_AI=$(hubphp report "$WS_DEMO" | tail -1)
echo "$REPORT_AI" | json 'd["source"], d["validation"].get("attempts")'
[ "$(echo "$REPORT_AI" | json 'd["source"]')" = "ai" ] || fail "El informe con cifras de los hechos no salió con IA"
scenario invent
php artisan igr:report:weekly --workspace="$WS_DEMO" --force -q
REPORT_FB=$(hubphp report "$WS_DEMO" | tail -1)
echo "$REPORT_FB" | json 'd["source"], d["validation"].get("fallback_reason"), [a.get("unknown") for a in d["validation"].get("attempts", [])]'
[ "$(echo "$REPORT_FB" | json 'd["source"]')" = "fallback" ] || fail "Una cifra inventada no llevó a la plantilla sin IA"
echo "$REPORT_FB" | json 'd["body"]' | grep -q '987.654.321' && fail "La cifra inventada quedó en el informe"
ok "Informe con IA validado; con la cifra inventada (\$987.654.321) se regeneró una vez y quedó la plantilla"
scenario normal
ASK=$(hubphp ask "$WS_DEMO" "¿Cuánto vendí en los últimos 30 días?" | tail -1)
echo "$ASK" | json 'd["status"], d["answer"], d["validation"]'
[ "$(echo "$ASK" | json 'd["status"]')" = "answered" ] || fail "«Pregúntale a tus datos» no respondió"
ok "«Pregúntale a tus datos»: la cifra de la respuesta sale de la consulta del hub"

step "C. Tienda real conectada, catálogo sincronizado y el agente de ventas en automático"
STORE_STATE=$($WP option get woocommerce_coming_soon 2>/dev/null || echo no)
$WP option update woocommerce_coming_soon no >/dev/null
$WP igr disconnect >/dev/null 2>&1 || true
IGR_HUB_URL="$HUB" PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t "$WP_DIR" >"$LOGS/store.log" 2>&1 &
wait_http "http://127.0.0.1:8080/wp-login.php"
IGR_HUB_URL="$HUB" $WP igr connect "$CODE"
$WP eval 'ImaginaGrowth\Connector\Backfill\Runner::pause(true); as_unschedule_all_actions("igr_backfill_step"); ImaginaGrowth\Connector\Connection\RemoteConfig::refresh();'
IGR_HUB_URL="$HUB" $WP igr resync-catalog >/dev/null
IGR_HUB_URL="$HUB" $WP action-scheduler run --group=imagina-growth >/dev/null 2>&1 || true
settle
read -r P_NAME P_PRICE P_STOCK < <($WP eval "\$p = wc_get_product($PRODUCT_ID); echo str_replace(' ', '_', \$p->get_name()), ' ', \$p->get_price(), ' ', (int) \$p->get_stock_quantity(), PHP_EOL;")
P_NAME=${P_NAME//_/ }
P_MONEY=$(python3 -c "print('\$' + f'{int(float(\"$P_PRICE\")):,}'.replace(',', '.'))")
echo "En la tienda: «$P_NAME» a $P_MONEY con $P_STOCK unidades"

echo "-- 1. Precio y stock"
hubphp inbound "$WS_SHOP" "$PHONE_1" "Hola, ¿cuánto cuesta la $P_NAME y tienen stock?" "wamid.E2E1${STAMP}" >/dev/null
T1=$(agent_turn "$PHONE_1")
echo "$T1" | json 'd["turn"]["status"], d["turn"]["tools"], d["sent"][-1]["text"] if d["sent"] else None'
[ "$(echo "$T1" | json 'd["turn"]["status"]')" = "replied" ] || fail "El agente no respondió"
[ "$(echo "$T1" | json '"get_product" in d["turn"]["tools"]')" = "True" ] || fail "El agente no consultó la tienda"
REPLY1=$(echo "$T1" | json 'd["sent"][-1]["text"]')
case "$REPLY1" in *"$P_MONEY"*"$P_STOCK unidades"*) ;; *) fail "La respuesta no trae el precio y el stock de la tienda: $REPLY1";; esac
grep -q "$P_MONEY" "$META_LOG" || fail "El mensaje no llegó a la Graph API"
ok "Respondió «$REPLY1»: precio y stock idénticos a WooCommerce, consultados con product.lookup"

echo "-- 2. «El modelo» inventa el precio"
scenario invent
hubphp inbound "$WS_SHOP" "$PHONE_2" "¿Cuánto cuesta la $P_NAME? ¿Tienen stock?" "wamid.E2E2${STAMP}" >/dev/null
T2=$(agent_turn "$PHONE_2")
echo "$T2" | json 'd["turn"]["status"], d["turn"]["handoff_reason"], [a.get("unknown") for a in (d["turn"]["validation"] or {}).get("attempts", [])], [s["text"] for s in d["sent"]], d["conversation"]["ai_mode"]'
[ "$(echo "$T2" | json 'd["turn"]["handoff_reason"]')" = "unverified_numbers" ] || fail "El precio inventado no se detuvo"
[ "$(echo "$T2" | json 'd["conversation"]["ai_mode"]')" = "off" ] || fail "La conversación no pasó a una persona"
INVENTED=$(python3 -c "print('\$' + f'{int(float(\"$P_PRICE\")) + 13000:,}'.replace(',', '.'))")
[ "$(echo "$T2" | json "any('$INVENTED' in s['text'] for s in d['sent'])")" = "False" ] || fail "Se envió el precio inventado"
ok "El precio inventado ($INVENTED) no salió: se reintentó una vez y la conversación pasó a una persona"
scenario normal

echo "-- 3. Fuera del negocio"
hubphp inbound "$WS_SHOP" "$PHONE_3" "¿Cuál es la capital de Francia?" "wamid.E2E3${STAMP}" >/dev/null
T3=$(agent_turn "$PHONE_3")
echo "$T3" | json 'd["turn"]["status"], d["turn"]["tools"], d["sent"][-1]["text"] if d["sent"] else None'
[ "$(echo "$T3" | json 'd["turn"]["tools"] == []')" = "True" ] || fail "Una pregunta fuera del negocio usó herramientas"
ok "Fuera del negocio: sin herramientas, con la respuesta acotada a la tienda"

echo "-- 4. «Quiero comprarlo»: carrito y enlace de pago"
hubphp inbound "$WS_SHOP" "$PHONE_1" "Quiero comprar la $P_NAME" "wamid.E2E4${STAMP}" >/dev/null
sleep 3
T4=$(agent_turn "$PHONE_1")
CART_URL=$(echo "$T4" | json '__import__("re").search(r"https?://\S+", d["sent"][-1]["text"]).group(0)')
echo "$T4" | json 'd["turn"]["tools"]'
echo "Enlace del carrito: ${CART_URL:0:80}…"
CART=$(SHOTS="$LOGS/capturas" node "$E2E/fase4-browser.cjs" link "$CART_URL" "$P_NAME" | tail -1)
echo "$CART"
[ "$(echo "$CART" | json 'd["hasProduct"]')" = "True" ] || fail "El enlace del carrito no tiene el producto"
PAY=$(hubphp paylink "$WS_SHOP" "$PHONE_3" "$PRODUCT_ID" | tail -1)
PAY_URL=$(echo "$PAY" | json 'd["url"]')
echo "Enlace de pago: ${PAY_URL:0:80}…"
PAYPAGE=$(SHOTS="$LOGS/capturas" node "$E2E/fase4-browser.cjs" link "$PAY_URL" "$P_NAME" | tail -1)
echo "$PAYPAGE"
[ "$(echo "$PAYPAGE" | json 'd["hasProduct"] and d["page"] == "pago"')" = "True" ] || fail "El enlace de pago no muestra el pedido"
ok "Carrito restaurable y enlace de pago abren con el producto listo para pagar"
settle

step "D. Panel: predicciones, «Pregúntale», informes y la bandeja"
PANEL=$(HUB="$HUB" WS_DEMO="$WS_DEMO" WS_SHOP="$WS_SHOP" SHOTS="$LOGS/capturas" node "$E2E/fase4-browser.cjs" panel | tail -1)
echo "$PANEL" | python3 -m json.tool
[ "$(echo "$PANEL" | json 'len(d["reliability"]) > 0')" = "True" ] || fail "La página de predicciones no muestra la precisión"
[ "$(echo "$PANEL" | json 'd["ask"]["status"] == 201')" = "True" ] || fail "«Pregúntale» por el API del panel falló"

step "Pruebas automáticas de la IA (cifras, agente, copiloto) en una base aislada"
(
    unset IGR_ANTHROPIC_API_KEY IGR_ANTHROPIC_BASE_URL IGR_META_GRAPH_URL IGR_META_APP_SECRET IGR_ML_URL IGR_ML_TOKEN APP_URL IGR_APP_URL IGR_INGEST_URL IGR_LINKS_URL
    $DB_ROOT -e "CREATE DATABASE IF NOT EXISTS igr_test_e4; GRANT ALL ON igr_test_e4.* TO 'igr'@'%';"
    curl -fsS "http://127.0.0.1:8123/" --data-binary "CREATE DATABASE IF NOT EXISTS igr_test_e4" >/dev/null
    DB_DATABASE=igr_test_e4 CLICKHOUSE_DATABASE=igr_test_e4 REDIS_INGEST_DB=15 REDIS_DB=15 REDIS_CACHE_DB=15 QUEUE_CONNECTION=sync CACHE_STORE=array SESSION_DRIVER=array \
        vendor/bin/pest tests/Feature/Ai tests/Feature/Intelligence tests/Feature/Agent tests/Feature/Copilot 2>&1 | tail -1
)

printf '\n\033[1mFase 4: todo en verde.\033[0m Registros, pedidos al modelo simulado y capturas en %s\n' "$LOGS"
