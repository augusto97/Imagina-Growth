#!/usr/bin/env bash
# Prueba final del despliegue nativo (docs/despliegue.md), en un Ubuntu 24.04 limpio con systemd dentro de un
# contenedor desechable (solo para verificar; la app no usa Docker):
#   1. install.sh instala el servidor y la versión 0.1.0 (firmada con una llave de prueba);
#   2. desde el panel (navegador), el administrador instala 0.2.0, que trae una migración y una variable nueva,
#      mientras se mide que el panel y la ingesta no se corten;
#   3. desde el panel instala 0.3.0, rota a propósito (/health/ready responde 503): tiene que volver sola a 0.2.0.
#
#   e2e-update.sh --assets DIR --key llave-privada.pem [--keep]
#
# DIR tiene growth-0.1.0.zip, .sha256, .sig, release-0.1.0.json, install.sh y release-signing.pub.pem (armados con
# infra/release/build.sh y sign.sh). Deja capturas y registros en DIR/e2e/.
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
RUN="$HERE/run.sh"
NAME=growth-it-e2e
# *.localhost: Chromium nunca lo manda por un proxy y Caddy le da certificados locales solo.
DOMAIN=growth.localhost
ASSETS="" KEY="" KEEP=0
while [ $# -gt 0 ]; do
    case "$1" in
        --assets) ASSETS=$(cd "$2" && pwd); shift 2 ;;
        --key) KEY=$2; shift 2 ;;
        --keep) KEEP=1; shift ;;
        *) echo "Opción desconocida: $1" >&2; exit 2 ;;
    esac
done
[ -n "$ASSETS" ] && [ -f "$KEY" ] || { echo "Faltan --assets o --key" >&2; exit 2; }
OUT="$ASSETS/e2e"
mkdir -p "$OUT"

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
ok() { printf '\033[32m✓\033[0m %s\n' "$*"; }
fail() { printf '\033[31m✗ %s\033[0m\n' "$*"; exit 1; }
in_box() { "$RUN" exec --name "$NAME" -- bash -c "$1"; }
as_growth() { in_box "cd /opt/growth/current/hub && runuser -u growth -- env HOME=/tmp /usr/local/bin/growth-php $1"; }
health() { in_box "curl -s http://127.0.0.1:$1/health/ready" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("status"), d.get("version"))'; }
FWD_PID=""
cleanup() {
    in_box 'touch /tmp/stop-availability' >/dev/null 2>&1 || true
    [ -n "$FWD_PID" ] && kill "$FWD_PID" 2>/dev/null || true
    [ "$KEEP" = 1 ] || "$RUN" down --name "$NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

step "Servidor limpio: install.sh instala la versión 0.1.0"
echo 0.1.0 >"$ASSETS/LATEST"
"$RUN" down --name "$NAME" >/dev/null 2>&1 || true
"$RUN" up --name "$NAME" --assets "$ASSETS"
"$RUN" install --name "$NAME" -- --domain "$DOMAIN" --email ops@e2e.test --releases-api http://127.0.0.1:9999 \
    --pubkey /assets/release-signing.pub.pem --skip-firewall 2>&1 | tee "$OUT/install.log" | tail -5
[ "$(in_box 'readlink -f /opt/growth/current')" = "/opt/growth/releases/0.1.0" ] || fail "current no apunta a 0.1.0"
[ "$(health 8080)" = "ok 0.1.0" ] || fail "/health/ready no responde 0.1.0"
ok "0.1.0 instalada: $(in_box 'systemctl list-units "growth*" --state=active --no-legend | wc -l') unidades growth activas"

step "Segunda corrida de install.sh: no cambia nada"
before=$(in_box 'sha256sum /opt/growth/shared/.env /etc/growth/updater.conf /etc/caddy/Caddyfile | sha256sum')
"$RUN" install --name "$NAME" -- --releases-api http://127.0.0.1:9999 --pubkey /assets/release-signing.pub.pem --skip-firewall >"$OUT/install-2.log" 2>&1
after=$(in_box 'sha256sum /opt/growth/shared/.env /etc/growth/updater.conf /etc/caddy/Caddyfile | sha256sum')
[ "$before" = "$after" ] || fail "la segunda corrida cambió el .env, updater.conf o el Caddyfile"
[ "$(in_box 'readlink -f /opt/growth/current')" = "/opt/growth/releases/0.1.0" ] || fail "la segunda corrida cambió la versión"
ok "Idempotente: .env, updater.conf, Caddyfile y versión iguales"

step "Acceso al panel desde el navegador de prueba"
IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$NAME")
# Solo en la prueba: el 443 de esta máquina reenvía al Caddy del servidor de prueba (growth.localhost → 127.0.0.1).
python3 - "$IP" >"$OUT/reenvio-443.log" 2>&1 <<'PY' &
import socket, sys, threading
target = sys.argv[1]
srv = socket.socket(); srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); srv.bind(("127.0.0.1", 443)); srv.listen(128)
def pipe(a, b):
    try:
        while (data := a.recv(65536)):
            b.sendall(data)
    except OSError:
        pass
    finally:
        for s in (a, b):
            try: s.shutdown(socket.SHUT_RDWR)
            except OSError: pass
while True:
    c, _ = srv.accept()
    try:
        u = socket.create_connection((target, 443), timeout=10)
    except OSError:
        c.close(); continue
    threading.Thread(target=pipe, args=(c, u), daemon=True).start()
    threading.Thread(target=pipe, args=(u, c), daemon=True).start()
PY
FWD_PID=$!
ADMIN_EMAIL=admin@e2e.test ADMIN_PASS=Clave-plataforma-2026 TOTP_SECRET=JBSWY3DPEHPK3PXP
WS=$(as_growth "artisan tinker --execute='
\$u = App\Domain\Workspaces\Models\User::create([\"name\" => \"Equipo Imagina\", \"email\" => \"$ADMIN_EMAIL\", \"password\" => \"$ADMIN_PASS\"]);
\$u->forceFill([\"two_factor_secret\" => encrypt(\"$TOTP_SECRET\"), \"two_factor_confirmed_at\" => now(), \"is_platform_admin\" => true])->save();
\$ws = app(App\Domain\Workspaces\Actions\CreateWorkspace::class)->handle(\$u, new App\Domain\Workspaces\Data\WorkspaceData(name: \"Imagina\"));
echo \$ws->publicId();'" | tail -1)
[[ $WS =~ ^ws_ ]] || fail "no se creó el administrador de la plataforma"
APP_KEY_BEFORE=$(in_box "grep -E '^APP_KEY=' /opt/growth/shared/.env")
ok "Administrador de la plataforma en $WS (https://$DOMAIN → 127.0.0.1:443 → $IP)"

panel() {
    BASE="https://$DOMAIN" ADMIN_EMAIL="$ADMIN_EMAIL" ADMIN_PASS="$ADMIN_PASS" TOTP_SECRET="$TOTP_SECRET" \
        WS="$WS" SHOTS="$OUT" node "$HERE/panel.cjs" "$1" | tail -1
}
status() { in_box 'cat /opt/growth/shared/state/update-status.json'; }

step "Versión 0.2.0 desde el panel, sin cortes"
"$HERE/make-variant.sh" --from "$ASSETS" --base 0.1.0 --version 0.2.0 --key "$KEY" --migration --env E2E_NUEVA=hola
echo 0.2.0 >"$ASSETS/LATEST"
# Mientras dura la actualización: el panel (/up) y la ingesta (/p/…) pedidos a Caddy cada 200 ms.
in_box "rm -f /tmp/stop-availability /tmp/availability.log; nohup bash -c 'while [ ! -f /tmp/stop-availability ]; do
  a=\$(curl -sk -o /dev/null -w \"%{http_code}\" --resolve $DOMAIN:443:127.0.0.1 https://$DOMAIN/up);
  i=\$(curl -sk -o /dev/null -w \"%{http_code}\" --resolve in.$DOMAIN:443:127.0.0.1 https://in.$DOMAIN/p/pk_e2e/visitor.json);
  echo \"\$(date +%s.%N) \$a \$i\" >>/tmp/availability.log; sleep 0.2; done' >/dev/null 2>&1 &"
RESULT=$(panel 0.2.0)
in_box 'touch /tmp/stop-availability'
echo "$RESULT"
[ "$(echo "$RESULT" | python3 -c 'import json,sys; print(json.load(sys.stdin)["state"])')" = "succeeded" ] || { status; fail "el panel no mostró 0.2.0 instalada"; }
[ "$(in_box 'readlink -f /opt/growth/current')" = "/opt/growth/releases/0.2.0" ] || fail "current no apunta a 0.2.0"
for port in 8080 8081 8082; do [ "$(health $port)" = "ok 0.2.0" ] || fail "el puerto $port no responde 0.2.0"; done
in_box "mariadb -N -e 'SHOW TABLES IN igr LIKE \"e2e_upgrade_marker\"'" | grep -q e2e_upgrade_marker || fail "no corrió la migración nueva"
in_box "grep -qx 'E2E_NUEVA=hola' /opt/growth/shared/.env" || fail "no se agregó la variable nueva al .env"
[ "$(in_box "grep -E '^APP_KEY=' /opt/growth/shared/.env")" = "$APP_KEY_BEFORE" ] || fail "la actualización cambió APP_KEY"
in_box 'ls /opt/growth/shared/backups/pre-update/' | grep -q '0.1.0-to-0.2.0' || fail "no hubo copia de la base antes de migrar"
in_box 'cmp -s /usr/local/lib/growth/bin/growth-update /opt/growth/releases/0.2.0/bin/growth-update' || fail "no se instalaron los scripts de root de 0.2.0"
[ "$(in_box 'stat -c %U /opt/growth/releases/0.2.0/hub/artisan')" = "root" ] || fail "la versión no quedó de root"
total=$(in_box 'wc -l </tmp/availability.log')
bad=$(in_box "awk '\$2 !~ /^(200)\$/ || \$3 !~ /^(200|404)\$/' /tmp/availability.log | wc -l")
in_box 'cat /tmp/availability.log' >"$OUT/disponibilidad-0.2.0.log"
[ "$bad" = 0 ] || { in_box "awk '\$2 !~ /^(200)\$/ || \$3 !~ /^(200|404)\$/' /tmp/availability.log | head"; fail "$bad de $total pedidos fallaron durante la actualización"; }
ok "0.2.0 instalada desde el panel: migración y variable nuevas, APP_KEY intacta, copia previa, scripts de root al día; $total pedidos al panel y a la ingesta sin un corte"

step "Versión 0.3.0 rota desde el panel: vuelve sola a 0.2.0"
"$HERE/make-variant.sh" --from "$ASSETS" --base 0.1.0 --version 0.3.0 --key "$KEY" --break-health
echo 0.3.0 >"$ASSETS/LATEST"
RESULT=$(panel 0.3.0)
echo "$RESULT"
[ "$(echo "$RESULT" | python3 -c 'import json,sys; print(json.load(sys.stdin)["state"])')" = "rolled_back" ] || { status; fail "el panel no mostró la vuelta atrás"; }
[ "$(in_box 'readlink -f /opt/growth/current')" = "/opt/growth/releases/0.2.0" ] || fail "current no volvió a 0.2.0"
for port in 8080 8081 8082; do [ "$(health $port)" = "ok 0.2.0" ] || fail "el puerto $port no volvió a 0.2.0"; done
in_box 'cmp -s /usr/local/lib/growth/bin/growth-update /opt/growth/releases/0.2.0/bin/growth-update' || fail "los scripts de root cambiaron tras la vuelta atrás"
status | python3 -c 'import json,sys; d=json.load(sys.stdin); print("estado:", d["state"], "· error:", d.get("error"))'
ok "0.3.0 falló el health-check y el servidor volvió a 0.2.0 solo"

printf '\n\033[1mDespliegue nativo: todo en verde.\033[0m Capturas y registros en %s\n' "$OUT"
