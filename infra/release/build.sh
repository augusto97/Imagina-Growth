#!/usr/bin/env bash
# Arma el paquete de una versión del hub (docs/despliegue.md §3).
#
#   infra/release/build.sh <versión> [carpeta-de-salida]
#
# Deja en la carpeta de salida (por defecto ./dist):
#   growth-<v>.zip          el paquete: VERSION, release.json, hub/ (con vendor y la SPA construida),
#                           hub/ml (pyproject.toml y uv.lock), bin/, backup/, systemd/, config/, caddy/ e install.sh
#   growth-<v>.zip.sha256   «<sha256>  growth-<v>.zip»
#   release.json            el mismo de adentro del zip
#   install.sh              si existe infra/install.sh
#   release-signing.pub.pem si se da GROWTH_RELEASE_PUBKEY_FILE (también va en la raíz del zip)
# La firma (.sig) la hace infra/release/sign.sh después.
#
# Variables opcionales:
#   GROWTH_COMMIT                 commit que se anota en release.json (por defecto GITHUB_SHA o «desconocido»)
#   GROWTH_RELEASE_PUBKEY_FILE    llave pública ed25519 (PEM) para incluir en el paquete
#   GROWTH_BUILD_SKIP_ASSETS=1    no compila la SPA (solo para pruebas del empaquetado; no publica así)
#
# Necesita: php 8.4 y composer, node 22 y npm, zip, sha256sum.
set -euo pipefail

VERSION=${1:-}
OUT=${2:-dist}
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Uso: $0 <versión X.Y.Z> [carpeta-de-salida]" >&2
    exit 2
fi
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
mkdir -p "$OUT"
OUT=$(cd "$OUT" && pwd)
NAME="growth-${VERSION}"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
SRC="$WORK/src"
STAGE="$WORK/stage"
mkdir -p "$SRC" "$STAGE"

say() { printf '› %s\n' "$*"; }
warn() { printf '! %s\n' "$*" >&2; }

for tool in php composer zip sha256sum; do
    command -v "$tool" >/dev/null || { echo "Falta $tool" >&2; exit 2; }
done
if [ "${GROWTH_BUILD_SKIP_ASSETS:-0}" != "1" ]; then
    command -v npm >/dev/null || { echo "Falta npm" >&2; exit 2; }
fi

say "Copia limpia del hub"
# Lo que nunca va en un paquete: dependencias y compilados locales, configuración y datos.
tar -C "$ROOT/hub" -cf - \
    --exclude='./vendor' --exclude='./node_modules' --exclude='./storage' --exclude='./.env' --exclude='./.env.*' \
    --exclude='./public/build' --exclude='./public/hot' --exclude='./public/storage' --exclude='./.phpunit.cache' \
    --exclude='./bootstrap/cache/*.php' --exclude='./ml/.venv' --exclude='*/__pycache__' --exclude='./ml/.pytest_cache' \
    . | tar -C "$SRC" -xf -
# Laravel necesita storage/ para correr artisan durante la compilación (en el servidor es shared/storage).
mkdir -p "$SRC/storage/framework/cache" "$SRC/storage/framework/sessions" "$SRC/storage/framework/views" "$SRC/storage/logs" "$SRC/bootstrap/cache"

say "Dependencias de PHP de producción"
(
    cd "$SRC"
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --no-progress \
        --optimize-autoloader --classmap-authoritative
    # Si Composer tuvo que clonar algún paquete (sin el zip de GitHub), su historial de git no va al paquete.
    find vendor -name .git -prune -exec rm -rf {} +
)

if [ "${GROWTH_BUILD_SKIP_ASSETS:-0}" = "1" ]; then
    warn "GROWTH_BUILD_SKIP_ASSETS=1: la SPA no se compila (paquete solo para pruebas)"
    mkdir -p "$SRC/public/build"
    printf '{}\n' > "$SRC/public/build/manifest.json"
else
    say "SPA (Vite)"
    (
        cd "$SRC"
        # Sin VITE_REVERB_*: la conexión a Reverb llega del servidor en tiempo de ejecución (window.igrRuntime).
        env -u VITE_REVERB_APP_KEY -u VITE_REVERB_HOST -u VITE_REVERB_PORT -u VITE_REVERB_SCHEME npm ci --no-audit --no-fund
        env -u VITE_REVERB_APP_KEY -u VITE_REVERB_HOST -u VITE_REVERB_PORT -u VITE_REVERB_SCHEME npm run build
    )
fi
[ -f "$SRC/public/build/manifest.json" ] || { echo "La SPA no dejó public/build/manifest.json" >&2; exit 1; }

say "Armado del paquete"
HUB="$STAGE/hub"
mkdir -p "$HUB/resources" "$HUB/bootstrap/cache" "$HUB/ml"
for item in app config database lang public routes vendor artisan composer.json composer.lock; do
    cp -a "$SRC/$item" "$HUB/"
done
cp -a "$SRC/resources/views" "$SRC/resources/schemas" "$HUB/resources/"
cp -a "$SRC/bootstrap/app.php" "$SRC/bootstrap/providers.php" "$HUB/bootstrap/"
# El manifiesto de paquetes sin las dependencias de desarrollo; sin caché de configuración ni de rutas
# (las arma el actualizador con el .env del servidor).
for f in packages.php services.php; do
    [ -f "$SRC/bootstrap/cache/$f" ] && cp -a "$SRC/bootstrap/cache/$f" "$HUB/bootstrap/cache/"
done
printf '*\n!.gitignore\n' > "$HUB/bootstrap/cache/.gitignore"
rm -rf "$HUB/public/hot" "$HUB/public/storage"
# Servicio de predicciones: el código y el lockfile; el entorno lo crea uv en el servidor.
cp -a "$SRC/ml/app" "$SRC/ml/pyproject.toml" "$SRC/ml/uv.lock" "$HUB/ml/"
[ -f "$SRC/ml/README.md" ] && cp -a "$SRC/ml/README.md" "$HUB/ml/"
find "$HUB/ml" -name '__pycache__' -type d -prune -exec rm -rf {} +

# Piezas del servidor (las mantiene infra/). Si todavía no existen, el paquete sale sin ellas y avisa.
copy_dir() {
    local from="$ROOT/infra/$1" to="$STAGE/$2"
    if [ -d "$from" ] && [ -n "$(ls -A "$from" 2>/dev/null)" ]; then
        mkdir -p "$to"
        cp -a "$from/." "$to/"
    else
        warn "infra/$1 no existe o está vacío: el paquete sale sin $2/"
    fi
}
copy_dir bin bin
copy_dir backup backup
copy_dir systemd systemd
copy_dir config config
copy_dir caddy caddy
if [ -f "$ROOT/infra/install.sh" ]; then
    cp -a "$ROOT/infra/install.sh" "$STAGE/install.sh"
    chmod 0755 "$STAGE/install.sh"
else
    warn "infra/install.sh no existe: el paquete sale sin install.sh"
fi
# Las guías de las copias se quedan en el repositorio; en el servidor solo van los scripts y el ejemplo de configuración.
rm -f "$STAGE/backup/README.md" "$STAGE/backup/RESTAURACION.md"
# Scripts ejecutables (el zip guarda los permisos de Unix y unzip los restaura).
for d in bin backup; do
    if [ -d "$STAGE/$d" ]; then
        find "$STAGE/$d" -type f ! -name '*.example' ! -name '*.md' -exec chmod 0755 {} +
    fi
done
if [ -n "${GROWTH_RELEASE_PUBKEY_FILE:-}" ]; then
    [ -f "$GROWTH_RELEASE_PUBKEY_FILE" ] || { echo "No existe $GROWTH_RELEASE_PUBKEY_FILE" >&2; exit 2; }
    cp "$GROWTH_RELEASE_PUBKEY_FILE" "$STAGE/release-signing.pub.pem"
    # install.sh lleva la llave pública incrustada (marca __GROWTH_RELEASE_PUBKEY__): con ella verifica la versión
    # que baja y la deja en /etc/growth para las actualizaciones. Sin la marca, el paquete no sirve: se detiene.
    if [ -f "$STAGE/install.sh" ]; then
        grep -qx '__GROWTH_RELEASE_PUBKEY__' "$STAGE/install.sh" \
            || { echo "install.sh no tiene la marca __GROWTH_RELEASE_PUBKEY__" >&2; exit 2; }
        python3 - "$STAGE/install.sh" "$GROWTH_RELEASE_PUBKEY_FILE" <<'PY'
import sys
script, key = sys.argv[1], sys.argv[2]
pem = open(key).read().strip()
src = open(script).read()
lines = src.split('\n')
lines = [pem if line == '__GROWTH_RELEASE_PUBKEY__' else line for line in lines]
open(script, 'w').write('\n'.join(lines))
PY
    fi
fi

# Nada de pruebas ni de dependencias de Node en el paquete.
find "$STAGE" -name node_modules -type d -prune -exec rm -rf {} +
rm -rf "$HUB/tests" "$HUB/ml/tests"

printf '%s\n' "$VERSION" > "$STAGE/VERSION"

say "release.json"
COMMIT=${GROWTH_COMMIT:-${GITHUB_SHA:-desconocido}}
# release.json va dentro del zip, así que se mide sin él y después se suma su tamaño estimado.
BYTES=$(du -sb "$STAGE" | cut -f1)
FILES=$(find "$STAGE" -type f | wc -l)
# shellcheck disable=SC2016 # código PHP: las variables son de PHP, no del shell
php -r '
    [$_, $version, $commit, $bytes, $files, $envFile, $out] = $argv;
    $env = json_decode((string) file_get_contents($envFile), true, 512, JSON_THROW_ON_ERROR);
    $data = [
        "version" => $version,
        "commit" => substr($commit, 0, 40),
        "built_at" => gmdate("Y-m-d\TH:i:s\Z"),
        "php" => "8.4",
        "unpacked_bytes" => (int) $bytes + 65536,
        "files" => (int) $files + 1,
        "env" => $env["variables"],
    ];
    file_put_contents($out, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
' "$VERSION" "$COMMIT" "$BYTES" "$FILES" "$ROOT/infra/release/env.json" "$STAGE/release.json"

say "Zip y sha256"
rm -f "$OUT/$NAME.zip" "$OUT/$NAME.zip.sha256" "$OUT/$NAME.zip.sha256.sig"
(cd "$STAGE" && zip -q -r -X -y "$OUT/$NAME.zip" .)
(cd "$OUT" && sha256sum "$NAME.zip" > "$NAME.zip.sha256")
cp "$STAGE/release.json" "$OUT/release.json"
[ -f "$STAGE/install.sh" ] && cp "$STAGE/install.sh" "$OUT/install.sh"
[ -f "$STAGE/release-signing.pub.pem" ] && cp "$STAGE/release-signing.pub.pem" "$OUT/release-signing.pub.pem"

say "Listo: $OUT/$NAME.zip ($(du -h "$OUT/$NAME.zip" | cut -f1), $FILES archivos)"
