#!/usr/bin/env bash
# Bases de datos para desarrollar Imagina Growth sin Docker (infra/dev/README.md): MariaDB 11.4,
# ClickHouse 25.8 y Redis 7 nativos, con los usuarios y bases de desarrollo y de pruebas.
#
#   Ubuntu o Debian:  sudo infra/dev/setup-local.sh      (repositorios oficiales, igual que producción)
#   macOS:            infra/dev/setup-local.sh           (Homebrew)
#
# Crea: MariaDB igr/igr con las bases igr, igr_test, wp_igr y wp_igr_tests (y permiso para crear igr_* y
# wp_igr_*, que usan las pruebas en paralelo); ClickHouse igr/igr con la base igr; Redis en 6379 sin
# contraseña. Al final imprime los valores para hub/.env. Correrlo otra vez no cambia nada.
set -euo pipefail

log() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die() { printf '\033[31m[error]\033[0m %s\n' "$*" >&2; exit 1; }

DEV_SQL=$(cat <<'SQL'
CREATE DATABASE IF NOT EXISTS igr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS igr_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS wp_igr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS wp_igr_tests CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'igr'@'localhost' IDENTIFIED BY 'igr';
CREATE USER IF NOT EXISTS 'igr'@'127.0.0.1' IDENTIFIED BY 'igr';
GRANT ALL PRIVILEGES ON `igr%`.* TO 'igr'@'localhost';
GRANT ALL PRIVILEGES ON `igr%`.* TO 'igr'@'127.0.0.1';
GRANT ALL PRIVILEGES ON `wp\_igr%`.* TO 'igr'@'localhost';
GRANT ALL PRIVILEGES ON `wp\_igr%`.* TO 'igr'@'127.0.0.1';
SQL
)

CH_USERS=$(cat <<'XML'
<!-- Usuario de desarrollo de Imagina Growth (infra/dev/setup-local.sh). Solo para la máquina local. -->
<clickhouse>
    <users>
        <igr>
            <password>igr</password>
            <networks><ip>127.0.0.1</ip><ip>::1</ip></networks>
            <profile>default</profile>
            <quota>default</quota>
            <access_management>1</access_management>
        </igr>
    </users>
</clickhouse>
XML
)

ch_wait() {
    for _ in $(seq 1 60); do curl -fsS http://127.0.0.1:8123/ping >/dev/null 2>&1 && return 0; sleep 1; done
    die "ClickHouse no responde en 127.0.0.1:8123."
}

setup_debian() {
    [ "$(id -u)" -eq 0 ] || die "En Linux corre el script con sudo."
    export DEBIAN_FRONTEND=noninteractive
    local codename arch
    codename=$(grep -E '^VERSION_CODENAME=' /etc/os-release | cut -d= -f2)
    arch=$(dpkg --print-architecture)
    log "Repositorios oficiales (MariaDB 11.4, ClickHouse 25.8, Redis 7.4)"
    apt-get update -q >/dev/null
    apt-get install -y -q ca-certificates curl gnupg >/dev/null
    install -d -m 0755 /etc/apt/keyrings
    if ! grep -qs "11.4" /etc/apt/sources.list.d/mariadb.sources /etc/apt/sources.list.d/mariadb.list; then
        curl -fsSL https://r.mariadb.com/downloads/mariadb_repo_setup | bash -s -- --mariadb-server-version=mariadb-11.4 --skip-maxscale --skip-tools >/dev/null
    fi
    [ -s /etc/apt/keyrings/clickhouse.gpg ] || curl -fsSL https://packages.clickhouse.com/rpm/lts/repodata/repomd.xml.key | gpg --dearmor -o /etc/apt/keyrings/clickhouse.gpg
    echo "deb [signed-by=/etc/apt/keyrings/clickhouse.gpg arch=$arch] https://packages.clickhouse.com/deb stable main" >/etc/apt/sources.list.d/clickhouse.list
    printf 'Package: clickhouse-server clickhouse-client clickhouse-common-static\nPin: version 25.8.*\nPin-Priority: 1001\n' >/etc/apt/preferences.d/growth-clickhouse
    [ -s /etc/apt/keyrings/redis.gpg ] || curl -fsSL https://packages.redis.io/gpg | gpg --dearmor -o /etc/apt/keyrings/redis.gpg
    echo "deb [signed-by=/etc/apt/keyrings/redis.gpg] https://packages.redis.io/deb $codename main" >/etc/apt/sources.list.d/redis.list
    printf 'Package: redis redis-server redis-tools\nPin: version 6:7.4.*\nPin-Priority: 1001\n' >/etc/apt/preferences.d/growth-redis
    apt-get update -q >/dev/null
    log "Paquetes"
    apt-get install -y -q mariadb-server mariadb-client clickhouse-server clickhouse-client redis-server >/dev/null
    systemctl enable --now mariadb redis-server >/dev/null 2>&1

    log "MariaDB: usuario igr y bases de desarrollo y pruebas"
    mariadb <<<"$DEV_SQL"

    log "ClickHouse: usuario igr y base igr"
    printf '%s\n' "$CH_USERS" >/etc/clickhouse-server/users.d/growth-dev.xml
    chown root:clickhouse /etc/clickhouse-server/users.d/growth-dev.xml
    chmod 0640 /etc/clickhouse-server/users.d/growth-dev.xml
    systemctl enable clickhouse-server >/dev/null 2>&1
    systemctl restart clickhouse-server
    ch_wait
    curl -fsS -u igr:igr http://127.0.0.1:8123/ --data-binary 'CREATE DATABASE IF NOT EXISTS igr' >/dev/null
}

setup_macos() {
    command -v brew >/dev/null || die "Instala Homebrew (https://brew.sh) y vuelve a correr el script."
    log "Homebrew: MariaDB 11.4 y Redis"
    brew list mariadb@11.4 >/dev/null 2>&1 || brew install mariadb@11.4
    brew list redis >/dev/null 2>&1 || brew install redis
    brew services start mariadb@11.4 >/dev/null
    brew services start redis >/dev/null
    local mariadb_bin
    mariadb_bin="$(brew --prefix mariadb@11.4)/bin/mariadb"
    for _ in $(seq 1 30); do "$mariadb_bin" -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
    log "MariaDB: usuario igr y bases de desarrollo y pruebas"
    "$mariadb_bin" <<<"$DEV_SQL"

    log "ClickHouse 25.8 (binario oficial, sin servicio: se arranca con launchd del usuario)"
    local dir=$HOME/.local/share/growth-clickhouse
    mkdir -p "$dir/config.d" "$dir/users.d" "$dir/data"
    if ! command -v clickhouse >/dev/null; then
        brew install --cask clickhouse
    fi
    printf '%s\n' "$CH_USERS" >"$dir/users.d/growth-dev.xml"
    if ! curl -fsS http://127.0.0.1:8123/ping >/dev/null 2>&1; then
        (cd "$dir/data" && nohup clickhouse server -- --path="$dir/data/" --listen_host=127.0.0.1 \
            --user_directories.users_xml.path="$dir/users.d/growth-dev.xml" >"$dir/server.log" 2>&1 &)
    fi
    ch_wait
    curl -fsS -u igr:igr http://127.0.0.1:8123/ --data-binary 'CREATE DATABASE IF NOT EXISTS igr' >/dev/null
    echo "    ClickHouse corre en segundo plano (registro: $dir/server.log); al reiniciar la Mac, vuelve a correr este script."
}

case "$(uname -s)" in
    Linux) command -v apt-get >/dev/null || die "En Linux, el script es para Ubuntu o Debian (apt)."; setup_debian ;;
    Darwin) setup_macos ;;
    *) die "Sistema no soportado: $(uname -s)." ;;
esac

log "Listo. Valores para hub/.env"
cat <<'ENV'
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=igr
DB_USERNAME=igr
DB_PASSWORD=igr
CLICKHOUSE_URL=http://127.0.0.1:8123
CLICKHOUSE_DATABASE=igr
CLICKHOUSE_USERNAME=igr
CLICKHOUSE_PASSWORD=igr
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
ENV
