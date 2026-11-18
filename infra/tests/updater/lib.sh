#!/usr/bin/env bash
# Ayudas del arnés del actualizador (infra/tests/updater/run.sh): un /opt/growth falso en una carpeta
# temporal, releases firmados con una llave ed25519 de un solo uso y comandos falsos que registran todo.
# shellcheck disable=SC2034,SC2015  # variables usadas por run.sh; «A && ok || bad» es la forma de las aserciones
set -uo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_ROOT=$(cd "$HERE/../../.." && pwd)
BIN="$REPO_ROOT/infra/bin"
PASS=0
FAILS=0
CURRENT_TEST=""

ok() { PASS=$((PASS + 1)); }
bad() { FAILS=$((FAILS + 1)); printf '  ✗ [%s] %s\n' "$CURRENT_TEST" "$*"; }
assert_eq() { [[ $1 == "$2" ]] && ok || bad "${3:-}: esperaba «$2», obtuve «$1»"; }
assert_contains() { [[ $1 == *"$2"* ]] && ok || bad "${3:-}: «$2» no está en «${1:0:300}»"; }
assert_not_contains() { [[ $1 != *"$2"* ]] && ok || bad "${3:-}: «$2» no debía estar"; }
assert_exists() { [[ -e $1 || -L $1 ]] && ok || bad "${2:-}: falta $1"; }
assert_missing() { [[ ! -e $1 && ! -L $1 ]] && ok || bad "${2:-}: no debía existir $1"; }
current() { basename "$(readlink "$GROWTH_ROOT/current")"; }
status() { jq -r "$1" "$GROWTH_ROOT/shared/state/update-status.json"; }
calls() { cat "$FAKE/calls.log" 2>/dev/null || true; }
restarts() { grep -E '^systemctl restart' "$FAKE/calls.log" | sed 's/^systemctl restart //' | tr '\n' ' ' | sed 's/ $//'; }

# Un mundo nuevo para cada escenario.
setup_world() {
    CURRENT_TEST=$1
    WORLD=$(mktemp -d)
    export FAKE="$WORLD/fake" GROWTH_ROOT="$WORLD/opt/growth" GROWTH_ETC="$WORLD/etc/growth"
    export SYSTEMD_UNIT_DIR="$WORLD/etc/systemd/system" GROWTH_LOCK_FILE="$WORLD/run/growth-update.lock"
    export SYSTEMCTL="$HERE/fakes/systemctl" SYSTEMD_RUN="$HERE/fakes/systemd-run" CURL="$HERE/fakes/curl" PHP="$HERE/fakes/php"
    export UV="$HERE/fakes/uv" MARIADB="$HERE/fakes/mariadb" MARIADB_DUMP="$HERE/fakes/mariadb-dump" RUNUSER="$HERE/fakes/runuser"
    export CHOWN="$HERE/fakes/chown" DF="$HERE/fakes/df" SLEEP="$HERE/fakes/sleep"
    unset FAKE_FREE_BYTES FAKE_FREE_INODES FAKE_ARTISAN_FAIL FAKE_DUMP_FAIL FAKE_DB_TABLES
    mkdir -p "$FAKE/releases" "$GROWTH_ROOT/releases" "$GROWTH_ROOT/shared/state" "$GROWTH_ETC" "$SYSTEMD_UNIT_DIR" "$(dirname "$GROWTH_LOCK_FILE")"
    : >"$FAKE/calls.log"
    openssl genpkey -algorithm ed25519 -out "$FAKE/signing.pem" 2>/dev/null
    openssl pkey -in "$FAKE/signing.pem" -pubout -out "$GROWTH_ETC/release-signing.pub.pem" 2>/dev/null
    printf 'GROWTH_UPDATE_REPO=acme/growth\nGROWTH_UPDATE_API="http://api.fake.test"  # API falsa\nGROWTH_HEALTH_TIMEOUT=4\n' >"$GROWTH_ETC/updater.conf"
    cat >"$GROWTH_ROOT/shared/.env" <<'ENV'
APP_KEY=base64:dGVzdGtleXRlc3RrZXl0ZXN0a2V5dGVzdGtleTEyMzQ=
IGR_BLIND_INDEX_KEY=prueba
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=igr
DB_USERNAME=igr
DB_PASSWORD="cl@ve con \"comillas\" y #"
CLICKHOUSE_PASSWORD=ch
MAIL_FROM_NAME=Clínica <hola@clinica.test>
ENV
    chmod 0600 "$GROWTH_ROOT/shared/.env"
}

teardown_world() { rm -rf "$WORLD"; }

# make_release <versión> [variante]: arma el zip, el .sha256, la firma y release.json en $FAKE/releases/v<versión>.
#   variantes: ok | corrupt-zip (bytes cambiados después de firmar) | bad-sig (firmado con otra llave) |
#              broken-zip (zip inválido pero bien firmado) | big (release.json pide mucho espacio)
make_release() {
    local v=$1 variant=${2:-ok}
    local src="$WORLD/src-$v" dir="$FAKE/releases/v$v" zip="growth-$v.zip"
    mkdir -p "$src/hub/ml" "$src/hub/bootstrap" "$src/hub/public/build" "$src/bin" "$src/systemd" "$dir"
    echo "$v" >"$src/VERSION"
    echo "<?php // artisan falso $v" >"$src/hub/artisan"
    echo "# uv.lock falso" >"$src/hub/ml/uv.lock"
    cp "$BIN/growth-update" "$BIN/growth-env" "$src/bin/"
    printf '[Unit]\nDescription=growth-app %s\n' "$v" >"$src/systemd/growth-app.service"
    printf '[Unit]\nDescription=growth.target\n' >"$src/systemd/growth.target"
    local unpacked=1000000 files=200
    [[ $variant == big ]] && unpacked=900000000000
    jq -n --arg v "$v" --argjson b "$unpacked" --argjson f "$files" '{version: $v, commit: "abc1234", built_at: "2026-11-20T00:00:00Z",
        php: "8.4", unpacked_bytes: $b, files: $f,
        env: [{name: "REDIS_CACHE_PORT", default: "6380", secret: false},
              {name: "IGR_ML_TOKEN", default: null, secret: true},
              {name: "APP_KEY", default: null, secret: true, never_regenerate: true}]}' >"$src/release.json"
    cp "$src/release.json" "$dir/release.json"
    if [[ $variant == broken-zip ]]; then
        echo "esto no es un zip" >"$dir/$zip"
    else
        (cd "$src" && zip -qr "$dir/$zip" .)
    fi
    (cd "$dir" && sha256sum "$zip" >"$zip.sha256")
    local key="$FAKE/signing.pem"
    if [[ $variant == bad-sig ]]; then
        openssl genpkey -algorithm ed25519 -out "$FAKE/other.pem" 2>/dev/null
        key="$FAKE/other.pem"
    fi
    openssl pkeyutl -sign -rawin -inkey "$key" -in "$dir/$zip.sha256" -out "$dir/$zip.sha256.sig"
    if [[ $variant == corrupt-zip ]]; then
        printf 'X' | dd of="$dir/$zip" bs=1 seek=40 conv=notrunc 2>/dev/null
    fi
}

# install_release <versión>: deja una versión instalada y activa sin pasar por el actualizador.
install_release() {
    local v=$1
    make_release "$v"
    mkdir -p "$GROWTH_ROOT/releases/$v"
    (cd "$GROWTH_ROOT/releases/$v" && unzip -q "$FAKE/releases/v$v/growth-$v.zip")
    ln -sfn "releases/$v" "$GROWTH_ROOT/current"
}

run_update() { "$BIN/growth-update" "$@" >"$FAKE/out.log" 2>&1; echo $?; }
