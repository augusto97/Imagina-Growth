#!/usr/bin/env bash
# Instalador de Imagina Growth para un servidor Ubuntu 24.04 o 26.04 LTS limpio (docs/despliegue.md §7).
#
#   curl -fsSL https://github.com/augusto97/Imagina-Growth/releases/latest/download/install.sh \
#     | sudo bash -s -- --domain growth.ejemplo.com --email ops@ejemplo.com
#
# Opciones:
#   --domain D          dominio del panel (la ingesta y los enlaces van en el mismo dominio). Obligatorio la
#                       primera vez; después se toma del APP_URL del .env.
#   --email E           correo para Let's Encrypt (opcional).
#   --version X.Y.Z     versión a instalar (por defecto, la última publicada).
#   --repo O/R          repositorio de GitHub (por defecto augusto97/Imagina-Growth).
#   --releases-api URL  API de releases (por defecto https://api.github.com; las pruebas usan un simulador).
#   --pubkey ARCHIVO    llave pública ed25519 (PEM) que valida las versiones. Por defecto, la que el CI
#                       incrusta en este archivo si el repositorio tiene llave de firma (marcas GROWTH_PUBKEY).
#                       La firma es opcional: sin llave, las versiones se validan solo con sha256.
#   --skip-firewall     no tocar ufw.
#   --no-release        prepara el servidor sin instalar una versión de la app.
#
# Idempotente: correrlo otra vez no pisa el .env, ni las bases, ni el Caddyfile, ni los datos; solo agrega lo
# que falte. Si ya hay una versión instalada, no instala otra (las actualizaciones se hacen desde el panel).
set -euo pipefail
umask 022

GROWTH_ROOT=/opt/growth
SHARED=$GROWTH_ROOT/shared
ENV_FILE=$SHARED/.env
ETC_DIR=/etc/growth
BACKUP_DIR=/var/lib/growth-backups
LIB_DIR=/usr/local/lib/growth

DOMAIN=""
DOMAIN_GIVEN=0
EMAIL=""
VERSION=latest
VERSION_GIVEN=0
REPO=augusto97/Imagina-Growth
REPO_GIVEN=0
API=https://api.github.com
API_GIVEN=0
PUBKEY_FILE=""
SKIP_FIREWALL=0
NO_RELEASE=0

# La llave pública de las versiones. El workflow de release reemplaza el marcador por el PEM.
EMBEDDED_PUBKEY=$(cat <<'GROWTH_PUBKEY'
__GROWTH_RELEASE_PUBKEY__
GROWTH_PUBKEY
)

log() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '\033[33m[aviso]\033[0m %s\n' "$*" >&2; }
die() { printf '\033[31m[error]\033[0m %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --domain) DOMAIN=${2:?}; DOMAIN_GIVEN=1; shift 2 ;;
        --email) EMAIL=${2:?}; DOMAIN_GIVEN=1; shift 2 ;;
        --version) VERSION=${2#v}; VERSION_GIVEN=1; shift 2 ;;
        --repo) REPO=${2:?}; REPO_GIVEN=1; shift 2 ;;
        --releases-api) API=${2%/}; API_GIVEN=1; shift 2 ;;
        --pubkey) PUBKEY_FILE=${2:?}; shift 2 ;;
        --skip-firewall) SKIP_FIREWALL=1; shift ;;
        --no-release) NO_RELEASE=1; shift ;;
        -h|--help) sed -n 2,24p "$0" 2>/dev/null || true; exit 0 ;;
        *) die "Opción desconocida: $1" ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "Corre el instalador como root (sudo)."
# Datos del sistema sin `source` (/etc/os-release define VERSION y pisaría la nuestra).
os_field() { grep -E "^$1=" /etc/os-release 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"'; }
OS_ID=$(os_field ID)
OS_VERSION_ID=$(os_field VERSION_ID)
OS_CODENAME=$(os_field VERSION_CODENAME)
# Versiones por sistema (docs/despliegue.md §1): en 26.04 los repositorios oficiales ya no publican MariaDB 11.4
# ni Redis 7, así que se usan las LTS que sí existen ahí.
case "$OS_ID $OS_VERSION_ID" in
    "ubuntu 26.04") MARIADB_SERIES=11.8 REDIS_PIN='6:8.*' ;;
    "ubuntu 24.04") MARIADB_SERIES=11.4 REDIS_PIN='6:7.4.*' ;;
    *)
        warn "Probado en Ubuntu 24.04 y 26.04; este servidor es $(os_field PRETTY_NAME). Se usan las versiones de 24.04."
        MARIADB_SERIES=11.4 REDIS_PIN='6:7.4.*'
        ;;
esac
# Memoria y perfil de procesos (docs/despliegue.md §7). Con menos de ~3,5 GB se usa el perfil pequeño: ClickHouse y
# MariaDB con menos memoria, un solo worker de ingesta para todos los shards y Horizon con 3 procesos de trabajo.
# GROWTH_MEMORY_MB solo sirve para las pruebas (un contenedor ve la RAM de la máquina anfitriona).
MEM_MB=${GROWTH_MEMORY_MB:-$(awk '/MemTotal/ {print int($2 / 1024)}' /proc/meminfo)}
# En un contenedor (LXC u otro), /proc/meminfo muestra la RAM de la máquina anfitriona: manda el límite del cgroup
# (el menor memory.max desde el cgroup de PID 1 hasta la raíz). La misma regla que bin/growth-system.
if [ -z "${GROWTH_MEMORY_MB:-}" ]; then
    CG_PATH=$(awk -F: '$1 == "0" {print $3}' /proc/1/cgroup 2>/dev/null || true)
    while [ -n "$CG_PATH" ]; do
        CG_MAX=$(cat "/sys/fs/cgroup${CG_PATH%/}/memory.max" 2>/dev/null || true)
        if [[ $CG_MAX =~ ^[0-9]+$ ]] && [ $((CG_MAX / 1048576)) -lt "$MEM_MB" ]; then MEM_MB=$((CG_MAX / 1048576)); fi
        [ "$CG_PATH" = / ] && break
        CG_PATH=$(dirname "$CG_PATH")
    done
fi
[[ $MEM_MB =~ ^[0-9]+$ ]] || die "No se pudo leer la memoria del servidor."
if [ "$MEM_MB" -lt 1400 ]; then
    die "Este servidor tiene $MEM_MB MB de RAM; Imagina Growth necesita al menos 1,5 GB (2 GB o más para trabajar cómodo)."
fi
if [ "$MEM_MB" -lt 3500 ]; then PROFILE=small; else PROFILE=standard; fi
if [ "$VERSION" != latest ] && ! [[ $VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    die "Versión inválida: $VERSION (formato X.Y.Z)."
fi
ARCH=$(dpkg --print-architecture)
export DEBIAN_FRONTEND=noninteractive
APT=(apt-get -y -q -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)
WORK=$(mktemp -d /tmp/growth-install.XXXXXX)
trap 'rm -rf "$WORK"' EXIT

# ── Utilidades ──────────────────────────────────────────────────────────

# Copia SRC a DEST solo si cambió. Devuelve 0 si escribió (cambió) y 1 si ya estaba igual.
put_file() {
    local src=$1 dest=$2 mode=${3:-0644} owner=${4:-root:root}
    if [ -f "$dest" ] && cmp -s "$src" "$dest"; then
        chmod "$mode" "$dest"; chown "$owner" "$dest"
        return 1
    fi
    install -D -m "$mode" -o "${owner%%:*}" -g "${owner##*:}" "$src" "$dest"
    return 0
}

# Escribe el contenido de stdin en DEST solo si cambió (mismo retorno que put_file).
put_content() {
    local tmp
    tmp=$(mktemp "$WORK/put.XXXXXX")
    cat >"$tmp"
    put_file "$tmp" "$@"
}

rand_hex() { openssl rand -hex "${1:-24}"; }
rand_b64key() { printf 'base64:%s' "$(openssl rand -base64 32)"; }
rand_alnum() { tr -dc 'a-z0-9' </dev/urandom | head -c "${1:-20}"; }

# Lee una variable del .env sin `source` (un valor como `Nombre <correo>` rompería bash). Solo se usa
# para valores que escribe este instalador (sin comillas internas ni saltos de línea).
env_get() {
    local key=$1 line
    [ -f "$ENV_FILE" ] || return 0
    line=$(grep -E "^${key}=" "$ENV_FILE" | tail -n 1 || true)
    line=${line#"$key"=}
    line=${line%$'\r'}
    if [[ $line == \"*\" ]] || [[ $line == \'*\' ]]; then line=${line:1:${#line}-2}; fi
    printf '%s' "$line"
}

env_has() { [ -f "$ENV_FILE" ] && grep -qE "^${1}=" "$ENV_FILE"; }

# Agrega KEY=VALUE al .env solo si KEY no existe. Nunca reemplaza un valor existente.
ENV_ADDED=()
env_add() {
    local key=$1 value=$2
    env_has "$key" && return 0
    if [[ $value =~ [[:space:]\#\"\'\$\<\>] ]]; then
        value="\"${value//\"/\\\"}\""
    fi
    printf '%s=%s\n' "$key" "$value" >>"$ENV_FILE"
    ENV_ADDED+=("$key")
}

wait_for() { # descripción comando...
    local what=$1; shift
    for _ in $(seq 1 60); do
        "$@" >/dev/null 2>&1 && return 0
        sleep 1
    done
    die "No responde: $what"
}


# ── 1. Plantillas (del repositorio o de la versión publicada) ───────────

SCRIPT_DIR=""
if [ -n "${BASH_SOURCE[0]:-}" ] && [ -f "${BASH_SOURCE[0]}" ]; then
    SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
fi
TPL=""
if [ -n "$SCRIPT_DIR" ] && [ -d "$SCRIPT_DIR/config/php" ] && [ -d "$SCRIPT_DIR/systemd" ] && [ -d "$SCRIPT_DIR/caddy" ]; then
    TPL=$SCRIPT_DIR
fi

# La llave pública: --pubkey, la incrustada por el CI o la que ya está instalada.
PUBKEY_PEM=""
if [ -n "$PUBKEY_FILE" ]; then
    [ -f "$PUBKEY_FILE" ] || die "No existe la llave $PUBKEY_FILE."
    PUBKEY_PEM=$(cat "$PUBKEY_FILE")
elif [[ $EMBEDDED_PUBKEY == *"BEGIN PUBLIC KEY"* ]]; then
    PUBKEY_PEM=$EMBEDDED_PUBKEY
elif [ -f "$ETC_DIR/release-signing.pub.pem" ]; then
    PUBKEY_PEM=$(cat "$ETC_DIR/release-signing.pub.pem")
fi

REL_VERSION=""
REL_DIR=""
api_get() { curl -fsSL --retry 3 -H 'Accept: application/vnd.github+json' "$1"; }

# Descarga zip y .sha256 (y el .sig si hay llave pública) de la versión pedida, los valida y deja todo en $REL_DIR.
fetch_release() {
    [ -n "$REL_DIR" ] && return 0
    command -v jq >/dev/null || "${APT[@]}" install jq >/dev/null
    local rel url name
    if [ "$VERSION" = latest ]; then
        rel=$(api_get "$API/repos/$REPO/releases/latest") || die "No se pudo consultar la última versión en $API ($REPO)."
    else
        rel=$(api_get "$API/repos/$REPO/releases/tags/v$VERSION") || die "No existe la versión v$VERSION en $REPO."
    fi
    REL_VERSION=$(jq -r '.tag_name' <<<"$rel")
    REL_VERSION=${REL_VERSION#v}
    [[ $REL_VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Tag de versión inválido: $REL_VERSION"
    REL_DIR=$WORK/release
    mkdir -p "$REL_DIR"
    local names=("growth-$REL_VERSION.zip" "growth-$REL_VERSION.zip.sha256")
    # La firma es opcional (docs/despliegue.md §3): solo se exige si hay llave pública.
    [ -n "$PUBKEY_PEM" ] && names+=("growth-$REL_VERSION.zip.sha256.sig")
    for name in "${names[@]}"; do
        url=$(jq -r --arg n "$name" '.assets[] | select(.name == $n) | .browser_download_url' <<<"$rel")
        if [ -z "$url" ] || [ "$url" = null ]; then die "La versión $REL_VERSION no trae $name."; fi
        curl -fsSL --retry 3 -o "$REL_DIR/$name" "$url"
    done
    if [ -n "$PUBKEY_PEM" ]; then
        printf '%s\n' "$PUBKEY_PEM" >"$WORK/release-signing.pub.pem"
        openssl pkeyutl -verify -pubin -inkey "$WORK/release-signing.pub.pem" -rawin \
            -in "$REL_DIR/growth-$REL_VERSION.zip.sha256" -sigfile "$REL_DIR/growth-$REL_VERSION.zip.sha256.sig" >/dev/null \
            || die "La firma de growth-$REL_VERSION.zip no es válida."
    fi
    (cd "$REL_DIR" && sha256sum --check --status "growth-$REL_VERSION.zip.sha256") || die "El sha256 de growth-$REL_VERSION.zip no coincide."
    if [ -n "$PUBKEY_PEM" ]; then
        info "Versión $REL_VERSION descargada; firma y sha256 válidos."
    else
        info "Versión $REL_VERSION descargada; sha256 válido (sin firma)."
    fi
}

# ── 2. Paquetes base y repositorios oficiales ───────────────────────────

log "Paquetes base"
"${APT[@]}" update >/dev/null
"${APT[@]}" install ca-certificates curl gnupg openssl unzip jq zstd ufw rclone openssh-client python3 python3-venv procps >/dev/null

if [ -z "$TPL" ]; then
    fetch_release
    unzip -q "$REL_DIR/growth-$REL_VERSION.zip" 'config/*' 'systemd/*' 'caddy/*' 'bin/*' 'backup/*' -d "$WORK/tpl"
    TPL=$WORK/tpl
fi
info "Plantillas: $TPL"

log "Repositorios oficiales"
install -d -m 0755 /etc/apt/keyrings
add_key() { # url destino dearmor(0/1)
    [ -s "$2" ] && return 0
    if [ "$3" = 1 ]; then curl -fsSL "$1" | gpg --dearmor -o "$2" --yes; else curl -fsSL "$1" -o "$2"; fi
    chmod 0644 "$2"
}
# MariaDB LTS (script oficial de configuración del repositorio): 11.4 en 24.04, 11.8 en 26.04.
if ! grep -qs "/$MARIADB_SERIES/" /etc/apt/sources.list.d/mariadb.sources /etc/apt/sources.list.d/mariadb.list; then
    curl -fsSL https://r.mariadb.com/downloads/mariadb_repo_setup -o "$WORK/mariadb_repo_setup"
    bash "$WORK/mariadb_repo_setup" --mariadb-server-version="mariadb-$MARIADB_SERIES" --skip-maxscale --skip-tools >/dev/null
fi
# ClickHouse 25.8 (LTS), fijado a 25.8.*.
add_key https://packages.clickhouse.com/rpm/lts/repodata/repomd.xml.key /etc/apt/keyrings/clickhouse.gpg 1
put_content /etc/apt/sources.list.d/clickhouse.list <<EOF || true
deb [signed-by=/etc/apt/keyrings/clickhouse.gpg arch=$ARCH] https://packages.clickhouse.com/deb stable main
EOF
put_content /etc/apt/preferences.d/growth-clickhouse <<'EOF' || true
Package: clickhouse-server clickhouse-client clickhouse-common-static clickhouse-keeper
Pin: version 25.8.*
Pin-Priority: 1001
EOF
# Redis (repositorio oficial de Redis), fijado por sistema: 7.4.* en 24.04; 8.* en 26.04, donde no hay Redis 7.
add_key https://packages.redis.io/gpg /etc/apt/keyrings/redis.gpg 1
put_content /etc/apt/sources.list.d/redis.list <<EOF || true
deb [signed-by=/etc/apt/keyrings/redis.gpg] https://packages.redis.io/deb ${OS_CODENAME:-noble} main
EOF
put_content /etc/apt/preferences.d/growth-redis <<EOF || true
Package: redis redis-server redis-tools
Pin: version $REDIS_PIN
Pin-Priority: 1001
EOF
# FrankenPHP y PHP 8.4 ZTS (repositorio oficial de FrankenPHP para Debian y Ubuntu, línea 84).
add_key https://pkg.henderkes.com/api/packages/84/debian/repository.key /etc/apt/keyrings/static-php84.asc 0
put_content /etc/apt/sources.list.d/static-php84.list <<'EOF' || true
deb [signed-by=/etc/apt/keyrings/static-php84.asc] https://pkg.henderkes.com/api/packages/84/debian php-zts main
EOF
# Caddy (repositorio oficial).
add_key https://dl.cloudsmith.io/public/caddy/stable/gpg.key /etc/apt/keyrings/caddy-stable.gpg 1
put_content /etc/apt/sources.list.d/caddy-stable.list <<'EOF' || true
deb [signed-by=/etc/apt/keyrings/caddy-stable.gpg] https://dl.cloudsmith.io/public/caddy/stable/deb/debian any-version main
EOF

log "Paquetes: MariaDB, ClickHouse, Redis, FrankenPHP con PHP 8.4, Caddy"
"${APT[@]}" update >/dev/null
"${APT[@]}" install mariadb-server mariadb-client mariadb-backup \
    clickhouse-server clickhouse-client \
    redis-server redis-tools \
    frankenphp php-zts-cli php-zts-pdo-mysql php-zts-mysqlnd php-zts-redis php-zts-intl php-zts-zip \
    php-zts-gd php-zts-gmp php-zts-bcmath \
    caddy >/dev/null
# Los servicios que traen los paquetes y que no usamos: FrankenPHP propio (tomaría el 80 y el 443) y el
# Redis de una sola instancia (usamos redis-server@queue y redis-server@cache).
for unit in frankenphp.service redis-server.service; do
    systemctl disable --now "$unit" >/dev/null 2>&1 || true
    systemctl mask "$unit" >/dev/null 2>&1 || true
done
info "MariaDB $(mariadb --version | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1) · ClickHouse $(clickhouse-server --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | head -1) · Redis $(redis-server --version | grep -oE 'v=[0-9.]+' | cut -d= -f2) · $(frankenphp version | cut -d' ' -f1-4) · Caddy $(caddy version | cut -d' ' -f1)"

log "uv (Python para el servicio de predicciones)"
if ! command -v uv >/dev/null 2>&1; then
    if ! curl -fsSL https://astral.sh/uv/install.sh | env UV_INSTALL_DIR=/usr/local/bin UV_NO_MODIFY_PATH=1 INSTALLER_NO_MODIFY_PATH=1 sh >/dev/null 2>&1; then
        # Respaldo: la misma herramienta publicada por Astral en PyPI, en un entorno aparte.
        warn "El instalador de Astral no respondió; se instala uv desde PyPI."
        python3 -m venv /usr/local/lib/growth-uv
        /usr/local/lib/growth-uv/bin/pip install --quiet uv
        ln -sfn /usr/local/lib/growth-uv/bin/uv /usr/local/bin/uv
    fi
fi
info "$(uv --version)"

log "PHP de línea de comandos"
PHP_BIN=/usr/bin/php-zts
[ -x "$PHP_BIN" ] || die "No se encontró $PHP_BIN (paquete php-zts-cli)."
ln -sfn "$PHP_BIN" /usr/local/bin/growth-php
put_file "$TPL/config/php/90-growth.ini" /etc/php-zts/conf.d/90-growth.ini || true
missing=""
# La lista se lee una sola vez: «php -m | grep -q» con pipefail falla al azar (grep termina antes y PHP recibe SIGPIPE).
PHP_MODULES=$(growth-php -m)
for ext in pdo_mysql redis intl zip pcntl 'Zend OPcache' sockets gd exif gmp bcmath sodium; do
    grep -qx "$ext" <<<"$PHP_MODULES" || missing="$missing $ext"
done
[ -z "$missing" ] || die "Faltan extensiones de PHP:$missing"
info "$(growth-php -v | head -1)"

# ── 3. Usuario y estructura ─────────────────────────────────────────────

log "Usuario growth y estructura en $GROWTH_ROOT"
if ! id growth >/dev/null 2>&1; then
    useradd --system --user-group --home-dir "$SHARED" --no-create-home --shell /usr/sbin/nologin growth
fi
# Las versiones son de root y de solo lectura para la app; solo shared/ es del usuario growth.
install -d -m 0755 -o root -g root "$GROWTH_ROOT" "$GROWTH_ROOT/releases" "$ETC_DIR" "$LIB_DIR" "$LIB_DIR/bin" "$LIB_DIR/backup"
install -d -m 0755 -o growth -g growth "$SHARED"
for d in storage storage/app storage/app/public storage/app/private storage/framework storage/framework/cache \
    storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    backups backups/pre-update tmp cache cache/uv python run; do
    install -d -m 0750 -o growth -g growth "$SHARED/$d"
done
# shared/state: el panel deja pedidos; lo que escribe root (estado e historial de actualizaciones) no lo puede
# tocar «growth» (sticky). El actualizador baja y descomprime en una carpeta solo de root.
install -d -m 1775 -o root -g growth "$SHARED/state"
install -d -m 0700 -o root -g root /var/lib/growth-update
install -d -m 0711 -o root -g root "$BACKUP_DIR"
install -d -m 0700 -o root -g root "$BACKUP_DIR/mariadb"

# Scripts que corren como root (actualizador y copias): nunca desde una ruta que growth pueda escribir.
install_root_scripts() { # carpeta de origen (raíz del repo infra/ o del zip verificado)
    local src=$1 f n=0
    for f in growth-update growth-update-dispatch growth-env; do
        [ -f "$src/bin/$f" ] || continue
        put_file "$src/bin/$f" "$LIB_DIR/bin/$f" 0755 root:root || true
        n=$((n + 1))
    done
    if [ -d "$src/backup" ]; then
        for f in "$src"/backup/growth-backup-*; do
            [ -f "$f" ] || continue
            put_file "$f" "$LIB_DIR/backup/$(basename "$f")" 0755 root:root || true
        done
    fi
    [ "$n" = 3 ] || warn "Faltan scripts del actualizador en $src/bin ($n de 3)."
}
install_root_scripts "$TPL"
info "Scripts de root en $LIB_DIR (bin y backup)."

# ── 4. Configuración de la app (.env) ───────────────────────────────────

log "Configuración de la app ($ENV_FILE)"
if [ -z "$DOMAIN" ]; then
    url=$(env_get APP_URL)
    DOMAIN=${url#*://}
    DOMAIN=${DOMAIN%%/*}
fi
[ -n "$DOMAIN" ] || die "Falta --domain (la primera vez es obligatorio)."
[[ $DOMAIN =~ ^[A-Za-z0-9.-]+$ ]] || die "Dominio inválido: $DOMAIN"
NEW_ENV=0
if [ ! -f "$ENV_FILE" ]; then
    install -m 0600 -o growth -g growth /dev/null "$ENV_FILE"
    printf '# Configuración de Imagina Growth. La creó infra/install.sh; no se versiona. docs/despliegue.md §2.\n' >"$ENV_FILE"
    NEW_ENV=1
fi
vapid_pair() {
    # VAPID (P-256): la pública es el punto sin comprimir (65 bytes) y la privada el escalar (32 bytes), en base64url.
    local pem=$WORK/vapid.pem
    openssl ecparam -name prime256v1 -genkey -noout -out "$pem" 2>/dev/null
    VAPID_PUBLIC=$(openssl ec -in "$pem" -pubout -outform DER 2>/dev/null | tail -c 65 | base64 -w0 | tr '+/' '-_' | tr -d '=')
    VAPID_PRIVATE=$(openssl ec -in "$pem" -outform DER 2>/dev/null | head -c 39 | tail -c 32 | base64 -w0 | tr '+/' '-_' | tr -d '=')
}
env_add APP_NAME "Imagina Growth"
env_add APP_ENV production
env_has APP_KEY || env_add APP_KEY "$(rand_b64key)"
env_add APP_DEBUG false
env_add APP_URL "https://$DOMAIN"
env_add APP_LOCALE es
env_add APP_FALLBACK_LOCALE es
env_add APP_FAKER_LOCALE es_CO
env_add IGR_APP_URL "https://$DOMAIN"
# Todo en el dominio principal: la ingesta (/v1, /p) y los enlaces (/c, /u, /preferencias) no necesitan
# subdominios ni registros DNS extra. Quien quiera separarlos los cambia aquí y agrega el DNS y el bloque de Caddy.
env_add IGR_INGEST_URL "https://$DOMAIN"
env_add IGR_LINKS_URL "https://$DOMAIN"
env_add IGR_CUSTOM_DOMAINS_CNAME "$DOMAIN"
# Las instalaciones anteriores usaban in., go. y domains. del dominio: si esos nombres no existen en el DNS, se pasa
# al dominio principal (si existen, se respetan).
URLS_CHANGED=0
move_off_subdomain() { # clave valor_viejo valor_nuevo host
    [ "$(env_get "$1")" = "$2" ] || return 0
    getent ahosts "$4" >/dev/null 2>&1 && return 0
    sed -i "s|^$1=.*|$1=$3|" "$ENV_FILE"
    URLS_CHANGED=1
    info "$4 no existe en el DNS: $1 pasa a $3."
}
move_off_subdomain IGR_INGEST_URL "https://in.$DOMAIN" "https://$DOMAIN" "in.$DOMAIN"
move_off_subdomain IGR_LINKS_URL "https://go.$DOMAIN" "https://$DOMAIN" "go.$DOMAIN"
move_off_subdomain IGR_CUSTOM_DOMAINS_CNAME "domains.$DOMAIN" "$DOMAIN" "domains.$DOMAIN"
env_has IGR_BLIND_INDEX_KEY || env_add IGR_BLIND_INDEX_KEY "$(rand_b64key)"
env_add IGR_REQUIRE_2FA true
env_add IGR_INGEST_SHARDS 4
env_add BCRYPT_ROUNDS 12
env_add LOG_CHANNEL stack
env_add LOG_STACK stderr
env_add LOG_LEVEL info
env_add DB_CONNECTION mariadb
env_add DB_HOST 127.0.0.1
env_add DB_PORT 3306
env_add DB_DATABASE igr
env_add DB_USERNAME igr
env_has DB_PASSWORD || env_add DB_PASSWORD "$(rand_hex 24)"
env_add CLICKHOUSE_URL http://127.0.0.1:8123
env_add CLICKHOUSE_DATABASE igr
env_add CLICKHOUSE_USERNAME igr
env_has CLICKHOUSE_PASSWORD || env_add CLICKHOUSE_PASSWORD "$(rand_hex 24)"
env_add SESSION_DRIVER redis
env_add SESSION_LIFETIME 120
env_add SESSION_ENCRYPT true
env_add SESSION_PATH /
env_add SESSION_DOMAIN null
env_add SESSION_SECURE_COOKIE true
env_add SANCTUM_STATEFUL_DOMAINS "$DOMAIN"
env_add BROADCAST_CONNECTION reverb
env_has REVERB_APP_ID || env_add REVERB_APP_ID "$((100000 + RANDOM * 30 % 900000))"
env_has REVERB_APP_KEY || env_add REVERB_APP_KEY "$(rand_alnum 20)"
env_has REVERB_APP_SECRET || env_add REVERB_APP_SECRET "$(rand_alnum 32)"
env_add REVERB_SERVER_HOST 127.0.0.1
env_add REVERB_SERVER_PORT 8085
env_add REVERB_HOST 127.0.0.1
env_add REVERB_PORT 8085
env_add REVERB_SCHEME http
env_add FILESYSTEM_DISK local
env_add QUEUE_CONNECTION redis
env_add CACHE_STORE redis
env_add REDIS_CLIENT phpredis
env_add REDIS_HOST 127.0.0.1
env_add REDIS_PORT 6379
env_has REDIS_PASSWORD || env_add REDIS_PASSWORD "$(rand_hex 24)"
env_add REDIS_CACHE_HOST 127.0.0.1
env_add REDIS_CACHE_PORT 6380
env_add MAIL_MAILER log
env_add MAIL_FROM_ADDRESS "hola@$DOMAIN"
env_add MAIL_FROM_NAME "Imagina Growth"
env_add IGR_ML_URL http://127.0.0.1:8095
env_has IGR_ML_TOKEN || env_add IGR_ML_TOKEN "$(rand_hex 32)"
if ! env_has IGR_PUSH_VAPID_PUBLIC_KEY || ! env_has IGR_PUSH_VAPID_PRIVATE_KEY; then
    vapid_pair
    env_add IGR_PUSH_VAPID_PUBLIC_KEY "$VAPID_PUBLIC"
    env_add IGR_PUSH_VAPID_PRIVATE_KEY "$VAPID_PRIVATE"
fi
env_add IGR_ANTHROPIC_API_KEY ""
env_add GROWTH_UPDATE_REPO "$REPO"
env_add GROWTH_UPDATE_API "$API"
chown growth:growth "$ENV_FILE"
chmod 0600 "$ENV_FILE"
if [ "$NEW_ENV" = 1 ]; then
    info "Creado con secretos nuevos."
elif [ ${#ENV_ADDED[@]} -gt 0 ]; then
    info "Se agregaron variables que faltaban: ${ENV_ADDED[*]}"
else
    info "Sin cambios."
fi
if [ "$PROFILE" = small ]; then
    info "Perfil de procesos: pequeño ($MEM_MB MB de RAM). Con 4 GB o más, volver a correr el instalador pasa al estándar."
else
    info "Perfil de procesos: estándar ($MEM_MB MB de RAM)."
fi


# ── 5. Actualizador: repositorio y llave ────────────────────────────────

log "Actualizador ($ETC_DIR)"
if [ ! -f "$ETC_DIR/updater.conf" ] || [ "$REPO_GIVEN" = 1 ] || [ "$API_GIVEN" = 1 ]; then
    cur_timeout=120
    if [ -f "$ETC_DIR/updater.conf" ]; then
        cur_timeout=$(grep -E '^GROWTH_HEALTH_TIMEOUT=' "$ETC_DIR/updater.conf" | cut -d= -f2 || true)
        [ "$REPO_GIVEN" = 1 ] || REPO=$(grep -E '^GROWTH_UPDATE_REPO=' "$ETC_DIR/updater.conf" | cut -d= -f2)
        [ "$API_GIVEN" = 1 ] || API=$(grep -E '^GROWTH_UPDATE_API=' "$ETC_DIR/updater.conf" | cut -d= -f2)
    fi
    put_content "$ETC_DIR/updater.conf" 0644 root:root <<EOF || true
# Repositorio y API de las versiones de Imagina Growth. Solo root lo puede cambiar: el actualizador descarga
# con estos valores y valida con release-signing.pub.pem (docs/despliegue.md §5).
GROWTH_UPDATE_REPO=$REPO
GROWTH_UPDATE_API=$API
GROWTH_HEALTH_TIMEOUT=${cur_timeout:-120}
EOF
fi
if [ -n "$PUBKEY_PEM" ]; then
    if [ -f "$ETC_DIR/release-signing.pub.pem" ] && ! printf '%s\n' "$PUBKEY_PEM" | cmp -s - "$ETC_DIR/release-signing.pub.pem" && [ -z "$PUBKEY_FILE" ]; then
        warn "La llave pública instalada es distinta de la de este instalador; se conserva la instalada (usa --pubkey para cambiarla)."
    else
        printf '%s\n' "$PUBKEY_PEM" | put_content "$ETC_DIR/release-signing.pub.pem" 0644 root:root || true
    fi
    openssl pkey -pubin -in "$ETC_DIR/release-signing.pub.pem" -noout 2>/dev/null || die "La llave pública no es válida."
    info "Llave pública de las versiones instalada."
else
    info "Sin llave pública: las versiones se validan solo con sha256. Para exigir firma, instala $ETC_DIR/release-signing.pub.pem (--pubkey)."
fi
# Copias (infra/backup): la configuración es del administrador; solo se crea si falta.
if [ ! -f "$ETC_DIR/backup.conf" ] && [ -f "$TPL/backup/backup.conf.example" ]; then
    install -m 0600 -o root -g root "$TPL/backup/backup.conf.example" "$ETC_DIR/backup.conf"
    info "Creado $ETC_DIR/backup.conf (el destino de las copias se configura en Plataforma → Copias de seguridad)."
fi

# ── 6. Configuración del sistema (la misma que aplica cada actualización) ─

log "Configuración del sistema (memoria, Redis, MariaDB, ClickHouse)"
# bin/growth-system es el único lugar donde vive esta configuración: el botón de actualizar del panel la aplica
# en cada versión, así que nunca hace falta volver a correr el instalador para recibirla (docs/despliegue.md §7).
install -d -m 0750 -o redis -g redis /var/lib/redis-queue /var/lib/redis-cache
# Una RAM fijada a mano (GROWTH_MEMORY_MB) queda en updater.conf: así cada actualización usa la misma.
if [ -n "${GROWTH_MEMORY_MB:-}" ] && ! grep -qx "GROWTH_MEMORY_MB=$GROWTH_MEMORY_MB" "$ETC_DIR/updater.conf" 2>/dev/null; then
    sed -i '/^GROWTH_MEMORY_MB=/d' "$ETC_DIR/updater.conf" 2>/dev/null || true
    printf 'GROWTH_MEMORY_MB=%s\n' "$GROWTH_MEMORY_MB" >>"$ETC_DIR/updater.conf"
fi
SYSTEM_OUT=$(GROWTH_SYSTEM_ROOT="$TPL" GROWTH_ROOT="$GROWTH_ROOT" GROWTH_ENV_FILE="$ENV_FILE" GROWTH_ETC="$ETC_DIR" \
    GROWTH_MEMORY_MB="$MEM_MB" PHP=/usr/local/bin/growth-php bash "$TPL/bin/growth-system" apply --install 2>&1) \
    || die "No se pudo aplicar la configuración del sistema: $SYSTEM_OUT"
info "$SYSTEM_OUT"
PROFILE_CHANGED=0
case "$SYSTEM_OUT" in *"perfil de procesos"* | *"workers de ingesta"*) PROFILE_CHANGED=1 ;; esac

# ── 7. MariaDB ──────────────────────────────────────────────────────────

log "MariaDB"
systemctl enable --now mariadb >/dev/null 2>&1
wait_for MariaDB mariadb-admin ping
DB_PASS=$(env_get DB_PASSWORD)
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS igr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'igr'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS 'igr'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON igr.* TO 'igr'@'localhost';
GRANT ALL PRIVILEGES ON igr.* TO 'igr'@'127.0.0.1';
SQL
if ! MYSQL_PWD=$DB_PASS mariadb -h 127.0.0.1 -u igr igr -e 'SELECT 1' >/dev/null 2>&1; then
    # El usuario ya existía con otra contraseña (p. ej. se borró el .env): manda el .env.
    warn "La contraseña de MariaDB no coincidía con el .env; se actualizó."
    mariadb -e "ALTER USER 'igr'@'localhost' IDENTIFIED BY '$DB_PASS'; ALTER USER 'igr'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';"
fi
info "Base igr y usuario igr listos."

# ── 8. ClickHouse ───────────────────────────────────────────────────────

log "ClickHouse"
install -d -m 0700 -o clickhouse -g clickhouse "$BACKUP_DIR/clickhouse"
# La configuración, la memoria y los usuarios de ClickHouse los escribe growth-system (sección 6), que también lo
# reinicia si ya corría y la configuración cambió.
CH_PASS=$(env_get CLICKHOUSE_PASSWORD)
systemctl enable --now clickhouse-server >/dev/null 2>&1
wait_for ClickHouse curl -fsS http://127.0.0.1:8123/ping
ch_query() { curl -fsS -u "igr:$CH_PASS" http://127.0.0.1:8123/ --data-binary "$1"; }
wait_for "ClickHouse (usuario igr)" ch_query 'SELECT 1'
ch_query 'CREATE DATABASE IF NOT EXISTS igr' >/dev/null
info "Base igr y usuario igr listos."

# ── 9. Redis: colas y caché ─────────────────────────────────────────────

log "Redis (colas en 6379 y caché en 6380)"
REDIS_PASS=$(env_get REDIS_PASSWORD)
for inst in queue cache; do
    systemctl enable --now "redis-server@$inst" >/dev/null 2>&1
done
for port in 6379 6380; do
    wait_for "Redis $port" env REDISCLI_AUTH="$REDIS_PASS" redis-cli -p "$port" ping
done
info "Colas: $(REDISCLI_AUTH=$REDIS_PASS redis-cli -p 6379 config get maxmemory-policy | tail -1) + AOF (maxmemory $(REDISCLI_AUTH=$REDIS_PASS redis-cli -p 6379 config get maxmemory | tail -1)) · caché: $(REDISCLI_AUTH=$REDIS_PASS redis-cli -p 6380 config get maxmemory-policy | tail -1)"

# ── 10. Servicios de systemd ─────────────────────────────────────────────

log "Servicios de systemd"
for f in "$TPL"/systemd/growth*; do
    put_file "$f" "/etc/systemd/system/$(basename "$f")" || true
done
systemctl daemon-reload
systemctl enable --now growth-update.path >/dev/null 2>&1
# Pedidos de Plataforma → Copias de seguridad (destino, prueba, copia ahora): los atiende growth-backup-dispatch.
systemctl enable --now growth-backup.path >/dev/null 2>&1
# growth.target se habilita al final, cuando hay una versión instalada (sin ella los servicios no arrancan).
info "Unidades instaladas; growth-update.path y growth-backup.path habilitados."

# ── 11. Caddy ───────────────────────────────────────────────────────────

log "Caddy"
EMAIL_LINE=""
[ -n "$EMAIL" ] && EMAIL_LINE="email $EMAIL"
# Rangos de Cloudflare: los que ya descargó growth-system (paso 6) o los del paquete. Después los renueva
# growth-system en cada actualización, igual que el resto del Caddyfile mientras nadie lo edite a mano.
CF_RANGES=$(grep -hE '^[0-9a-fA-F:.]+/[0-9]{1,3}$' /var/lib/growth-system/cloudflare-ips 2>/dev/null || grep -hE '^[0-9a-fA-F:.]+/[0-9]{1,3}$' "$TPL/caddy/cloudflare-ips.txt")
CF_RANGES=$(tr '\n' ' ' <<<"$CF_RANGES" | sed -E 's/ +$//')
sed -e "s/__DOMAIN__/$DOMAIN/g" -e "s/__EMAIL_LINE__/$EMAIL_LINE/" -e "s|__TRUSTED_PROXIES__|$CF_RANGES|" "$TPL/caddy/Caddyfile" >"$WORK/Caddyfile"
caddy fmt --overwrite "$WORK/Caddyfile" >/dev/null 2>&1 || true
CADDY_DEST=/etc/caddy/Caddyfile
if [ ! -f "$CADDY_DEST" ] || grep -q 'root \* /usr/share/caddy' "$CADDY_DEST"; then
    caddy validate --adapter caddyfile --config "$WORK/Caddyfile" >/dev/null 2>&1 || die "El Caddyfile generado no es válido."
    install -m 0644 "$WORK/Caddyfile" "$CADDY_DEST"
    systemctl enable caddy >/dev/null 2>&1
    systemctl restart caddy
    info "Caddyfile instalado para $DOMAIN (panel, ingesta y enlaces en el mismo dominio)."
elif [ "$DOMAIN_GIVEN" = 1 ] && ! cmp -s "$WORK/Caddyfile" "$CADDY_DEST"; then
    install -m 0644 "$WORK/Caddyfile" "$CADDY_DEST.growth.new"
    warn "Ya hay un $CADDY_DEST; no se toca. La versión para --domain/--email quedó en $CADDY_DEST.growth.new."
    systemctl enable --now caddy >/dev/null 2>&1
else
    systemctl enable --now caddy >/dev/null 2>&1
    info "Sin cambios (el Caddyfile instalado es del administrador)."
fi

# ── 12. Firewall ────────────────────────────────────────────────────────

if [ "$SKIP_FIREWALL" = 1 ]; then
    log "Firewall: omitido (--skip-firewall)"
else
    log "Firewall (ufw)"
    ssh_ports=$( (command -v sshd >/dev/null && sshd -T 2>/dev/null | awk '$1 == "port" {print $2}') || true)
    [ -n "$ssh_ports" ] || ssh_ports=22
    for p in $ssh_ports; do ufw allow "$p/tcp" >/dev/null; done
    ufw allow 80/tcp >/dev/null
    ufw allow 443/tcp >/dev/null
    ufw allow 443/udp >/dev/null
    ufw default deny incoming >/dev/null
    ufw default allow outgoing >/dev/null
    ufw --force enable >/dev/null
    info "Abiertos: SSH ($(echo "$ssh_ports" | tr '\n' ' ' | xargs)), 80/tcp, 443/tcp y 443/udp."
fi

# ── 13. La app ──────────────────────────────────────────────────────────

if [ "$NO_RELEASE" = 1 ]; then
    log "La app: omitida (--no-release)"
elif [ -L "$GROWTH_ROOT/current" ]; then
    log "La app"
    info "Ya está instalada la versión $(basename "$(readlink -f "$GROWTH_ROOT/current")"); las actualizaciones se hacen desde el panel."
    [ "$VERSION_GIVEN" = 1 ] && warn "Se ignora --version: el instalador no reemplaza una versión instalada."
    # La caché de configuración se rearma en cada corrida (el .env pudo cambiar, p. ej. el perfil), como lo hace
    # growth-update: bootstrap/cache se presta a «growth» solo mientras tanto y vuelve a root.
    CACHE_DIR=$(readlink -f "$GROWTH_ROOT/current")/hub/bootstrap/cache
    chown growth:growth "$CACHE_DIR"
    (cd "$GROWTH_ROOT/current/hub" && runuser -u growth -- env HOME=/tmp /usr/local/bin/growth-php artisan config:cache --no-interaction >/dev/null) \
        || warn "No se pudo rearmar la caché de configuración; corre el instalador otra vez."
    find "$CACHE_DIR" -mindepth 1 ! -type f -exec rm -rf -- {} +
    chown -R -h root:root "$CACHE_DIR"
    chmod -R u+rwX,go+rX,go-w "$CACHE_DIR"
    if [ "$PROFILE_CHANGED" = 1 ] || [ "$URLS_CHANGED" = 1 ]; then
        if systemctl is-active --quiet growth.target; then
            systemctl restart growth-horizon.service growth-scheduler.service growth-ingest@1.service
            [ "$PROFILE" = standard ] && systemctl start growth-ingest@2.service growth-ingest@3.service growth-ingest@4.service
        fi
        info "Configuración aplicada (perfil de procesos: $PROFILE)."
    fi
else
    log "La app"
    fetch_release
    # Los scripts de root salen del zip ya verificado (firma y sha256) de la versión que se instala.
    unzip -q -o "$REL_DIR/growth-$REL_VERSION.zip" 'bin/*' 'backup/*' -d "$WORK/rel-scripts"
    install_root_scripts "$WORK/rel-scripts"
    [ -x "$LIB_DIR/bin/growth-update" ] || die "La versión $REL_VERSION no trae bin/growth-update."
    # El actualizador vuelve a validar y hace la instalación completa (docs/despliegue.md §5, modo --install).
    GROWTH_ASSETS_DIR="$REL_DIR" "$LIB_DIR/bin/growth-update" --install "$REL_VERSION"
fi

# Copias nocturnas y su verificación: corren desde los scripts de root, haya o no una versión instalada.
for t in growth-backup-mariadb.timer growth-backup-clickhouse.timer growth-backup-verify.timer; do
    [ -e "/etc/systemd/system/$t" ] && systemctl enable --now "$t" >/dev/null 2>&1
done
if [ -L "$GROWTH_ROOT/current" ]; then
    # --now: si se apagó la app para correr el instalador (p. ej. sin memoria), vuelve a arrancar.
    systemctl enable --now growth.target >/dev/null 2>&1
    # La vigilancia usa la app (artisan growth:monitor): solo con una versión instalada.
    [ -e /etc/systemd/system/growth-monitor.timer ] && systemctl enable --now growth-monitor.timer >/dev/null 2>&1
fi

log "Listo"
# DNS: sin estos registros el panel no abre o los sitios no pueden enviar datos (el plugin muestra
# «Could not resolve host: in.…»). Solo se avisa: el instalador no toca el DNS.
MISSING_DNS=()
for url in "https://$DOMAIN" "$(env_get IGR_INGEST_URL)" "$(env_get IGR_LINKS_URL)"; do
    host=${url#*://}
    host=${host%%/*}
    [ -n "$host" ] || continue
    [[ " ${MISSING_DNS[*]} " == *" $host "* ]] && continue
    getent ahosts "$host" >/dev/null 2>&1 || MISSING_DNS+=("$host")
done
if [ ${#MISSING_DNS[@]} -gt 0 ]; then
    warn "Estos nombres no tienen registro DNS: ${MISSING_DNS[*]}. Crea un registro A hacia la IP de este servidor para cada uno."
fi
info "Panel, ingesta y enlaces: https://$DOMAIN"
info "Configuración: $ENV_FILE (solo el usuario growth) · actualizador: $ETC_DIR/updater.conf"
info "Copias a la Storage Box: se configuran en el panel, Plataforma → Copias de seguridad (infra/backup/README.md)."
