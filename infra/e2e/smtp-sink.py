#!/usr/bin/env python3
"""Buzón SMTP mínimo para las pruebas de punta a punta (sin dependencias).

    python3 smtp-sink.py 127.0.0.1 2526 /ruta/buzon

Acepta cualquier mensaje y lo guarda como <n>.eml en la carpeta indicada, con
los destinatarios del sobre en la cabecera X-Igr-Rcpt-To. No es un servidor de
correo: solo sirve para comprobar que el hub entregó un mensaje por SMTP.
"""
import os
import socketserver
import sys
import threading

HOST, PORT, BOX = sys.argv[1], int(sys.argv[2]), sys.argv[3]
os.makedirs(BOX, exist_ok=True)
counter = threading.Lock()


class Handler(socketserver.StreamRequestHandler):
    def reply(self, line: str) -> None:
        self.wfile.write((line + "\r\n").encode())

    def handle(self) -> None:
        self.reply("220 igr-e2e ESMTP")
        rcpt: list[str] = []
        while True:
            raw = self.rfile.readline()
            if not raw:
                return
            cmd = raw.decode(errors="replace").strip()
            verb = cmd.split(" ", 1)[0].upper()
            if verb in ("EHLO", "HELO"):
                self.wfile.write(b"250-igr-e2e\r\n250-8BITMIME\r\n250 SMTPUTF8\r\n")
            elif verb == "MAIL":
                rcpt = []
                self.reply("250 OK")
            elif verb == "RCPT":
                rcpt.append(cmd.split(":", 1)[1].strip().strip("<>"))
                self.reply("250 OK")
            elif verb == "DATA":
                self.reply("354 Fin con <CRLF>.<CRLF>")
                lines = []
                while True:
                    line = self.rfile.readline()
                    if line in (b".\r\n", b".\n", b""):
                        break
                    lines.append(line[1:] if line.startswith(b"..") else line)
                with counter:
                    n = len([f for f in os.listdir(BOX) if f.endswith(".eml")]) + 1
                    with open(os.path.join(BOX, f"{n}.eml"), "wb") as fh:
                        fh.write(f"X-Igr-Rcpt-To: {', '.join(rcpt)}\r\n".encode())
                        fh.write(b"".join(lines))
                self.reply("250 OK guardado")
            elif verb == "QUIT":
                self.reply("221 Adiós")
                return
            elif verb in ("RSET", "NOOP"):
                self.reply("250 OK")
            else:
                self.reply("502 No implementado")


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


if __name__ == "__main__":
    with Server((HOST, PORT), Handler) as srv:
        srv.serve_forever()
