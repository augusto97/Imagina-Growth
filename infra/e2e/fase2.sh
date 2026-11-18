#!/usr/bin/env bash
# Prueba de punta a punta de la Fase 2 (CLAUDE.md §5, «Mensajería y automatizaciones»):
#   «el flujo de carrito abandonado recupera pedidos en la tienda de prueba de punta
#    a punta, ningún mensaje de marketing sale un domingo, un festivo o fuera de
#    horario en Colombia (con prueba automática), y cada pedido recuperado aparece
#    atribuido».
#
# Tienda WooCommerce real conectada a un hub aislado (igr_e2e2) con correo por
# SMTP hacia un buzón local y el flujo «Carrito abandonado» de la biblioteca:
#   1. Marta compra por primera vez y acepta recibir correos.
#   2. Vuelve (mismas cookies), arma un carrito, entra al checkout y se va.
#   3. El job de carritos lo marca abandonado (--at simula los 45 minutos) y el
#      flujo arranca; sus esperas (1 h, 4 h, 24 h) se publicaron en 1 minuto, como
#      lo haría la comerciante en el constructor.
#   4. Llega el correo con el enlace rastreado; Marta lo abre en otro dispositivo,
#      la tienda le restaura el carrito y paga con tarjeta.
#   5. Se comprueba la recuperación, la meta del flujo, la atribución y la política
#      de envío (domingo, festivo y sábado tarde se aplazan).
#
# Variables: WP="wp --allow-root --path=/ruta/wp"  WP_DIR=/ruta/wp  (obligatorias)
#   HUB_PORT=8010  SMTP_PORT=2526  LOGS=/carpeta  DB_ROOT="sudo mariadb"
set -euo pipefail
E2E=$(cd "$(dirname "$0")" && pwd)
cd "$E2E/../../hub"

: "${WP:?define WP con el comando de WP-CLI de la tienda de prueba}"
: "${WP_DIR:?define WP_DIR con la ruta de WordPress}"
HUB_PORT=${HUB_PORT:-8010}
SMTP_PORT=${SMTP_PORT:-2526}
DB_ROOT=${DB_ROOT:-sudo mariadb}
LOGS=${LOGS:-$(mktemp -d)}
mkdir -p "$LOGS/buzon" "$LOGS/capturas"
HUB="http://127.0.0.1:${HUB_PORT}"
export APP_URL="$HUB" IGR_APP_URL="$HUB" IGR_INGEST_URL="$HUB" IGR_LINKS_URL="$HUB"
export DB_DATABASE=igr_e2e2 CLICKHOUSE_DATABASE=igr_e2e2 REDIS_INGEST_DB=10 REDIS_DB=11 REDIS_CACHE_DB=12
export QUEUE_CONNECTION=redis CACHE_STORE=redis SESSION_DRIVER=database SANCTUM_STATEFUL_DOMAINS="127.0.0.1:${HUB_PORT}"
export SMTP_PORT
EMAIL="marta.rincon.$(date +%s)@example.com"
STATE="$LOGS/marta-cookies.json"
MU="$WP_DIR/wp-content/mu-plugins/igr-e2e-gateway.php"

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
STORE_STATE=""
cleanup() {
    kill $(jobs -p) 2>/dev/null || true
    rm -f "$MU"
    if [ -n "$STORE_STATE" ]; then
        $WP option update woocommerce_coming_soon "$STORE_STATE" >/dev/null 2>&1 || true
        $WP igr disconnect >/dev/null 2>&1 || true
        # Ya desconectada: los pedidos de la prueba se borran (devolviendo el stock) sin dejar nada en el outbox.
        $WP eval 'global $wpdb; foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wc_orders WHERE billing_email LIKE %s", "marta.rincon.%@example.com")) as $id) { $o = wc_get_order((int) $id); if ($o) { wc_increase_stock_levels($o); $o->delete(true); } } foreach (get_posts(array("post_type" => "shop_coupon", "meta_key" => "igr_coupon", "numberposts" => -1, "post_status" => "any")) as $c) { wp_delete_post($c->ID, true); }' >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT
wait_http() { until curl -fsS "$1" >/dev/null 2>&1; do sleep 0.5; done; }
hubphp() { php "$E2E/fase2-hub.php" "$@"; }
wait_hub() {
    until php artisan tinker --execute='
$s = app(App\Ingest\IngestStream::class); $r = $s->connection()->client(); $p = 0; $lag = 0;
for ($i = 0; $i < $s->shards(); $i++) { $x = $r->xPending($s->key($i), config("igr.ingest.consumer_group")); $p += is_array($x) ? (int) ($x[0] ?? 0) : 0; foreach ((array) $r->xInfo("GROUPS", $s->key($i)) as $g) { $lag += (int) ($g["lag"] ?? 0); } }
echo ($p + $lag) === 0 ? "listo" : "pendientes=".($p + $lag);' | tail -1 | grep -q listo; do sleep 2; done
    until [ "$(php artisan tinker --execute='$q = Illuminate\Support\Facades\Queue::connection("redis"); echo $q->size("projections") + $q->size("triggers") + $q->size("flows") + $q->size("messages:email") + $q->size("default");' | tail -1)" = "0" ]; do sleep 2; done
}
flush_store() { IGR_HUB_URL="$HUB" $WP igr flush --all >/dev/null 2>&1 || true; }

step "Hub aislado: bases nuevas, procesos y buzón SMTP"
$DB_ROOT -e "DROP DATABASE IF EXISTS igr_e2e2; CREATE DATABASE igr_e2e2; GRANT ALL ON igr_e2e2.* TO 'igr'@'%';"
php artisan migrate --force -q
php artisan igr:clickhouse:migrate --fresh -q
for db in 10 11 12; do redis-cli -n $db flushdb >/dev/null; done
python3 "$E2E/smtp-sink.py" 127.0.0.1 "$SMTP_PORT" "$LOGS/buzon" >"$LOGS/smtp.log" 2>&1 &
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port="$HUB_PORT" >"$LOGS/serve.log" 2>&1 &
php artisan igr:ingest:work >"$LOGS/ingest.log" 2>&1 &
php artisan queue:work --queue=projections,triggers,flows,messages:email,messages:whatsapp,messages:sms,default,analytics,conversions:meta --sleep=1 --tries=3 >"$LOGS/queue.log" 2>&1 &
wait_http "$HUB/health"

step "Dueña, espacio de trabajo, correo por SMTP y el flujo «Carrito abandonado» de la biblioteca activo"
read -r WS CODE FLOW < <(hubphp setup | tail -1)
echo "Espacio de trabajo: $WS · flujo: $FLOW"

step "Tienda conectada (sin banner: modo según el sitio → informado), con la pasarela de tarjeta de prueba"
mkdir -p "$(dirname "$MU")"
cp "$E2E/wp/igr-e2e-gateway.php" "$MU"
STORE_STATE=$($WP option get woocommerce_coming_soon 2>/dev/null || echo no)
$WP option update woocommerce_coming_soon no >/dev/null
$WP igr disconnect >/dev/null 2>&1 || true
IGR_HUB_URL="$HUB" PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t "$WP_DIR" >"$LOGS/store.log" 2>&1 &
wait_http "http://127.0.0.1:8080/wp-login.php"
IGR_HUB_URL="$HUB" $WP igr connect "$CODE"
$WP eval 'ImaginaGrowth\Connector\Backfill\Runner::pause(true); as_unschedule_all_actions("igr_backfill_step"); ImaginaGrowth\Connector\Settings::save(array("tracker" => true, "banner" => false, "excluded_roles" => null)); ImaginaGrowth\Connector\Connection\RemoteConfig::refresh();'
IGR_HUB_URL="$HUB" $WP igr resync-catalog >/dev/null
IGR_HUB_URL="$HUB" $WP action-scheduler run --group=imagina-growth >/dev/null 2>&1 || true
flush_store
wait_hub

step "1. Marta compra por primera vez y acepta recibir correos"
STORE_URL=http://127.0.0.1:8080 EMAIL="$EMAIL" STATE="$STATE" node "$E2E/fase2-browser.cjs" compra | tee "$LOGS/compra.out" | tail -1
flush_store
wait_hub
TZ_MARTA=$(hubphp reloj "$WS" "$EMAIL" | tail -1)
echo "Zona horaria de Marta para la prueba: $TZ_MARTA (la ventana legal de Colombia debe estar abierta ahora en su hora)"

step "2. Vuelve, arma un carrito, entra al checkout y se va"
STORE_URL=http://127.0.0.1:8080 EMAIL="$EMAIL" STATE="$STATE" SHOTS="$LOGS/capturas" node "$E2E/fase2-browser.cjs" abandona | tee "$LOGS/abandona.out" | tail -1
flush_store
wait_hub

step "3. El job de carritos lo marca abandonado (a los 45 minutos) y el flujo arranca"
php artisan igr:carts:abandon --at="$(date -u -d '+50 minutes' +%Y-%m-%dT%H:%M:%SZ)" -v
wait_hub
hubphp estado "$WS" "$EMAIL"
# Las esperas del flujo quedaron en 1 minuto: el scheduler (aquí a mano) las va cumpliendo.
for i in $(seq 1 12); do
    if ls "$LOGS"/buzon/*.eml >/dev/null 2>&1; then break; fi
    sleep 20
    php artisan igr:flows:tick -q
    wait_hub
    hubphp estado "$WS" "$EMAIL"
done
MAIL_FILE=$(ls "$LOGS"/buzon/*.eml 2>/dev/null | head -1 || true)
[ -n "$MAIL_FILE" ] || { echo "✗ El correo del flujo no llegó al buzón"; exit 1; }
echo "✓ Correo del flujo entregado por SMTP: $(grep -m1 '^Subject:' "$MAIL_FILE" | cut -c1-120)"
grep -q "^List-Unsubscribe-Post: List-Unsubscribe=One-Click" "$MAIL_FILE" && echo "✓ Con baja en un clic (RFC 8058)" || { echo "✗ Sin List-Unsubscribe-Post"; exit 1; }

step "4. Abre el enlace en otro dispositivo, la tienda le restaura el carrito y paga"
LINK=$(hubphp enlace "$WS" "$EMAIL" | tail -1)
echo "Enlace rastreado: $LINK"
STORE_URL=http://127.0.0.1:8080 EMAIL="$EMAIL" SHOTS="$LOGS/capturas" node "$E2E/fase2-browser.cjs" recupera "$LINK" | tee "$LOGS/recupera.out" | tail -1
ORDER=$(tail -1 "$LOGS/recupera.out" | python3 -c 'import json,sys; print(json.load(sys.stdin)["order"]["orderId"])')
flush_store
wait_hub
sleep 2
wait_hub

step "5. Comprobaciones en el hub"
hubphp check "$WS" "$EMAIL" "$ORDER"

printf '\n\033[1mFase 2: todo en verde.\033[0m Registros, correo y capturas en %s\n' "$LOGS"
