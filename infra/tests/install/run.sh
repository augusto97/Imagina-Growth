#!/usr/bin/env bash
# Prueba de install.sh en un Ubuntu limpio (24.04 o 26.04) con systemd, dentro de un contenedor desechable.
# El contenedor es solo para verificar: la app no usa Docker. docs/despliegue.md §7.
#
#   infra/tests/install/run.sh up      [--name N] [--assets DIR]   crea la imagen y arranca el contenedor
#   infra/tests/install/run.sh install [--name N] -- <flags de install.sh>
#   infra/tests/install/run.sh exec    [--name N] -- <comando>     corre un comando como root adentro
#   infra/tests/install/run.sh down    [--name N]                  borra el contenedor y el reenvío del proxy
#
# --name N      nombre del contenedor (por defecto growth-it-1; siempre empieza con growth-it-)
# GROWTH_IT_UBUNTU=26.04   versión de Ubuntu del contenedor (por defecto 24.04)
# GROWTH_IT_MEMORY=1600m   límite de RAM del contenedor (y 2 GB de swap), para probar servidores pequeños;
#                          install pasa además GROWTH_MEMORY_MB con ese valor (el contenedor ve la RAM del anfitrión)
# --assets DIR  carpeta con versiones de prueba: growth-<v>.zip, .sha256, .sig, release-<v>.json, install.sh
#               y opcionalmente LATEST y notes-<v>.md. Se monta en /assets y se sirve adentro con
#               mock-releases.py en http://127.0.0.1:9999 (misma forma que la API de GitHub Releases).
#               Para usarla: install.sh --releases-api http://127.0.0.1:9999 --pubkey /assets/release-signing.pub.pem
#
# El repositorio se monta en /src (solo lectura): install.sh corre desde /src/infra/install.sh y toma las
# plantillas de infra/ (modo «desde el repositorio»). La salida HTTPS pasa por el proxy del entorno: se
# reenvía 172.17.0.1:<puerto> → 127.0.0.1:<puerto> y se confía en su CA (/root/.ccr/ca-bundle.crt).
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../../.." && pwd)
UBUNTU=${GROWTH_IT_UBUNTU:-24.04}
IMAGE=growth-it-ubuntu:$UBUNTU
# Imagen base: la oficial de Ubuntu (por defecto desde el espejo de Google, sin el límite de Docker Hub).
BASE_IMAGE=${GROWTH_IT_BASE_IMAGE:-mirror.gcr.io/library/ubuntu:$UBUNTU}
NAME=growth-it-1
ASSETS=""
CMD=${1:-}
[ $# -gt 0 ] && shift
REST=()
while [ $# -gt 0 ]; do
    case "$1" in
        --name) NAME=$2; shift 2 ;;
        --assets) ASSETS=$(cd "$2" && pwd); shift 2 ;;
        --) shift; REST=("$@"); break ;;
        *) echo "Opción desconocida: $1" >&2; exit 2 ;;
    esac
done
case "$NAME" in growth-it-*) ;; *) echo "El nombre debe empezar con growth-it-" >&2; exit 2 ;; esac

PROXY=${HTTPS_PROXY:-${https_proxy:-}}
PROXY_PORT=${PROXY##*:}
GATEWAY=$(docker network inspect bridge --format '{{range .IPAM.Config}}{{.Gateway}}{{end}}' 2>/dev/null || echo 172.17.0.1)
FWD_PID_FILE=/tmp/growth-it-proxy-$PROXY_PORT.pid

start_forwarder() {
    [ -n "$PROXY" ] || return 0
    if [ -f "$FWD_PID_FILE" ] && kill -0 "$(cat "$FWD_PID_FILE")" 2>/dev/null; then
        return 0
    fi
    # Reenvío TCP mínimo para que el contenedor use el mismo proxy (y su política) que esta máquina.
    python3 - "$GATEWAY" "$PROXY_PORT" >/tmp/growth-it-proxy.log 2>&1 <<'PY' &
import socket, sys, threading
host, port = sys.argv[1], int(sys.argv[2])
srv = socket.socket(); srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); srv.bind((host, port)); srv.listen(128)
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
    u = socket.create_connection(("127.0.0.1", port))
    threading.Thread(target=pipe, args=(c, u), daemon=True).start()
    threading.Thread(target=pipe, args=(u, c), daemon=True).start()
PY
    echo $! >"$FWD_PID_FILE"
    sleep 0.5
}

build_image() {
    docker image inspect "$IMAGE" >/dev/null 2>&1 && return 0
    docker build --build-arg BASE="$BASE_IMAGE" -t "$IMAGE" - <<'DOCKERFILE'
ARG BASE
FROM ${BASE}
ENV DEBIAN_FRONTEND=noninteractive container=docker
RUN apt-get update && apt-get install -y --no-install-recommends systemd systemd-sysv dbus ca-certificates curl \
      python3 iproute2 procps openssh-server sudo less \
 && rm -rf /var/lib/apt/lists/* \
 && systemctl mask getty.target console-getty.service systemd-logind.service \
 && systemctl disable ssh.service ssh.socket || true
STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
DOCKERFILE
}

in_env() {
    # Variables para los procesos que corren adentro (apt, curl, uv…).
    local px=""
    [ -n "$PROXY" ] && px="http://$GATEWAY:$PROXY_PORT"
    printf '%s\n' "-e" "HTTPS_PROXY=$px" "-e" "https_proxy=$px" \
        "-e" "NO_PROXY=localhost,127.0.0.1,::1" "-e" "no_proxy=localhost,127.0.0.1,::1" \
        "-e" "DEBIAN_FRONTEND=noninteractive"
    [ -n "${GROWTH_IT_MEMORY:-}" ] && printf '%s\n' "-e" "GROWTH_MEMORY_MB=${GROWTH_IT_MEMORY%m}"
    return 0
}

case "$CMD" in
    up)
        start_forwarder
        build_image
        docker rm -f "$NAME" >/dev/null 2>&1 || true
        # systemd 258+ (Ubuntu 26.04) ya no arranca con cgroup v1: si esta máquina usa v1, se le da una
        # jerarquía cgroup v2 propia montada aparte.
        CGROUP_SRC=/sys/fs/cgroup
        if [ "$UBUNTU" != 24.04 ] && [ "$(stat -fc %T /sys/fs/cgroup)" != cgroup2fs ]; then
            CGROUP_SRC=/tmp/growth-it-cgroup2
            mkdir -p "$CGROUP_SRC"
            mountpoint -q "$CGROUP_SRC" || mount -t cgroup2 none "$CGROUP_SRC"
        fi
        args=(-d --name "$NAME" --hostname "$NAME" --privileged --cgroupns=host
            -v "$CGROUP_SRC:/sys/fs/cgroup:rw" --tmpfs /run --tmpfs /run/lock
            -v "$REPO:/src:ro" -v /root/.ccr/ca-bundle.crt:/usr/local/share/ca-certificates/growth-it-proxy.crt:ro)
        [ -n "$ASSETS" ] && args+=(-v "$ASSETS:/assets")
        if [ -n "${GROWTH_IT_MEMORY:-}" ]; then
            mem_mb=${GROWTH_IT_MEMORY%m}
            args+=(--memory "${mem_mb}m" --memory-swap "$((mem_mb + 2048))m")
        fi
        docker run "${args[@]}" "$IMAGE" >/dev/null
        for _ in $(seq 1 30); do docker exec "$NAME" systemctl is-system-running >/dev/null 2>&1 && break
            [ "$(docker exec "$NAME" systemctl is-system-running 2>/dev/null || true)" = "degraded" ] && break; sleep 1; done
        docker exec "$NAME" update-ca-certificates >/dev/null
        # Proxy para los servicios de systemd (apt dentro de install.sh lo hereda del entorno de exec).
        mapfile -t envs < <(in_env)
        if [ -n "$ASSETS" ]; then
            docker exec -d "$NAME" python3 /src/infra/tests/install/mock-releases.py --dir /assets --port 9999
        fi
        echo "Contenedor $NAME listo (systemd: $(docker exec "$NAME" systemctl is-system-running 2>/dev/null || true))."
        ;;
    install)
        mapfile -t envs < <(in_env)
        docker exec "${envs[@]}" "$NAME" bash /src/infra/install.sh "${REST[@]}"
        ;;
    exec)
        mapfile -t envs < <(in_env)
        docker exec "${envs[@]}" "$NAME" "${REST[@]}"
        ;;
    down)
        docker rm -f "$NAME" >/dev/null 2>&1 || true
        if [ -f "$FWD_PID_FILE" ] && ! docker ps --format '{{.Names}}' | grep -q '^growth-it-'; then
            kill "$(cat "$FWD_PID_FILE")" 2>/dev/null || true
            rm -f "$FWD_PID_FILE"
        fi
        echo "Contenedor $NAME borrado."
        ;;
    *)
        sed -n 2,20p "$0"
        exit 2
        ;;
esac
