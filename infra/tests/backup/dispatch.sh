#!/usr/bin/env bash
# Pruebas de growth-backup-dispatch (Plataforma → Copias de seguridad, docs/despliegue.md §6) con rclone,
# ssh-keygen, ssh-keyscan y systemctl falsos: pedidos válidos e inválidos, permisos 0600, la llave generada una
# sola vez, el estado sin secretos y los symlinks que no se siguen. Sin root, sin red y sin Storage Box:
#   bash infra/tests/backup/dispatch.sh        (también lo corre infra/tests/backup/run.sh)
# shellcheck disable=SC2016,SC2015,SC2034  # las comprobaciones van entre comillas simples (se evalúan después); «A && ok || bad» es la forma
# shellcheck source-path=SCRIPTDIR
set -uo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
DISPATCH=$(cd "$HERE/../../backup" && pwd)/growth-backup-dispatch

PASS=0
FAIL=0
ok() { PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); printf '  \033[31m✗\033[0m %s\n' "$1"; }
check() { if eval "$2"; then ok "$1"; else bad "$1 ($2)"; fi; }
called() { grep -qF -- "$1" "$CALLS"; }
st() { jq -r "$1" "$STATE/backup-status.json" 2>/dev/null; }
mode() { stat -c %a "$1" 2>/dev/null; }

ENC='frase de cifrado larga y secreta'
PW='Clave#del$Storage"Box'
HB_M='https://uptime.betterstack.com/api/v1/heartbeat/HBmariadb12345'
HB_C='https://uptime.betterstack.com/api/v1/heartbeat/HBclickhouse678'

make_fakes() {
    case ${FAKEBIN:-} in /tmp/growth-dispatch-test.?*/bin) ;; *) echo "FAKEBIN inválido" >&2; exit 2 ;; esac
    mkdir -p "$FAKEBIN"
    # rclone: anota cada llamada (nunca la entrada), el modo de su configuración, y simula el Storage Box en
    # $FAKE_REMOTE (growth-backup:… y growth-install:…). FAKE_RCLONE_ERR hace fallar la conexión con ese mensaje.
    cat >"$FAKEBIN/rclone" <<'SH'
#!/usr/bin/env bash
cfg=""; args=()
while [ $# -gt 0 ]; do
  case $1 in
    --config) cfg=$2; shift ;;
    --contimeout|--timeout|--retries|--low-level-retries|--max-depth) shift ;;
    --*) ;;
    *) args+=("$1") ;;
  esac
  shift
done
echo "rclone ${args[*]}" >>"$CALLS"
[ -n "$cfg" ] && echo "config-mode $(stat -c %a "$cfg") ${cfg##*/}" >>"$CALLS"
if [ "${args[0]}" = obscure ]; then
  IFS= read -r line
  printf 'OBS%s\n' "$(printf '%s' "$line" | base64 -w0 | tr '+/' '-_' | tr -d '=')"
  exit 0
fi
# FAKE_RCLONE_ERR_CFG=install: solo falla con la configuración de un solo uso (la de la contraseña).
if [ -n "${FAKE_RCLONE_ERR:-}" ]; then
  fail=1
  if [ "${FAKE_RCLONE_ERR_CFG:-}" = install ]; then case ${cfg##*/} in .install.*) ;; *) fail=0 ;; esac; fi
  if [ $fail = 1 ]; then echo "ERROR : $FAKE_RCLONE_ERR" >&2; exit 1; fi
fi
map() { case $1 in growth-backup:*) echo "$FAKE_REMOTE/crypt/${1#growth-backup:}" ;; growth-install:*|growth-storagebox:*) echo "$FAKE_REMOTE/sftp/${1#*:}" ;; *) echo "$FAKE_REMOTE/other/${1#*:}" ;; esac; }
case ${args[0]} in
  listremotes) cat "$FAKE_STATE/remotes" 2>/dev/null; true ;;
  mkdir) mkdir -p "$(map "${args[1]}")" ;;
  rcat) p=$(map "${args[1]}"); mkdir -p "$(dirname "$p")"; cat >"$p" ;;
  deletefile) rm -f "$(map "${args[1]}")" ;;
  cat) p=$(map "${args[1]}"); [ -f "$p" ] || { echo "ERROR : object not found" >&2; exit 3; }; cat "$p" ;;
  lsf) ls "$(map "${args[1]}")" 2>/dev/null; true ;;
  about) echo '{"total":1099511627776,"used":1000,"free":1099511626776}' ;;
esac
SH
    # ssh-keygen: genera una llave de mentira (y cuenta cuántas veces), da su huella o la pública.
    cat >"$FAKEBIN/ssh-keygen" <<'SH'
#!/usr/bin/env bash
echo "ssh-keygen $*" >>"$CALLS"
[ -n "${FAKE_KEYGEN_FAIL:-}" ] && exit 1
f=""; comment=""; mode=gen
while [ $# -gt 0 ]; do
  case $1 in -f) f=$2; shift ;; -C) comment=$2; shift ;; -l) mode=fp ;; -lf) mode=fp; f=$2; shift ;; -y) mode=pub ;; esac
  shift
done
case $mode in
  gen) echo "GEN" >>"$FAKE_STATE/keygen-count"
       printf -- '-----BEGIN OPENSSH PRIVATE KEY-----\nfalsa-%s\n-----END OPENSSH PRIVATE KEY-----\n' "$RANDOM" >"$f"
       echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFalsa$RANDOM $comment" >"$f.pub" ;;
  fp) [ -s "$f" ] || exit 1; echo "256 SHA256:huellaFalsa${f##*/} x (ED25519)" ;;
  pub) echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIDerivada growth" ;;
esac
SH
    # ssh-keyscan: la llave del servidor; FAKE_KEYSCAN=refused|silent simula que no responde.
    cat >"$FAKEBIN/ssh-keyscan" <<'SH'
#!/usr/bin/env bash
echo "ssh-keyscan $*" >>"$CALLS"
host=${*: -1}; port=22
while [ $# -gt 0 ]; do case $1 in -p) port=$2; shift ;; esac; shift; done
case ${FAKE_KEYSCAN:-ok} in
  refused) echo "connect (\`$host'): Connection refused" >&2; exit 0 ;;
  silent) exit 0 ;;
esac
echo "# $host:$port SSH-2.0-OpenSSH"
echo "[$host]:$port ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIServidor"
SH
    # systemctl: anota; is-enabled según $FAKE_STATE/enabled; show ActiveState según $FAKE_STATE/active-<unidad>.
    cat >"$FAKEBIN/systemctl" <<'SH'
#!/usr/bin/env bash
echo "systemctl $*" >>"$CALLS"
case $1 in
  is-enabled) grep -qx "$2" "$FAKE_STATE/enabled" 2>/dev/null && echo enabled || echo disabled ;;
  enable) for u in "$@"; do case $u in enable|--*) ;; *) echo "$u" >>"$FAKE_STATE/enabled" ;; esac; done ;;
  show) cat "$FAKE_STATE/active-${*: -1}" 2>/dev/null || echo inactive ;;
  start) [ -z "${FAKE_START_FAIL:-}" ] || exit 1 ;;
esac
exit 0
SH
    chmod +x "$FAKEBIN"/*
}

setup() {
    printf '\n\033[1m%s\033[0m\n' "$1"
    unset TMPDIR FAKE_RCLONE_ERR FAKE_RCLONE_ERR_CFG FAKE_KEYSCAN FAKE_KEYGEN_FAIL FAKE_START_FAIL
    W=$(mktemp -d /tmp/growth-dispatch-test.XXXXXX) || exit 2
    case $W in /tmp/growth-dispatch-test.*) ;; *) exit 2 ;; esac
    export FAKEBIN=$W/bin CALLS=$W/calls.log FAKE_REMOTE=$W/remote FAKE_STATE=$W/fake
    export GROWTH_ROOT=$W/opt/growth GROWTH_ETC=$W/etc/growth GROWTH_BACKUP_WORK=$W/var/lib/growth-backup
    export GROWTH_STATE_DIR=$GROWTH_ROOT/shared/state
    unset GROWTH_BACKUP_CONF GROWTH_RCLONE_CONF GROWTH_BACKUP_SSH_DIR RCLONE_CONFIG
    export RCLONE=$FAKEBIN/rclone SSH_KEYGEN=$FAKEBIN/ssh-keygen SSH_KEYSCAN=$FAKEBIN/ssh-keyscan SYSTEMCTL=$FAKEBIN/systemctl
    STATE=$GROWTH_STATE_DIR
    CONF=$GROWTH_ETC/backup.conf
    RCONF=$GROWTH_ETC/backup-rclone.conf
    make_fakes
    mkdir -p "$STATE" "$GROWTH_ETC" "$FAKE_REMOTE" "$FAKE_STATE"
    chmod 1775 "$STATE"
    : >"$CALLS"
    : >"$FAKE_STATE/enabled"
}
teardown() { case ${W:-} in /tmp/growth-dispatch-test.?*) rm -rf "$W" ;; esac; }

# request JSON: deja el pedido como lo haría la app (0600) y corre el despachador.
request() {
    local tmp
    tmp=$(mktemp "$STATE/.tmp.XXXXXX")
    printf '%s' "$1" >"$tmp"
    chmod 0600 "$tmp"
    mv -f "$tmp" "$STATE/backup-request.json"
}
dispatch() { "$DISPATCH" >"$W/out.log" 2>&1; echo $?; }

configure_json() { # configure_json [auth] [con frase] [heartbeats JSON]
    local auth=${1:-key} enc=${2:-si} hb=${3:-"{\"mariadb\":\"$HB_M\",\"clickhouse\":null,\"verify\":\"\"}"} secrets
    secrets=$(jq -n --arg enc "$ENC" --arg pw "$PW" --arg auth "$auth" --arg withenc "$enc" \
        '{encryption_password: (if $withenc == "si" then $enc else null end), password: (if $auth == "password" then $pw else null end)}')
    jq -n --arg auth "$auth" --argjson hb "$hb" --argjson secrets "$secrets" \
        '{id: "bkp_A1b2C3d4e5", action: "configure", requested_by: "usr_01ABC", requested_at: "2026-11-20T10:00:00Z",
          config: {host: "u123456.your-storagebox.de", port: 23, user: "u123456", path: "imagina-growth", auth: $auth,
                   keep_days: 30, heartbeats: $hb}, secrets: $secrets}'
}
no_secrets_in() { # no_secrets_in ARCHIVO…: ni la frase, ni la contraseña, ni los heartbeats, ni lo «obscured»
    local f
    for f in "$@"; do
        [ -e "$f" ] || continue
        if grep -qF -e "$ENC" -e "$PW" -e "HBmariadb12345" -e "HBclickhouse678" -e "OBS" -e "PRIVATE KEY" "$f"; then return 1; fi
    done
    return 0
}

# ── configure con llave ──────────────────────────────────────────────────────────
setup "Guardar el destino con llave SSH: archivos 0600, llave nueva, prueba y estado sin secretos"
printf '# a mano\nGROWTH_BACKUP_LOCAL_DAYS=5\nCLICKHOUSE_BACKUP_USER=respaldo\nCLICKHOUSE_BACKUP_PASSWORD="con \\"comillas\\" y $dolar"\n' >"$CONF"
chmod 0600 "$CONF"
request "$(configure_json key)"
code=$(dispatch)
check "termina bien" '[ "$code" = 0 ]'
check "el pedido se tomó y no queda copia en la carpeta de trabajo" '[ ! -e "$STATE/backup-request.json" ] && [ ! -e "$GROWTH_BACKUP_WORK/request.json" ]'
check "backup.conf 0600 con el remoto cifrado del panel" '[ "$(mode "$CONF")" = 600 ] && grep -qx "GROWTH_BACKUP_REMOTE=growth-backup:growth" "$CONF" && grep -qx "GROWTH_RCLONE_CONFIG=$RCONF" "$CONF"'
check "destino, retención y heartbeat en backup.conf" 'grep -qx "GROWTH_BACKUP_SFTP_HOST=u123456.your-storagebox.de" "$CONF" && grep -qx "GROWTH_BACKUP_KEEP_DAYS=30" "$CONF" && grep -qx "BETTERSTACK_HEARTBEAT_BACKUP_MARIADB=$HB_M" "$CONF" && grep -qx "BETTERSTACK_HEARTBEAT_BACKUP_VERIFY=" "$CONF"'
check "conserva lo que se puso a mano (días locales y usuario de ClickHouse)" 'grep -qx "GROWTH_BACKUP_LOCAL_DAYS=5" "$CONF" && grep -q "^CLICKHOUSE_BACKUP_USER=\"respaldo\"$" "$CONF"'
# shellcheck source=../../backup/growth-backup-lib.sh
. "$(dirname "$DISPATCH")/growth-backup-lib.sh"
check "lo conservado se lee igual (comillas y \$ escapados)" '[ "$(dotenv_get "$CONF" CLICKHOUSE_BACKUP_PASSWORD)" = "con \"comillas\" y \$dolar" ]'
check "rclone.conf 0600: sftp con la llave, known_hosts y crypt encima de la carpeta" '[ "$(mode "$RCONF")" = 600 ] && grep -qx "key_file = $GROWTH_ETC/backup-ssh/id_ed25519" "$RCONF" && grep -qx "known_hosts_file = $GROWTH_ETC/backup-ssh/known_hosts" "$RCONF" && grep -qx "remote = growth-storagebox:imagina-growth" "$RCONF" && ! grep -q "^pass =" "$RCONF"'
check "la frase de cifrado solo «obscured» (nunca en claro)" 'grep -q "^password = OBS" "$RCONF" && ! grep -qF "$ENC" "$RCONF" "$CONF"'
check "llave SSH generada: carpeta 0700, privada 0600" '[ "$(mode "$GROWTH_ETC/backup-ssh")" = 700 ] && [ "$(mode "$GROWTH_ETC/backup-ssh/id_ed25519")" = 600 ] && [ "$(wc -l <"$FAKE_STATE/keygen-count")" = 1 ]'
check "known_hosts del servidor (puerto 23), 0600" 'grep -q "^\[u123456.your-storagebox.de\]:23 ssh-ed25519" "$GROWTH_ETC/backup-ssh/known_hosts" && [ "$(mode "$GROWTH_ETC/backup-ssh/known_hosts")" = 600 ]'
check "probó la conexión de punta a punta (carpeta, escribir y borrar)" 'called "rclone mkdir growth-backup:growth" && called "rclone rcat growth-backup:growth/.prueba-de-conexion-" && called "rclone deletefile growth-backup:growth/.prueba-de-conexion-" && [ -z "$(ls -A "$FAKE_REMOTE/crypt/growth")" ]'
check "rclone siempre con su configuración 0600" '! grep "^config-mode" "$CALLS" | grep -qv "^config-mode 600"'
check "encendió los horarios de las copias" 'called "systemctl enable --now growth-backup-mariadb.timer growth-backup-clickhouse.timer growth-backup-verify.timer"'
check "estado 0644 y de root (dueño del proceso)" '[ "$(mode "$STATE/backup-status.json")" = 644 ] && [ -O "$STATE/backup-status.json" ]'
check "estado: destino y pedido bien" '[ "$(st .managed)" = true ] && [ "$(st .destination.host)" = u123456.your-storagebox.de ] && [ "$(st .destination.port)" = 23 ] && [ "$(st .destination.auth)" = key ] && [ "$(st .last_request.state)" = succeeded ] && [ "$(st .last_request.id)" = bkp_A1b2C3d4e5 ]'
check "estado: la prueba funcionó y dice el espacio libre" '[ "$(st .last_test.ok)" = true ] && [ "$(st .last_test.free_bytes)" = 1099511626776 ]'
check "estado: de los secretos y heartbeats solo si están" '[ "$(st .secrets.encryption)" = true ] && [ "$(st .secrets.password)" = false ] && [ "$(st .heartbeats.mariadb)" = true ] && [ "$(st .heartbeats.clickhouse)" = false ] && [ "$(st .timers_enabled)" = true ]'
check "estado: la llave pública y las huellas (nunca la privada)" '[ "$(st ".ssh_key.public | startswith(\"ssh-ed25519 \")")" = true ] && [ "$(st .ssh_key.fingerprint)" != null ] && [ "$(st .host_key.fingerprint)" != null ]'
check "ningún secreto en el estado, en la carpeta de trabajo ni en la salida" 'no_secrets_in "$STATE"/* "$GROWTH_BACKUP_WORK"/* "$W/out.log"'
check "ningún secreto en la línea de comandos de nadie" 'no_secrets_in "$CALLS"'
PUB1=$(cat "$GROWTH_ETC/backup-ssh/id_ed25519.pub")

# Otra vez: la llave no se regenera; null conserva el heartbeat, "" lo quita, sin frase se conserva la anterior.
OBS1=$(grep '^password' "$RCONF")
request "$(configure_json key no '{"mariadb":null,"clickhouse":"'"$HB_C"'","verify":null}')"
code=$(dispatch)
check "segunda vez: bien" '[ "$code" = 0 ] && [ "$(st .last_request.state)" = succeeded ]'
check "la llave no se regenera" '[ "$(wc -l <"$FAKE_STATE/keygen-count")" = 1 ] && [ "$(cat "$GROWTH_ETC/backup-ssh/id_ed25519.pub")" = "$PUB1" ]'
check "sin frase nueva se conserva la de antes" '[ "$(grep "^password" "$RCONF")" = "$OBS1" ]'
check "null conserva el heartbeat; uno nuevo se agrega" 'grep -qx "BETTERSTACK_HEARTBEAT_BACKUP_MARIADB=$HB_M" "$CONF" && grep -qx "BETTERSTACK_HEARTBEAT_BACKUP_CLICKHOUSE=$HB_C" "$CONF"'
request "$(configure_json key no '{"mariadb":"","clickhouse":null,"verify":null}')"
dispatch >/dev/null
check "\"\" quita el heartbeat" 'grep -qx "BETTERSTACK_HEARTBEAT_BACKUP_MARIADB=" "$CONF" && [ "$(st .heartbeats.mariadb)" = false ] && [ "$(st .heartbeats.clickhouse)" = true ]'
teardown

# ── configure con contraseña ────────────────────────────────────────────────────
setup "Guardar con contraseña: queda solo del lado de root"
request "$(configure_json password)"
code=$(dispatch)
check "termina bien" '[ "$code" = 0 ] && [ "$(st .last_request.state)" = succeeded ]'
check "rclone.conf con la contraseña «obscured» y sin llave" 'grep -q "^pass = OBS" "$RCONF" && ! grep -q "^key_file" "$RCONF" && [ "$(st .secrets.password)" = true ] && [ "$(st .destination.auth)" = password ]'
check "la contraseña nunca en claro: ni en archivos, ni en el estado, ni en llamadas" 'no_secrets_in "$STATE"/* "$CALLS" "$W/out.log" && ! grep -qF "$PW" "$CONF" "$RCONF"'
check "sin llave SSH si no se usa" '[ ! -e "$GROWTH_ETC/backup-ssh/id_ed25519" ]'
OBS_PASS=$(grep '^pass =' "$RCONF")
request "$(configure_json password no | jq '.secrets.password = null')"
dispatch >/dev/null
check "sin contraseña nueva se conserva la guardada" '[ "$(grep "^pass =" "$RCONF")" = "$OBS_PASS" ] && [ "$(st .last_request.state)" = succeeded ]'
teardown

setup "Faltan secretos la primera vez: no se guarda nada"
request "$(configure_json key no)"
dispatch >/dev/null
check "sin frase de cifrado: falla y lo dice" '[ "$(st .last_request.state)" = failed ] && [ "$(st .last_request.code)" = encryption_required ]'
check "no escribió backup.conf ni rclone.conf" '[ ! -e "$CONF" ] && [ ! -e "$RCONF" ]'
request "$(configure_json password | jq '.secrets.password = null')"
dispatch >/dev/null
check "con contraseña pero sin ella: password_required" '[ "$(st .last_request.code)" = password_required ] && [ ! -e "$CONF" ]'
teardown

# ── Pedidos inválidos ────────────────────────────────────────────────────────────
INVALID=(
    'host|.config.host = "u1.example.com; rm -rf /"|invalid_host'
    'host con opción|.config.host = "-oProxyCommand=id"|invalid_host'
    'host con espacio|.config.host = "u1 .example.com"|invalid_host'
    'puerto 0|.config.port = 0|invalid_port'
    'puerto 70000|.config.port = 70000|invalid_port'
    'puerto como texto|.config.port = "23"|invalid_port'
    'usuario con $()|.config.user = "u1$(id)"|invalid_user'
    'carpeta ..|.config.path = "../etc"|invalid_path'
    'carpeta absoluta|.config.path = "/etc/growth"|invalid_path'
    'carpeta .ssh|.config.path = ".ssh"|invalid_path'
    'carpeta con salto de línea|.config.path = "a\nb"|invalid_request'
    'días 0|.config.keep_days = 0|invalid_keep_days'
    'días 400|.config.keep_days = 400|invalid_keep_days'
    'forma de entrar|.config.auth = "agent"|invalid_auth'
    'heartbeat http|.config.heartbeats.mariadb = "http://uptime.betterstack.com/api/v1/heartbeat/abcdefgh12"|invalid_heartbeat'
    'heartbeat de otro sitio|.config.heartbeats.mariadb = "https://evil.example/api/v1/heartbeat/abcdefgh12"|invalid_heartbeat'
    'heartbeat como número|.config.heartbeats.verify = 5|invalid_heartbeat'
    'contraseña con salto de línea|.secrets.encryption_password = "frase larga\npass = x"|invalid_encryption'
    'frase corta|.secrets.encryption_password = "corta"|invalid_encryption'
    'acción desconocida|.action = "rm"|invalid_request'
    'id inválido|.id = "../../etc/passwd"|invalid_request'
    'solicitante inválido|.requested_by = "usr_1 $(id)"|invalid_request'
)
for row in "${INVALID[@]}"; do
    IFS='|' read -r label filter want <<<"$row"
    setup "Pedido inválido: $label"
    request "$(configure_json key | jq "$filter")"
    code=$(dispatch)
    check "termina sin error del servicio" '[ "$code" = 0 ]'
    check "rechazado con $want" '[ "$(st .last_request.state)" = failed ] && [ "$(st .last_request.code)" = "$want" ]'
    check "no tocó la configuración ni llamó a nada" '[ ! -e "$CONF" ] && [ ! -e "$RCONF" ] && ! called "rclone mkdir" && ! called "ssh-keyscan"'
    check "el pedido se descartó" '[ ! -e "$STATE/backup-request.json" ] && [ ! -e "$GROWTH_BACKUP_WORK/request.json" ]'
    teardown
done

for raw in 'no es json' '["configure"]' "$(head -c 40000 /dev/zero | tr '\0' 'x')"; do
    setup "Pedido inválido: ${raw:0:20}"
    request "$raw"
    dispatch >/dev/null
    check "rechazado" '[ "$(st .last_request.state)" = failed ] && [ "$(st .last_request.code)" = invalid_request ] && [ ! -e "$CONF" ]'
    teardown
done

setup "Un symlink como pedido no se sigue"
echo '{"id":"bkp_A1b2C3d4e5","action":"refresh","requested_by":"usr_1","secreto":"NO-DEBE-SALIR"}' >"$W/secreto.json"
ln -s "$W/secreto.json" "$STATE/backup-request.json"
dispatch >/dev/null
check "rechazado" '[ "$(st .last_request.code)" = invalid_request ]'
check "el archivo apuntado sigue igual y no se filtró" '[ -f "$W/secreto.json" ] && grep -q NO-DEBE-SALIR "$W/secreto.json" && ! grep -rq NO-DEBE-SALIR "$STATE" "$GROWTH_BACKUP_WORK"'
check "no quedó nada en la carpeta de trabajo" '[ ! -e "$GROWTH_BACKUP_WORK/request.json" ] && [ ! -L "$GROWTH_BACKUP_WORK/request.json" ]'
teardown

setup "Una carpeta o un FIFO como pedido se rechazan"
mkdir "$STATE/backup-request.json"
dispatch >/dev/null
check "carpeta: rechazada" '[ "$(st .last_request.code)" = invalid_request ] && [ ! -e "$STATE/backup-request.json" ]'
mkfifo "$STATE/backup-request.json"
timeout 10 "$DISPATCH" >/dev/null 2>&1
check "FIFO: rechazado sin quedarse esperando" '[ $? -ne 124 ] && [ "$(st .last_request.code)" = invalid_request ]'
teardown

setup "El estado como symlink no se sigue"
echo intacto >"$W/victima"
ln -s "$W/victima" "$STATE/backup-status.json"
request '{"id":"bkp_A1b2C3d4e5","action":"refresh","requested_by":"usr_1"}'
dispatch >/dev/null
check "el archivo apuntado no cambió" '[ "$(cat "$W/victima")" = intacto ]'
check "el estado quedó en su lugar, como archivo" '[ ! -L "$STATE/backup-status.json" ] && [ "$(st .last_request.state)" = succeeded ]'
teardown

# ── Probar la conexión ───────────────────────────────────────────────────────────
setup "Probar la conexión: bien, y los errores en palabras"
request '{"id":"bkp_T1e2s3t4","action":"test","requested_by":"usr_1"}'
dispatch >/dev/null
check "sin destino: lo dice" '[ "$(st .last_request.code)" = not_configured ]'
request "$(configure_json key)"
dispatch >/dev/null
request '{"id":"bkp_T1e2s3t4","action":"test","requested_by":"usr_1"}'
dispatch >/dev/null
check "funciona" '[ "$(st .last_request.state)" = succeeded ] && [ "$(st .last_test.ok)" = true ]'
for row in 'ssh: handshake failed: ssh: unable to authenticate, attempted methods [none publickey]|auth_failed|llave' \
    'dial tcp 1.2.3.4:23: connect: connection refused|connection_refused|puerto 23' \
    'dial tcp: lookup u123456.your-storagebox.de: no such host|host_not_found|No se encontró' \
    'ssh: handshake failed: knownhosts: key mismatch|host_key_changed|identidad' \
    'dial tcp 1.2.3.4:23: i/o timeout|timeout|no respondió' \
    'sftp: "permission denied" (SSH_FX_PERMISSION_DENIED)|permission_denied|escribir'; do
    IFS='|' read -r err want words <<<"$row"
    export FAKE_RCLONE_ERR=$err
    request '{"id":"bkp_T1e2s3t4","action":"test","requested_by":"usr_1"}'
    dispatch >/dev/null
    check "«${err:0:40}…» → $want" '[ "$(st .last_request.state)" = failed ] && [ "$(st .last_test.ok)" = false ] && [ "$(st .last_test.code)" = "$want" ] && st .last_test.message | grep -q "$words"'
done
unset FAKE_RCLONE_ERR
teardown

setup "Guardar con un servidor que no responde: se guarda y la prueba lo explica"
export FAKE_KEYSCAN=refused
request "$(configure_json key)"
dispatch >/dev/null
check "guardado" '[ "$(st .last_request.state)" = succeeded ] && [ -f "$CONF" ]'
check "la prueba dice que rechazó la conexión" '[ "$(st .last_test.ok)" = false ] && [ "$(st .last_test.code)" = connection_refused ]'
check "sin known_hosts a medias" '[ ! -s "$GROWTH_ETC/backup-ssh/known_hosts" ] && ! called "rclone mkdir"'
export FAKE_KEYSCAN=silent
request '{"id":"bkp_T1e2s3t4","action":"test","requested_by":"usr_1"}'
dispatch >/dev/null
check "sin respuesta: no respondió a tiempo" '[ "$(st .last_test.code)" = timeout ]'
unset FAKE_KEYSCAN
request '{"id":"bkp_T1e2s3t4","action":"test","requested_by":"usr_1"}'
dispatch >/dev/null
check "cuando vuelve a responder, la prueba anota su identidad y funciona" '[ -s "$GROWTH_ETC/backup-ssh/known_hosts" ] && [ "$(st .last_test.ok)" = true ]'
teardown

# ── Copia ahora ──────────────────────────────────────────────────────────────────
setup "Hacer una copia ahora"
request '{"id":"bkp_R1u2n3n4","action":"backup_now","requested_by":"usr_1"}'
dispatch >/dev/null
check "sin destino: lo dice y no arranca nada" '[ "$(st .last_request.code)" = not_configured ] && ! called "systemctl start"'
request "$(configure_json key)"
dispatch >/dev/null
request '{"id":"bkp_R1u2n3n4","action":"backup_now","requested_by":"usr_1"}'
dispatch >/dev/null
check "arranca las dos copias sin esperar" 'called "systemctl start --no-block growth-backup-mariadb.service growth-backup-clickhouse.service" && [ "$(st .last_request.state)" = succeeded ]'
check "las deja «en cola» para el panel (0644)" '[ "$(jq -r .state "$STATE/backup-job-mariadb.json")" = queued ] && [ "$(jq -r .state "$STATE/backup-job-clickhouse.json")" = queued ] && [ "$(mode "$STATE/backup-job-mariadb.json")" = 644 ]'
: >"$CALLS"
echo activating >"$FAKE_STATE/active-growth-backup-mariadb.service"
request '{"id":"bkp_R1u2n3n4","action":"backup_now","requested_by":"usr_1"}'
dispatch >/dev/null
check "con una copia en curso: no arranca otra" '[ "$(st .last_request.code)" = already_running ] && ! called "systemctl start"'
teardown

# ── Instalar la llave con la contraseña ──────────────────────────────────────────
setup "Instalar la llave en el Storage Box con su contraseña (que no se guarda)"
request "$(configure_json key)"
dispatch >/dev/null
mkdir -p "$FAKE_REMOTE/sftp/.ssh"
echo "ssh-rsa AAAAotra otra@pc" >"$FAKE_REMOTE/sftp/.ssh/authorized_keys"
install_req=$(jq -n --arg pw "$PW" '{id: "bkp_K1e2y3s4", action: "install_key", requested_by: "usr_1", secrets: {password: $pw}}')
request "$install_req"
dispatch >/dev/null
check "instalada y probada con la llave" '[ "$(st .last_request.state)" = succeeded ] && [ "$(st .last_test.ok)" = true ]'
check "authorized_keys conserva las otras llaves y suma la nueva" 'grep -qx "ssh-rsa AAAAotra otra@pc" "$FAKE_REMOTE/sftp/.ssh/authorized_keys" && grep -qxF "$(cat "$GROWTH_ETC/backup-ssh/id_ed25519.pub")" "$FAKE_REMOTE/sftp/.ssh/authorized_keys"'
check "la configuración de un solo uso con la contraseña se borró" '[ -z "$(find "$GROWTH_BACKUP_WORK" -name ".install.*")" ] && ! grep -q "^pass =" "$RCONF"'
check "la contraseña no quedó en ningún lado" 'no_secrets_in "$STATE"/* "$CALLS" "$W/out.log" "$GROWTH_BACKUP_WORK"/* && ! grep -qF "$PW" "$CONF" "$RCONF"'
request "$install_req"
dispatch >/dev/null
check "otra vez: no la duplica" '[ "$(grep -c "AAAAC3NzaC1lZDI1NTE5AAAAIFalsa" "$FAKE_REMOTE/sftp/.ssh/authorized_keys")" = 1 ]'
export FAKE_RCLONE_ERR='ssh: handshake failed: ssh: unable to authenticate' FAKE_RCLONE_ERR_CFG=install
request "$install_req"
dispatch >/dev/null
check "contraseña equivocada: lo dice" '[ "$(st .last_request.state)" = failed ] && [ "$(st .last_request.code)" = auth_failed ] && st .last_request.message | grep -q "contraseña"'
unset FAKE_RCLONE_ERR FAKE_RCLONE_ERR_CFG
request '{"id":"bkp_K1e2y3s4","action":"install_key","requested_by":"usr_1","secrets":{}}'
dispatch >/dev/null
check "sin contraseña: password_required" '[ "$(st .last_request.code)" = password_required ]'
teardown

# ── Errores inesperados, candado y varios pedidos ────────────────────────────────
setup "Un error inesperado deja el pedido como fallido"
export FAKE_KEYGEN_FAIL=1
request "$(configure_json key)"
code=$(dispatch)
check "el servicio termina con error" '[ "$code" != 0 ]'
check "el panel ve el pedido fallido, en palabras" '[ "$(st .last_request.state)" = failed ] && [ "$(st .last_request.code)" = internal ] && [ ! -e "$GROWTH_BACKUP_WORK/request.json" ]'
teardown

setup "Con el candado tomado no hace nada; después atiende los pedidos en orden"
mkdir -p "$GROWTH_BACKUP_WORK"
request '{"id":"bkp_L1o2c3k4","action":"refresh","requested_by":"usr_1"}'
flock "$GROWTH_BACKUP_WORK/dispatch.lock" sleep 3 &
sleep 0.5
code=$(dispatch)
check "con el candado: sale bien sin tocar el pedido" '[ "$code" = 0 ] && [ -e "$STATE/backup-request.json" ]'
wait
dispatch >/dev/null
check "sin candado: lo atiende" '[ ! -e "$STATE/backup-request.json" ] && [ "$(st .last_request.id)" = bkp_L1o2c3k4 ] && [ "$(st .last_request.action)" = refresh ]'
teardown

setup "Configuración a mano: solo cuenta si su remoto existe en rclone"
printf 'GROWTH_BACKUP_REMOTE=storagebox-crypt:growth\nGROWTH_BACKUP_LOCAL_DAYS=3\n' >"$CONF"
"$DISPATCH" >/dev/null 2>&1
check "la plantilla vieja (remoto de ejemplo que no existe) no cuenta como configurada" '[ "$(st .configured)" = false ] && [ "$(st .manual_remote)" = null ]'
request '{"id":"bkp_M1a2n3u4","action":"backup_now","requested_by":"usr_1"}'
dispatch >/dev/null
check "y no lanza copias" '[ "$(st .last_request.code)" = not_configured ] && ! called "systemctl start"'
printf 'storagebox:\nstoragebox-crypt:\n' >"$FAKE_STATE/remotes"
"$DISPATCH" >/dev/null 2>&1
check "con el remoto en rclone: configurada a mano, sin destino del panel" '[ "$(st .configured)" = true ] && [ "$(st .managed)" = false ] && [ "$(st .manual_remote)" = storagebox-crypt:growth ] && [ "$(st .destination)" = null ]'
request '{"id":"bkp_M1a2n3u4","action":"test","requested_by":"usr_1"}'
dispatch >/dev/null
check "la prueba usa ese remoto tal cual, con la configuración de rclone de root" 'called "rclone mkdir storagebox-crypt:growth" && ! grep -q "^config-mode" "$CALLS" && [ "$(st .last_test.ok)" = true ]'
request "$(configure_json key)"
dispatch >/dev/null
check "guardar desde el panel la reemplaza y conserva los días locales" '[ "$(st .managed)" = true ] && grep -qx "GROWTH_BACKUP_REMOTE=growth-backup:growth" "$CONF" && grep -qx "GROWTH_BACKUP_LOCAL_DAYS=3" "$CONF"'
teardown

setup "Sin ssh-keyscan en el servidor: lo dice en palabras"
export SSH_KEYSCAN=$W/no-existe/ssh-keyscan
request "$(configure_json key)"
dispatch >/dev/null
check "missing_tool con el nombre del programa" '[ "$(st .last_request.code)" = missing_tool ] && st .last_request.message | grep -q "ssh-keyscan" && [ ! -e "$CONF" ]'
teardown

printf '\nDespachador: %d pruebas: %d bien, %d mal\n' $((PASS + FAIL)) "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
