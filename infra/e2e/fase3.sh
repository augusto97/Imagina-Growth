#!/usr/bin/env bash
# Prueba de punta a punta de la Fase 3 (CLAUDE.md §5, «Captación y fidelización»):
#   «un visitante anónimo deja su WhatsApp en un popup, recibe el cupón, compra, gana
#    puntos, deja una reseña con foto y refiere a un amigo. Todo queda en su perfil y
#    atribuido».
#
# Tienda WooCommerce real conectada a un hub aislado (igr_e2e3), con correo por SMTP
# hacia un buzón local y lo que la comerciante configuraría en el panel (fase3-hub.php setup):
#   1. Marta llega anónima: el popup le pide su WhatsApp (casilla sin marcar), le da el cupón.
#   2. Compra con el cupón, acepta correos y crea su cuenta; la tienda completa el pedido y
#      sus puntos se confirman.
#   3. Le llega el correo de reseña; desde el celular deja 5 estrellas con una foto (con GPS
#      en el EXIF, que el hub quita); se publica sola en el hub y en WooCommerce.
#   4. En Mi cuenta → Mis recompensas ve sus puntos y su enlace para invitar.
#   5. Luis abre ese enlace, la bienvenida de referido le da su regalo y compra con él; al
#      completarse, Marta gana los puntos del referido (la comerciante aprueba la revisión:
#      en la prueba los dos comparten IP, que es una señal de fraude).
#   6. Se comprueba el perfil de Marta, los puntos, la reseña y la atribución de los dos pedidos.
#
# Variables: WP="wp --allow-root --path=/ruta/wp"  WP_DIR=/ruta/wp  (obligatorias)
#   HUB_PORT=8010  SMTP_PORT=2526  LOGS=/carpeta  DB_ROOT="docker exec igr-mariadb mariadb -uroot -proot"
set -euo pipefail
E2E=$(cd "$(dirname "$0")" && pwd)
cd "$E2E/../../hub"

: "${WP:?define WP con el comando de WP-CLI de la tienda de prueba}"
: "${WP_DIR:?define WP_DIR con la ruta de WordPress}"
HUB_PORT=${HUB_PORT:-8010}
SMTP_PORT=${SMTP_PORT:-2526}
DB_ROOT=${DB_ROOT:-docker exec igr-mariadb mariadb -uroot -proot}
LOGS=${LOGS:-$(mktemp -d)}
mkdir -p "$LOGS/buzon" "$LOGS/capturas"
HUB="http://127.0.0.1:${HUB_PORT}"
export APP_URL="$HUB" IGR_APP_URL="$HUB" IGR_INGEST_URL="$HUB" IGR_LINKS_URL="$HUB"
export DB_DATABASE=igr_e2e3 CLICKHOUSE_DATABASE=igr_e2e3 REDIS_INGEST_DB=10 REDIS_DB=11 REDIS_CACHE_DB=12
export QUEUE_CONNECTION=redis CACHE_STORE=redis SESSION_DRIVER=database SANCTUM_STATEFUL_DOMAINS="127.0.0.1:${HUB_PORT}"
export SMTP_PORT
STAMP=$(date +%s)
EMAIL_M="marta.rincon.${STAMP}@example.com"
EMAIL_L="luis.ospina.${STAMP}@example.com"
PHONE_M="311${STAMP: -7}"
PHONE_L="320${STAMP: -7}"
PASS_M="Clave-Marta-${STAMP}!"
MU="$WP_DIR/wp-content/mu-plugins/igr-e2e-gateway.php"
PHOTO="$LOGS/foto-con-gps.jpg"

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
STORE_STATE=""
SIGNUP_STATE=""
GENPASS_STATE=""
cleanup() {
    kill $(jobs -p) 2>/dev/null || true
    rm -f "$MU"
    if [ -n "$STORE_STATE" ]; then
        $WP option update woocommerce_coming_soon "$STORE_STATE" >/dev/null 2>&1 || true
        $WP option update woocommerce_enable_signup_and_login_from_checkout "$SIGNUP_STATE" >/dev/null 2>&1 || true
        $WP option update woocommerce_registration_generate_password "$GENPASS_STATE" >/dev/null 2>&1 || true
        $WP igr disconnect >/dev/null 2>&1 || true
        # Ya desconectada: se borra lo de la prueba (devolviendo el stock) sin dejar nada en el outbox.
        $WP eval 'global $wpdb;
            foreach ($wpdb->get_col("SELECT id FROM {$wpdb->prefix}wc_orders WHERE billing_email LIKE \"marta.rincon.%@example.com\" OR billing_email LIKE \"luis.ospina.%@example.com\"") as $id) { $o = wc_get_order((int) $id); if ($o) { wc_increase_stock_levels($o); $o->delete(true); } }
            foreach (get_posts(array("post_type" => "shop_coupon", "meta_key" => "igr_coupon", "numberposts" => -1, "post_status" => "any")) as $c) { wp_delete_post($c->ID, true); }
            foreach ($wpdb->get_col("SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key IN (\"igr_review_id\", \"igr_reply\")") as $cid) { $c = get_comment((int) $cid); if ($c) { $pid = (int) $c->comment_post_ID; wp_delete_comment($c->comment_ID, true); WC_Comments::clear_transients($pid); } }
            require_once ABSPATH . "wp-admin/includes/user.php";
            foreach (get_users(array("search" => "*@example.com", "search_columns" => array("user_email"))) as $u) { if (preg_match("/^(marta\.rincon|luis\.ospina)\.\d+@example\.com$/", $u->user_email)) { wp_delete_user($u->ID); } }' >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT
wait_http() { until curl -fsS "$1" >/dev/null 2>&1; do sleep 0.5; done; }
hubphp() { php "$E2E/fase3-hub.php" "$@"; }
wait_hub() {
    until php artisan tinker --execute='
$s = app(App\Ingest\IngestStream::class); $r = $s->connection()->client(); $p = 0; $lag = 0;
for ($i = 0; $i < $s->shards(); $i++) { $x = $r->xPending($s->key($i), config("igr.ingest.consumer_group")); $p += is_array($x) ? (int) ($x[0] ?? 0) : 0; foreach ((array) $r->xInfo("GROUPS", $s->key($i)) as $g) { $lag += (int) ($g["lag"] ?? 0); } }
echo ($p + $lag) === 0 ? "listo" : "pendientes=".($p + $lag);' | tail -1 | grep -q listo; do sleep 2; done
    until [ "$(php artisan tinker --execute='$q = Illuminate\Support\Facades\Queue::connection("redis"); echo $q->size("projections") + $q->size("triggers") + $q->size("flows") + $q->size("messages:email") + $q->size("default") + $q->size("analytics");' | tail -1)" = "0" ]; do sleep 2; done
}
flush_store() { IGR_HUB_URL="$HUB" $WP igr flush --all >/dev/null 2>&1 || true; }
settle() { flush_store; wait_hub; sleep 2; flush_store; wait_hub; }
browser() { STORE_URL=http://127.0.0.1:8080 SHOTS="$LOGS/capturas" node "$E2E/fase3-browser.cjs" "$@"; }
json() { python3 -c "import json,sys; d=json.loads(sys.stdin.read().strip().splitlines()[-1]); print($1)"; }
complete_order() { $WP eval "\$o = wc_get_order($1); \$o->update_status('completed', 'Entregado (prueba de la Fase 3)');" >/dev/null; }

step "Hub aislado: bases nuevas, procesos y buzón SMTP"
$DB_ROOT -e "DROP DATABASE IF EXISTS igr_e2e3; CREATE DATABASE igr_e2e3; GRANT ALL ON igr_e2e3.* TO 'igr'@'%';"
php artisan migrate --force -q
php artisan igr:clickhouse:migrate --fresh -q
for db in 10 11 12; do redis-cli -n $db flushdb >/dev/null; done
python3 "$E2E/smtp-sink.py" 127.0.0.1 "$SMTP_PORT" "$LOGS/buzon" >"$LOGS/smtp.log" 2>&1 &
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port="$HUB_PORT" >"$LOGS/serve.log" 2>&1 &
php artisan igr:ingest:work >"$LOGS/ingest.log" 2>&1 &
php artisan queue:work --queue=projections,triggers,flows,messages:email,messages:whatsapp,messages:sms,default,analytics,conversions:meta --sleep=1 --tries=3 >"$LOGS/queue.log" 2>&1 &
wait_http "$HUB/health"

step "Lo que la comerciante configura en el panel: popup, bienvenida de referido, fidelización, referidos y reseñas"
read -r WS CODE < <(hubphp setup | tail -1)
echo "Espacio de trabajo: $WS"

step "Tienda conectada (sin banner: modo según el sitio → informado), tarjeta de prueba y cuentas en el checkout"
mkdir -p "$(dirname "$MU")"
cp "$E2E/wp/igr-e2e-gateway.php" "$MU"
STORE_STATE=$($WP option get woocommerce_coming_soon 2>/dev/null || echo no)
SIGNUP_STATE=$($WP option get woocommerce_enable_signup_and_login_from_checkout 2>/dev/null || echo no)
GENPASS_STATE=$($WP option get woocommerce_registration_generate_password 2>/dev/null || echo yes)
$WP option update woocommerce_coming_soon no >/dev/null
$WP option update woocommerce_enable_signup_and_login_from_checkout yes >/dev/null
$WP option update woocommerce_registration_generate_password no >/dev/null
$WP igr disconnect >/dev/null 2>&1 || true
IGR_HUB_URL="$HUB" PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t "$WP_DIR" >"$LOGS/store.log" 2>&1 &
wait_http "http://127.0.0.1:8080/wp-login.php"
IGR_HUB_URL="$HUB" $WP igr connect "$CODE"
$WP eval 'ImaginaGrowth\Connector\Backfill\Runner::pause(true); as_unschedule_all_actions("igr_backfill_step"); ImaginaGrowth\Connector\Settings::save(array("tracker" => true, "banner" => false, "excluded_roles" => null)); ImaginaGrowth\Connector\Connection\RemoteConfig::refresh();'
IGR_HUB_URL="$HUB" $WP igr resync-catalog >/dev/null
IGR_HUB_URL="$HUB" $WP action-scheduler run --group=imagina-growth >/dev/null 2>&1 || true
settle

step "1-2. Marta llega anónima, deja su WhatsApp en el popup, recibe el cupón y compra con él"
EMAIL="$EMAIL_M" PHONE="$PHONE_M" PASS="$PASS_M" browser popup | tee "$LOGS/popup.out" | tail -1
ORDER_M=$(json 'd["order"]["orderId"]' <"$LOGS/popup.out")
echo "Cupón del popup: $(json 'd["coupon"]["code"]' <"$LOGS/popup.out") · casilla sin marcar al abrir: $(json 'd["consentUnchecked"]' <"$LOGS/popup.out") · pedido #$ORDER_M"
settle
TZ_M=$(hubphp reloj "$WS" "$EMAIL_M" | tail -1)
echo "Zona horaria de Marta para la prueba: $TZ_M (la ventana legal de Colombia abierta ahora en su hora)"
complete_order "$ORDER_M"
settle
php artisan igr:loyalty:settle -q
settle
hubphp estado "$WS" "$EMAIL_M"

step "3. Le llega el correo de reseña y desde el celular deja 5 estrellas con una foto"
python3 - "$PHOTO" <<'PY'
import sys
from PIL import Image
img = Image.new('RGB', (1200, 900))
px = img.load()
for x in range(1200):
    for y in range(900):
        px[x, y] = (40 + x * 180 // 1200, 90 + y * 120 // 900, 160)
exif = Image.Exif()
exif[0x010F] = 'Telefono de prueba'          # Make
exif[0x0110] = 'Modelo E2E'                  # Model
gps = exif.get_ifd(0x8825)
gps[1] = 'N'; gps[2] = (5.0, 4.0, 12.0)       # Manizales
gps[3] = 'W'; gps[4] = (75.0, 30.0, 36.0)
img.save(sys.argv[1], 'JPEG', quality=88, exif=exif.tobytes())
PY
python3 -c "import sys; d=open(sys.argv[1],'rb').read(); print('Foto de prueba:', len(d), 'bytes · EXIF con GPS:', b'Exif' in d and b'Modelo E2E' in d)" "$PHOTO"
php artisan igr:reviews:send --at="$(date -u -d '+1 minute' +%Y-%m-%dT%H:%M:%SZ)" -v
settle
for i in $(seq 1 15); do ls "$LOGS"/buzon/*.eml >/dev/null 2>&1 && break; sleep 2; done
MAIL_FILE=$(ls "$LOGS"/buzon/*.eml 2>/dev/null | head -1 || true)
[ -n "$MAIL_FILE" ] || { echo "✗ El correo de reseña no llegó al buzón"; hubphp estado "$WS" "$EMAIL_M"; exit 1; }
echo "✓ Correo de reseña entregado por SMTP: $(grep -m1 '^Subject:' "$MAIL_FILE" | cut -c1-120)"
grep -q "/c/http" "$MAIL_FILE" && { echo "✗ La baja o las preferencias salieron dentro de un enlace rastreado"; exit 1; } || echo "✓ Baja y preferencias sin rastrear, con su enlace directo"
REVIEW_LINK=$(python3 - "$MAIL_FILE" <<'PY'
import email, re, sys
from email import policy
msg = email.message_from_binary_file(open(sys.argv[1], 'rb'), policy=policy.default)
html = ''.join(p.get_content() for p in msg.walk() if p.get_content_type() == 'text/html')
# El botón de 5 estrellas (★★★★★), rastreado con /c/ o directo a /r/…?nota=5.
pick = [h for h, t in re.findall(r'<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>', html, re.S) if re.sub(r'<[^>]+>', '', t).strip().count('★') == 5]
print(pick[0] if pick else '')
PY
)
[ -n "$REVIEW_LINK" ] || { echo "✗ El correo no trae el enlace de la reseña"; exit 1; }
EMAIL="$EMAIL_M" browser resena "$REVIEW_LINK" "$PHOTO" | tee "$LOGS/resena.out" | tail -1
settle
hubphp estado "$WS" "$EMAIL_M"

step "4. Marta abre Mi cuenta → Mis recompensas: sus puntos y su enlace para invitar"
EMAIL="$EMAIL_M" PASS="$PASS_M" browser recompensas | tee "$LOGS/recompensas.out" | tail -1
REF_LINK=$(json 'd["link"]' <"$LOGS/recompensas.out")
echo "Enlace de referido: $REF_LINK"

step "5. Luis abre el enlace, recibe su regalo en la bienvenida de referido y compra con él"
EMAIL="$EMAIL_L" PHONE="$PHONE_L" browser amigo "$REF_LINK" | tee "$LOGS/amigo.out" | tail -1
ORDER_L=$(json 'd["order"]["orderId"]' <"$LOGS/amigo.out")
settle
complete_order "$ORDER_L"
settle
php artisan igr:loyalty:settle -q
php artisan igr:referrals:qualify -q
settle
hubphp aprobar "$WS"
# El job horario de calificación recoge el referido ya aprobado (con la devolución vencida) y recompensa a Marta.
php artisan igr:referrals:qualify -q
settle
hubphp estado "$WS" "$EMAIL_M"

step "6. Comprobaciones en el hub y en la tienda"
OUTBOX_DEAD=$($WP eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}igr_outbox WHERE status = \"dead\"");' 2>/dev/null || echo "?")
echo "Eventos muertos en el outbox de la tienda: $OUTBOX_DEAD"
[ "$OUTBOX_DEAD" = "0" ] || { echo "✗ Hay eventos muertos en el outbox"; exit 1; }
STORE_REVIEW=$($WP eval 'global $wpdb; $id = (int) $wpdb->get_var("SELECT m.comment_id FROM {$wpdb->commentmeta} m JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id WHERE m.meta_key = \"igr_review_id\" AND c.comment_approved = \"1\" ORDER BY m.comment_id DESC LIMIT 1"); $c = $id ? get_comment($id) : null; if (!$c) { echo "{}"; return; } $m = json_decode((string) get_comment_meta($c->comment_ID, "igr_media", true), true) ?: array(); echo wp_json_encode(array("rating" => (int) get_comment_meta($c->comment_ID, "rating", true), "verified" => (int) get_comment_meta($c->comment_ID, "verified", true), "media" => count($m), "url" => $m[0]["url"] ?? null, "author" => $c->comment_author, "type" => $c->comment_type));')
echo "Reseña nativa en WooCommerce: $STORE_REVIEW"
python3 - "$STORE_REVIEW" <<'PY'
import json, sys, urllib.request
r = json.loads(sys.argv[1])
assert r.get('rating') == 5 and r.get('media', 0) >= 1, '✗ La reseña de WooCommerce no tiene 5 estrellas con foto'
data = urllib.request.urlopen(r['url'], timeout=10).read()
assert data[:2] == b'\xff\xd8', '✗ La foto publicada no es un JPEG'
assert b'Modelo E2E' not in data and b'GPS' not in data and b'Exif' not in data, '✗ La foto publicada conserva el EXIF o el GPS'
print('✓ Reseña en WooCommerce con 5 estrellas y la foto servida por el hub (%d bytes) sin EXIF ni GPS' % len(data))
PY
hubphp check "$WS" "$EMAIL_M" "$EMAIL_L" "$ORDER_M" "$ORDER_L"

printf '\n\033[1mFase 3: todo en verde.\033[0m Registros, correo y capturas en %s\n' "$LOGS"
