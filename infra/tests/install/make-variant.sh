#!/usr/bin/env bash
# Deriva una versión de prueba a partir de un paquete ya armado con infra/release/build.sh, para la prueba de
# actualización de punta a punta (infra/tests/install/e2e-update.sh). Solo para pruebas: cambia la versión,
# opcionalmente agrega una migración y una variable de entorno, o rompe /health/ready, y vuelve a firmar.
#
#   make-variant.sh --from DIR --base 0.1.0 --version 0.2.0 --key key.pem [--migration] [--env NOMBRE=valor] [--break-health]
#
# DIR tiene growth-<base>.zip (y en él quedan growth-<v>.zip, .sha256, .sig y release-<v>.json).
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
REL=$(cd "$HERE/../../release" && pwd)
FROM="" BASE="" VERSION="" KEY="" MIGRATION=0 BREAK=0 ENVS=()
while [ $# -gt 0 ]; do
    case "$1" in
        --from) FROM=$2; shift 2 ;;
        --base) BASE=$2; shift 2 ;;
        --version) VERSION=$2; shift 2 ;;
        --key) KEY=$2; shift 2 ;;
        --migration) MIGRATION=1; shift ;;
        --env) ENVS+=("$2"); shift 2 ;;
        --break-health) BREAK=1; shift ;;
        *) echo "Opción desconocida: $1" >&2; exit 2 ;;
    esac
done
[ -n "$FROM" ] && [ -n "$BASE" ] && [ -n "$VERSION" ] && [ -f "$KEY" ] || { echo "Faltan --from, --base, --version o --key" >&2; exit 2; }
[[ $VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Versión inválida: $VERSION" >&2; exit 2; }
SRC="$FROM/growth-$BASE.zip"
[ -f "$SRC" ] || { echo "No existe $SRC" >&2; exit 2; }

WORK=$(mktemp -d /tmp/growth-variant.XXXXXX)
case "$WORK" in /tmp/growth-variant.*) ;; *) echo "mktemp devolvió una ruta inesperada" >&2; exit 1 ;; esac
trap 'rm -rf "$WORK"' EXIT
unzip -q "$SRC" -d "$WORK/pkg"

printf '%s\n' "$VERSION" >"$WORK/pkg/VERSION"
ENV_JSON=$(printf '%s\n' "${ENVS[@]+"${ENVS[@]}"}" | python3 -c '
import json, sys
out = []
for line in sys.stdin:
    line = line.rstrip("\n")
    if line:
        name, _, value = line.partition("=")
        out.append({"name": name, "default": value, "secret": False})
print(json.dumps(out))')
python3 - "$WORK/pkg/release.json" "$VERSION" "$ENV_JSON" <<'PY'
import json, sys
path, version, extra = sys.argv[1], sys.argv[2], json.loads(sys.argv[3])
data = json.load(open(path))
data["version"] = version
data["commit"] = data.get("commit", "") + "-e2e"
data["env"] = data.get("env", []) + extra
json.dump(data, open(path, "w"), indent=2)
PY

if [ "$MIGRATION" = 1 ]; then
    cat >"$WORK/pkg/hub/database/migrations/2099_01_01_000000_e2e_upgrade_marker.php" <<'PHP'
<?php

// Solo existe en las versiones de prueba de infra/tests/install: demuestra que la actualización migra.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e2e_upgrade_marker', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e2e_upgrade_marker');
    }
};
PHP
fi

if [ "$BREAK" = 1 ]; then
    # La versión rota responde 503 en /health/ready: el actualizador tiene que volver atrás.
    cat >"$WORK/pkg/hub/app/Http/Controllers/HealthReadyController.php" <<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/** Versión de prueba rota a propósito (infra/tests/install/make-variant.sh --break-health). */
final class HealthReadyController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'version' => 'rota'], 503);
    }
}
PHP
fi

ZIP="$FROM/growth-$VERSION.zip"
rm -f "$ZIP"
(cd "$WORK/pkg" && zip -qrX "$ZIP" .)
(cd "$FROM" && sha256sum "growth-$VERSION.zip" >"growth-$VERSION.zip.sha256")
"$REL/sign.sh" "$FROM/growth-$VERSION.zip.sha256" "$KEY" >/dev/null
cp "$WORK/pkg/release.json" "$FROM/release-$VERSION.json"
python3 - "$FROM/release-$VERSION.json" "$WORK/pkg" <<'PY'
import json, os, sys
path, root = sys.argv[1], sys.argv[2]
data = json.load(open(path))
total = files = 0
for d, _, names in os.walk(root):
    for n in names:
        files += 1
        total += os.path.getsize(os.path.join(d, n))
data["unpacked_bytes"], data["files"] = total, files
json.dump(data, open(path, "w"), indent=2)
PY
echo "Versión de prueba $VERSION lista en $FROM"
