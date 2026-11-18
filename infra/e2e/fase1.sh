#!/usr/bin/env bash
# Prueba de punta a punta de la Fase 1 (CLAUDE.md §5, «Conocer al cliente»):
#   «el tablero responde en menos de 1,5 s con un año de datos, Meta muestra la
#    deduplicación correcta entre píxel y servidor, y el informe semanal por
#    correo llega al dueño».
#
# Tienda WooCommerce real (wp igr demo-store) conectada a un hub real aislado
# (igr_e2e1). Una visitante llega desde un anuncio de Facebook, acepta el banner,
# navega, compra con tarjeta y responde la encuesta; después se comprueba en el
# hub la identidad, el perfil 360, el consentimiento, la atribución, la conversión
# hacia Meta (simulada en META_PORT) con el mismo event_id del píxel, los segmentos,
# el RFM, los tableros (medidos por HTTP con sesión y 2FA) y el informe semanal
# entregado por SMTP (buzón local en SMTP_PORT).
#
# Variables:
#   WP="wp --allow-root --path=/ruta/wp"   WP_DIR=/ruta/wp   (obligatorias)
#   HUB_PORT=8010  META_PORT=8091  SMTP_PORT=2526  BACKFILL=1  LOGS=/carpeta
#   DB_ROOT="docker exec igr-mariadb mariadb -uroot -proot"
# La tienda se sirve en 127.0.0.1:8080 (la URL del sitio de prueba).
set -euo pipefail
E2E=$(cd "$(dirname "$0")" && pwd)
cd "$E2E/../../hub"

: "${WP:?define WP con el comando de WP-CLI de la tienda de prueba}"
: "${WP_DIR:?define WP_DIR con la ruta de WordPress}"
HUB_PORT=${HUB_PORT:-8010}
META_PORT=${META_PORT:-8091}
SMTP_PORT=${SMTP_PORT:-2526}
BACKFILL=${BACKFILL:-1}
DB_ROOT=${DB_ROOT:-docker exec igr-mariadb mariadb -uroot -proot}
LOGS=${LOGS:-$(mktemp -d)}
mkdir -p "$LOGS/buzon" "$LOGS/capturas"
HUB="http://127.0.0.1:${HUB_PORT}"
export APP_URL="$HUB" IGR_APP_URL="$HUB" IGR_INGEST_URL="$HUB"
export DB_DATABASE=igr_e2e1 CLICKHOUSE_DATABASE=igr_e2e1 REDIS_INGEST_DB=13 REDIS_DB=14 REDIS_CACHE_DB=15
export QUEUE_CONNECTION=redis CACHE_STORE=redis SESSION_DRIVER=database
export SANCTUM_STATEFUL_DOMAINS="127.0.0.1:${HUB_PORT}"
export IGR_META_GRAPH_URL="http://127.0.0.1:${META_PORT}"
export MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT="$SMTP_PORT" MAIL_SCHEME=smtp MAIL_USERNAME= MAIL_PASSWORD=
export IGR_E2E_LOGS="$LOGS"
OWNER_EMAIL=dueno.f1@tienda.test
OWNER_PASS=Clave-f1-2026
TOTP_SECRET=JBSWY3DPEHPK3PXP
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
        $WP eval 'global $wpdb; foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wc_orders WHERE billing_email LIKE %s", "laura.gomez.%@example.com")) as $id) { $o = wc_get_order((int) $id); if ($o) { wc_increase_stock_levels($o); $o->delete(true); } }' >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT
wait_http() { until curl -fsS "$1" >/dev/null 2>&1; do sleep 0.5; done; }

step "Hub aislado: bases nuevas, procesos, Meta simulado y buzón SMTP"
$DB_ROOT -e "DROP DATABASE IF EXISTS igr_e2e1; CREATE DATABASE igr_e2e1; GRANT ALL ON igr_e2e1.* TO 'igr'@'%';"
php artisan migrate --force -q
php artisan igr:clickhouse:migrate --fresh -q
for db in 13 14 15; do redis-cli -n $db flushdb >/dev/null; done
META_LOG="$LOGS/meta.jsonl" php -S "127.0.0.1:${META_PORT}" "$E2E/mock-meta.php" >"$LOGS/meta.log" 2>&1 &
python3 "$E2E/smtp-sink.py" 127.0.0.1 "$SMTP_PORT" "$LOGS/buzon" >"$LOGS/smtp.log" 2>&1 &
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port="$HUB_PORT" >"$LOGS/serve.log" 2>&1 &
php artisan igr:ingest:work >"$LOGS/ingest.log" 2>&1 &
php artisan queue:work --queue=projections,triggers,default,analytics,conversions:meta,conversions:google,conversions:tiktok --sleep=1 --tries=3 >"$LOGS/queue.log" 2>&1 &
wait_http "$HUB/health"
wait_http "http://127.0.0.1:${META_PORT}/_log"

step "Dueño con 2FA, espacio de trabajo, destino de Meta (píxel + Conversions API) y código de conexión"
WS_CODE=$(php artisan tinker --execute='
$u = App\Domain\Workspaces\Models\User::create(["name" => "Dueña F1", "email" => "'"$OWNER_EMAIL"'", "password" => "'"$OWNER_PASS"'"]);
$u->forceFill(["two_factor_secret" => encrypt("'"$TOTP_SECRET"'"), "two_factor_confirmed_at" => now()])->save();
$ws = app(App\Domain\Workspaces\Actions\CreateWorkspace::class)->handle($u, new App\Domain\Workspaces\Data\WorkspaceData(name: "Pinturas La 14"));
app(App\Support\Tenancy\CurrentWorkspace::class)->run($ws, fn () => App\Domain\Measurement\Models\AdDestination::query()->create([
    "workspace_id" => $ws->id, "platform" => App\Domain\Measurement\Enums\Platform::Meta, "name" => "Meta",
    "config" => ["dataset_id" => "1234567890", "access_token" => "EAAB-e2e-fase1"],
    "mode" => App\Domain\Measurement\Enums\DestinationMode::Full, "pixel_id" => "1234567890", "status" => "active",
]));
echo $ws->publicId(), " ", app(App\Domain\Sites\Actions\CreateConnectCode::class)->handle($ws, $u, [])["code"];' | tail -1)
read -r WS CODE <<<"$WS_CODE"
echo "Espacio de trabajo: $WS · código: $CODE"

step "Tienda conectada al hub (banner propio, pasarela de tarjeta de prueba)"
mkdir -p "$(dirname "$MU")"
cp "$E2E/wp/igr-e2e-gateway.php" "$MU"
STORE_STATE=$($WP option get woocommerce_coming_soon 2>/dev/null || echo no)
$WP option update woocommerce_coming_soon no >/dev/null
$WP igr disconnect >/dev/null 2>&1 || true
IGR_HUB_URL="$HUB" PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t "$WP_DIR" >"$LOGS/store.log" 2>&1 &
wait_http "http://127.0.0.1:8080/wp-login.php"
IGR_HUB_URL="$HUB" $WP igr connect "$CODE"
$WP eval 'ImaginaGrowth\Connector\Settings::save(array("tracker" => true, "banner" => true, "excluded_roles" => null)); ImaginaGrowth\Connector\Connection\RemoteConfig::refresh();'
if [ "$BACKFILL" = "1" ]; then
    step "Importación histórica (la tienda demo: 24 meses de pedidos)"
    IGR_HUB_URL="$HUB" $WP igr backfill --now --flush --delay=0
else
    $WP eval 'ImaginaGrowth\Connector\Backfill\Runner::pause(true); as_unschedule_all_actions("igr_backfill_step");'
fi

step "La «noche anterior»: sesiones, atribución, KPI, RFM, segmentos y hallazgos con el historial"
wait_ingest() {
    until php artisan tinker --execute='
$s = app(App\Ingest\IngestStream::class); $r = $s->connection()->client(); $p = 0; $lag = 0;
for ($i = 0; $i < $s->shards(); $i++) { $x = $r->xPending($s->key($i), config("igr.ingest.consumer_group")); $p += is_array($x) ? (int) ($x[0] ?? 0) : 0; foreach ((array) $r->xInfo("GROUPS", $s->key($i)) as $g) { $lag += (int) ($g["lag"] ?? 0); } }
echo ($p + $lag) === 0 ? "listo" : "pendientes=".($p + $lag);' | tail -1 | grep -q listo; do sleep 2; done
    until [ "$(php artisan tinker --execute='echo Illuminate\Support\Facades\Queue::connection("redis")->size("projections") + Illuminate\Support\Facades\Queue::connection("redis")->size("triggers") + Illuminate\Support\Facades\Queue::connection("redis")->size("conversions:meta");' | tail -1)" = "0" ]; do sleep 2; done
}
nightly() {
    php artisan igr:sessions:build --workspace="$WS"
    php artisan igr:attribution:backfill --workspace="$WS"
    php artisan igr:kpi:build --workspace="$WS" --days=800
    php artisan igr:rfm --workspace="$WS"
    php artisan igr:segments:recompute --workspace="$WS" --sync | tail -3
    php artisan igr:insights --workspace="$WS"
}
wait_ingest
IGR_HUB_URL="$HUB" $WP igr flush --all >/dev/null || true
wait_ingest
nightly

step "Navegador: anuncio de Facebook → banner → producto → búsqueda → compra con tarjeta → encuesta"
EMAIL="laura.gomez.$(date +%s)@example.com"
STORE_URL=http://127.0.0.1:8080 EMAIL="$EMAIL" SHOTS="$LOGS/capturas" node "$E2E/fase1-browser.cjs" | tee "$LOGS/browser.out"
tail -1 "$LOGS/browser.out" >"$LOGS/browser.json"

step "Después de la visita: segmentos en tiempo real (15 s) y el cálculo de la noche siguiente"
IGR_HUB_URL="$HUB" $WP igr flush --all >/dev/null || true
sleep 3
wait_ingest
sleep 20
wait_ingest
nightly
wait_ingest

step "Comprobaciones en el hub (identidad, perfil, consentimiento, atribución, Meta, segmentos, RFM)"
php "$E2E/fase1-check.php" "$WS" "$LOGS/browser.json"

step "Panel: inicio de sesión con 2FA, tableros (criterio < 1,5 s) y perfil 360"
HUB="$HUB" WS="$WS" OWNER_EMAIL="$OWNER_EMAIL" OWNER_PASS="$OWNER_PASS" TOTP_SECRET="$TOTP_SECRET" \
    EMAIL="$EMAIL" OUT="$LOGS/capturas" node "$E2E/fase1-panel.cjs"

step "Informe semanal por correo al dueño (criterio)"
php artisan igr:report:weekly --workspace="$WS" --force
for _ in $(seq 1 60); do [ -n "$(ls "$LOGS/buzon" 2>/dev/null)" ] && break; sleep 1; done
MAIL_FILE=$(ls "$LOGS"/buzon/*.eml 2>/dev/null | head -1 || true)
[ -n "$MAIL_FILE" ] || { echo "✗ No llegó ningún correo al buzón"; exit 1; }
grep -q "X-Igr-Rcpt-To: $OWNER_EMAIL" "$MAIL_FILE" || { echo "✗ El correo no va al dueño"; head -5 "$MAIL_FILE"; exit 1; }
echo "✓ Informe semanal entregado por SMTP a $OWNER_EMAIL ($(grep -m1 '^Subject:' "$MAIL_FILE"))"

printf '\n\033[1mFase 1: todo en verde.\033[0m Registros y capturas en %s\n' "$LOGS"
