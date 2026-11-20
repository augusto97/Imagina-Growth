#!/usr/bin/env bash
# Pruebas de las copias (infra/backup) con comandos falsos: no necesitan MariaDB, ClickHouse, rclone ni
# Better Stack. Corre en el CI (job backup-scripts) y en cualquier máquina con bash, tar y zstd:
#   bash infra/tests/backup/run.sh
# Las condiciones de check() van entre comillas simples a propósito (se evalúan después de correr el script),
# por eso SC2016 y SC2034 no aplican.
# shellcheck disable=SC2016,SC2034
# shellcheck source-path=SCRIPTDIR
set -uo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
BACKUP=$(cd "$HERE/../../backup" && pwd)
# shellcheck source=fakes.sh
. "$HERE/fakes.sh"

PASS=0
FAIL=0
CURRENT=""

ok() { PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); printf '  \033[31m✗\033[0m %s\n' "$1"; }
check() { if eval "$2"; then ok "$1"; else bad "$1 ($2)"; fi; }
called() { grep -qF -- "$1" "$CALLS"; }

setup() {
    CURRENT=$1
    printf '\n\033[1m%s\033[0m\n' "$CURRENT"
    # La carpeta de la prueba sale de /tmp del sistema (no del TMPDIR de la prueba anterior, que ya no existe).
    # Si mktemp falla, se detiene todo: con TMPROOT vacío los comandos falsos irían a /bin.
    unset TMPDIR
    TMPROOT=$(mktemp -d /tmp/growth-backup-test.XXXXXX) || { echo "no se pudo crear la carpeta temporal" >&2; exit 2; }
    case $TMPROOT in /tmp/growth-backup-test.*) ;; *) echo "carpeta temporal inesperada: «$TMPROOT»" >&2; exit 2 ;; esac
    [ -d "$TMPROOT" ] || exit 2
    export TMPROOT
    export FAKEBIN=$TMPROOT/bin CALLS=$TMPROOT/calls.log FAKE_REMOTE=$TMPROOT/remote FAKE_DATADIR=$TMPROOT/datadir
    export GROWTH_BACKUP_DIR=$TMPROOT/backups GROWTH_BACKUP_CONF=$TMPROOT/backup.conf GROWTH_ENV_FILE=$TMPROOT/.env
    export CLICKHOUSE_CLIENT=$FAKEBIN/clickhouse-client MARIADBD=$FAKEBIN/mariadbd
    export GROWTH_MYSQL_USER=- GROWTH_CLICKHOUSE_OWNER=-
    # Donde las copias dejan su resultado para el panel (shared/state).
    export GROWTH_STATE_DIR=$TMPROOT/state
    unset FAKE_FAIL FAKE_BINLOGS FAKE_RESTORED_COUNT_contacts FAKE_CH_RESTORED_EVENTS GROWTH_BACKUP_DOW FAKE_CURL_EXIT RCLONE_CONFIG
    make_fakes
    export PATH="$FAKEBIN:$ORIG_PATH"
    : >"$CALLS"
    mkdir -p "$FAKE_REMOTE/growth" "$FAKE_DATADIR" "$GROWTH_BACKUP_DIR" "$TMPROOT/tmp" "$GROWTH_STATE_DIR"
    export TMPDIR=$TMPROOT/tmp
    for n in 1 2 3; do echo "binlog $n" >"$FAKE_DATADIR/mariadb-bin.00000$n"; done
    cat >"$GROWTH_BACKUP_CONF" <<'CONF'
# /etc/growth/backup.conf de prueba
GROWTH_BACKUP_REMOTE=remote:growth
GROWTH_BACKUP_KEEP_DAYS=30
BETTERSTACK_HEARTBEAT_BACKUP_MARIADB=https://uptime.example/api/v1/heartbeat/MARIADB
BETTERSTACK_HEARTBEAT_BACKUP_CLICKHOUSE=https://uptime.example/api/v1/heartbeat/CLICKHOUSE
BETTERSTACK_HEARTBEAT_BACKUP_VERIFY=https://uptime.example/api/v1/heartbeat/VERIFY
CONF
    cat >"$GROWTH_ENV_FILE" <<'ENV'
APP_NAME="Imagina Growth"
MAIL_FROM_NAME=Imagina Growth <hola@growth.test>
DB_DATABASE=igr
CLICKHOUSE_DATABASE=igr
CLICKHOUSE_USERNAME=igr
CLICKHOUSE_PASSWORD="s3cr&t<\"x>"
ENV
}

teardown() {
    pkill -f "growth-fake-mariadbd" 2>/dev/null || true
    case ${TMPROOT:-} in /tmp/growth-backup-test.?*) rm -rf "$TMPROOT" ;; esac
    unset TMPDIR
}

ORIG_PATH=$PATH

# ── Lector dotenv ──────────────────────────────────────────────────────────────
setup "El lector de .env no ejecuta nada y entiende los valores raros"
# shellcheck source=../../backup/growth-backup-lib.sh
. "$BACKUP/growth-backup-lib.sh"
cat >"$TMPROOT/raro.env" <<'ENV'
# comentario
MAIL_FROM=Nombre <correo@x.co>
SPACED = valor con espacios
QUOTED="a b#c"
SINGLE='literal $HOME y "comillas"'
INLINE=valor # comentario
HASHNOSPACE=abc#def
export EXPORTED=si
EMPTY=
ESCAPED="dice \"hola\" y \\ barra"
EQUALS=a=b=c
CMD=$(touch /tmp/growth-no-debe-existir)
MULTI="linea uno
linea dos"
DUP=primero
DUP=segundo
WIN=crlf
ENV
printf 'WIN=crlf\r\n' >>"$TMPROOT/raro.env"
rm -f /tmp/growth-no-debe-existir
check "valor con < y > sin comillas" '[ "$(dotenv_get "$TMPROOT/raro.env" MAIL_FROM)" = "Nombre <correo@x.co>" ]'
check "espacios alrededor del = y al final" '[ "$(dotenv_get "$TMPROOT/raro.env" SPACED)" = "valor con espacios" ]'
check "comillas dobles con # adentro" '[ "$(dotenv_get "$TMPROOT/raro.env" QUOTED)" = "a b#c" ]'
check "comillas simples literales" '[ "$(dotenv_get "$TMPROOT/raro.env" SINGLE)" = "literal \$HOME y \"comillas\"" ]'
check "comentario al final" '[ "$(dotenv_get "$TMPROOT/raro.env" INLINE)" = "valor" ]'
check "# sin espacio es parte del valor" '[ "$(dotenv_get "$TMPROOT/raro.env" HASHNOSPACE)" = "abc#def" ]'
check "export" '[ "$(dotenv_get "$TMPROOT/raro.env" EXPORTED)" = "si" ]'
check "vacío" '[ -z "$(dotenv_get "$TMPROOT/raro.env" EMPTY)" ]'
check "escapes en comillas dobles" '[ "$(dotenv_get "$TMPROOT/raro.env" ESCAPED)" = "dice \"hola\" y \\ barra" ]'
check "varios = en el valor" '[ "$(dotenv_get "$TMPROOT/raro.env" EQUALS)" = "a=b=c" ]'
check "\$(…) se lee como texto y no se ejecuta" '[ "$(dotenv_get "$TMPROOT/raro.env" CMD)" = "\$(touch /tmp/growth-no-debe-existir)" ] && [ ! -e /tmp/growth-no-debe-existir ]'
check "varias líneas entre comillas" '[ "$(dotenv_get "$TMPROOT/raro.env" MULTI)" = "linea uno
linea dos" ]'
check "la última definición gana" '[ "$(dotenv_get "$TMPROOT/raro.env" DUP)" = "segundo" ]'
check "fin de línea de Windows" '[ "$(dotenv_get "$TMPROOT/raro.env" WIN)" = "crlf" ]'
check "clave que no está: vacío" '[ -z "$(dotenv_get "$TMPROOT/raro.env" NO_EXISTE)" ]'
check "archivo que no existe: vacío y sin error" '[ -z "$(dotenv_get "$TMPROOT/no-existe.env" X)" ]'
teardown

# ── MariaDB ────────────────────────────────────────────────────────────────────
setup "MariaDB: copia, manifiesto, binlogs, subida y heartbeat"
"$BACKUP/growth-backup-mariadb" >"$TMPROOT/out.log" 2>&1
code=$?
STAMP=$(find "$FAKE_REMOTE/growth/mariadb" -maxdepth 1 -name '*.tar.zst' -printf '%f\n' | head -n 1)
check "termina bien" '[ $code -eq 0 ]'
check "la copia comprimida llegó al remoto" '[ -n "$STAMP" ]'
check "la copia trae el datadir preparado" 'tar -I zstd -tf "$FAKE_REMOTE/growth/mariadb/$STAMP" | grep -q "/prepared$"'
check "el manifiesto llegó con los conteos de antes y después" 'grep -q "\"before\":{\"tables\":120,\"workspaces\":10,\"users\":10,\"contacts\":10,\"orders\":10}" "$FAKE_REMOTE/growth/mariadb/${STAMP%.tar.zst}.manifest.json"'
check "binlogs cerrados copiados y subidos, sin el activo" '[ -f "$FAKE_REMOTE/growth/mariadb/binlogs/mariadb-bin.000001" ] && [ -f "$FAKE_REMOTE/growth/mariadb/binlogs/mariadb-bin.000002" ] && [ ! -e "$FAKE_REMOTE/growth/mariadb/binlogs/mariadb-bin.000003" ]'
check "no queda el datadir de trabajo" '[ -z "$(find "$GROWTH_BACKUP_DIR/mariadb" -mindepth 1 -maxdepth 1 -type d ! -name binlogs)" ]'
check "avisa al heartbeat" 'called "curl https://uptime.example/api/v1/heartbeat/MARIADB" && ! called "/MARIADB/fail"'
check "no escribe la URL del heartbeat en el log" '! grep -q "uptime.example" "$TMPROOT/out.log"'
check "permisos: carpeta común 0711 (ClickHouse y mysql entran a su subcarpeta), copias de MariaDB 0700" \
    '[ "$(stat -c %a "$GROWTH_BACKUP_DIR")" = 711 ] && [ "$(stat -c %a "$GROWTH_BACKUP_DIR/mariadb")" = 700 ]'
J=$GROWTH_STATE_DIR/backup-job-mariadb.json
check "deja el resultado para el panel: bien, con la copia y su tamaño (0644)" \
    '[ "$(jq -r .state "$J")" = succeeded ] && [ "$(jq -r .name "$J")" = "$STAMP" ] && [ "$(jq -r .size_bytes "$J")" -gt 0 ] && [ "$(jq -r .last_success.name "$J")" = "$STAMP" ] && [ "$(stat -c %a "$J")" = 644 ]'
check "el resultado no trae el heartbeat" '! grep -q "uptime.example" "$J"'
# Una corrida que falla después: el panel ve la falla en palabras y sigue viendo la última copia buena.
export FAKE_FAIL=mariadb-backup
"$BACKUP/growth-backup-mariadb" >/dev/null 2>&1
check "la falla dice en qué paso, y conserva la última copia buena" \
    '[ "$(jq -r .state "$J")" = failed ] && [ "$(jq -r .code "$J")" = dump_failed ] && jq -r .message "$J" | grep -q "base de datos" && [ "$(jq -r .last_success.name "$J")" = "$STAMP" ]'
unset FAKE_FAIL
teardown

setup "MariaDB: si mariadb-backup falla, avisa a /fail y no deja basura"
export FAKE_FAIL=mariadb-backup
"$BACKUP/growth-backup-mariadb" >"$TMPROOT/out.log" 2>&1
code=$?
check "termina con error" '[ $code -ne 0 ]'
check "avisa a /fail" 'called "curl https://uptime.example/api/v1/heartbeat/MARIADB/fail"'
check "no sube nada" '[ -z "$(find "$FAKE_REMOTE/growth" -type f)" ]'
check "no deja carpetas ni archivos a medias" '[ -z "$(find "$GROWTH_BACKUP_DIR/mariadb" -mindepth 1 -maxdepth 1 ! -name binlogs)" ]'
teardown

setup "MariaDB: sin remoto configurado también avisa a /fail"
sed -i '/GROWTH_BACKUP_REMOTE/d' "$GROWTH_BACKUP_CONF"
"$BACKUP/growth-backup-mariadb" >"$TMPROOT/out.log" 2>&1
code=$?
check "termina con error" '[ $code -ne 0 ]'
check "explica qué falta" 'grep -q "falta GROWTH_BACKUP_REMOTE" "$TMPROOT/out.log"'
check "avisa a /fail" 'called "/MARIADB/fail"'
check "el panel ve «sin destino»" '[ "$(jq -r .code "$GROWTH_STATE_DIR/backup-job-mariadb.json")" = not_configured ]'
teardown

setup "El resultado para el panel nunca sigue un symlink ni hace fallar la copia"
echo intacto >"$TMPROOT/victima"
ln -s "$TMPROOT/victima" "$GROWTH_STATE_DIR/backup-job-mariadb.json"
"$BACKUP/growth-backup-mariadb" >"$TMPROOT/out.log" 2>&1
code=$?
check "la copia termina bien" '[ $code -eq 0 ]'
check "el archivo apuntado no cambió y el resultado quedó en su lugar" '[ "$(cat "$TMPROOT/victima")" = intacto ] && [ ! -L "$GROWTH_STATE_DIR/backup-job-mariadb.json" ] && [ "$(jq -r .state "$GROWTH_STATE_DIR/backup-job-mariadb.json")" = succeeded ]'
rm -f "$GROWTH_STATE_DIR/backup-job-mariadb.json"
mkdir "$GROWTH_STATE_DIR/backup-job-mariadb.json"
"$BACKUP/growth-backup-mariadb" >"$TMPROOT/out.log" 2>&1
check "si no se puede escribir (una carpeta en su lugar), la copia sigue bien" '[ $? -eq 0 ] && called "/MARIADB"'
teardown

setup "Con la configuración del panel, rclone usa la suya (GROWTH_RCLONE_CONFIG)"
printf '[growth-backup]\ntype = crypt\n' >"$TMPROOT/backup-rclone.conf"
printf 'GROWTH_RCLONE_CONFIG=%s\n' "$TMPROOT/backup-rclone.conf" >>"$GROWTH_BACKUP_CONF"
cat >>"$FAKEBIN/rclone" <<'SH'
echo "RCLONE_CONFIG=${RCLONE_CONFIG:-}" >>"$CALLS"
SH
"$BACKUP/growth-backup-mariadb" >/dev/null 2>&1
check "rclone recibe RCLONE_CONFIG del panel" 'called "RCLONE_CONFIG=$TMPROOT/backup-rclone.conf"'
teardown

setup "MariaDB: la retención borra solo lo viejo (local 2 días, remoto 30)"
mkdir -p "$GROWTH_BACKUP_DIR/mariadb/binlogs" "$FAKE_REMOTE/growth/mariadb"
touch -d '5 days ago' "$GROWTH_BACKUP_DIR/mariadb/20260101T031500Z.tar.zst" "$GROWTH_BACKUP_DIR/mariadb/20260101T031500Z.manifest.json"
touch -d '1 day ago' "$GROWTH_BACKUP_DIR/mariadb/20260104T031500Z.tar.zst"
touch -d '40 days ago' "$FAKE_REMOTE/growth/mariadb/20251101T031500Z.tar.zst"
touch -d '10 days ago' "$FAKE_REMOTE/growth/mariadb/20251201T031500Z.tar.zst"
touch -d '40 days ago' "$FAKE_REMOTE/growth/mariadb/notas.txt"
"$BACKUP/growth-backup-mariadb" >"$TMPROOT/out.log" 2>&1
check "local de 5 días: borrada" '[ ! -e "$GROWTH_BACKUP_DIR/mariadb/20260101T031500Z.tar.zst" ] && [ ! -e "$GROWTH_BACKUP_DIR/mariadb/20260101T031500Z.manifest.json" ]'
check "local de 1 día: se queda" '[ -e "$GROWTH_BACKUP_DIR/mariadb/20260104T031500Z.tar.zst" ]'
check "remota de 40 días: borrada" '[ ! -e "$FAKE_REMOTE/growth/mariadb/20251101T031500Z.tar.zst" ]'
check "remota de 10 días: se queda" '[ -e "$FAKE_REMOTE/growth/mariadb/20251201T031500Z.tar.zst" ]'
check "un archivo ajeno del remoto no se toca" '[ -e "$FAKE_REMOTE/growth/mariadb/notas.txt" ]'
teardown

# ── ClickHouse ─────────────────────────────────────────────────────────────────
setup "ClickHouse: completa si no hay ninguna, incremental sobre la última completa"
export GROWTH_BACKUP_DOW=3
TODAY=$(date -u +%Y%m%d)
"$BACKUP/growth-backup-clickhouse" >"$TMPROOT/out1.log" 2>&1
c1=$?
"$BACKUP/growth-backup-clickhouse" >"$TMPROOT/out2.log" 2>&1
c2=$?
check "las dos corridas terminan bien" '[ $c1 -eq 0 ] && [ $c2 -eq 0 ]'
check "primera: completa" 'called "BACKUP DATABASE \`igr\` TO Disk('"'"'backups'"'"', '"'"'full-$TODAY.zip'"'"')"'
check "segunda: incremental sobre la completa" 'called "TO Disk('"'"'backups'"'"', '"'"'incr-$TODAY.zip'"'"') SETTINGS base_backup = Disk('"'"'backups'"'"', '"'"'full-$TODAY.zip'"'"')"'
check "zips y manifiestos en el remoto" '[ -f "$FAKE_REMOTE/growth/clickhouse/full-$TODAY.zip" ] && [ -f "$FAKE_REMOTE/growth/clickhouse/incr-$TODAY.manifest.json" ]'
check "el manifiesto dice la base de la incremental" 'grep -q "\"base\":\"full-$TODAY.zip\"" "$GROWTH_BACKUP_DIR/clickhouse/incr-$TODAY.manifest.json"'
check "la contraseña va en el archivo de configuración (0600, escapada) y nunca en la línea de comandos" \
    'grep -q "<password>s3cr&amp;t&lt;&quot;x&gt;</password>" "$TMPROOT/last-ch-config.xml" && [ "$(cat "$TMPROOT/last-ch-config.mode")" = 600 ] && ! grep -q "s3cr" "$CALLS"'
check "no deja el archivo de configuración temporal" '[ -z "$(ls -A "$TMPROOT/tmp")" ]'
check "avisa al heartbeat" 'called "curl https://uptime.example/api/v1/heartbeat/CLICKHOUSE" && ! called "/CLICKHOUSE/fail"'
check "deja el resultado para el panel (la incremental, con su tamaño)" \
    '[ "$(jq -r .state "$GROWTH_STATE_DIR/backup-job-clickhouse.json")" = succeeded ] && [ "$(jq -r .name "$GROWTH_STATE_DIR/backup-job-clickhouse.json")" = "incr-$TODAY.zip" ] && [ "$(jq -r .size_bytes "$GROWTH_STATE_DIR/backup-job-clickhouse.json")" -gt 0 ]'
check "sin la contraseña de ClickHouse en el resultado" '! grep -q "s3cr" "$GROWTH_STATE_DIR/backup-job-clickhouse.json"'
teardown

setup "ClickHouse: los domingos siempre es completa"
export GROWTH_BACKUP_DOW=7
TODAY=$(date -u +%Y%m%d)
mkdir -p "$GROWTH_BACKUP_DIR/clickhouse"
echo vieja >"$GROWTH_BACKUP_DIR/clickhouse/full-20000101.zip"
"$BACKUP/growth-backup-clickhouse" >"$TMPROOT/out.log" 2>&1
check "completa aunque ya hubiera una" 'called "TO Disk('"'"'backups'"'"', '"'"'full-$TODAY.zip'"'"')" && ! called "base_backup"'
teardown

setup "ClickHouse: si el BACKUP falla, borra el zip a medias y avisa a /fail"
export GROWTH_BACKUP_DOW=3 FAKE_FAIL=ch-backup
"$BACKUP/growth-backup-clickhouse" >"$TMPROOT/out.log" 2>&1
code=$?
check "termina con error" '[ $code -ne 0 ]'
check "no queda el zip a medias" '[ -z "$(find "$GROWTH_BACKUP_DIR/clickhouse" -name "*.zip")" ]'
check "no sube nada" '[ -z "$(find "$FAKE_REMOTE/growth" -type f)" ]'
check "avisa a /fail" 'called "/CLICKHOUSE/fail"'
teardown

setup "ClickHouse: retención sin romper las incrementales"
export GROWTH_BACKUP_DOW=3
D=$GROWTH_BACKUP_DIR/clickhouse
mkdir -p "$D" "$FAKE_REMOTE/growth/clickhouse"
touch -d '20 days ago' "$D/full-20000101.zip"
touch -d '9 days ago' "$D/incr-20000110.zip"
touch -d '3 days ago' "$D/incr-20000116.zip"
touch -d '40 days ago' "$FAKE_REMOTE/growth/clickhouse/incr-19991201.zip"
touch -d '33 days ago' "$FAKE_REMOTE/growth/clickhouse/full-19991208.zip"
touch -d '40 days ago' "$FAKE_REMOTE/growth/clickhouse/full-19991201.zip"
"$BACKUP/growth-backup-clickhouse" >"$TMPROOT/out.log" 2>&1
check "la única completa se conserva aunque sea vieja (la nueva incremental depende de ella)" '[ -e "$D/full-20000101.zip" ]'
check "incremental local de 9 días: borrada; la de 3: se queda" '[ ! -e "$D/incr-20000110.zip" ] && [ -e "$D/incr-20000116.zip" ]'
check "remoto: incremental de 40 días borrada" '[ ! -e "$FAKE_REMOTE/growth/clickhouse/incr-19991201.zip" ]'
check "remoto: completa de 33 días se queda (vive una semana más)" '[ -e "$FAKE_REMOTE/growth/clickhouse/full-19991208.zip" ]'
check "remoto: completa de 40 días borrada" '[ ! -e "$FAKE_REMOTE/growth/clickhouse/full-19991201.zip" ]'
teardown

# ── Prueba de restauración ─────────────────────────────────────────────────────
setup "Restauración: baja del remoto, levanta la instancia temporal, compara y limpia"
export GROWTH_BACKUP_DOW=3
"$BACKUP/growth-backup-mariadb" >/dev/null 2>&1
"$BACKUP/growth-backup-clickhouse" >/dev/null 2>&1
"$BACKUP/growth-backup-clickhouse" >/dev/null 2>&1
# El disco local ya no tiene las copias de ClickHouse: las tiene que bajar del remoto (con su base).
rm -f "$GROWTH_BACKUP_DIR"/clickhouse/*
: >"$CALLS"
"$BACKUP/growth-backup-verify" >"$TMPROOT/out.log" 2>&1
code=$?
check "termina bien" '[ $code -eq 0 ]'
check "levantó la instancia temporal en 127.0.0.1:3307 con su socket" 'called "--port=3307" && called "--bind-address=127.0.0.1" && called "restore-check/mariadbd.sock"'
check "comparó los conteos dentro de la instancia temporal" 'called "mariadb[1] SELECT COUNT(*) FROM \`igr\`.\`contacts\`"'
check "restauró ClickHouse en la base temporal desde la incremental" 'called "RESTORE DATABASE \`igr\` AS \`growth_restore_check\` FROM Disk('"'"'backups'"'"', '"'"'incr-"'
check "bajó la incremental y su completa" 'called "rclone copyto remote:growth/clickhouse/incr-" && called "rclone copyto remote:growth/clickhouse/full-"'
check "borró la base temporal" 'called "DROP DATABASE IF EXISTS \`growth_restore_check\` SYNC"'
check "no queda la instancia temporal corriendo" '! pgrep -f "growth-fake-mariadbd" >/dev/null'
check "no queda el datadir temporal ni lo bajado" '[ ! -e "$GROWTH_BACKUP_DIR/restore-check" ] && [ -z "$(find "$GROWTH_BACKUP_DIR/clickhouse" -type f)" ]'
check "avisa al heartbeat" 'called "curl https://uptime.example/api/v1/heartbeat/VERIFY" && ! called "/VERIFY/fail"'
check "deja el resultado para el panel" '[ "$(jq -r .state "$GROWTH_STATE_DIR/backup-job-verify.json")" = succeeded ] && jq -r .name "$GROWTH_STATE_DIR/backup-job-verify.json" | grep -q "incr-"'
teardown

setup "Restauración: un conteo distinto falla, avisa a /fail y limpia igual"
export GROWTH_BACKUP_DOW=3
"$BACKUP/growth-backup-mariadb" >/dev/null 2>&1
"$BACKUP/growth-backup-clickhouse" >/dev/null 2>&1
: >"$CALLS"
export FAKE_RESTORED_COUNT_contacts=999 FAKE_CH_RESTORED_EVENTS=7
"$BACKUP/growth-backup-verify" >"$TMPROOT/out.log" 2>&1
code=$?
check "termina con error" '[ $code -ne 0 ]'
check "dice qué no coincide (MariaDB y ClickHouse)" 'grep -q "MariaDB contacts: 999, se esperaba entre 10 y 10" "$TMPROOT/out.log" && grep -q "ClickHouse events: 7, se esperaba entre 1000 y 1000" "$TMPROOT/out.log"'
check "el panel ve que no coincide y qué conteo" '[ "$(jq -r .code "$GROWTH_STATE_DIR/backup-job-verify.json")" = verify_mismatch ] && jq -r .message "$GROWTH_STATE_DIR/backup-job-verify.json" | grep -q "MariaDB contacts: 999"'
check "avisa a /fail" 'called "/VERIFY/fail"'
check "apaga la instancia temporal" '! pgrep -f "growth-fake-mariadbd" >/dev/null'
check "borra el datadir temporal y la base temporal" '[ ! -e "$GROWTH_BACKUP_DIR/restore-check" ] && called "DROP DATABASE IF EXISTS \`growth_restore_check\` SYNC"'
teardown

setup "Restauración: si la instancia temporal no arranca, falla y limpia"
export GROWTH_BACKUP_DOW=3
"$BACKUP/growth-backup-mariadb" >/dev/null 2>&1
"$BACKUP/growth-backup-clickhouse" >/dev/null 2>&1
# Una copia sin preparar: el mariadbd falso se niega a arrancar.
f=$(find "$FAKE_REMOTE/growth/mariadb" -name '*.tar.zst' | head -n 1)
mkdir -p "$TMPROOT/x" && tar -C "$TMPROOT/x" -I zstd -xf "$f" && rm -f "$TMPROOT"/x/*/prepared && tar -C "$TMPROOT/x" -I zstd -cf "$f" "$(ls "$TMPROOT/x")"
"$BACKUP/growth-backup-verify" >"$TMPROOT/out.log" 2>&1
code=$?
check "termina con error" '[ $code -ne 0 ]'
check "explica que no arrancó" 'grep -q "no arrancó" "$TMPROOT/out.log"'
check "avisa a /fail y limpia" 'called "/VERIFY/fail" && [ ! -e "$GROWTH_BACKUP_DIR/restore-check" ]'
check "el panel ve que la instancia no arrancó" '[ "$(jq -r .code "$GROWTH_STATE_DIR/backup-job-verify.json")" = restore_failed ]'
teardown

printf '\n%d pruebas: %d bien, %d mal\n' $((PASS + FAIL)) "$PASS" "$FAIL"

# El despachador de Plataforma → Copias de seguridad (pedidos del panel) tiene su propio arnés.
PATH=$ORIG_PATH bash "$HERE/dispatch.sh"
DISPATCH_OK=$?
[ "$FAIL" -eq 0 ] && [ "$DISPATCH_OK" -eq 0 ]
