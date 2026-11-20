#!/usr/bin/env bash
# Funciones comunes de las copias de Imagina Growth (docs/despliegue.md §6).
# La cargan growth-backup-mariadb, growth-backup-clickhouse, growth-backup-verify y growth-backup-dispatch
# con `source`. Solo se agregan funciones: una versión anterior de esos scripts tiene que seguir funcionando con
# esta librería (growth-system la instala junto al despachador si falta, antes que growth-update los reemplace).
#
# Reglas:
# - Los archivos de configuración (.env de la app y /etc/growth/backup.conf) se leen con un lector
#   dotenv propio: nunca con `source`, porque un valor como `MAIL_FROM=Nombre <correo>` sin comillas
#   rompe bash y un valor con `$(…)` se ejecutaría.
# - Ningún secreto se escribe en los logs.
# - Todas las rutas se pueden cambiar por variables de entorno, para las pruebas.

GROWTH_BACKUP_CONF=${GROWTH_BACKUP_CONF:-/etc/growth/backup.conf}
GROWTH_ENV_FILE=${GROWTH_ENV_FILE:-/opt/growth/shared/.env}
GROWTH_BACKUP_DIR=${GROWTH_BACKUP_DIR:-/var/lib/growth-backups}
# Donde el panel lee el resultado de cada copia (backup-job-<tarea>.json); lo escribe root (job_status).
GROWTH_STATE_DIR=${GROWTH_STATE_DIR:-/opt/growth/shared/state}

# Valor de KEY en un archivo dotenv, o vacío si no está. Admite `export KEY=…`, espacios alrededor del
# `=`, comentarios, comillas dobles (con \" \\ \n \t y varias líneas), comillas simples literales y
# valores sin comillas (el `#` precedido de espacio empieza un comentario). No expande variables ni
# ejecuta nada.
#   dotenv_get ARCHIVO CLAVE
dotenv_get() {
    local file=$1 want=$2 line key value rest quote out found=0 found_value=""
    [ -r "$file" ] || return 0
    while IFS= read -r line || [ -n "$line" ]; do
        line=${line%$'\r'}
        # Quita espacios iniciales.
        line=${line#"${line%%[![:space:]]*}"}
        [ -z "$line" ] && continue
        [ "${line:0:1}" = "#" ] && continue
        if [[ $line == export[[:space:]]* ]]; then
            line=${line#export}
            line=${line#"${line%%[![:space:]]*}"}
        fi
        [[ $line == *=* ]] || continue
        key=${line%%=*}
        key=${key%"${key##*[![:space:]]}"}
        [[ $key =~ ^[A-Za-z_][A-Za-z0-9_.]*$ ]] || continue
        rest=${line#*=}
        rest=${rest#"${rest%%[![:space:]]*}"}
        quote=${rest:0:1}
        if [ "$quote" = '"' ]; then
            # Comillas dobles: hasta la comilla de cierre no escapada (puede abarcar varias líneas).
            rest=${rest:1}
            out=""
            local i ch nx closed=0
            while :; do
                i=0
                while [ $i -lt ${#rest} ]; do
                    ch=${rest:$i:1}
                    if [ "$ch" = "\\" ] && [ $((i + 1)) -lt ${#rest} ]; then
                        nx=${rest:$((i + 1)):1}
                        case $nx in
                            n) out+=$'\n' ;;
                            t) out+=$'\t' ;;
                            r) out+=$'\r' ;;
                            '"' | "\\" | '$') out+=$nx ;;
                            *) out+="\\$nx" ;;
                        esac
                        i=$((i + 2))
                        continue
                    fi
                    if [ "$ch" = '"' ]; then
                        closed=1
                        break
                    fi
                    out+=$ch
                    i=$((i + 1))
                done
                [ $closed -eq 1 ] && break
                # Sigue en la línea siguiente.
                if IFS= read -r rest; then
                    rest=${rest%$'\r'}
                    out+=$'\n'
                else
                    break
                fi
            done
            value=$out
        elif [ "$quote" = "'" ]; then
            rest=${rest:1}
            value=${rest%%\'*}
        else
            # Sin comillas: un ` #` empieza un comentario; se quitan los espacios del final.
            value=$rest
            if [[ $value =~ ^(.*[^[:space:]])?[[:space:]]+#.*$ ]]; then
                value=${BASH_REMATCH[1]}
            elif [ "${value:0:1}" = "#" ]; then
                value=""
            fi
            value=${value%"${value##*[![:space:]]}"}
        fi
        if [ "$key" = "$want" ]; then
            # La última definición gana, como en phpdotenv con variables repetidas.
            printf -v found_value '%s' "$value"
            found=1
        fi
    done <"$file"
    if [ $found -eq 1 ]; then
        printf '%s' "$found_value"
    fi
}

# Valor de la configuración: primero la variable de entorno (pruebas o un override del servicio), después
# /etc/growth/backup.conf, después el valor por defecto.
#   conf KEY [DEFECTO]
conf() {
    local key=$1 default=${2:-} value
    value=${!key-}
    if [ -z "$value" ]; then
        value=$(dotenv_get "$GROWTH_BACKUP_CONF" "$key")
    fi
    printf '%s' "${value:-$default}"
}

# Valor del .env de la app (credenciales de ClickHouse, nombre de la base…).
appenv() {
    local key=$1 default=${2:-} value
    value=$(dotenv_get "$GROWTH_ENV_FILE" "$key")
    printf '%s' "${value:-$default}"
}

log() {
    printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

die() {
    JOB_ERROR=$*
    log "ERROR: $*" >&2
    exit 1
}

# Como die, con el código que muestra el panel (Plataforma → Copias de seguridad).
#   die_code CÓDIGO MENSAJE
die_code() {
    JOB_CODE=$1
    shift
    die "$@"
}

# Aviso a un heartbeat de Better Stack. Sin URL configurada no hace nada. Nunca imprime la URL (es un
# secreto: quien la tenga puede marcar la copia como hecha).
#   heartbeat URL [fail]
heartbeat() {
    local url=$1 kind=${2:-ok}
    [ -n "$url" ] || return 0
    if [ "$kind" = "fail" ]; then
        url="${url%/}/fail"
    fi
    if curl -fsS -m 10 --retry 3 -o /dev/null "$url"; then
        log "Heartbeat de Better Stack enviado ($kind)."
    else
        log "No se pudo avisar al heartbeat de Better Stack ($kind)." >&2
    fi
}

require() {
    local cmd
    for cmd in "$@"; do
        command -v "$cmd" >/dev/null 2>&1 || die_code missing_tool "falta el comando «$cmd»"
    done
}

# Quita de una carpeta local los archivos (no carpetas) que coinciden con PATRÓN y tienen más de N minutos.
#   prune_local CARPETA PATRÓN MINUTOS
prune_local() {
    local dir=$1 pattern=$2 minutes=$3
    [ -d "$dir" ] || return 0
    find "$dir" -maxdepth 1 -type f -name "$pattern" -mmin "+$minutes" -print -delete | while IFS= read -r f; do
        log "Borrada la copia local vieja $(basename "$f")."
    done
}

# Número entero o vacío (para los conteos que devuelven las bases).
int_or_empty() {
    local v=${1//[[:space:]]/}
    if [[ $v =~ ^[0-9]+$ ]]; then
        printf '%s' "$v"
    fi
    return 0
}

# ¿VALOR está entre A y B (en cualquier orden)?
#   in_range VALOR A B
in_range() {
    local v=$1 a=$2 b=$3 lo hi
    if [ "$a" -le "$b" ]; then lo=$a hi=$b; else lo=$b hi=$a; fi
    [ "$v" -ge "$lo" ] && [ "$v" -le "$hi" ]
}

# Escapa un valor para XML (configuración temporal de clickhouse-client). Ojo: en bash 5.2 un `&` sin escapar
# en el reemplazo de ${var//…/…} significa «lo que coincidió»; por eso va como \&.
xml_escape() {
    local s=$1
    s=${s//&/\&amp;}
    s=${s//</\&lt;}
    s=${s//>/\&gt;}
    s=${s//\"/\&quot;}
    s=${s//\'/\&apos;}
    printf '%s' "$s"
}

# Archivo temporal (0600) con la conexión de clickhouse-client; imprime su ruta. La contraseña nunca va en la
# línea de comandos (se vería en `ps`).
#   ch_config HOST PUERTO USUARIO CONTRASEÑA
ch_config() {
    local file
    file=$(mktemp)
    chmod 0600 "$file"
    printf '<config><host>%s</host><port>%s</port><user>%s</user><password>%s</password></config>\n' \
        "$(xml_escape "$1")" "$(xml_escape "$2")" "$(xml_escape "$3")" "$(xml_escape "$4")" >"$file"
    printf '%s' "$file"
}

# ── Lo que ve el panel (Plataforma → Copias de seguridad, docs/despliegue.md §6) ───────────────────────────

# Configuración de rclone que escribe el panel (GROWTH_RCLONE_CONFIG en backup.conf, p. ej.
# /etc/growth/backup-rclone.conf). Sin ella, rclone usa la suya (/root/.config/rclone/rclone.conf, la de la
# configuración a mano de infra/backup/README.md).
use_rclone_config() {
    local rc
    [ -n "${RCLONE_CONFIG:-}" ] && return 0
    rc=$(conf GROWTH_RCLONE_CONFIG)
    case $rc in
        /*) if [ -f "$rc" ]; then export RCLONE_CONFIG=$rc; fi ;;
    esac
    return 0
}
use_rclone_config

# Resultado de cada tarea en shared/state/backup-job-<tarea>.json (mariadb, clickhouse, verify): estado, fechas,
# copia, tamaño y, si falló, un código y el motivo en palabras. Sin secretos. Lo escribe root con un temporal +
# rename en la carpeta «sticky» (la app lo lee, pero no lo puede reemplazar ni borrar) y nunca sigue un symlink.
# Nunca hace fallar la copia: si no se puede escribir, se sigue.
JOB_KIND=""
JOB_STARTED=""
JOB_STEP=""
JOB_CODE=""
JOB_ERROR=""

#   job_status ESTADO [CÓDIGO] [MENSAJE] [NOMBRE] [BYTES]        ESTADO: queued | running | succeeded | failed
job_status() {
    local state=$1 code=${2:-} message=${3:-} name=${4:-} size=${5:-} file prev=null tmp
    if [ -z "$JOB_KIND" ] || [ ! -d "$GROWTH_STATE_DIR" ] || ! command -v jq >/dev/null 2>&1; then
        return 0
    fi
    file=$GROWTH_STATE_DIR/backup-job-$JOB_KIND.json
    # La última copia buena se conserva mientras corre o falla la siguiente (solo de un archivo propio).
    if [ -f "$file" ] && [ ! -L "$file" ] && [ -O "$file" ]; then
        prev=$(jq -c '.last_success | if type == "object" then . else null end' "$file" 2>/dev/null) || prev=null
        [ -n "$prev" ] || prev=null
    fi
    [[ $size =~ ^[0-9]+$ ]] || size=""
    tmp=$(mktemp "$GROWTH_STATE_DIR/.tmp.XXXXXX" 2>/dev/null) || return 0
    if jq -n --arg job "$JOB_KIND" --arg state "$state" --arg code "$code" --arg msg "$message" --arg name "$name" \
        --arg size "$size" --arg started "$JOB_STARTED" --arg at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --argjson prev "$prev" '
        def nn: if . == "" then null else . end;
        ($size | if . == "" then null else tonumber end) as $bytes
        | {job: $job, state: $state, started_at: ($started | nn),
           finished_at: (if $state == "succeeded" or $state == "failed" then $at else null end),
           name: ($name | nn), size_bytes: $bytes, code: ($code | nn), message: ($msg | nn),
           last_success: (if $state == "succeeded" then {finished_at: $at, name: ($name | nn), size_bytes: $bytes} else $prev end)}' \
        >"$tmp" 2>/dev/null && chmod 0644 "$tmp" && mv -fT "$tmp" "$file" 2>/dev/null; then
        return 0
    fi
    rm -f "$tmp"
    return 0
}

# Empieza una tarea: anota «corriendo» para el panel.
#   job_begin TAREA
job_begin() {
    JOB_KIND=$1
    JOB_STARTED=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    job_status running
}

# Paso en curso: si un comando falla (set -e), el panel dice en qué paso.
#   job_step PASO        PASO: dump | compress | upload | cleanup | download | restore
job_step() {
    JOB_STEP=$1
}

# La falla para el panel: el código de die_code (o el del paso) y el motivo (el de die, o el del paso).
#   job_failed CÓDIGO_DE_SALIDA
job_failed() {
    local exit_code=$1 code=$JOB_CODE message=$JOB_ERROR
    if [ -z "$code" ]; then
        code=${JOB_STEP:+${JOB_STEP}_failed}
        code=${code:-unknown}
    fi
    if [ -z "$message" ]; then
        case $JOB_STEP in
            dump) message="No se pudo sacar la copia de la base de datos." ;;
            compress) message="No se pudo comprimir la copia (¿falta espacio en el disco del servidor?)." ;;
            upload) message="No se pudo subir la copia al Storage Box." ;;
            cleanup) message="La copia se subió, pero no se pudieron borrar las copias viejas del Storage Box." ;;
            download) message="No se pudo bajar la copia del Storage Box." ;;
            restore) message="No se pudo restaurar la copia en la instancia de prueba." ;;
            *) message="La tarea terminó con un error (código $exit_code)." ;;
        esac
    fi
    job_status failed "$code" "$message"
}
