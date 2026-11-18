#!/usr/bin/env python3
"""API de GitHub Releases simulada para probar install.sh y el actualizador sin publicar nada.

    python3 mock-releases.py --dir /assets --port 9999 --repo augusto97/Imagina-Growth

Cada versión es un juego de archivos en --dir: growth-<v>.zip, growth-<v>.zip.sha256,
growth-<v>.zip.sha256.sig, release.json (opcional, de la versión más nueva) e install.sh.
La carpeta se relee en cada petición: copiar una versión nueva la publica al instante.

Rutas (las mismas que usan install.sh, growth-update y growth:updates:check):
  GET /repos/<repo>/releases/latest          la versión más alta (o la de --dir/LATEST si existe)
  GET /repos/<repo>/releases/tags/v<versión>
  GET /assets/<archivo>                      descarga de un asset
"""
import argparse
import json
import os
import re
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ARGS = None
ZIP = re.compile(r"^growth-(\d+\.\d+\.\d+)\.zip$")


def versions():
    found = []
    for name in os.listdir(ARGS.dir):
        m = ZIP.match(name)
        if m:
            found.append(m.group(1))
    return sorted(found, key=lambda v: tuple(int(x) for x in v.split(".")))


def release(version):
    base = f"http://{ARGS.host}:{ARGS.port}/assets/"
    assets = []
    for name in sorted(os.listdir(ARGS.dir)):
        if name.startswith(f"growth-{version}.zip") or name in ("install.sh",) or name == f"release-{version}.json":
            path = os.path.join(ARGS.dir, name)
            public = "release.json" if name == f"release-{version}.json" else name
            assets.append({
                "name": public,
                "size": os.path.getsize(path),
                "browser_download_url": base + name,
                "content_type": "application/octet-stream",
            })
    notes_path = os.path.join(ARGS.dir, f"notes-{version}.md")
    body = open(notes_path, encoding="utf-8").read() if os.path.exists(notes_path) else f"Versión {version} de prueba."
    return {
        "tag_name": f"v{version}",
        "name": f"v{version}",
        "draft": False,
        "prerelease": False,
        "published_at": "2026-11-18T12:00:00Z",
        "html_url": f"https://github.com/{ARGS.repo}/releases/tag/v{version}",
        "body": body,
        "assets": assets,
    }


class Handler(BaseHTTPRequestHandler):
    def send_json(self, code, data):
        raw = json.dumps(data).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self):  # noqa: N802
        path = self.path.split("?", 1)[0]
        prefix = f"/repos/{ARGS.repo}/releases/"
        if path == prefix + "latest":
            latest_file = os.path.join(ARGS.dir, "LATEST")
            vs = versions()
            if os.path.exists(latest_file):
                v = open(latest_file).read().strip()
            elif vs:
                v = vs[-1]
            else:
                return self.send_json(404, {"message": "Not Found"})
            return self.send_json(200, release(v))
        if path.startswith(prefix + "tags/v"):
            v = path[len(prefix + "tags/v"):]
            if v in versions():
                return self.send_json(200, release(v))
            return self.send_json(404, {"message": "Not Found"})
        if path.startswith("/assets/"):
            name = os.path.basename(path[len("/assets/"):])
            full = os.path.join(ARGS.dir, name)
            if not os.path.isfile(full):
                return self.send_json(404, {"message": "Not Found"})
            size = os.path.getsize(full)
            self.send_response(200)
            self.send_header("Content-Type", "application/octet-stream")
            self.send_header("Content-Length", str(size))
            self.end_headers()
            with open(full, "rb") as fh:
                while chunk := fh.read(1 << 20):
                    self.wfile.write(chunk)
            return None
        return self.send_json(404, {"message": "Not Found"})

    def log_message(self, fmt, *args):  # registro corto en stderr
        print("mock-releases:", fmt % args, flush=True)


if __name__ == "__main__":
    p = argparse.ArgumentParser()
    p.add_argument("--dir", required=True)
    p.add_argument("--port", type=int, default=9999)
    p.add_argument("--host", default="127.0.0.1")
    p.add_argument("--repo", default="augusto97/Imagina-Growth")
    ARGS = p.parse_args()
    ThreadingHTTPServer((ARGS.host, ARGS.port), Handler).serve_forever()
