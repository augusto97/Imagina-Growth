#!/usr/bin/env bash
# Crea el par de llaves ed25519 que firma las versiones (docs/despliegue.md §3).
#
#   infra/release/keygen.sh [carpeta]        (por defecto una carpeta temporal nueva)
#
# - La llave PRIVADA va al secreto del repositorio GROWTH_RELEASE_SIGNING_KEY y se guarda fuera de
#   línea (gestor de contraseñas). Nunca se escribe dentro del repositorio: este script se niega.
# - La llave PÚBLICA es la que usan install.sh y el actualizador (/etc/growth/release-signing.pub.pem).
#   Se puede publicar; el workflow de release la deriva de la privada y la incluye en cada versión.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
DIR=${1:-$(mktemp -d)}
mkdir -p "$DIR"
DIR=$(cd "$DIR" && pwd)
case "$DIR/" in
    "$ROOT"/*) echo "Por seguridad, la llave privada no se crea dentro del repositorio ($ROOT)." >&2; exit 2 ;;
esac
PRIV="$DIR/growth-release-signing.key.pem"
PUB="$DIR/growth-release-signing.pub.pem"
[ -e "$PRIV" ] && { echo "Ya existe $PRIV; no se sobrescribe." >&2; exit 2; }

(umask 077 && openssl genpkey -algorithm ed25519 -out "$PRIV")
openssl pkey -in "$PRIV" -pubout -out "$PUB"

cat <<MSG
Llaves creadas:
  privada: $PRIV   (0600)
  pública: $PUB

Siguientes pasos:
  1. Guarda la privada como secreto del repositorio:
       gh secret set GROWTH_RELEASE_SIGNING_KEY < "$PRIV"
     (o en GitHub: Settings → Secrets and variables → Actions → New repository secret)
  2. Guarda una copia de la privada fuera de línea y borra este archivo:
       shred -u "$PRIV"
  3. La pública no es secreta: el workflow la publica en cada versión (release-signing.pub.pem) e
     install.sh la deja en /etc/growth/release-signing.pub.pem. Cambiar la llave obliga a reinstalarla
     en cada servidor.
MSG
