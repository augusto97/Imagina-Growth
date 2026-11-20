#!/usr/bin/env bash
# Arnés del actualizador con un «deployer» falso (docs/despliegue.md §5). Sin root, sin systemd y sin red:
#   bash infra/tests/updater/run.sh
# Cada escenario arma un /opt/growth en una carpeta temporal y revisa estado, archivos y llamadas.
# shellcheck source-path=SCRIPTDIR
# shellcheck disable=SC2015,SC2016  # «A && ok || bad» es la forma de las aserciones; las comillas simples son literales a propósito
set -uo pipefail
# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

EXPECTED_RESTARTS="growth-ingest-api@1.service growth-ingest-api@2.service growth-ingest@1.service growth-ingest@2.service growth-ingest@3.service growth-ingest@4.service growth-horizon.service growth-scheduler.service growth-reverb.service growth-ml.service growth-app.service"

test_update_ok() {
    setup_world "actualiza bien"
    install_release 0.1.0
    for v in 0.0.1 0.0.2 0.0.3; do mkdir -p "$GROWTH_ROOT/releases/$v"; done
    mkdir -p "$GROWTH_ROOT/shared/backups/pre-update"
    for i in 1 2 3 4 5 6; do
        touch -d "2026-01-0$i" "$GROWTH_ROOT/shared/backups/pre-update/vieja-$i.sql.zst"
    done
    make_release 0.2.0
    echo '{"version":"0.2.0","id":"upd_abc123XYZ","requested_by":"usr_01ABC"}' >"$GROWTH_ROOT/shared/state/update-request.processing.json"
    local code
    code=$(run_update 0.2.0)
    assert_eq "$code" 0 "código de salida"
    assert_eq "$(current)" 0.2.0 "current apunta a la nueva"
    assert_eq "$(status .state)" succeeded "estado"
    assert_eq "$(status .id)" upd_abc123XYZ "id del pedido"
    assert_eq "$(status .requested_by)" usr_01ABC "quién la pidió"
    assert_eq "$(status '[.steps[] | select(.status == "done" or .status == "skipped")] | length')" 11 "todos los pasos terminaron"
    assert_eq "$(restarts)" "$EXPECTED_RESTARTS" "orden del reinicio sin cortes"
    local c
    c=$(calls)
    assert_contains "$c" "artisan config:cache --no-interaction (releases/0.2.0/hub)" "config:cache con la versión nueva"
    assert_contains "$c" "artisan route:cache" "route:cache"
    assert_contains "$c" "artisan view:cache" "view:cache"
    assert_contains "$c" "artisan event:cache" "event:cache"
    assert_contains "$c" "artisan migrate --force --no-interaction (releases/0.2.0/hub)" "migraciones de MariaDB"
    assert_contains "$c" "artisan igr:clickhouse:migrate" "migraciones de ClickHouse"
    assert_contains "$c" "uv as=growth sync --frozen --no-dev --quiet (releases/0.2.0/hub/ml) env=shared/venvs/0.2.0" "uv sync como growth, entorno en shared/"
    assert_eq "$(readlink "$GROWTH_ROOT/releases/0.2.0/hub/ml/.venv")" "$GROWTH_ROOT/shared/venvs/0.2.0" ".venv apunta a shared/venvs"
    # releases/ es de root: solo bootstrap/cache se presta a growth para las cachés y vuelve a root.
    assert_contains "$c" "chown -R -h root:root $GROWTH_ROOT/releases/0.2.0.partial" "la versión queda de root"
    assert_contains "$c" "chown growth:growth $GROWTH_ROOT/releases/0.2.0/hub/bootstrap/cache" "bootstrap/cache prestado a growth"
    assert_contains "$c" "chown -R -h root:root $GROWTH_ROOT/releases/0.2.0/hub/bootstrap/cache" "bootstrap/cache vuelve a root"
    local lent
    lent=$(grep -E "^chown .*growth:growth .*/releases/" "$FAKE/calls.log" | grep -vc "/hub/bootstrap/cache$" || true)
    assert_eq "$lent" 0 "nada más de releases/ pasa a growth"
    line_cache_back=$(grep -n "chown -R -h root:root .*bootstrap/cache" "$FAKE/calls.log" | cut -d: -f1)
    line_migrate_first=$(grep -n "artisan migrate" "$FAKE/calls.log" | cut -d: -f1)
    ((line_cache_back < line_migrate_first)) && ok || bad "bootstrap/cache vuelve a root antes de migrar"
    assert_no_root_exec
    # Scripts de root de la versión verificada, instalados después del health-check.
    assert_contains "$(cat "$GROWTH_LIB_DIR/bin/growth-update")" "TRAMPA bin/growth-update 0.2.0" "growth-update nuevo instalado"
    assert_contains "$(cat "$GROWTH_LIB_DIR/bin/growth-update-dispatch")" "0.2.0" "dispatch nuevo instalado"
    assert_contains "$(cat "$GROWTH_LIB_DIR/bin/growth-env")" "0.2.0" "growth-env nuevo instalado"
    assert_contains "$(cat "$GROWTH_LIB_DIR/backup/growth-backup-mariadb")" "0.2.0" "copias nuevas instaladas"
    assert_contains "$(cat "$GROWTH_LIB_DIR/backup/growth-backup-dispatch")" "0.2.0" "despachador de las copias del panel instalado (también si no estaba)"
    assert_eq "$(stat -c %a "$GROWTH_LIB_DIR/bin/growth-update")" 755 "modo 0755"
    assert_eq "$(find "$GROWTH_LIB_DIR" -name '.inst.*' | wc -l)" 0 "sin temporales en la carpeta de scripts"
    assert_contains "$c" "mariadb-dump igr" "copia de MariaDB"
    assert_contains "$c" "systemctl daemon-reload" "unidades nuevas"
    assert_exists "$SYSTEMD_UNIT_DIR/growth-backup.path" "una unidad nueva de la versión (las copias del panel) se copia"
    # Orden: copia y migraciones antes del cambio; el cambio antes de los reinicios.
    local line_dump line_migrate line_restart
    line_dump=$(grep -n 'mariadb-dump' "$FAKE/calls.log" | cut -d: -f1)
    line_migrate=$(grep -n 'artisan migrate' "$FAKE/calls.log" | cut -d: -f1)
    line_restart=$(grep -n 'systemctl restart' "$FAKE/calls.log" | head -1 | cut -d: -f1)
    ((line_dump < line_migrate && line_migrate < line_restart)) && ok || bad "copia → migraciones → reinicio"
    assert_exists "$GROWTH_ROOT/releases/0.2.0/hub/.env" "enlace al .env"
    assert_eq "$(readlink "$GROWTH_ROOT/releases/0.2.0/hub/storage")" "$GROWTH_ROOT/shared/storage" "enlace a storage"
    assert_eq "$(readlink "$GROWTH_ROOT/releases/0.2.0/hub/public/storage")" "$GROWTH_ROOT/shared/storage/app/public" "enlace a public/storage"
    assert_contains "$(cat "$SYSTEMD_UNIT_DIR/growth-app.service")" "growth-app 0.2.0" "unidad instalada"
    # Limpieza: 3 versiones (la nueva, la anterior y la más nueva del resto), 5 copias, sin descargas.
    assert_eq "$(find "$GROWTH_ROOT/releases" -mindepth 1 -maxdepth 1 -printf '%f\n' | sort -V | tr '\n' ' ')" "0.0.3 0.1.0 0.2.0 " "quedan 3 versiones"
    assert_eq "$(find "$GROWTH_ROOT/shared/backups/pre-update" -type f | wc -l)" 5 "quedan 5 copias"
    assert_eq "$(find "$GROWTH_UPDATE_TMP" -mindepth 1 | wc -l)" 0 "sin descargas"
    assert_eq "$(wc -l <"$GROWTH_ROOT/shared/state/update-history.jsonl")" 1 "una línea de historial"
    assert_missing "$GROWTH_ROOT/shared/state/update-request.processing.json" "pedido en proceso borrado"
    # .env reparado sin pisar valores
    assert_contains "$(cat "$GROWTH_ROOT/shared/.env")" "REDIS_CACHE_PORT=6380" "variable nueva agregada"
    assert_contains "$(cat "$GROWTH_ROOT/shared/.env")" 'MAIL_FROM_NAME=Clínica <hola@clinica.test>' "valor existente intacto"
    assert_eq "$(stat -c %a "$GROWTH_ROOT/shared/.env")" 600 "modo del .env"
    teardown_world
}

test_health_fails_rollback() {
    setup_world "health-check falla y vuelve atrás"
    install_release 0.1.0
    make_release 0.2.0
    echo "0.2.0 8080" >"$FAKE/health-fail" # el panel nuevo no responde; las réplicas de ingesta sí
    local code
    code=$(run_update 0.2.0)
    assert_eq "$code" 1 "código de salida"
    assert_eq "$(current)" 0.1.0 "current volvió a la anterior"
    assert_eq "$(status .state)" rolled_back "estado"
    assert_eq "$(status '.steps[] | select(.key == "health") | .status')" failed "paso health"
    assert_eq "$(status '.steps[] | select(.key == "rollback") | .status')" "done" "paso rollback"
    assert_eq "$(restarts)" "$EXPECTED_RESTARTS $EXPECTED_RESTARTS" "reinició dos veces en el mismo orden"
    assert_contains "$(status .error)" "health-check" "mensaje"
    assert_contains "$(cat "$SYSTEMD_UNIT_DIR/growth-app.service")" "growth-app 0.1.0" "volvieron las unidades de la anterior"
    lib_is_original && ok || bad "después de volver atrás quedan los scripts de root anteriores"
    assert_no_root_exec
    assert_eq "$(wc -l <"$GROWTH_ROOT/shared/state/update-history.jsonl")" 1 "una línea de historial"
    teardown_world
}

test_ingest_replica_fails_rollback() {
    setup_world "una réplica de ingesta no responde y vuelve atrás"
    install_release 0.1.0
    make_release 0.2.0
    echo "0.2.0 8081" >"$FAKE/health-fail"
    local code
    code=$(run_update 0.2.0)
    assert_eq "$code" 1 "código de salida"
    assert_eq "$(current)" 0.1.0 "current volvió a la anterior"
    assert_eq "$(status .state)" rolled_back "estado"
    assert_eq "$(restarts | cut -d' ' -f1)" "growth-ingest-api@1.service" "se detuvo en la primera réplica"
    assert_not_contains "$(restarts | cut -d' ' -f1-2)" "growth-ingest-api@2.service" "no siguió con la segunda réplica"
    teardown_world
}

test_no_space() {
    setup_world "sin espacio no descarga"
    install_release 0.1.0
    make_release 0.2.0
    export FAKE_FREE_BYTES=1000
    local code
    code=$(run_update 0.2.0)
    assert_eq "$code" 1 "código de salida"
    assert_eq "$(current)" 0.1.0 "current intacto"
    assert_eq "$(status .state)" failed "estado"
    assert_contains "$(status .error)" "Espacio insuficiente" "mensaje"
    assert_not_contains "$(calls)" "download growth-0.2.0.zip" "no bajó el zip"
    assert_missing "$GROWTH_ROOT/releases/0.2.0" "sin carpeta nueva"
    assert_eq "$(find "$GROWTH_UPDATE_TMP" -mindepth 1 | wc -l)" 0 "sin restos en tmp"
    assert_eq "$(status '.steps[] | select(.key == "preflight") | .status')" failed "paso preflight"
    teardown_world

    setup_world "sin inodos no descarga"
    install_release 0.1.0
    make_release 0.2.0
    export FAKE_FREE_INODES=10
    code=$(run_update 0.2.0)
    assert_eq "$code" 1 "código de salida"
    assert_contains "$(status .error)" "Inodos insuficientes" "mensaje"
    assert_not_contains "$(calls)" "download growth-0.2.0.zip" "no bajó el zip"
    teardown_world
}

check_nothing_left() {
    assert_eq "$(current)" 0.1.0 "current intacto"
    assert_eq "$(status .state)" failed "estado"
    assert_missing "$GROWTH_ROOT/releases/0.2.0" "sin carpeta de la versión"
    assert_missing "$GROWTH_ROOT/releases/0.2.0.partial" "sin carpeta a medio extraer"
    assert_eq "$(find "$GROWTH_UPDATE_TMP" -mindepth 1 | wc -l)" 0 "sin zip ni restos en tmp"
    assert_not_contains "$(calls)" "systemctl restart" "no reinició nada"
    [[ ${1:-} == migrated ]] || assert_not_contains "$(calls)" "artisan migrate" "no migró"
    lib_is_original && ok || bad "los scripts de root no cambiaron"
    assert_no_root_exec
}

test_bad_packages() {
    setup_world "zip alterado"
    install_release 0.1.0
    make_release 0.2.0 corrupt-zip
    run_update 0.2.0 >/dev/null
    assert_contains "$(status .error)" "sha256" "mensaje"
    check_nothing_left
    teardown_world

    setup_world "firma de otra llave"
    install_release 0.1.0
    make_release 0.2.0 bad-sig
    run_update 0.2.0 >/dev/null
    assert_contains "$(status .error)" "firma" "mensaje"
    check_nothing_left
    teardown_world

    setup_world "zip roto bien firmado"
    install_release 0.1.0
    make_release 0.2.0 broken-zip
    run_update 0.2.0 >/dev/null
    assert_contains "$(status .error)" "dañado" "mensaje"
    check_nothing_left
    teardown_world

    setup_world "falla una migración"
    install_release 0.1.0
    make_release 0.2.0
    export FAKE_ARTISAN_FAIL="migrate --force"
    run_update 0.2.0 >/dev/null
    assert_eq "$(status '.steps[] | select(.key == "migrate") | .status')" failed "paso migrate"
    check_nothing_left migrated
    unset FAKE_ARTISAN_FAIL
    teardown_world

    setup_world "falla la copia de la base"
    install_release 0.1.0
    make_release 0.2.0
    export FAKE_DUMP_FAIL=1
    run_update 0.2.0 >/dev/null
    assert_contains "$(status .error)" "no se migra sin copia" "mensaje"
    check_nothing_left
    assert_eq "$(find "$GROWTH_ROOT/shared/backups/pre-update" -type f 2>/dev/null | wc -l)" 0 "sin copia a medias"
    unset FAKE_DUMP_FAIL
    teardown_world

    setup_world "falta APP_KEY en una actualización"
    install_release 0.1.0
    make_release 0.2.0
    sed -i '/^APP_KEY=/d' "$GROWTH_ROOT/shared/.env"
    run_update 0.2.0 >/dev/null
    assert_contains "$(status .error)" "APP_KEY" "mensaje"
    assert_not_contains "$(cat "$GROWTH_ROOT/shared/.env")" "APP_KEY=" "no inventó APP_KEY"
    check_nothing_left
    teardown_world
}

test_lock_held() {
    setup_world "candado tomado"
    install_release 0.1.0
    make_release 0.2.0
    echo '{"state":"running","id":"upd_otraOTRA1"}' >"$GROWTH_ROOT/shared/state/update-status.json"
    flock "$GROWTH_LOCK_FILE" sleep 5 &
    local holder=$! code
    sleep 0.3
    code=$(run_update 0.2.0)
    kill "$holder" 2>/dev/null
    wait "$holder" 2>/dev/null
    assert_eq "$code" 0 "sale sin error"
    assert_eq "$(current)" 0.1.0 "current intacto"
    assert_eq "$(status .id)" upd_otraOTRA1 "no tocó el estado de la otra"
    assert_not_contains "$(calls)" "curl" "no descargó nada"
    teardown_world
}

test_first_install() {
    setup_world "primera instalación"
    make_release 0.1.0
    export FAKE_DB_TABLES=0
    local code
    code=$(run_update --install 0.1.0)
    assert_eq "$code" 0 "código de salida"
    assert_eq "$(current)" 0.1.0 "current"
    assert_eq "$(status .state)" succeeded "estado"
    assert_eq "$(status '.steps[] | select(.key == "backup") | .status')" skipped "sin copia de una base vacía"
    assert_eq "$(restarts)" "growth.target" "arranca growth.target"
    assert_contains "$(cat "$GROWTH_ROOT/shared/.env")" "IGR_ML_TOKEN=" "secreto nuevo generado"
    assert_contains "$(cat "$GROWTH_LIB_DIR/bin/growth-update")" "TRAMPA bin/growth-update 0.1.0" "--install instala los scripts de root"
    assert_no_root_exec
    teardown_world

    setup_world "actualizar sin versión instalada"
    make_release 0.2.0
    local code2
    code2=$(run_update 0.2.0)
    assert_eq "$code2" 2 "pide --install"
    teardown_world
}

test_dispatch() {
    local DISPATCH="$BIN/growth-update-dispatch"
    setup_world "despacho válido"
    install_release 0.1.0
    echo '{"version":"0.2.0","id":"upd_A1b2C3d4","requested_by":"usr_01ABC","extra":"x"}' >"$GROWTH_ROOT/shared/state/update-request.json"
    "$DISPATCH" >/dev/null 2>&1
    assert_missing "$GROWTH_ROOT/shared/state/update-request.json" "pedido tomado"
    assert_eq "$(jq -r '.version + " " + .id + " " + (has("extra") | tostring)' "$GROWTH_ROOT/shared/state/update-request.processing.json")" "0.2.0 upd_A1b2C3d4 false" "pedido limpio en proceso"
    assert_eq "$(status .state)" queued "estado en cola"
    assert_eq "$(status .from)" 0.1.0 "desde"
    assert_contains "$(calls)" "systemd-run --unit=growth-update-A1b2C3d4 --collect --property=Type=exec" "unidad transitoria"
    assert_contains "$(calls)" "$GROWTH_LIB_DIR/bin/growth-update 0.2.0" "growth-update de /usr/local/lib/growth"
    assert_not_contains "$(calls)" "current/bin" "nunca uno de la versión"
    teardown_world

    local bad
    for bad in '{"version":"1.2.3; rm -rf /","id":"upd_A1b2C3d4","requested_by":"usr_1"}' \
        '{"version":"0.2.0","id":"../../etc/passwd","requested_by":"usr_1"}' \
        '{"version":"0.2.0","id":"upd_A1b2C3d4","requested_by":"usr_1 $(id)"}' \
        'no es json' '["0.2.0"]'; do
        setup_world "despacho rechaza: ${bad:0:30}"
        install_release 0.1.0
        printf '%s' "$bad" >"$GROWTH_ROOT/shared/state/update-request.json"
        "$DISPATCH" >/dev/null 2>&1
        assert_missing "$GROWTH_ROOT/shared/state/update-request.json" "pedido descartado"
        assert_missing "$GROWTH_ROOT/shared/state/update-request.processing.json" "nada en proceso"
        assert_eq "$(status .state)" failed "estado"
        assert_not_contains "$(calls)" "systemd-run" "no lanzó nada"
        teardown_world
    done

    setup_world "despacho rechaza un symlink"
    install_release 0.1.0
    echo secreto >"$WORLD/secreto"
    ln -s "$WORLD/secreto" "$GROWTH_ROOT/shared/state/update-request.json"
    "$DISPATCH" >/dev/null 2>&1
    assert_eq "$(status .state)" failed "estado"
    assert_eq "$(cat "$WORLD/secreto")" secreto "no tocó el destino del symlink"
    assert_not_contains "$(calls)" "systemd-run" "no lanzó nada"
    teardown_world

    setup_world "estado como symlink no se sigue"
    install_release 0.1.0
    echo intacto >"$WORLD/victima"
    ln -s "$WORLD/victima" "$GROWTH_ROOT/shared/state/update-status.json"
    echo '{"version":"0.2.0","id":"upd_A1b2C3d4","requested_by":"usr_01ABC"}' >"$GROWTH_ROOT/shared/state/update-request.json"
    "$DISPATCH" >/dev/null 2>&1
    assert_eq "$(cat "$WORLD/victima")" intacto "el archivo apuntado no cambió"
    assert_eq "$(status .state)" queued "estado escrito en su lugar"
    teardown_world
}

test_env_reader() {
    CURRENT_TEST="growth-env"
    local dir env
    dir=$(mktemp -d)
    env="$dir/.env"
    printf '%s\r\n' '# comentario' 'APP_NAME="Imagina Growth"' 'MAIL_FROM_NAME=Clínica <hola@clinica.test>' >"$env"
    cat >>"$env" <<'ENV'
export EXPORTADA=si
ESPACIOS = valor  # comentario al final
HASH_EN_COMILLAS="clave#con#hash"
HASH_SIN_ESPACIO=a#b
SIMPLES='literal ${APP_NAME} \n'
DOBLES="línea1\nlínea2 \"citada\" \$NO"
EXPANDIDA="${APP_NAME} panel"
VACIA=
VACIA_COMILLAS=""
VARIAS="primera
segunda"
REPETIDA=primera
REPETIDA=segunda
ENV
    get() { php "$BIN/growth-env" get "$1" "$env"; }
    assert_eq "$(get APP_NAME)" "Imagina Growth" "comillas dobles con CRLF"
    assert_eq "$(get MAIL_FROM_NAME)" "Clínica <hola@clinica.test>" "valor que rompe bash"
    assert_eq "$(get EXPORTADA)" "si" "export"
    assert_eq "$(get ESPACIOS)" "valor" "espacios y comentario"
    assert_eq "$(get HASH_EN_COMILLAS)" "clave#con#hash" "# entre comillas"
    assert_eq "$(get HASH_SIN_ESPACIO)" "a#b" "# sin espacio antes"
    assert_eq "$(get SIMPLES)" 'literal ${APP_NAME} \n' "comillas simples literales"
    assert_eq "$(get DOBLES)" $'línea1\nlínea2 "citada" $NO' "escapes en comillas dobles"
    assert_eq "$(get EXPANDIDA)" "Imagina Growth panel" "\${VAR}"
    assert_eq "$(get VACIA)" "" "vacía"
    assert_eq "$(get VACIA_COMILLAS)" "" "vacía entre comillas"
    assert_eq "$(get VARIAS)" $'primera\nsegunda' "varias líneas"
    assert_eq "$(get REPETIDA)" "primera" "la primera gana, como en Laravel"
    php "$BIN/growth-env" get NO_EXISTE "$env" >/dev/null
    assert_eq "$?" 1 "variable que no existe"
    php "$BIN/growth-env" check "$env" >/dev/null 2>&1
    assert_eq "$?" 4 "check avisa del valor con espacios sin comillas"
    assert_not_contains "$(php "$BIN/growth-env" list "$env")" "Clínica" "list no imprime valores"

    # repair: agrega, no pisa, conserva orden, comentarios y modo; secretos solo si faltan.
    chmod 0600 "$env"
    local before
    before=$(cat "$env")
    cat >"$dir/release.json" <<'JSON'
{"version":"0.3.0","env":[
 {"name":"APP_NAME","default":"Otro","secret":false},
 {"name":"NUEVA","default":"con espacios y #","secret":false},
 {"name":"TOKEN_NUEVO","default":null,"secret":true},
 {"name":"LLAVE_LARAVEL","default":null,"secret":true,"generate":"laravel_key"},
 {"name":"VAPID_NULA","default":null,"secret":true,"generate":null},
 {"name":"VAPID_NONE","default":null,"secret":true,"generate":"none"}
]}
JSON
    php "$BIN/growth-env" repair "$dir/release.json" "$env" >/dev/null
    local after
    after=$(cat "$env")
    assert_contains "$after" "$before" "las líneas existentes quedan igual y en su orden"
    assert_eq "$(get APP_NAME)" "Imagina Growth" "no pisó un valor"
    assert_eq "$(get NUEVA)" "con espacios y #" "valor nuevo bien citado"
    [[ $(get TOKEN_NUEVO) =~ ^[0-9a-f]{64}$ ]] && ok || bad "secreto de 32 bytes"
    [[ $(get LLAVE_LARAVEL) =~ ^base64: ]] && ok || bad "llave con formato de Laravel"
    assert_eq "$(get VAPID_NULA)" "" "generate null: queda vacía, no inventa una llave"
    assert_eq "$(get VAPID_NONE)" "" "generate none: queda vacía"
    assert_eq "$(stat -c %a "$env")" 600 "modo 0600"
    local token
    token=$(get TOKEN_NUEVO)
    php "$BIN/growth-env" repair "$dir/release.json" "$env" >/dev/null
    assert_eq "$(get TOKEN_NUEVO)" "$token" "un segundo repair no regenera"
    echo '{"version":"0.3.0","env":[{"name":"IGR_BLIND_INDEX_KEY","default":null,"secret":true}]}' >"$dir/r2.json"
    php "$BIN/growth-env" repair "$dir/r2.json" "$env" >/dev/null 2>&1
    assert_eq "$?" 3 "una llave que no se regenera detiene la actualización"
    php "$BIN/growth-env" repair "$dir/r2.json" "$env" --install >/dev/null 2>&1
    assert_eq "$?" 0 "en una instalación nueva sí se genera"
    rm -rf "$dir"
}

# La firma es opcional: sin llave pública basta el sha256; con llave, una versión sin .sig no se instala.
test_unsigned() {
    setup_world "sin llave pública y sin firma"
    install_release 0.1.0
    rm -f "$GROWTH_ETC/release-signing.pub.pem"
    make_release 0.2.0 unsigned
    local code
    code=$(run_update 0.2.0)
    assert_eq "$code" 0 "código de salida"
    assert_eq "$(current)" 0.2.0 "current apunta a la nueva"
    assert_eq "$(status .state)" succeeded "estado"
    assert_contains "$(status '.steps[] | select(.key == "verify") | .message')" "sin firma" "el paso verify dice que no hubo firma"
    assert_no_root_exec
    teardown_world

    setup_world "sin llave pública y zip alterado"
    install_release 0.1.0
    rm -f "$GROWTH_ETC/release-signing.pub.pem"
    make_release 0.2.0 corrupt-zip
    rm -f "$FAKE/releases/v0.2.0/growth-0.2.0.zip.sha256.sig"
    run_update 0.2.0 >/dev/null
    assert_contains "$(status .error)" "sha256" "el sha256 sigue protegiendo"
    check_nothing_left
    teardown_world

    setup_world "con llave pública y versión sin firma"
    install_release 0.1.0
    make_release 0.2.0 unsigned
    run_update 0.2.0 >/dev/null
    assert_contains "$(status .error)" "exige versiones firmadas" "mensaje"
    check_nothing_left
    teardown_world
}

# Perfil pequeño: updater.conf dice qué workers de ingesta existen y solo esos se reinician.
test_small_profile() {
    setup_world "perfil pequeño: un worker de ingesta"
    install_release 0.1.0
    printf 'GROWTH_INGEST_WORKERS=1\n' >>"$GROWTH_ETC/updater.conf"
    make_release 0.2.0
    local code
    code=$(GROWTH_INGEST_WORKERS="" run_update 0.2.0)
    assert_eq "$code" 0 "código de salida"
    assert_eq "$(current)" 0.2.0 "current apunta a la nueva"
    local c
    c=$(calls)
    assert_contains "$c" "systemctl restart growth-ingest@1.service" "reinicia el worker 1"
    assert_contains "$c" "systemctl stop growth-ml.service" "libera la memoria del ML mientras actualiza"
    assert_not_contains "$c" "growth-ingest@2.service" "no toca el worker 2 (enmascarado)"
    teardown_world
}

test_system_and_hardening() {
    setup_world "configuración del sistema, sin bajar de versión y archivos de estado de root"
    install_release 0.1.0
    make_release 0.2.0
    # Un symlink plantado donde va el historial no debe hacer que root escriba en otro archivo.
    echo "intacto" >"$WORLD/victima"
    ln -s "$WORLD/victima" "$GROWTH_ROOT/shared/state/update-history.jsonl"
    local code c
    code=$(run_update 0.2.0)
    assert_eq "$code" 0 "código de salida"
    c=$(calls)
    assert_contains "$c" "growth-system apply --update" "aplica la configuración del sistema de la versión"
    assert_contains "$(status '.steps[] | select(.key == "switch") | .message')" "Sistema al día (prueba 0.2.0)" "el panel ve el resultado"
    assert_eq "$(cat "$WORLD/victima")" "intacto" "el symlink del historial no se siguió"
    [[ -f $GROWTH_ROOT/shared/state/update-history.jsonl && ! -L $GROWTH_ROOT/shared/state/update-history.jsonl ]] && ok || bad "el historial quedó como archivo normal"
    assert_eq "$(wc -l <"$GROWTH_ROOT/shared/state/update-history.jsonl")" 1 "una línea de historial"
    assert_not_contains "$c" "chown growth:growth $GROWTH_ROOT/shared/state" "los archivos de estado quedan de root"
    assert_eq "$(find "$GROWTH_UPDATE_TMP" -mindepth 1 | wc -l)" 0 "sin restos en la carpeta temporal de root"
    assert_missing "$GROWTH_ROOT/shared/tmp/update-0.2.0" "nada en shared/tmp"

    teardown_world

    # Volver a una versión anterior por este camino no se permite (después de una actualización, los scripts de
    # root son los de la versión nueva —en este arnés, trampas—: mundo nuevo).
    setup_world "no baja de versión"
    install_release 0.2.0
    make_release 0.1.5
    code=$(run_update 0.1.5)
    assert_eq "$code" 1 "no baja de versión"
    assert_contains "$(status .error)" "anterior a la instalada" "explica por qué"
    assert_eq "$(current)" 0.2.0 "sigue la instalada"
    teardown_world
}

for t in test_system_and_hardening test_update_ok test_small_profile test_unsigned test_health_fails_rollback test_ingest_replica_fails_rollback test_no_space test_bad_packages \
    test_lock_held test_first_install test_dispatch test_env_reader; do
    "$t"
done

printf '\nActualizador: %d comprobaciones bien, %d mal.\n' "$PASS" "$FAILS"
((FAILS == 0))
