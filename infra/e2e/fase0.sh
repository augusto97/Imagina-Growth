#!/usr/bin/env bash
# Prueba de punta a punta del criterio de la Fase 0 (CLAUDE.md §5):
#   «un WooCommerce de prueba con 10.000 pedidos generados se conecta en menos
#    de 2 minutos, el backfill completo termina sin errores, y los conteos de
#    pedidos y la venta total coinciden al 100% con WooCommerce».
#
# Usa una base aislada del hub (igr_e2e) y una tienda WordPress ya preparada
# con `wp igr demo-store`. Variables:
#   WP="php wp-cli.phar --path=/ruta/wp --allow-root"   (obligatoria)
#   HUB_PORT=8000  DB_ROOT="sudo mariadb"
set -euo pipefail
cd "$(dirname "$0")/../../hub"

: "${WP:?define WP con el comando de WP-CLI de la tienda de prueba}"
HUB_PORT=${HUB_PORT:-8000}
DB_ROOT=${DB_ROOT:-sudo mariadb}
LOGS=${LOGS:-$(mktemp -d)}
export APP_URL="http://127.0.0.1:${HUB_PORT}" IGR_APP_URL="http://127.0.0.1:${HUB_PORT}" IGR_INGEST_URL="http://127.0.0.1:${HUB_PORT}"
export DB_DATABASE=igr_e2e CLICKHOUSE_DATABASE=igr_e2e REDIS_INGEST_DB=9 REDIS_DB=8 REDIS_CACHE_DB=7
export QUEUE_CONNECTION=redis CACHE_STORE=redis SESSION_DRIVER=array

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
cleanup() { kill $(jobs -p) 2>/dev/null || true; }
trap cleanup EXIT

step "Hub aislado: bases nuevas y procesos"
$DB_ROOT -e "DROP DATABASE IF EXISTS igr_e2e; CREATE DATABASE igr_e2e; GRANT ALL ON igr_e2e.* TO 'igr'@'%';"
php artisan migrate --force -q
php artisan igr:clickhouse:migrate --fresh -q
for db in 9 8 7; do redis-cli -n $db flushdb >/dev/null; done
php artisan serve --host=127.0.0.1 --port="$HUB_PORT" >"$LOGS/serve.log" 2>&1 &
php artisan igr:ingest:work >"$LOGS/ingest.log" 2>&1 &
php artisan queue:work --queue=projections,default --sleep=1 >"$LOGS/queue.log" 2>&1 &
until curl -fsS "http://127.0.0.1:${HUB_PORT}/health" >/dev/null 2>&1; do sleep 0.5; done

step "Dueño con 2FA, espacio de trabajo y código de conexión manual"
CODE=$(php artisan tinker --execute='
$u = App\Domain\Workspaces\Models\User::create(["name" => "Dueño E2E", "email" => "e2e@tienda.test", "password" => "Clave-e2e-2026"]);
$u->forceFill(["two_factor_secret" => encrypt("JBSWY3DPEHPK3PXP"), "two_factor_confirmed_at" => now()])->save();
$ws = app(App\Domain\Workspaces\Actions\CreateWorkspace::class)->handle($u, new App\Domain\Workspaces\Data\WorkspaceData(name: "Pinturas La 14"));
echo app(App\Domain\Sites\Actions\CreateConnectCode::class)->handle($ws, $u, [])["code"];' | tail -1)
echo "Código: $CODE"

step "Tienda de prueba"
$WP igr status || true
WC_ORDERS=$($WP eval 'echo count(wc_get_orders(["limit" => -1, "return" => "ids", "type" => "shop_order", "status" => array_diff(array_keys(wc_get_order_statuses()), ["wc-checkout-draft"])]));')
echo "Pedidos en WooCommerce: $WC_ORDERS"

step "Conexión (criterio: menos de 2 minutos)"
T0=$(date +%s)
$WP igr connect "$CODE"
T1=$(date +%s)
echo "Conectado en $((T1 - T0)) s"

step "Importación histórica completa"
$WP igr backfill --now --flush --delay=0
T2=$(date +%s)
echo "Backfill enviado en $((T2 - T1)) s"

step "Esperando a que el hub procese todo"
until php artisan tinker --execute='
$s = app(App\Ingest\IngestStream::class); $r = $s->connection()->client(); $p = 0; $l = 0;
for ($i = 0; $i < $s->shards(); $i++) { $x = $r->xPending($s->key($i), config("igr.ingest.consumer_group")); $p += is_array($x) ? (int) ($x[0] ?? 0) : 0; $l += (int) $r->xLen($s->key($i)); }
$info = 0; foreach (range(0, $s->shards() - 1) as $i) { $g = $r->xInfo("GROUPS", $s->key($i)); foreach ((array) $g as $gr) { $info += (int) ($gr["lag"] ?? 0); } }
echo ($p + $info) === 0 ? "listo" : "pendientes=".($p + $info);' | tail -1 | grep -q listo; do sleep 2; done
$WP igr flush || true
# También la cola de recálculos de contadores de contactos.
until [ "$(php artisan tinker --execute='echo Illuminate\Support\Facades\Queue::connection("redis")->size("projections");' | tail -1)" = "0" ]; do sleep 2; done
sleep 3
T3=$(date +%s)
echo "Ingesta completa en $((T3 - T1)) s desde la conexión"

step "Conciliación por estado (WooCommerce vs. hub)"
php artisan tinker --execute='
$site = App\Domain\Sites\Models\Site::withoutWorkspaceScope()->firstOrFail();
$ws = App\Domain\Workspaces\Models\Workspace::findOrFail($site->workspace_id);
$r = app(App\Support\Tenancy\CurrentWorkspace::class)->run($ws, fn () => app(App\Domain\Sites\Queries\ReconciliationQuery::class)->handle($site));
if (! ($r["available"] ?? false)) { echo "SIN CONCILIACION\n"; exit(1); }
printf("%-12s %8s %8s %18s %18s %s\n", "estado", "WC", "hub", "total WC", "total hub", "ok");
foreach ($r["rows"] as $row) printf("%-12s %8d %8d %18s %18s %s\n", $row["status"], $row["wc_count"], $row["hub_count"], $row["wc_total"], $row["hub_total"], $row["ok"] ? "✓" : "✗");
printf("TOTAL        %8d %8d %18s %18s\n", $r["totals"]["wc_count"], $r["totals"]["hub_count"], $r["totals"]["wc_total"], $r["totals"]["hub_total"]);
echo "rechazos: ", Illuminate\Support\Facades\DB::table("ingest_rejections")->count(), "\n";
echo $r["ok"] ? "RESULTADO: coincide al 100%\n" : "RESULTADO: hay diferencias\n";'
echo "Registros en $LOGS"
