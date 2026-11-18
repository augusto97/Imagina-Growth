#!/usr/bin/env bash
# Funciones comunes de las copias de Imagina Growth (docs/despliegue.md §6).
# La cargan growth-backup-mariadb, growth-backup-clickhouse y growth-backup-verify con `source`.
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
    log "ERROR: $*" >&2
    exit 1
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
        command -v "$cmd" >/dev/null 2>&1 || die "falta el comando «$cmd»"
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
