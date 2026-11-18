#!/usr/bin/env bash
# Verifica una versión como lo hace el actualizador (docs/despliegue.md §5, paso 3):
# primero la firma ed25519 del .sha256 y después el sha256 del zip.
#
#   infra/release/verify.sh <growth-X.Y.Z.zip> <growth-X.Y.Z.zip.sha256> <…sha256.sig> <llave-pública.pem>
#
# Códigos de salida:
#   0  la versión es auténtica e íntegra
#   2  uso incorrecto o falta un archivo
#   3  la firma no es válida (no la firmó la llave del proyecto, o el .sha256 cambió)
#   4  el sha256 no coincide con el zip (o el .sha256 no tiene el formato esperado)
set -uo pipefail

ZIP=${1:-}
SHA=${2:-}
SIG=${3:-}
PUB=${4:-}
for f in "$ZIP" "$SHA" "$SIG" "$PUB"; do
    if [ -z "$f" ] || [ ! -f "$f" ]; then
        echo "Uso: $0 <zip> <sha256> <sig> <llave-pública.pem> (falta: ${f:-argumento})" >&2
        exit 2
    fi
done

if ! openssl pkeyutl -verify -rawin -pubin -inkey "$PUB" -in "$SHA" -sigfile "$SIG" >/dev/null 2>&1; then
    echo "Firma inválida: $SIG no corresponde a $SHA con la llave $PUB" >&2
    exit 3
fi

LINE=$(head -n 1 "$SHA")
EXPECTED_NAME=$(basename "$ZIP")
if [[ ! "$LINE" =~ ^([0-9a-f]{64})\ \ (.+)$ ]] || [ "${BASH_REMATCH[2]}" != "$EXPECTED_NAME" ]; then
    echo "El .sha256 no tiene el formato «<sha256>  $EXPECTED_NAME»" >&2
    exit 4
fi
EXPECTED=${BASH_REMATCH[1]}
ACTUAL=$(sha256sum "$ZIP" | cut -d' ' -f1)
if [ "$ACTUAL" != "$EXPECTED" ]; then
    echo "El sha256 del zip no coincide (esperado $EXPECTED, real $ACTUAL)" >&2
    exit 4
fi
echo "Versión verificada: firma y sha256 correctos."
