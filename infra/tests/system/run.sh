#!/usr/bin/env bash
# Pruebas de bin/growth-system con un sistema falso (carpetas temporales y systemctl, mariadb y redis-cli que
# anotan lo que se les pide). docs/despliegue.md §7.
# shellcheck disable=SC2015  # «A && ok || bad» es la forma de las aserciones
set -uo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../../.." && pwd)
PASS=0 FAILS=0 T=""
ok() { PASS=$((PASS + 1)); }
bad() { FAILS=$((FAILS + 1)); printf '  ✗ [%s] %s\n' "$T" "$*"; }
has() { grep -qF -- "$2" "$1" 2>/dev/null && ok || bad "${3:-}: «$2» no está en $(basename "$1")"; }
hasnt() { grep -qF -- "$2" "$1" 2>/dev/null && bad "${3:-}: «$2» no debía estar" || ok; }
eq() { [[ $1 == "$2" ]] && ok || bad "${3:-}: esperaba «$2», obtuve «$1»"; }

world() {
    T=$1
    W=$(mktemp -d)
    mkdir -p "$W/fake" "$W/etc/systemd/system" "$W/etc/mysql/mariadb.conf.d" "$W/etc/clickhouse-server/config.d" "$W/etc/redis" \
        "$W/etc/growth" "$W/etc/clickhouse-server/users.d" "$W/opt/growth/shared/state" "$W/proc/101" "$W/proc/102" "$W/proc/103"
    for p in 101 102 103; do echo 0 >"$W/proc/$p/oom_score_adj"; done
    printf 'GROWTH_UPDATE_REPO=acme/growth\nGROWTH_HEALTH_TIMEOUT=120\n' >"$W/etc/growth/updater.conf"
    printf 'APP_KEY=base64:x\nREDIS_PASSWORD=secreto-redis\nCLICKHOUSE_PASSWORD=clave-ch\nIGR_WORKERS_PROFILE=standard\n' >"$W/opt/growth/shared/.env"
    # systemctl falso: anota todo; is-active según $W/fake/active; MainPID según $W/fake/pid-<unidad>.
    cat >"$W/fake/systemctl" <<'EOF'
#!/usr/bin/env bash
echo "systemctl $*" >>"$FAKE/calls.log"
args=("$@"); [[ ${args[0]} == --no-block ]] && args=("${args[@]:1}")
case "${args[0]}" in
    is-active) [[ ${args[1]} == --quiet ]] && u=${args[2]} || u=${args[1]}; grep -qx "$u" "$FAKE/active" 2>/dev/null ;;
    is-enabled) grep -qx "${args[1]}" "$FAKE/masked" 2>/dev/null && echo masked || echo enabled ;;
    mask) echo "${args[1]}" >>"$FAKE/masked" ;;
    unmask) sed -i "\#^${args[1]}\$#d" "$FAKE/masked" ;;
    show) u=${args[-1]}; cat "$FAKE/pid-$u" 2>/dev/null || echo 0 ;;
esac
exit 0
EOF
    printf '#!/usr/bin/env bash\necho "mariadb $*" >>"$FAKE/calls.log"\n' >"$W/fake/mariadb"
    printf '#!/usr/bin/env bash\necho "redis-cli auth=${REDISCLI_AUTH:+si} $*" >>"$FAKE/calls.log"\n' >"$W/fake/redis-cli"
    printf '#!/usr/bin/env bash\necho "runuser $*" >>"$FAKE/calls.log"\n' >"$W/fake/runuser"
    chmod 0755 "$W/fake/"*
    printf 'mariadb\nmariadb.service\nclickhouse-server\nclickhouse-server.service\nredis-server@queue.service\nredis-server@cache.service\n' >"$W/fake/active"
    echo 101 >"$W/fake/pid-mariadb.service"
    echo 102 >"$W/fake/pid-clickhouse-server.service"
    echo 103 >"$W/fake/pid-redis-server@queue.service"
    : >"$W/fake/calls.log"
    export FAKE="$W/fake" GROWTH_ROOT="$W/opt/growth" GROWTH_ETC="$W/etc/growth" SYSTEMD_UNIT_DIR="$W/etc/systemd/system"
    export GROWTH_MARIADB_CONF_DIR="$W/etc/mysql/mariadb.conf.d" GROWTH_CH_CONF_DIR="$W/etc/clickhouse-server/config.d"
    export GROWTH_REDIS_CONF_DIR="$W/etc/redis" GROWTH_SYSTEM_STATE="$W/var/lib/growth-system" GROWTH_PROC="$W/proc"
    export SYSTEMCTL="$W/fake/systemctl" MARIADB="$W/fake/mariadb" REDIS_CLI="$W/fake/redis-cli" RUNUSER="$W/fake/runuser"
    # ClickHouse «responde» salvo que exista $FAKE/ch-down.
    printf '#!/usr/bin/env bash\n[[ ! -e "$FAKE/ch-down" ]]\n' >"$W/fake/ch-ping"
    chmod 0755 "$W/fake/ch-ping"
    export GROWTH_CH_PING="$W/fake/ch-ping" GROWTH_CH_WAIT=1
    GROWTH_USER=$(id -un)
    GROWTH_CH_GROUP=$(id -gn)
    GROWTH_REDIS_GROUP=$GROWTH_CH_GROUP
    export PHP=${GROWTH_TEST_PHP:-php} GROWTH_USER GROWTH_CH_GROUP GROWTH_REDIS_GROUP
    unset GROWTH_SYSTEM_FORCE
}
run() { "$REPO/infra/bin/growth-system" "$@" >"$W/out.log" 2>&1; echo $?; }

# 1. Servidor pequeño en una actualización: todo se escribe y se aplica en caliente.
world "perfil pequeño con --update"
eq "$(GROWTH_MEMORY_MB=1638 run apply --update)" 0 "código de salida"
has "$GROWTH_ROOT/shared/.env" "IGR_WORKERS_PROFILE=small" ".env"
has "$GROWTH_ETC/updater.conf" 'GROWTH_INGEST_WORKERS="1"' "workers del actualizador"
has "$SYSTEMD_UNIT_DIR/growth-ingest@1.service.d/growth-profile.conf" "igr:ingest:work" "drop-in del worker único"
has "$FAKE/calls.log" "systemctl mask growth-ingest@4.service" "enmascara los workers 2 a 4"
has "$GROWTH_MARIADB_CONF_DIR/90-growth.cnf" "innodb-buffer-pool-size = 204M" "MariaDB"
has "$GROWTH_MARIADB_CONF_DIR/90-growth.cnf" "204M" "buffer pool 1/8"
has "$FAKE/calls.log" "SET GLOBAL innodb_buffer_pool_size = 213909504" "buffer pool en caliente"
has "$GROWTH_CH_CONF_DIR/growth-memory.xml" "<max_server_memory_usage>429391872</max_server_memory_usage>" "ClickHouse 25 %"
has "$GROWTH_CH_CONF_DIR/growth-memory.xml" "<uncompressed_cache_size>0</uncompressed_cache_size>" "cachés mínimas en el perfil pequeño"
hasnt "$GROWTH_CH_CONF_DIR/growth-memory.xml" "background_pool_size" "nunca toca los pools de fondo (ClickHouse no arrancaría)"
has "$GROWTH_CH_CONF_DIR/igr.xml" "clickhouse" "configuración de ClickHouse de la versión"
has "$W/etc/clickhouse-server/users.d/growth-users.xml" "$(printf '%s' clave-ch | sha256sum | cut -d' ' -f1)" "usuario igr con el sha256 del .env"
hasnt "$W/etc/clickhouse-server/users.d/growth-users.xml" "access_management" "igr sin administración de usuarios"
hasnt "$W/etc/clickhouse-server/users.d/growth-users.xml" "clave-ch" "la contraseña nunca en claro"
[[ -s $GROWTH_ETC/clickhouse-default.pass ]] && ok || bad "contraseña aleatoria de default"
has "$FAKE/calls.log" "systemctl restart clickhouse-server.service" "reinicia ClickHouse y espera"
has "$GROWTH_REDIS_CONF_DIR/redis-queue.conf" "maxmemory 327mb" "Redis de colas con tope"
hasnt "$GROWTH_REDIS_CONF_DIR/redis-queue.conf" "__REDIS_PASSWORD__" "contraseña reemplazada"
has "$FAKE/calls.log" "redis-cli auth=si -p 6379 CONFIG SET maxmemory 327mb" "tope en caliente, con la contraseña por entorno"
hasnt "$FAKE/calls.log" "secreto-redis" "la contraseña nunca en la línea de comandos"
has "$SYSTEMD_UNIT_DIR/mariadb.service.d/growth-oom.conf" "OOMScoreAdjust=-300" "prioridad de MariaDB"
eq "$(cat "$W/proc/101/oom_score_adj")" "-300" "prioridad de MariaDB en caliente"
eq "$(cat "$W/proc/102/oom_score_adj")" "200" "prioridad de ClickHouse en caliente"
eq "$(cat "$W/proc/103/oom_score_adj")" "-400" "prioridad de Redis en caliente"
has "$FAKE/calls.log" "systemctl daemon-reload" "recarga systemd"
eq "$(stat -c %a "$GROWTH_ROOT/shared/state")" 1775 "shared/state sticky"
has "$W/out.log" "Perfil small (1638 MB)" "resumen"
[[ -s $GROWTH_SYSTEM_STATE/applied ]] && ok || bad "sello escrito"

# 2. La misma versión y RAM desde el hook: no hace nada.
: >"$FAKE/calls.log"
eq "$(GROWTH_MEMORY_MB=1638 run apply --hook)" 0 "código de salida del hook"
eq "$(wc -l <"$FAKE/calls.log")" 0 "el hook con sello no llama a nada"

# 3. Sin cambios con --update: al día y sin reinicios.
eq "$(GROWTH_MEMORY_MB=1638 run apply --update)" 0 "segunda corrida"
has "$W/out.log" "Sistema al día" "sin cambios"
hasnt "$FAKE/calls.log" "restart" "sin reinicios"

# 4. El servidor creció (8 GB) y lo detecta el hook: perfil estándar, todo sin bloquear a systemd.
: >"$FAKE/calls.log"
echo growth.target >>"$FAKE/active"
eq "$(GROWTH_MEMORY_MB=8000 run apply --hook)" 0 "código de salida"
has "$GROWTH_ROOT/shared/.env" "IGR_WORKERS_PROFILE=standard" "perfil estándar"
[[ ! -e $SYSTEMD_UNIT_DIR/growth-ingest@1.service.d/growth-profile.conf ]] && ok || bad "quita el drop-in del worker único"
has "$FAKE/calls.log" "systemctl unmask growth-ingest@2.service" "desenmascara los workers"
has "$FAKE/calls.log" "systemctl --no-block start growth-ingest@2.service" "los arranca sin bloquear"
has "$FAKE/calls.log" "systemctl restart clickhouse-server.service" "ClickHouse se reinicia esperando que vuelva (también en el hook)"
has "$FAKE/calls.log" "systemctl --no-block restart growth-horizon.service" "Horizon con el perfil nuevo"
hasnt "$FAKE/calls.log" "restart growth-app" "nunca reinicia growth-app desde su propio arranque"
hasnt "$GROWTH_CH_CONF_DIR/growth-memory.xml" "uncompressed_cache_size" "sin recortes en el perfil estándar"
has "$GROWTH_ETC/updater.conf" 'GROWTH_INGEST_WORKERS="1 2 3 4"' "cuatro workers"
eq "$(grep -c '^GROWTH_INGEST_WORKERS=' "$GROWTH_ETC/updater.conf")" 1 "una sola línea de workers"

# 4b. ClickHouse no arranca con la configuración nueva: se restaura la anterior y no se sella.
cp "$GROWTH_CH_CONF_DIR/growth-memory.xml" "$W/memoria-buena.xml"
rm -f "$GROWTH_SYSTEM_STATE/applied"
touch "$FAKE/ch-down"
: >"$FAKE/calls.log"
eq "$(GROWTH_MEMORY_MB=1638 run apply --update)" 0 "nunca falla la actualización"
has "$W/out.log" "se restaura la anterior" "avisa"
eq "$(cat "$GROWTH_CH_CONF_DIR/growth-memory.xml")" "$(cat "$W/memoria-buena.xml")" "vuelve la configuración que funcionaba"
eq "$(grep -c 'systemctl restart clickhouse-server.service' "$FAKE/calls.log")" 2 "reinicia otra vez con la anterior"
[[ ! -e $GROWTH_SYSTEM_STATE/applied ]] && ok || bad "sin sello: lo reintenta la próxima vez"
rm -f "$FAKE/ch-down"

# 5. RAM: la fijada en updater.conf manda; si no, el límite del cgroup (VPS que son contenedores) recorta meminfo.
printf 'MemTotal:       16473600 kB\n' >"$W/proc/meminfo"
mkdir -p "$W/proc/1" "$W/cgroup/lxc/vps1/init.scope"
printf '0::/lxc/vps1/init.scope\n' >"$W/proc/1/cgroup"
echo max >"$W/cgroup/lxc/vps1/init.scope/memory.max"
echo 1717567488 >"$W/cgroup/lxc/vps1/memory.max"
GROWTH_CGROUP_ROOT="$W/cgroup" run status >/dev/null
has "$W/out.log" "Perfil: small (1638 MB de RAM)" "límite del cgroup"
echo max >"$W/cgroup/lxc/vps1/memory.max"
GROWTH_CGROUP_ROOT="$W/cgroup" run status >/dev/null
has "$W/out.log" "Perfil: standard (16087 MB de RAM)" "sin límite, la RAM de meminfo"
printf 'GROWTH_MEMORY_MB=2048\n' >>"$GROWTH_ETC/updater.conf"
GROWTH_CGROUP_ROOT="$W/cgroup" run status >/dev/null
has "$W/out.log" "Perfil: small (2048 MB de RAM)" "la fijada en updater.conf"

# 5. status no cambia nada.
: >"$FAKE/calls.log"
before=$(cat "$GROWTH_ROOT/shared/.env")
eq "$(GROWTH_MEMORY_MB=1638 run status)" 0 "código de status"
eq "$(cat "$GROWTH_ROOT/shared/.env")" "$before" "status no toca el .env"
hasnt "$FAKE/calls.log" "mask" "status no enmascara"
rm -rf "$W"

printf '\ngrowth-system: %d comprobaciones bien, %d mal.\n' "$PASS" "$FAILS"
((FAILS == 0))
