#!/usr/bin/env bash
# Pruebas de bin/growth-system con un sistema falso (carpetas temporales y systemctl, mariadb y redis-cli que
# anotan lo que se les pide). docs/despliegue.md §7.
# shellcheck disable=SC2015,SC2016  # «A && ok || bad» son las aserciones; los falsos se escriben con $ literal
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
    is-active) [[ ${args[1]} == --quiet ]] && u=${args[2]} || u=${args[1]}; grep -qx "$u" "$FAKE/active" 2>/dev/null; exit $? ;;
    is-enabled)
        if grep -qx "${args[1]}" "$FAKE/masked" 2>/dev/null; then echo masked
        elif grep -qx "${args[1]}" "$FAKE/enabled" 2>/dev/null; then echo enabled
        else echo disabled; fi ;;
    enable) for u in "${args[@]:1}"; do [[ $u == --* ]] || echo "$u" >>"$FAKE/enabled"; done ;;
    mask) echo "${args[1]}" >>"$FAKE/masked" ;;
    unmask) sed -i "\#^${args[1]}\$#d" "$FAKE/masked" ;;
    show) u=${args[-1]}; cat "$FAKE/pid-$u" 2>/dev/null || echo 0 ;;
    reload) [[ ! -e $FAKE/reload-fail ]] || exit 1 ;;
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
    # Caddy: sin binario (las pruebas de Caddy lo activan con CADDY=$FAKE/caddy).
    export GROWTH_CADDYFILE="$W/etc/caddy/Caddyfile" CADDY="$W/fake/sin-caddy" CURL="$W/fake/curl" GROWTH_CF_IPS_URL=https://cf.test
    export GROWTH_LOCK_FILE="$W/run/growth-update.lock"
    GROWTH_USER=$(id -un)
    GROWTH_CH_GROUP=$(id -gn)
    GROWTH_REDIS_GROUP=$GROWTH_CH_GROUP
    export PHP=${GROWTH_TEST_PHP:-php} GROWTH_USER GROWTH_CH_GROUP GROWTH_REDIS_GROUP
    # Scripts de root y carpeta del despachador de copias, dentro del mundo falso (sin backup/: no se instala nada).
    export GROWTH_LIB_DIR="$W/usr/local/lib/growth" GROWTH_BACKUP_WORK="$W/var/lib/growth-backup"
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

# 7. Pedidos de actualización huérfanos: se limpian en cada arranque aunque el sello esté al día, nunca durante una
#    actualización, y el vigilante de pedidos se reactiva si se cayó.
world "pedido huérfano"
ST=$GROWTH_ROOT/shared/state
eq "$(GROWTH_MEMORY_MB=1638 run apply --update)" 0 "primera aplicación"
printf '{"version":"0.1.9","id":"upd_viejo","accepted_at":"2026-11-20T13:32:00Z"}\n' >"$ST/update-request.processing.json"
printf '{"id":"upd_viejo","state":"succeeded"}\n' >"$ST/update-status.json"
: >"$W/etc/systemd/system/growth-update.path"
: >"$FAKE/calls.log"
eq "$(GROWTH_MEMORY_MB=1638 run apply --hook)" 0 "hook con el sello al día"
[[ ! -e $ST/update-request.processing.json ]] && ok || bad "borra el pedido de una actualización ya terminada"
has "$FAKE/calls.log" "systemctl reset-failed growth-update.path" "reactiva el vigilante caído"
has "$FAKE/calls.log" "enable --now growth-update.path" "y lo deja activo"

printf '{"version":"0.2.0","id":"upd_nuevo","accepted_at":"2026-11-20T14:00:00Z"}\n' >"$ST/update-request.processing.json"
printf '{"id":"upd_nuevo","state":"running"}\n' >"$ST/update-status.json"
echo growth-update.path >>"$FAKE/active"
: >"$FAKE/calls.log"
GROWTH_MEMORY_MB=1638 run apply --hook >/dev/null
[[ -e $ST/update-request.processing.json ]] && ok || bad "una actualización corriendo no se toca"
hasnt "$FAKE/calls.log" "reset-failed growth-update.path" "vigilante activo: no se toca"

printf '{"id":"upd_nuevo","state":"succeeded"}\n' >"$ST/update-status.json"
mkdir -p "$(dirname "$GROWTH_LOCK_FILE")"
exec 8>"$GROWTH_LOCK_FILE"; flock 8
GROWTH_MEMORY_MB=1638 run apply --hook >/dev/null
[[ -e $ST/update-request.processing.json ]] && ok || bad "con el candado tomado (growth-update corriendo) no se toca"
flock -u 8; exec 8>&-
GROWTH_MEMORY_MB=1638 run apply --hook >/dev/null
[[ ! -e $ST/update-request.processing.json ]] && ok || bad "sin candado y terminada: se borra"
rm -rf "$W"

# 6. Caddy: el Caddyfile sin editar se pone al día; el editado a mano no se toca.
caddy_world() {
    world "$1"
    mkdir -p "$W/etc/caddy"
    printf 'APP_URL=https://growth.ejemplo.com\n' >>"$GROWTH_ROOT/shared/.env"
    cat >"$W/fake/caddy" <<'EOF'
#!/usr/bin/env bash
echo "caddy $*" >>"$FAKE/calls.log"
[[ $1 == validate && -e $FAKE/caddy-invalid ]] && exit 1
exit 0
EOF
    cat >"$W/fake/curl" <<'EOF'
#!/usr/bin/env bash
[[ -e $FAKE/curl-down ]] && exit 7
case "${*: -1}" in
    */ips-v4) printf '173.245.48.0/20\n103.21.244.0/22\n103.22.200.0/22\n103.31.4.0/22\n141.101.64.0/18\n108.162.192.0/18\n' ;;
    */ips-v6) printf '2400:cb00::/32\n2606:4700::/32\n2803:f800::/32\n2405:b500::/32\n' ;;
esac
EOF
    chmod 0755 "$W/fake/caddy" "$W/fake/curl"
    export CADDY="$W/fake/caddy"
    echo caddy >>"$FAKE/active"
}
render_old() { # render_old <plantilla> <correo>
    sed -e "s/__DOMAIN__/growth.ejemplo.com/g" -e "s/__EMAIL_LINE__/${2:+email $2}/" "$1" >"$GROWTH_CADDYFILE"
}

caddy_world "Caddyfile de la 0.1.4 sin editar"
render_old "$REPO/infra/caddy/history/0.1.4.Caddyfile" ops@ejemplo.com
cp "$GROWTH_CADDYFILE" "$W/caddy-antes"
eq "$(GROWTH_MEMORY_MB=1638 run apply --update)" 0 "código de salida"
has "$GROWTH_CADDYFILE" "trusted_proxies static 173.245.48.0/20 103.21.244.0/22" "rangos de Cloudflare descargados"
has "$GROWTH_CADDYFILE" "2405:b500::/32" "también los de IPv6"
has "$GROWTH_CADDYFILE" "client_ip_headers CF-Connecting-IP X-Forwarded-For" "IP real desde Cloudflare"
has "$GROWTH_CADDYFILE" "header_up X-Forwarded-For {client_ip}" "la app recibe solo la IP resuelta"
has "$GROWTH_CADDYFILE" "email ops@ejemplo.com" "conserva el correo de Let's Encrypt"
has "$GROWTH_CADDYFILE" "growth.ejemplo.com {" "dominio de APP_URL"
has "$GROWTH_CADDYFILE" "resp_headers>Set-Cookie delete" "registros sin cookies"
hasnt "$GROWTH_CADDYFILE" "__" "sin marcadores sin reemplazar"
has "$FAKE/calls.log" "caddy validate --adapter caddyfile" "valida antes de instalar"
has "$FAKE/calls.log" "systemctl reload caddy" "recarga Caddy"
eq "$(cat "$GROWTH_CADDYFILE.growth.bak")" "$(cat "$W/caddy-antes")" "copia del anterior"
[[ -s $GROWTH_SYSTEM_STATE/caddyfile.sha256 ]] && ok || bad "huella del Caddyfile escrito"
has "$GROWTH_SYSTEM_STATE/cloudflare-ips" "2606:4700::/32" "guarda la lista vigente"
has "$W/out.log" "Caddy: configuración actualizada" "resumen"

: >"$FAKE/calls.log"
eq "$(GROWTH_MEMORY_MB=1638 run apply --update)" 0 "segunda corrida"
hasnt "$FAKE/calls.log" "reload caddy" "al día: no recarga"

# Sin internet: la última lista guardada.
touch "$FAKE/curl-down"
eq "$(GROWTH_MEMORY_MB=1638 run apply --update)" 0 "sin internet"
has "$GROWTH_CADDYFILE" "103.21.244.0/22" "sigue con la lista guardada"

# Editado a mano: no se toca, la versión nueva queda al lado y se avisa una vez.
rm -f "$FAKE/curl-down"
printf 'extra.ejemplo.com {\n\trespond "hola"\n}\n' >>"$GROWTH_CADDYFILE"
cp "$GROWTH_CADDYFILE" "$W/editado"
: >"$FAKE/calls.log"
eq "$(GROWTH_MEMORY_MB=1638 run apply --update)" 0 "editado a mano"
eq "$(cat "$GROWTH_CADDYFILE")" "$(cat "$W/editado")" "el editado no se toca"
has "$GROWTH_CADDYFILE.growth.new" "client_ip_headers" "la versión nueva al lado"
has "$W/out.log" "editado a mano" "avisa"
hasnt "$FAKE/calls.log" "reload caddy" "no recarga"
GROWTH_MEMORY_MB=1638 run apply --update >/dev/null
hasnt "$W/out.log" "editado a mano" "no repite el aviso si la versión nueva no cambió"
rm -rf "$W"

caddy_world "Caddyfile de la 0.1.0 con in. y go."
render_old "$REPO/infra/caddy/history/0.1.0.Caddyfile" ""
touch "$FAKE/curl-down"
eq "$(GROWTH_MEMORY_MB=1638 run apply --update)" 0 "código de salida"
has "$GROWTH_CADDYFILE" "growth.ejemplo.com, go.growth.ejemplo.com, in.growth.ejemplo.com {" "los subdominios de antes siguen atendidos"
hasnt "$GROWTH_CADDYFILE" "email " "sin correo si no había"
has "$GROWTH_CADDYFILE" "2c0f:f248::/32" "sin internet ni lista guardada: la del paquete"
rm -rf "$W"

caddy_world "Caddy rechaza la configuración nueva"
render_old "$REPO/infra/caddy/history/0.1.4.Caddyfile" ""
cp "$GROWTH_CADDYFILE" "$W/caddy-antes"
touch "$FAKE/caddy-invalid"
eq "$(GROWTH_MEMORY_MB=1638 run apply --update)" 0 "no falla la actualización"
eq "$(cat "$GROWTH_CADDYFILE")" "$(cat "$W/caddy-antes")" "inválida: se deja la actual"
has "$W/out.log" "no es válida" "avisa"
rm -f "$FAKE/caddy-invalid"
touch "$FAKE/reload-fail"
eq "$(GROWTH_MEMORY_MB=1638 run apply --update)" 0 "la recarga falla"
eq "$(cat "$GROWTH_CADDYFILE")" "$(cat "$W/caddy-antes")" "vuelve a la anterior"
has "$W/out.log" "se volvió a la anterior" "avisa"
[[ ! -e $GROWTH_SYSTEM_STATE/caddyfile.sha256 ]] && ok || bad "sin huella si no quedó"
rm -rf "$W"

caddy_world "Caddyfile de ejemplo del paquete de Caddy"
printf ':80 {\n\troot * /usr/share/caddy\n\tfile_server\n}\n' >"$GROWTH_CADDYFILE"
cp "$GROWTH_CADDYFILE" "$W/caddy-antes"
eq "$(GROWTH_MEMORY_MB=1638 run apply --install)" 0 "código de salida"
eq "$(cat "$GROWTH_CADDYFILE")" "$(cat "$W/caddy-antes")" "lo reemplaza install.sh, no growth-system"
[[ ! -e $GROWTH_CADDYFILE.growth.new ]] && ok || bad "sin versión nueva al lado ni aviso"
rm -rf "$W"

# 7. Copias desde el panel: un servidor de una versión anterior (sin despachador) lo recibe con la actualización.
world "despachador de copias en una actualización"
mkdir -p "$GROWTH_LIB_DIR/backup"
echo "# librería vieja" >"$GROWTH_LIB_DIR/backup/growth-backup-lib.sh"
cp "$REPO/infra/systemd/growth-backup.path" "$REPO/infra/systemd/growth-backup-dispatch.service" "$SYSTEMD_UNIT_DIR/"
eq "$(GROWTH_MEMORY_MB=1638 run apply --update)" 0 "código de salida"
cmp -s "$GROWTH_LIB_DIR/backup/growth-backup-dispatch" "$REPO/infra/backup/growth-backup-dispatch" && ok || bad "instala el despachador de la versión"
cmp -s "$GROWTH_LIB_DIR/backup/growth-backup-lib.sh" "$REPO/infra/backup/growth-backup-lib.sh" && ok || bad "con su librería"
eq "$(stat -c %a "$GROWTH_LIB_DIR/backup/growth-backup-dispatch")" 755 "permisos del despachador"
has "$FAKE/calls.log" "systemctl enable growth-backup.path" "habilita la unidad .path"
has "$FAKE/calls.log" "systemctl start growth-backup.path" "y la arranca"
has "$W/out.log" "el panel ya puede configurarlas" "resumen"
[[ -f $GROWTH_ROOT/shared/state/backup-status.json ]] && ok || bad "publica el primer estado para el panel"
eq "$(jq -r '.configured' "$GROWTH_ROOT/shared/state/backup-status.json" 2>/dev/null)" false "sin destino todavía"
# Ya habilitada y con el despachador: no se reinstala ni se vuelve a habilitar; uno instalado nunca se reemplaza.
echo "# despachador de otra versión sana" >"$GROWTH_LIB_DIR/backup/growth-backup-dispatch"
chmod 0755 "$GROWTH_LIB_DIR/backup/growth-backup-dispatch"
: >"$FAKE/calls.log"
rm -f "$GROWTH_SYSTEM_STATE/applied"
eq "$(GROWTH_MEMORY_MB=1638 run apply --update)" 0 "segunda corrida"
has "$GROWTH_LIB_DIR/backup/growth-backup-dispatch" "otra versión sana" "no reemplaza el instalado"
hasnt "$FAKE/calls.log" "enable growth-backup.path" "no vuelve a habilitar"
# Desde el hook (actualizador anterior): sin bloquear a systemd.
sed -i '/growth-backup.path/d' "$FAKE/enabled"
rm -f "$GROWTH_SYSTEM_STATE/applied"
: >"$FAKE/calls.log"
eq "$(GROWTH_MEMORY_MB=1638 run apply --hook)" 0 "hook"
has "$FAKE/calls.log" "systemctl --no-block start growth-backup.path" "arranca sin esperar"
rm -rf "$W"

world "sin scripts de root (instalación a medias): no instala nada"
cp "$REPO/infra/systemd/growth-backup.path" "$SYSTEMD_UNIT_DIR/"
eq "$(GROWTH_MEMORY_MB=1638 run apply --update)" 0 "código de salida"
[[ ! -e $GROWTH_LIB_DIR/backup/growth-backup-dispatch ]] && ok || bad "no crea /usr/local/lib/growth/backup"
hasnt "$FAKE/calls.log" "enable growth-backup.path" "no habilita una unidad sin despachador"
rm -rf "$W"

# Plantilla: los marcadores y las anteriores guardadas para reconocer los Caddyfile sin editar.
T="plantilla"
for m in __DOMAIN__ __EMAIL_LINE__ __TRUSTED_PROXIES__; do has "$REPO/infra/caddy/Caddyfile" "$m" "marcador $m"; done
[[ $(find "$REPO/infra/caddy/history" -name '*.Caddyfile' | wc -l) -ge 2 ]] && ok || bad "plantillas anteriores en caddy/history"

printf '\ngrowth-system: %d comprobaciones bien, %d mal.\n' "$PASS" "$FAILS"
((FAILS == 0))
