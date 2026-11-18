#!/usr/bin/env bash
# Prueba de punta a punta de la Fase 5 (CLAUDE.md §5, «Servicios, agencia y escala»):
#   «un sitio corporativo sin WooCommerce usa el producto completo para leads y la agencia ve a todos sus
#    clientes en un tablero».
#
#   A. La agencia «Imagina La Web» con tres clientes: la Clínica Dental Sonrisa (WordPress corporativo SIN
#      WooCommerce, con Contact Form 7), Pinturas La 14 (tienda demo) y Café Sierra (sin conectar).
#   B. Una persona llega desde un anuncio de Google, mira los servicios y deja sus datos en el formulario: nace
#      un lead con puntaje, fuente (Google Ads, gclid) y vendedora asignada, y la vendedora recibe el aviso por
#      WhatsApp. Luego agenda su valoración con el botón del sitio (página de reservas del hub): la cita queda
#      en el mismo lead.
#   C. Dos horas antes sale el recordatorio; la persona responde «Confirmo» por WhatsApp y la cita queda confirmada.
#   D. Otra persona usa el cotizador incrustado en el sitio: ve su rango de precio y queda como lead del cotizador.
#   E. El dueño marca el primer lead como ganado ($3.500.000) desde el panel: la conversión offline va a Meta
#      (Conversions API) y a Google Ads (Data Manager, con el gclid).
#   F. La dueña de la agencia ve a sus tres clientes en un tablero, con el dinero generado de la clínica
#      (el lead ganado), y genera y descarga el reporte mensual en PDF con marca; el dueño lo recibe por correo.
#
# Meta, Google y WhatsApp son simuladores locales (mock-meta.php): se prueba lo que hace el hub, no la
# respuesta de las plataformas reales. Sin clave de Anthropic: el resumen del reporte usa la plantilla sin IA.
#
# Variables: WP_CORP_DIR=/ruta/wordpress-corporativo (sin WooCommerce, con Contact Form 7 y el plugin; su wp-config.php
#   lleva WP_ENVIRONMENT_TYPE=local y `define('IGR_HUB_URL', getenv('IGR_HUB_URL') ?: …)` como la tienda de prueba, y las
#   páginas /servicios/, /valoracion/ (formulario 5 de CF7 con casillas de novedades) y /agenda/ ([igr_booking]))
#   HUB_PORT=8010  CORP_PORT=8081  LOGS=/carpeta  DB_ROOT="docker exec igr-mariadb mariadb -uroot -proot"
set -euo pipefail
E2E=$(cd "$(dirname "$0")" && pwd)
cd "$E2E/../../hub"
HUB_DIR=$(pwd)

: "${WP_CORP_DIR:?define WP_CORP_DIR con la ruta del WordPress corporativo}"
WPC="wp --allow-root --path=$WP_CORP_DIR"
HUB_PORT=${HUB_PORT:-8010}
CORP_PORT=${CORP_PORT:-8081}
SMTP_PORT=${SMTP_PORT:-2527}
META_PORT=${META_PORT:-8091}
DB_ROOT=${DB_ROOT:-docker exec igr-mariadb mariadb -uroot -proot}
LOGS=${LOGS:-$(mktemp -d)}
mkdir -p "$LOGS/buzon" "$LOGS/capturas"
HUB="http://127.0.0.1:${HUB_PORT}"
CORP="http://127.0.0.1:${CORP_PORT}"
export HUB CORP APP_URL="$HUB" IGR_APP_URL="$HUB" IGR_INGEST_URL="$HUB" IGR_LINKS_URL="$HUB"
export DB_DATABASE=igr_e2e5 CLICKHOUSE_DATABASE=igr_e2e5 REDIS_INGEST_DB=10 REDIS_DB=11 REDIS_CACHE_DB=12
export QUEUE_CONNECTION=redis CACHE_STORE=redis SESSION_DRIVER=database SANCTUM_STATEFUL_DOMAINS="127.0.0.1:${HUB_PORT}"
export SMTP_PORT MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT="$SMTP_PORT" MAIL_SCHEME=smtp MAIL_ENCRYPTION=null MAIL_USERNAME= MAIL_PASSWORD=
export IGR_ANTHROPIC_API_KEY= IGR_ANTHROPIC_BASE_URL=
export IGR_META_GRAPH_URL="http://127.0.0.1:${META_PORT}" IGR_META_APP_SECRET=e2e-meta-secret META_LOG="$LOGS/plataformas.jsonl"
export IGR_GOOGLE_OAUTH_TOKEN_URL="http://127.0.0.1:${META_PORT}/google/token" IGR_GOOGLE_DATA_MANAGER_EVENTS_URL="http://127.0.0.1:${META_PORT}/google/v1/events:ingest"
export AGENCY_EMAIL=agencia@imaginalaweb.test AGENCY_PASS=Clave-agencia-2026 OWNER_EMAIL=dueno@clinicasonrisa.test OWNER_PASS=Clave-clinica-2026 TOTP_SECRET=JBSWY3DPEHPK3PXP
STAMP=$(date +%s)
export SELLER_PHONE="+57310${STAMP: -7}"
PHONE_1="+57300${STAMP: -7}"
PHONE_2="+57301${STAMP: -7}"
EMAIL_1="maria.${STAMP}@constructora-andina.co"
EMAIL_2="julian.${STAMP}@estudio-norte.co"
GCLID="Cj0KCQiE2E${STAMP}"
WON_VALUE=3500000
MONTH=$(TZ=America/Bogota date +%Y-%m)

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
fail() { printf '\033[31m✗ %s\033[0m\n' "$*"; exit 1; }
ok() { printf '\033[32m✓\033[0m %s\n' "$*"; }
QUOTE_PAGE=""
cleanup() {
    kill $(jobs -p) 2>/dev/null || true
    $WPC igr disconnect >/dev/null 2>&1 || true
    [ -n "$QUOTE_PAGE" ] && $WPC post delete "$QUOTE_PAGE" --force >/dev/null 2>&1 || true
}
trap cleanup EXIT
wait_http() { for _ in $(seq 1 240); do curl -fsS "$1" >/dev/null 2>&1 && return 0; sleep 0.5; done; fail "no responde $1"; }
hubphp() { php "$E2E/fase5-hub.php" "$@"; }
json() { python3 -c "import json,sys; d=json.loads(sys.stdin.read().strip().splitlines()[-1]); print($1)"; }
QUEUES="projections,triggers,flows,ai,messages:whatsapp,messages:email,conversions:meta,conversions:google,conversions:tiktok,default,analytics"
wait_hub() {
    until php artisan tinker --execute='
$s = app(App\Ingest\IngestStream::class); $r = $s->connection()->client(); $p = 0; $lag = 0;
for ($i = 0; $i < $s->shards(); $i++) { $x = $r->xPending($s->key($i), config("igr.ingest.consumer_group")); $p += is_array($x) ? (int) ($x[0] ?? 0) : 0; foreach ((array) $r->xInfo("GROUPS", $s->key($i)) as $g) { $lag += (int) ($g["lag"] ?? 0); } }
echo ($p + $lag) === 0 ? "listo" : "pendientes=".($p + $lag);' | tail -1 | grep -q listo; do sleep 2; done
    until [ "$(php artisan tinker --execute='$q = Illuminate\Support\Facades\Queue::connection("redis"); $n = 0; foreach (explode(",", "'"$QUEUES"'") as $c) { $n += $q->size($c); } echo $n;' | tail -1)" = "0" ]; do sleep 2; done
}
flush_site() { IGR_HUB_URL="$HUB" $WPC igr flush --all >/dev/null 2>&1 || true; }
settle() { flush_site; wait_hub; sleep 2; flush_site; wait_hub; }
platform_log() { curl -fsS "http://127.0.0.1:${META_PORT}/_log"; }

step "Hub aislado: bases nuevas"
$DB_ROOT -e "DROP DATABASE IF EXISTS igr_e2e5; CREATE DATABASE igr_e2e5; GRANT ALL ON igr_e2e5.* TO 'igr'@'%';"
php artisan migrate --force -q
php artisan igr:clickhouse:migrate --fresh -q
for db in 10 11 12; do redis-cli -n $db flushdb >/dev/null; done
read -r WS_CLINIC WS_STORE WS_CAFE CODE QC < <(hubphp setup | tail -1)
echo "Clínica: $WS_CLINIC · Tienda: $WS_STORE · Café: $WS_CAFE · Cotizador: $QC"

step "Procesos del hub, Meta, Google y WhatsApp simulados, buzón SMTP"
php -S 127.0.0.1:"$META_PORT" "$E2E/mock-meta.php" >"$LOGS/mock-plataformas.log" 2>&1 &
python3 "$E2E/smtp-sink.py" 127.0.0.1 "$SMTP_PORT" "$LOGS/buzon" >"$LOGS/smtp.log" 2>&1 &
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port="$HUB_PORT" >"$LOGS/serve.log" 2>&1 &
php artisan igr:ingest:work >"$LOGS/ingest.log" 2>&1 &
php artisan queue:work --queue="$QUEUES" --sleep=1 --tries=3 >"$LOGS/queue.log" 2>&1 &
wait_http "$HUB/health"
wait_http "http://127.0.0.1:${META_PORT}/_log"

step "A. Clientes de la agencia: la tienda demo y el sitio corporativo de la clínica"
php artisan igr:seed-demo --workspace="$WS_STORE" --customers=300 --orders=1500 --months=6 --process | tail -2
$WPC plugin is-active woocommerce 2>/dev/null && fail "El sitio corporativo no debe tener WooCommerce"
$WPC igr disconnect >/dev/null 2>&1 || true
IGR_HUB_URL="$HUB" PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:"$CORP_PORT" -t "$WP_CORP_DIR" >"$LOGS/sitio.log" 2>&1 &
wait_http "$CORP/wp-login.php"
IGR_HUB_URL="$HUB" $WPC igr connect "$CODE"
QUOTE_PAGE=$($WPC post create --post_type=page --post_status=publish --post_title="Cotiza tu diseño de sonrisa" --post_name=cotiza \
    --post_content="<!-- wp:shortcode -->[igr_quote id=\"$QC\" height=\"520\"]<!-- /wp:shortcode -->" --porcelain)
settle
SITE=$(hubphp site "$WS_CLINIC" | tail -1)
echo "$SITE"
[ "$(echo "$SITE" | json 'd["site"]["status"] == "active" and d["site"]["wc_version"] is None')" = "True" ] || fail "El sitio corporativo no quedó conectado como WordPress sin WooCommerce"
ok "Clínica conectada: WordPress con Contact Form 7, sin WooCommerce"

step "B. Del anuncio al lead: formulario, aviso a la vendedora y cita agendada"
VISIT=$(NAME="María Fernanda Ospina" EMAIL="$EMAIL_1" PHONE="$PHONE_1" GCLID="$GCLID" SHOTS="$LOGS/capturas" node "$E2E/fase5-browser.cjs" visitor | tail -1)
echo "$VISIT"
[ "$(echo "$VISIT" | json 'd["form"] == "sent" and d["booked"] and d["manage"]')" = "True" ] || fail "La persona no pudo dejar sus datos o agendar"
[ "$(echo "$VISIT" | json 'd["errors"] == []')" = "True" ] || fail "Errores de JavaScript en el sitio o en la página de reservas"
settle
LEAD=$(hubphp lead "$WS_CLINIC" "$EMAIL_1" | tail -1)
echo "$LEAD" | python3 -m json.tool
[ "$(echo "$LEAD" | json 'd["lead"]["origin"]')" = "form" ] || fail "El formulario no creó el lead"
[ "$(echo "$LEAD" | json '"form" in d["lead"]["signals"] and "pricing_page" in d["lead"]["signals"] and d["lead"]["score"] >= 40')" = "True" ] || fail "El puntaje no tomó el formulario y la página de servicios"
[ "$(echo "$LEAD" | json 'd["lead"]["has_gclid"] and str(d["lead"]["channel"]).startswith("paid")')" = "True" ] || fail "El lead no trae la fuente del anuncio (Google Ads y gclid)"
[ "$(echo "$LEAD" | json 'd["lead"]["owner"]')" = "Vendedora de la clínica" ] || fail "El lead no se asignó a la vendedora"
[ "$(echo "$LEAD" | json 'any(a.startswith("alert:whatsapp") for a in d["lead"]["activities"])')" = "True" ] || fail "La vendedora no recibió el aviso por WhatsApp"
platform_log | python3 -c "import json,sys; d=json.load(sys.stdin); sys.exit(0 if any(x.get('kind')=='whatsapp' and x['body'].get('to')=='${SELLER_PHONE#+}' and x['body'].get('type')=='template' for x in d) else 1)" \
    || fail "El aviso no llegó a la Graph API con la plantilla aprobada"
[ "$(echo "$LEAD" | json 'len(d["appointments"]) == 1 and d["appointments"][0]["same_lead"] and d["leads_of_contact"] == 1')" = "True" ] || fail "La cita no quedó en el mismo lead"
LEAD_ID=$(echo "$LEAD" | json 'd["lead"]["id"]')
ok "Lead $LEAD_ID: $(echo "$LEAD" | json 'd["lead"]["score"]') puntos ($(echo "$LEAD" | json 'd["lead"]["temperature"]')), Google Ads con gclid, asignado y avisado por WhatsApp; la cita quedó en el mismo lead"

step "C. Recordatorio 2 horas antes y confirmación por WhatsApp"
REMIND=$(hubphp remind "$WS_CLINIC" "$EMAIL_1" | tail -1)
echo "$REMIND"
[ "$(echo "$REMIND" | json '"2h" in d["reminders"]')" = "True" ] || fail "No salió el recordatorio de 2 horas"
wait_hub
python3 "$E2E/mailcheck.py" "$LOGS/buzon" "$EMAIL_1" "Valoración" | tail -3 || fail "El recordatorio no llegó al correo de la persona"
hubphp inbound "$WS_CLINIC" "$PHONE_1" "Confirmo" "wamid.E2E5C${STAMP}" >/dev/null
wait_hub
APPT=$(hubphp appt "$WS_CLINIC" "$EMAIL_1" | tail -1)
echo "$APPT"
[ "$(echo "$APPT" | json 'd["appointment"]["status"]')" = "confirmed" ] || fail "«Confirmo» por WhatsApp no confirmó la cita"
ok "Recordatorio enviado y cita confirmada desde WhatsApp"

step "D. Cotizador incrustado en el sitio"
QUOTE=$(NAME="Julián Restrepo" EMAIL="$EMAIL_2" PHONE="$PHONE_2" SHOTS="$LOGS/capturas" node "$E2E/fase5-browser.cjs" quote | tail -1)
echo "$QUOTE"
[ "$(echo "$QUOTE" | json 'd["errors"] == [] and "$" in d["result"]')" = "True" ] || fail "El cotizador no mostró el rango de precio"
settle
QLEAD=$(hubphp quote "$WS_CLINIC" "$EMAIL_2" | tail -1)
echo "$QLEAD"
[ "$(echo "$QLEAD" | json 'd["lead"]["origin"] == "quote" and d["quote"]["same_lead"] and d["quote"]["estimate_min"] is not None')" = "True" ] || fail "La cotización no quedó como lead del cotizador"
ok "Cotización: $(echo "$QUOTE" | json 'd["result"]')"

step "E. El dueño marca el lead como ganado: conversión offline a Meta y Google Ads"
OWNER=$(LEAD="$LEAD_ID" VALUE="$WON_VALUE" WS_CLINIC="$WS_CLINIC" SHOTS="$LOGS/capturas" node "$E2E/fase5-browser.cjs" owner | tail -1)
echo "$OWNER"
[ "$(echo "$OWNER" | json 'd["won"]["status"] == 200 and d["errors"] == []')" = "True" ] || fail "No se pudo marcar el lead como ganado desde el panel"
wait_hub
WON=$(hubphp lead "$WS_CLINIC" "$EMAIL_1" | tail -1)
[ "$(echo "$WON" | json 'd["lead"]["won"] and d["lead"]["offline_conversion"]')" = "True" ] || fail "El lead ganado no envió la conversión offline"
platform_log | python3 -c "
import json, sys
d = json.load(sys.stdin)
meta = [e for x in d if x.get('dataset_id') for e in (x['body'].get('data') or []) if e.get('action_source') == 'system_generated']
google = [x for x in d if x.get('kind') == 'google_data_manager']
m = meta[-1] if meta else {}
g = (google[-1]['body']['events'][0] if google else {})
print(json.dumps({'meta': {'event': m.get('event_name'), 'value': (m.get('custom_data') or {}).get('value')},
                  'google': {'authorized': google[-1]['authorized'] if google else None, 'value': g.get('conversionValue'), 'gclid': (g.get('adIdentifiers') or {}).get('gclid') == '$GCLID'}}))
ok = meta and float((m.get('custom_data') or {}).get('value', 0)) == $WON_VALUE and google and google[-1]['authorized'] and float(g.get('conversionValue', 0)) == $WON_VALUE and (g.get('adIdentifiers') or {}).get('gclid') == '$GCLID'
sys.exit(0 if ok else 1)" || fail "Meta o Google no recibieron la conversión offline con su valor y el gclid"
ok "Lead ganado por \$3.500.000: Meta (system_generated) y Google Ads (Data Manager, con el gclid) lo recibieron"

step "F. La agencia ve a todos sus clientes y el reporte mensual en PDF"
AG=$(WS_CLINIC="$WS_CLINIC" MONTH="$MONTH" SHOTS="$LOGS/capturas" node "$E2E/fase5-browser.cjs" agency | tail -1)
echo "$AG" | python3 -m json.tool
[ "$(echo "$AG" | json 'd["overview"] == 200 and sorted(c["name"] for c in d["clients"]) == ["Café Sierra", "Clínica Dental Sonrisa", "Pinturas La 14"]')" = "True" ] || fail "El panel de agencia no muestra a los tres clientes"
[ "$(echo "$AG" | json 'float([c for c in d["clients"] if c["name"] == "Clínica Dental Sonrisa"][0]["money"]) == '"$WON_VALUE")" = "True" ] || fail "El panel no muestra el dinero que generó la clínica"
[ "$(echo "$AG" | json 'd["pdf"]["status"] == 200 and d["pdf"]["magic"] == "%PDF-" and d["pdf"]["bytes"] > 5000')" = "True" ] || fail "La agencia no pudo descargar el reporte en PDF"
php artisan igr:agency:monthly-reports --workspace="$WS_CLINIC" --month="$MONTH" --force --sync | tail -3
PDF=$(ls -t storage/app/private/agency/reports/*/"$MONTH".pdf 2>/dev/null | head -1)
[ -n "$PDF" ] || fail "No se guardó el PDF del reporte"
cp "$PDF" "$LOGS/reporte-clinica-$MONTH.pdf"
pdfinfo "$PDF" | grep -E "Pages|Title" || true
TEXT=$(pdftotext -layout "$PDF" - | tr -s ' ')
echo "$TEXT" | grep -q "Clínica Dental Sonrisa" || fail "El PDF no lleva el nombre del cliente"
echo "$TEXT" | grep -q "3.500.000" || fail "El PDF no muestra el dinero generado"
wait_hub
python3 "$E2E/mailcheck.py" "$LOGS/buzon" "$OWNER_EMAIL" | tail -2
[ "$(python3 "$E2E/mailcheck.py" "$LOGS/buzon" "$OWNER_EMAIL" | json 'd["pdf_bytes"] is not None and d["pdf_bytes"] > 5000')" = "True" ] || fail "El dueño no recibió el reporte con el PDF adjunto"
ok "La agencia ve sus 3 clientes; la clínica generó \$3.500.000 (leads ganados); el reporte PDF se descargó y llegó al dueño"

step "Pruebas automáticas de la Fase 5 en una base aislada"
(
    unset IGR_META_GRAPH_URL IGR_META_APP_SECRET IGR_GOOGLE_OAUTH_TOKEN_URL IGR_GOOGLE_DATA_MANAGER_EVENTS_URL APP_URL IGR_APP_URL IGR_INGEST_URL IGR_LINKS_URL
    $DB_ROOT -e "CREATE DATABASE IF NOT EXISTS igr_test_e5; GRANT ALL ON igr_test_e5.* TO 'igr'@'%';"
    curl -fsS "http://127.0.0.1:8123/" --data-binary "CREATE DATABASE IF NOT EXISTS igr_test_e5" >/dev/null
    DB_DATABASE=igr_test_e5 CLICKHOUSE_DATABASE=igr_test_e5 REDIS_INGEST_DB=15 REDIS_DB=15 REDIS_CACHE_DB=15 QUEUE_CONNECTION=sync CACHE_STORE=array SESSION_DRIVER=array \
        vendor/bin/pest tests/Feature/Leads tests/Feature/Bookings tests/Feature/Quotes tests/Feature/Agency tests/Feature/Billing tests/Feature/Api tests/Feature/Webhooks tests/Feature/Mcp 2>&1 | tail -1
)

printf '\n\033[1mFase 5: todo en verde.\033[0m Registros, capturas y el PDF del reporte en %s\n' "$LOGS"
