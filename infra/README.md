# infra/

Despliegue del hub **sin Docker**: servicios nativos de systemd en Ubuntu 24.04 o 26.04 LTS, versiones en zip (firma opcional) en
GitHub Releases, el symlink `current` y actualización con un clic desde el panel de la plataforma. El contrato
completo (procesos, puertos, rutas, paquete, actualizador, copias y vigilancia) está en
[`docs/despliegue.md`](../docs/despliegue.md); lo que diga ese documento manda sobre los scripts.

| Carpeta o archivo | Para qué |
|---|---|
| `install.sh` | Instalador para un servidor limpio: repositorios oficiales, MariaDB 11.4 (11.8 en 26.04), ClickHouse 25.8, dos Redis 7.4 (8 en 26.04), FrankenPHP con PHP 8.4, uv, Caddy, usuario `growth`, `/opt/growth`, `.env` con secretos, unidades, firewall y la última versión. Idempotente |
| `systemd/` | Unidades: `growth.target`, `growth-app`, `growth-ingest-api@`, `growth-ingest@`, `growth-horizon`, `growth-reverb`, `growth-scheduler`, `growth-ml`, `growth-update.path` + `growth-update-dispatch`, las copias y la vigilancia (timers) y nuestra plantilla `redis-server@` |
| `bin/` | Scripts del servidor: `growth-update` (actualiza o instala una versión), `growth-update-dispatch` (atiende el pedido del panel con `systemd-run`), `growth-env` (lee y repara el `.env` sin `source`) y los envoltorios de los servicios |
| `config/` | Plantillas de PHP, MariaDB, ClickHouse, Redis (colas y caché) y el Caddyfile interno de FrankenPHP |
| `caddy/Caddyfile` | Caddy de entrada: panel, ingesta (dos réplicas con reintento), Reverb y dominios de clientes con TLS bajo demanda. `install.sh` lo escribe solo si no existe; el actualizador nunca lo toca |
| `backup/` | Copias de MariaDB y ClickHouse al Storage Box, prueba semanal de restauración y guías (`README.md`, `RESTAURACION.md`) |
| `release/` | Paquete de versión: `build.sh`, `sign.sh`, `verify.sh`, `keygen.sh` y `env.json` (las variables del `.env`) |
| `dev/` | Desarrollo local sin Docker: `setup-local.sh` y su guía |
| `tests/` | Pruebas de los scripts: `release/`, `updater/` (el «deployer falso»), `backup/` e `install/` (Ubuntu limpio en un contenedor desechable, solo para verificar) |
| `e2e/` | Recorridos de punta a punta de cada fase |
| `wordpress/igr-setup.sh` | Tienda WooCommerce de prueba con el plugin (WP-CLI) |

## Desarrollo local

Ver [`dev/README.md`](dev/README.md): `sudo infra/dev/setup-local.sh` instala MariaDB, ClickHouse y Redis nativos y
crea los usuarios y bases de desarrollo. En el CI las pruebas usan los service containers de GitHub Actions (son del
CI, no de la app).

## Publicar una versión

1. **Firma (opcional, se puede activar después):** `infra/release/keygen.sh` crea el par de llaves ed25519. La
   privada va al secreto `GROWTH_RELEASE_SIGNING_KEY` del repositorio y **nunca** al repositorio. Sin el secreto,
   las versiones salen sin firma y los servidores validan solo el sha256 (`docs/despliegue.md` §3).
2. Sube el número en `VERSION` (por ejemplo `0.2.0`) y fusiona a `main` con el CI en verde.
3. Publica un release con el tag `v0.2.0`. El workflow `release.yml` falla si el tag no coincide con `VERSION`; si
   no, arma, verifica y sube `growth-0.2.0.zip`, su `.sha256`, `release.json` e `install.sh` (y, con el secreto, el
   `.sig` y `release-signing.pub.pem`).
4. Los servidores lo ven en la próxima consulta horaria; el administrador de la plataforma lo instala desde
   `/plataforma` → «Actualizaciones».

## Instalar un servidor

Requisitos: Ubuntu 24.04 o 26.04 LTS limpio, **mínimo 4 GB de RAM** (recomendado 8 GB y 4 vCPU). Con menos
memoria el instalador se detiene.

```bash
curl -fsSL https://github.com/augusto97/Imagina-Growth/releases/latest/download/install.sh \
  | sudo bash -s -- --domain growth.ejemplo.com --email ops@ejemplo.com
```

Después:
- DNS del dominio (y de `in.` para la ingesta) hacia el servidor; `domains.growth.imagina.cloud` (o
  `IGR_CUSTOM_DOMAINS_CNAME`) para los dominios propios de las agencias.
- Completar en `/opt/growth/shared/.env` lo que no se puede generar: correo (SES), Meta, Anthropic.
- Configurar `rclone` y `/etc/growth/backup.conf` para las copias ([`backup/README.md`](backup/README.md)) y los
  heartbeats y monitores de Better Stack.
- Crear el primer administrador de la plataforma (`growth-php artisan tinker` como `growth`).

Correr `install.sh` otra vez no pisa el `.env`, las bases, el Caddyfile ni la versión instalada.
