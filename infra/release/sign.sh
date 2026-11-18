#!/usr/bin/env bash
# Firma el .sha256 de una versión con ed25519 (docs/despliegue.md §3).
#
#   infra/release/sign.sh <growth-X.Y.Z.zip.sha256> [llave-privada.pem]
#
# Sin el segundo argumento lee la llave PEM de la variable GROWTH_RELEASE_SIGNING_KEY (el secreto
# del repositorio en GitHub Actions). Deja <archivo>.sig (64 bytes) junto al .sha256.
# La llave nunca se escribe en el repositorio: si viene en la variable, va a un temporal 0600 que se borra.
set -euo pipefail

SHA=${1:-}
KEY=${2:-}
if [ -z "$SHA" ] || [ ! -f "$SHA" ]; then
    echo "Uso: $0 <archivo.sha256> [llave.pem]" >&2
    exit 2
fi

TMP=""
cleanup() {
    if [ -n "$TMP" ]; then rm -f "$TMP"; fi
}
trap cleanup EXIT
if [ -z "$KEY" ]; then
    if [ -z "${GROWTH_RELEASE_SIGNING_KEY:-}" ]; then
        echo "Falta la llave: define el secreto GROWTH_RELEASE_SIGNING_KEY (PEM ed25519) o pásala como argumento." >&2
        exit 2
    fi
    TMP=$(umask 077 && mktemp)
    printf '%s\n' "$GROWTH_RELEASE_SIGNING_KEY" > "$TMP"
    KEY=$TMP
fi
[ -f "$KEY" ] || { echo "No existe la llave $KEY" >&2; exit 2; }

openssl pkeyutl -sign -rawin -inkey "$KEY" -in "$SHA" -out "$SHA.sig"
SIZE=$(wc -c < "$SHA.sig")
[ "$SIZE" -eq 64 ] || { echo "La firma no mide 64 bytes ($SIZE): ¿la llave es ed25519?" >&2; rm -f "$SHA.sig"; exit 1; }
echo "Firmado: $SHA.sig"
