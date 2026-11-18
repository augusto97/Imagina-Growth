# Despliegue nativo (systemd, versiones en zip y actualización desde el panel)

> Contrato del despliegue de producción del hub. Reemplaza a Docker Compose: el hub corre como servicios
> de systemd en un VPS de Ubuntu 24.04, cada versión es un zip publicado en GitHub Releases y el
> administrador de la plataforma actualiza con un clic. Lo que diga este documento manda sobre los
> scripts de `infra/`; si cambian, se cambia aquí en el mismo commit.

## 1. Componentes y puertos

Todo escucha en `127.0.0.1` salvo Caddy. El firewall (ufw) solo abre SSH, `80/tcp`, `443/tcp` y `443/udp`.

| Servicio (systemd) | Qué corre | Puerto |
|---|---|---|
| `growth-app` | FrankenPHP `php-server`: panel, API, SPA, webhooks, páginas públicas | 8080 |
| `growth-ingest-api@1`, `@2` | FrankenPHP `php-server`: ingesta `/v1/*` y `/p/*` (dos réplicas detrás de Caddy) | 8081, 8082 (`8080 + instancia`) |
| `growth-ingest@1` … `@4` | `artisan igr:ingest:work --shard=<instancia − 1>` | — |
| `growth-horizon` | `artisan horizon` | — |
| `growth-reverb` | `artisan reverb:start` | 8085 |
| `growth-scheduler` | `artisan schedule:work` | — |
| `growth-ml` | uvicorn (`hub/ml`, entorno de `uv`), 2 workers | 8095 |
| `mariadb` | MariaDB 11.4 (repositorio oficial de MariaDB) | 3306 |
| `clickhouse-server` | ClickHouse 25.8 (repositorio oficial, fijado a 25.8.*) | 8123 HTTP, 9000 nativo |
| `redis-server@queue` | Redis 7: colas, streams de ingesta, candados. `noeviction` + AOF (`everysec`) | 6379 |
| `redis-server@cache` | Redis 7: caché. `allkeys-lru`, sin persistencia | 6380 |
| `caddy` | Caddy (repositorio oficial), TLS automático y bajo demanda | 80, 443 |

Todos los servicios `growth-*` tienen `PartOf=growth.target` y `WantedBy=growth.target`; `growth.target` está en
`multi-user.target`. Corren como el usuario sin privilegios `growth` (sin shell de inicio de sesión), con
`WorkingDirectory=/opt/growth/current/hub` y `Restart=always`.

- **PHP:** FrankenPHP y PHP 8.4 ZTS del repositorio oficial de FrankenPHP para Debian y Ubuntu
  (`pkg.henderkes.com`, línea `84`): `frankenphp`, `php-zts-cli` (trae opcache, pcntl, sockets, sodium, exif,
  mbstring…), `php-zts-pdo-mysql`, `php-zts-mysqlnd`, `php-zts-redis`, `php-zts-intl`, `php-zts-zip`,
  `php-zts-gd`, `php-zts-gmp` y `php-zts-bcmath`. Ajustes en `/etc/php-zts/conf.d/90-growth.ini` (los de la
  imagen anterior: memoria 512M, opcache con `validate_timestamps=0`, zona UTC…).
- **`/usr/local/bin/growth-php`:** el PHP de línea de comandos para todo (unidades y scripts). Lo crea
  `install.sh` apuntando al binario CLI del paquete.
- **Python:** `uv` (instalador oficial de Astral) en `/usr/local/bin/uv`; Python lo baja `uv` a
  `/opt/growth/shared/python`; la caché de paquetes en `/opt/growth/shared/cache/uv`.

## 2. Estructura en disco

```
/opt/growth/                          root:root 0755
  releases/<versión>/                 growth   una carpeta por versión (sin la «v»: 0.2.0)
  current -> releases/<versión>       growth   symlink a la versión activa
  shared/                             growth
    .env                              0600     configuración y secretos (nunca en el repo)
    storage/                          Laravel storage: app/, framework/{cache,sessions,views}, logs/
    state/                            pedido, estado e historial del actualizador (§5)
    backups/pre-update/               copia de MariaDB antes de cada actualización (se conservan 5)
    tmp/                              descargas en curso
    cache/uv/  python/                caché de uv y Pythons administrados por uv
/etc/growth/                          root:root 0755
  updater.conf                        0644     repositorio y URL de la API de releases (§5)
  release-signing.pub.pem             0644     llave pública ed25519 que valida las versiones
  backup.conf                         0600     remoto de rclone, retención y heartbeats de Better Stack (§6)
/var/lib/growth-backups/{mariadb,clickhouse}   copias nocturnas antes de subirlas
```

En cada versión: `hub/.env -> /opt/growth/shared/.env`, `hub/storage -> /opt/growth/shared/storage` y
`hub/public/storage -> /opt/growth/shared/storage/app/public`.

## 3. Paquete de versión (GitHub Releases)

El workflow `release.yml` corre **al publicar un release** con tag `vX.Y.Z`; falla si `X.Y.Z` no coincide con el
archivo `VERSION` de la raíz. Arma el paquete con `infra/release/build.sh <versión>` y sube estos assets:

| Asset | Contenido |
|---|---|
| `growth-<v>.zip` | el paquete (abajo) |
| `growth-<v>.zip.sha256` | `<sha256 en hex>  growth-<v>.zip` |
| `growth-<v>.zip.sha256.sig` | firma ed25519 (64 bytes) del archivo `.sha256`, hecha con `openssl pkeyutl -sign -rawin` y la llave del secreto `GROWTH_RELEASE_SIGNING_KEY` (PEM). Sin el secreto, el workflow falla |
| `release.json` | el mismo de adentro del zip, para revisar espacio antes de descargar |
| `install.sh` | instalador para un servidor limpio |

Contenido del zip (sin carpeta raíz):

```
VERSION                     X.Y.Z
release.json                versión, commit, fecha, php, bytes y archivos descomprimidos, variables de entorno nuevas
hub/                        Laravel listo para correr: app, bootstrap (cache vacío), config, database (migraciones de
                            MariaDB y clickhouse/), lang, public (con build/ de la SPA), resources/views y
                            resources/schemas, routes, artisan, composer.json/lock y vendor (composer install --no-dev,
                            autoloader optimizado). Sin tests, node_modules, resources/js ni storage
hub/ml/                     app/, pyproject.toml y uv.lock (el entorno se crea en el servidor con uv sync --frozen)
bin/                        scripts del servidor (infra/bin)
systemd/                    unidades (infra/systemd)
config/                     plantillas de configuración (infra/config)
caddy/Caddyfile             plantilla del Caddyfile (infra/caddy)
install.sh                  el instalador
```

`release.json`:

```json
{
  "version": "0.2.0",
  "commit": "abc1234",
  "built_at": "2026-11-20T15:00:00Z",
  "php": "8.4",
  "unpacked_bytes": 123456789,
  "files": 23456,
  "env": [
    { "name": "REDIS_CACHE_PORT", "default": "6380", "secret": false },
    { "name": "IGR_ML_TOKEN", "default": null, "secret": true }
  ]
}
```

`env` lista **todas** las variables que la versión necesita, con su valor por defecto seguro. `secret: true`
significa que se genera (32 bytes aleatorios) solo si falta; nunca se regeneran `APP_KEY` ni
`IGR_BLIND_INDEX_KEY` en una actualización: si faltan, la actualización se detiene (sin ellos los datos
cifrados y los índices ciegos ya guardados no se pueden leer).

## 4. Salud y versión

- `GET /health/ready` (sin dominio, cualquier host): `200 {"status":"ok","version":"X.Y.Z","checks":{...}}` si
  MariaDB, ClickHouse y Redis responden; `503` si no. La versión sale del archivo `VERSION` de la raíz de la
  versión (`base_path('../VERSION')`; en desarrollo, el `VERSION` del repositorio).
- `GET /health` (ya existe) sigue siendo la salud de la ingesta con el atraso de los streams.
- El health-check del actualizador exige `status = ok` **y** `version` igual a la versión instalada en
  `growth-app` (8080) y en cada `growth-ingest-api@N`, para no confundirse con un proceso viejo.

## 5. Actualización desde el panel

1. **Detectar:** `growth:updates:check` (cada hora, en el scheduler) consulta
   `GET {GROWTH_UPDATE_API}/repos/{GROWTH_UPDATE_REPO}/releases/latest` y guarda el resultado en
   `shared/state/latest-release.json`. El panel `/plataforma` muestra la versión actual, la nueva y sus notas.
2. **Pedir:** solo un administrador de la plataforma (`is_platform_admin`, con 2FA y confirmando su contraseña).
   La app escribe (atómico: temporal + rename) `shared/state/update-request.json`:
   `{"version":"0.2.0","requested_by":"usr_…","requested_at":"…","id":"upd_…"}`. Si ya hay un pedido o una
   actualización en curso, responde 409. La app **no** actualiza nada.
3. **Despachar:** `growth-update.path` (root) vigila ese archivo y arranca `growth-update-dispatch.service`
   (root, oneshot): valida la versión (`^[0-9]+\.[0-9]+\.[0-9]+$`), mueve el pedido a
   `update-request.processing.json` y lanza `systemd-run --unit=growth-update-<id> --collect` con el script
   `growth-update` **de la versión actual** (`/opt/growth/current/bin/growth-update <versión>`). Al correr en su
   propia unidad transitoria, reiniciar `growth.target` no lo mata.
4. **Actualizar** (`growth-update`, root; lo que toca la app corre como `growth` con `runuser`), en este orden:
   1. candado `flock /run/lock/growth-update.lock` (si está tomado, termina sin hacer nada);
   2. **preflight:** espacio libre ≥ 3 × `unpacked_bytes` + tamaño de la base, e inodos ≥ 2 × `files`, en
      `/opt/growth`; si no alcanza, termina **sin descargar** el zip;
   3. **descargar** zip, `.sha256` y `.sig` a `shared/tmp`; **verificar** la firma con
      `/etc/growth/release-signing.pub.pem` y luego el sha256 del zip;
   4. **extraer** en `releases/<v>.partial` y renombrar a `releases/<v>`; **enlazar** `shared/` (§2);
      `uv sync --frozen --no-dev` en `hub/ml`; reparar `shared/.env` con `release.json` (`growth-env repair`);
   5. `config:cache`, `route:cache`, `view:cache` y `event:cache` con la versión nueva;
   6. **copia de MariaDB** (`mariadb-dump --single-transaction`, comprimida) en `shared/backups/pre-update`;
   7. **migraciones** de MariaDB (`migrate --force`) y de ClickHouse (`igr:clickhouse:migrate`), con el código
      nuevo, antes del cambio; tienen que ser compatibles con la versión anterior;
   8. **cambio** atómico del symlink (`ln -s` a un temporal y `mv -T`); instala las unidades de `systemd/` si
      cambiaron (`daemon-reload`) y **reinicia sin cortes**: `growth-ingest-api@1`, espera su health-check,
      luego `@2`; `growth-ingest@1..4` de a uno (SIGTERM: terminan el lote); `growth-horizon` (SIGTERM: termina
      los trabajos); `growth-scheduler`, `growth-reverb`, `growth-ml` y `growth-app` (Caddy reintenta
      mientras reinicia, `lb_try_duration`);
   9. **health-check** (§4) hasta `GROWTH_HEALTH_TIMEOUT` segundos (120). Si falla: vuelve el symlink a la
      versión anterior y reinicia otra vez en el mismo orden. Las migraciones no se revierten (son compatibles);
      la copia queda para restaurar a mano;
   10. **limpieza:** deja 3 versiones (nunca borra `current` ni la anterior), 5 copias previas y borra
       `*.partial` y descargas viejas de `shared/tmp`.
   Si falla en cualquier paso antes del cambio, borra el zip y la carpeta a medio extraer y deja `current` como
   estaba.
5. **Estado:** el script escribe `shared/state/update-status.json` en cada paso y agrega una línea a
   `shared/state/update-history.jsonl` al terminar. Formato:

```json
{
  "id": "upd_…", "from": "0.1.0", "to": "0.2.0", "requested_by": "usr_…",
  "state": "running",
  "step": "migrate",
  "steps": [{ "key": "preflight", "status": "done", "started_at": "…", "finished_at": "…", "message": null }],
  "started_at": "…", "finished_at": null, "error": null
}
```

`state`: `queued | running | succeeded | failed | rolled_back`. Pasos (`key`): `preflight`, `download`,
`verify`, `extract`, `caches`, `backup`, `migrate`, `switch`, `restart`, `health`, `prune` (y `rollback` si
hace falta). `status` de un paso: `pending | running | done | failed | skipped`.

`/etc/growth/updater.conf` (dotenv, root):

```
GROWTH_UPDATE_REPO=augusto97/Imagina-Growth
GROWTH_UPDATE_API=https://api.github.com
GROWTH_HEALTH_TIMEOUT=120
```

La app usa `GROWTH_UPDATE_REPO` y `GROWTH_UPDATE_API` del `.env` solo para **mostrar** la versión nueva; el
script descarga con lo que dice `updater.conf` (root) y valida con la llave de `/etc/growth`, así una app
comprometida no puede instalar un zip ajeno.

## 6. Copias y vigilancia

- `growth-backup-mariadb.timer` (03:15 UTC): `mariadb-backup --backup` + `--prepare`, comprimida, binlogs
  cerrados, subida con `rclone` al remoto de `/etc/growth/backup.conf` (Storage Box, remoto cifrado).
- `growth-backup-clickhouse.timer` (03:45 UTC): `BACKUP DATABASE … TO Disk('backups', …)` nativo, completa los
  domingos e incremental los demás días, subida con `rclone`.
- `growth-backup-verify.timer` (domingos 05:00 UTC): restaura la última copia de MariaDB en una instancia
  temporal (otro datadir y puerto) y la de ClickHouse en una base temporal (`RESTORE … AS growth_restore_check`),
  compara conteos y borra todo al terminar.
- `growth-monitor.timer` (cada minuto): `artisan growth:monitor` mide el atraso de los streams de Redis, la cola
  pendiente de Horizon y la salud, y avisa a los heartbeats de Better Stack solo si todo está bien (si un
  heartbeat no llega, Better Stack alerta). Cada copia y la prueba de restauración también avisan a su heartbeat
  (y a `/fail` si fallan). Los heartbeats van en `/etc/growth/backup.conf` y en `shared/.env`, nunca en el repo.

## 7. Instalación (`install.sh`)

`curl -fsSL https://github.com/augusto97/Imagina-Growth/releases/latest/download/install.sh | sudo bash -s -- --domain growth.ejemplo.com`

Instala los repositorios oficiales y los paquetes (§1), crea el usuario `growth`, la estructura (§2), el
`.env` con secretos nuevos, las bases (MariaDB `igr` y usuario, ClickHouse `igr` y usuario), las dos
instancias de Redis, la configuración de PHP, las unidades y el firewall; escribe `/etc/growth/*`, el
Caddyfile (solo si no existe) y baja e instala la última versión con `growth-update --install`.
**Idempotente:** correrlo otra vez no pisa el `.env`, ni las bases, ni el Caddyfile, ni los datos.
