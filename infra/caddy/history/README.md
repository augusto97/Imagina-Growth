# Plantillas anteriores del Caddyfile

`growth-system` compara el `/etc/caddy/Caddyfile` instalado con estas plantillas (sin comentarios, espacios, correo
ni dominio). Si coincide con alguna, nadie lo editó a mano y se reemplaza por la plantilla actual; si no, la nueva
queda en `Caddyfile.growth.new`. Al cambiar `infra/caddy/Caddyfile`, copia aquí la versión anterior como
`<versión>.Caddyfile` (la prueba `infra/tests/system/run.sh` lo revisa).
