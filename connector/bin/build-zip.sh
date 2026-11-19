#!/usr/bin/env bash
# Arma el zip instalable del plugin «Imagina Growth Connector» (connector/CLAUDE.md §15).
#
#   connector/bin/build-zip.sh [carpeta de salida]     (por defecto connector/build)
#
# Deja imagina-growth-connector-<versión>.zip e imagina-growth-connector.zip (el mismo, con nombre fijo para el
# enlace «latest/download» de GitHub Releases). Solo lleva lo que corre en producción: el plugin no tiene
# dependencias de Composer (usa su propio autoload PSR-4) y los assets del tracker ya están compilados en assets/.
set -euo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd)
OUT=${1:-$HERE/build}
mkdir -p "$OUT"
OUT=$(cd "$OUT" && pwd)
VERSION=$(sed -n 's/^ \* Version:[[:space:]]*\([0-9][0-9.]*\)[[:space:]]*$/\1/p' "$HERE/imagina-growth-connector.php" | head -1)
[[ $VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "No se encontró la versión en la cabecera del plugin" >&2; exit 1; }
CONST=$(sed -n "s/^define( 'IGR_VERSION', '\([0-9.]*\)' );$/\1/p" "$HERE/imagina-growth-connector.php")
[ "$CONST" = "$VERSION" ] || { echo "IGR_VERSION ($CONST) no coincide con la cabecera ($VERSION)" >&2; exit 1; }
[ -s "$HERE/assets/tracker.min.js" ] || { echo "Falta assets/tracker.min.js (compila el tracker: cd tracker && npm run build)" >&2; exit 1; }

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/imagina-growth-connector"
mkdir -p "$DEST"
cp "$HERE/imagina-growth-connector.php" "$HERE/uninstall.php" "$DEST/"
cp -R "$HERE/src" "$HERE/assets" "$DEST/"
[ -d "$HERE/languages" ] && cp -R "$HERE/languages" "$DEST/"
# Nada de desarrollo dentro del zip.
find "$DEST" \( -name '*.map' -o -name '.DS_Store' -o -name '*.test.*' \) -delete

ZIP="$OUT/imagina-growth-connector-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE" && zip -q -r -X "$ZIP" imagina-growth-connector)
cp "$ZIP" "$OUT/imagina-growth-connector.zip"
echo "Plugin $VERSION: $ZIP ($(du -h "$ZIP" | cut -f1))"
