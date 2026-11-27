#!/usr/bin/env bash
# Prueba del empaquetado de versiones (docs/despliegue.md §3): arma un paquete con una llave
# ed25519 de usar y tirar, lo verifica, revisa su contenido y comprueba que verify.sh rechaza
# un zip alterado (código 4), una firma alterada (código 3) y una llave ajena (código 3).
#
#   infra/tests/release/build_test.sh                  (paquete completo, con la SPA compilada)
#   GROWTH_BUILD_SKIP_ASSETS=1 infra/tests/release/build_test.sh   (más rápido, sin compilar la SPA)
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
REL="$ROOT/infra/release"
VERSION=${GROWTH_TEST_VERSION:-9.9.9}
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
fails=0
pass() { printf '  ✓ %s\n' "$*"; }
fail() { printf '  ✗ %s\n' "$*"; fails=$((fails + 1)); }
expect_code() { # código esperado, descripción, comando...
    local want=$1 desc=$2
    shift 2
    set +e
    "$@" >/dev/null 2>&1
    local got=$?
    set -e
    if [ "$got" -eq "$want" ]; then pass "$desc (código $got)"; else fail "$desc: esperaba código $want y fue $got"; fi
}

echo "Llaves de prueba (temporales)"
openssl genpkey -algorithm ed25519 -out "$T/key.pem" 2>/dev/null
openssl pkey -in "$T/key.pem" -pubout -out "$T/pub.pem"
openssl genpkey -algorithm ed25519 -out "$T/other.pem" 2>/dev/null
openssl pkey -in "$T/other.pem" -pubout -out "$T/other-pub.pem"

echo "Armado"
GROWTH_COMMIT=0123456789abcdef GROWTH_RELEASE_PUBKEY_FILE="$T/pub.pem" "$REL/build.sh" "$VERSION" "$T/out"
ZIP="$T/out/growth-$VERSION.zip"
SHA="$ZIP.sha256"
"$REL/sign.sh" "$SHA" "$T/key.pem" >/dev/null

echo "Verificación"
expect_code 0 "versión auténtica e íntegra" "$REL/verify.sh" "$ZIP" "$SHA" "$SHA.sig" "$T/pub.pem"
expect_code 3 "firma con una llave ajena" "$REL/verify.sh" "$ZIP" "$SHA" "$SHA.sig" "$T/other-pub.pem"
GROWTH_RELEASE_SIGNING_KEY=$(cat "$T/key.pem") "$REL/sign.sh" "$SHA" >/dev/null
expect_code 0 "firma con la llave desde la variable del secreto" "$REL/verify.sh" "$ZIP" "$SHA" "$SHA.sig" "$T/pub.pem"
expect_code 2 "sin llave no firma" env -u GROWTH_RELEASE_SIGNING_KEY "$REL/sign.sh" "$SHA"
expect_code 2 "faltan argumentos" "$REL/verify.sh" "$ZIP" "$SHA"

echo "Contenido"
LIST="$T/list.txt"
unzip -Z1 "$ZIP" > "$LIST"
has() { if grep -qx "$1" "$LIST"; then pass "trae $1"; else fail "falta $1"; fi; }
lacks() { if grep -qE "$1" "$LIST"; then fail "no debería traer $1: $(grep -E "$1" "$LIST" | head -1)"; else pass "sin $1"; fi; }
has hub/public/downloads/imagina-growth-connector.zip
has VERSION
has release.json
has hub/artisan
has hub/vendor/autoload.php
has hub/public/build/manifest.json
has hub/public/index.php
has hub/ml/uv.lock
has hub/ml/pyproject.toml
has hub/ml/app/main.py
has hub/resources/views/app.blade.php
has hub/resources/assistant/knowledge.md
has hub/resources/assistant/pages.json
has hub/database/clickhouse/0001_events.sql
has hub/bootstrap/app.php
has release-signing.pub.pem
# install.sh (el del zip y el asset suelto) lleva la llave pública incrustada en lugar de la marca.
inst="$T/out/install.sh"
if grep -q -- '-----BEGIN PUBLIC KEY-----' "$inst" && ! grep -qx '__GROWTH_RELEASE_PUBKEY__' "$inst" \
    && grep -qF -- "$(sed -n 2p "$T/pub.pem")" "$inst"; then
    pass "install.sh trae la llave pública de la versión"
else
    fail "install.sh no trae la llave pública incrustada"
fi
for f in growth-backup-mariadb growth-backup-clickhouse growth-backup-verify growth-backup-lib.sh growth-backup-dispatch; do
    if [ -f "$ROOT/infra/backup/$f" ]; then has "backup/$f"; fi
done
lacks '^backup/(README|RESTAURACION)\.md$'
lacks '(^|/)node_modules/'
lacks '^hub/tests/'
lacks '^hub/ml/tests/'
lacks '^hub/storage/'
lacks '^hub/\.env'
lacks '^hub/resources/js/'
lacks '^hub/vendor/(pestphp|phpunit|larastan|laravel/pint)/'
lacks '^hub/bootstrap/cache/(config|routes-v7|events)\.php$'
lacks '\.venv/'

# Los scripts de bin/ y backup/ quedan ejecutables dentro del zip (unzip restaura los permisos).
zipinfo "$ZIP" 'bin/*' 'backup/*' 2>/dev/null | awk '$1 ~ /^-/ {print $1, $NF}' > "$T/perms.txt" || true
scripts=$(awk '$2 !~ /\.(example|md)$/' "$T/perms.txt" | wc -l)
noexec=$(awk '$2 !~ /\.(example|md)$/ && $1 !~ /^-rwx/' "$T/perms.txt")
if [ "$scripts" -eq 0 ]; then
    pass "sin scripts en bin/ ni backup/ todavía (los aportan otras piezas)"
elif [ -z "$noexec" ]; then
    pass "$scripts scripts de bin/ y backup/ ejecutables en el zip"
else
    fail "scripts sin permiso de ejecución: $noexec"
fi
unzip -p "$ZIP" VERSION > "$T/VERSION"
if [ "$(cat "$T/VERSION")" = "$VERSION" ]; then pass "VERSION dice $VERSION"; else fail "VERSION dice $(cat "$T/VERSION")"; fi
unzip -p "$ZIP" release.json > "$T/release.json"
# shellcheck disable=SC2016 # código PHP: las variables son de PHP
if php -r '
    $r = json_decode((string) file_get_contents($argv[1]), true);
    $names = array_column($r["env"] ?? [], "name");
    $ok = ($r["version"] ?? null) === $argv[2] && $r["commit"] === "0123456789abcdef" && $r["php"] === "8.4"
        && $r["unpacked_bytes"] > 10_000_000 && $r["files"] > 1000
        && in_array("APP_KEY", $names, true) && in_array("REDIS_CACHE_PORT", $names, true);
    foreach ($r["env"] as $v) {
        if ($v["name"] === "APP_KEY" && empty($v["never_regenerate"])) { $ok = false; }
    }
    exit($ok ? 0 : 1);
' "$T/release.json" "$VERSION"; then pass "release.json válido (versión, commit, tamaño, archivos y variables)"; else fail "release.json no es el esperado"; fi
if cmp -s "$T/release.json" "$T/out/release.json"; then pass "release.json suelto igual al del zip"; else fail "release.json suelto distinto"; fi

echo "Alteraciones"
cp "$ZIP" "$T/tampered.zip"
printf 'x' >> "$T/tampered.zip"
sed "s/growth-$VERSION.zip/tampered.zip/" "$SHA" > "$T/tampered.zip.sha256"
# El .sha256 renombrado ya no es el firmado: la firma falla antes que el hash.
expect_code 3 ".sha256 distinto del firmado" "$REL/verify.sh" "$T/tampered.zip" "$T/tampered.zip.sha256" "$SHA.sig" "$T/pub.pem"
# Zip alterado con el .sha256 original (firmado): falla el hash.
cp "$ZIP" "$T/orig.zip"
printf 'x' >> "$ZIP"
expect_code 4 "zip alterado" "$REL/verify.sh" "$ZIP" "$SHA" "$SHA.sig" "$T/pub.pem"
cp "$T/orig.zip" "$ZIP"
cp "$SHA.sig" "$T/orig.sig"
# Se invierte el byte (no se escribe uno fijo): la firma es de una llave de usar y tirar y, si ese byte ya valía
# lo que se escribía, la firma quedaba igual y la prueba fallaba 1 de cada 256 veces.
sig_byte=$(od -An -tu1 -j10 -N1 "$SHA.sig" | tr -d ' ')
printf '%b' "\\$(printf '%03o' $(( sig_byte ^ 0xff )))" | dd of="$SHA.sig" bs=1 seek=10 conv=notrunc 2>/dev/null
cmp -s "$SHA.sig" "$T/orig.sig" && { echo "  ✗ la firma no cambió al alterarla"; exit 1; }
expect_code 3 "firma alterada" "$REL/verify.sh" "$ZIP" "$SHA" "$SHA.sig" "$T/pub.pem"
cp "$T/orig.sig" "$SHA.sig"
expect_code 0 "el original sigue verificando" "$REL/verify.sh" "$ZIP" "$SHA" "$SHA.sig" "$T/pub.pem"

echo
if [ "$fails" -gt 0 ]; then
    echo "Empaquetado: $fails comprobaciones fallaron."
    exit 1
fi
echo "Empaquetado: todo en verde."
