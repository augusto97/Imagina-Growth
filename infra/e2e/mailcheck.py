#!/usr/bin/env python3
"""Busca en el buzón de la prueba (smtp-sink.py) los correos para un destinatario.

    python3 mailcheck.py /ruta/buzon destinatario@x.test [texto]

Imprime una línea JSON por correo: asunto, si trae un PDF adjunto (y su tamaño) y si el
cuerpo contiene el texto pedido. Código 1 si no hay ninguno.
"""
import email
import email.policy
import glob
import json
import sys

box, rcpt = sys.argv[1], sys.argv[2].lower()
needle = sys.argv[3] if len(sys.argv) > 3 else None
found = 0
for path in sorted(glob.glob(f"{box}/*.eml")):
    with open(path, "rb") as fh:
        msg = email.message_from_binary_file(fh, policy=email.policy.default)
    if rcpt not in (msg.get("X-Igr-Rcpt-To", "") + " " + msg.get("To", "")).lower():
        continue
    found += 1
    pdf, text = None, ""
    for part in msg.walk():
        ctype = part.get_content_type()
        if ctype == "application/pdf":
            pdf = len(part.get_payload(decode=True) or b"")
        elif ctype in ("text/plain", "text/html"):
            text += part.get_content()
    print(json.dumps({"subject": str(msg.get("Subject", "")), "pdf_bytes": pdf, "has_text": (needle in text) if needle else None}, ensure_ascii=False))
sys.exit(0 if found else 1)
