# Despliegue nativo (systemd, versiones en zip y actualización desde el panel)

> Contrato del despliegue de producción del hub. Reemplaza a Docker Compose: el hub corre como servicios
> de systemd en un VPS de Ubuntu 24.04 o 26.04 LTS, cada versión es un zip publicado en GitHub Releases y el
> administrador de la plataforma actualiza con un clic. Lo que diga este documento manda sobre los
> scripts de `infra/`; si cambian, se cambia aquí en el mismo commit.

## 1. Componentes y puertos

Todo escucha en `127.0.0.1` salvo Caddy. El firewall (ufw) solo abre SSH, `80/tcp`, `443/tcp` y `443/udp`.

| Servicio (systemd) | Qué corre | Puerto |
|---|---|---|
| `growth-app` | FrankenPHP (`frankenphp run` con `config/frankenphp/Caddyfile`, admin apagado): panel, API, SPA, webhooks, páginas públicas | 8080 |
| `growth-ingest-api@1`, `@2` | FrankenPHP: ingesta `/v1/*` y `/p/*` (dos réplicas detrás de Caddy, que las balancea con chequeo en `/up` para no sacarlas si ClickHouse va lento) | 8081, 8082 (`8080 + instancia`) |
| `growth-ingest@1` … `@4` | `artisan igr:ingest:work --shard=<instancia − 1>` | — |
| `growth-horizon` | `artisan horizon` | — |
| `growth-reverb` | `artisan reverb:start` | 8085 |
| `growth-scheduler` | `artisan schedule:work` | — |
| `growth-ml` | uvicorn (`hub/ml`, entorno de `uv`), 2 workers | 8095 |
| `mariadb` | MariaDB LTS del repositorio oficial: 11.4 en Ubuntu 24.04 y 11.8 en 26.04 (el repositorio no publica 11.4 para 26.04) | 3306 |
| `clickhouse-server` | ClickHouse 25.8 (repositorio oficial, fijado a 25.8.*) | 8123 HTTP, 9000 nativo |
| `redis-server@queue` | Redis del repositorio oficial, fijado por sistema: `7.4.*` en Ubuntu 24.04 (si no, instala Redis 8) y `8.*` en 26.04 (no hay Redis 7 para 26.04); la app usa lo mismo en ambas: colas, streams de ingesta, candados. `noeviction` + AOF (`everysec`). La plantilla `redis-server@.service` es nuestra: el paquete oficial no la trae | 6379 |
| `redis-server@cache` | La misma versión de Redis: caché. `allkeys-lru`, sin persistencia | 6380 |
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
  `install.sh` apuntando a `/usr/bin/php-zts` (configuración en `/etc/php-zts/`). El paquete `frankenphp` trae su
  propio `frankenphp.service` en 80/443: el instalador lo desactiva y lo enmascara.
- **Python:** `uv` (instalador oficial de Astral) en `/usr/local/bin/uv`; Python lo baja `uv` a
  `/opt/growth/shared/python`; la caché de paquetes en `/opt/growth/shared/cache/uv`.

## 2. Estructura en disco

```
/opt/growth/                          root:root 0755
  releases/<versión>/                 root     una carpeta por versión (sin la «v»: 0.2.0), solo lectura para growth
  current -> releases/<versión>       root     symlink a la versión activa
  shared/                             growth   lo único que growth puede escribir
    .env                              0600     configuración y secretos (nunca en el repo)
    storage/                          Laravel storage: app/, framework/{cache,sessions,views}, logs/
    state/                            pedido, estado e historial del actualizador (§5)
    backups/pre-update/               copia de MariaDB antes de cada actualización (se conservan 5)
    tmp/                              descargas en curso
    cache/uv/  python/                caché de uv y Pythons administrados por uv
    venvs/<versión>/                  entorno de Python del ML de cada versión (`hub/ml/.venv` apunta aquí)
/usr/local/lib/growth/                root:root 0755  scripts que corren como root (copiados de la versión verificada)
  bin/growth-update  bin/growth-update-dispatch  bin/growth-env
  backup/growth-backup-{mariadb,clickhouse,verify}  backup/growth-backup-lib.sh
/etc/growth/                          root:root 0755
  updater.conf                        0644     repositorio y URL de la API de releases (§5)
  release-signing.pub.pem             0644     llave pública ed25519 que valida las versiones (opcional, §3)
  clickhouse-default.pass             0600     contraseña aleatoria del usuario `default` de ClickHouse (nadie lo usa)
  backup.conf                         0600     remoto de rclone, retención y heartbeats de Better Stack (§6)
/var/lib/growth/<unidad>/             estado interno de cada proceso de FrankenPHP
/var/lib/growth-backups/              root 0711    copias nocturnas antes de subirlas
  mariadb/                            root 0700
  clickhouse/                         clickhouse 0700 (disco `backups` de ClickHouse)
```

**Regla de seguridad:** root nunca ejecuta nada de una ruta que `growth` pueda escribir. Las versiones son de
root y de solo lectura para la app; los scripts de root viven en `/usr/local/lib/growth/` y se reemplazan solo
con los de una versión verificada (sha256, y la firma si el servidor tiene llave pública) que pasó el health-check.

En cada versión: `hub/.env -> /opt/growth/shared/.env`, `hub/storage -> /opt/growth/shared/storage` y
`hub/public/storage -> /opt/growth/shared/storage/app/public`.

## 3. Paquete de versión (GitHub Releases)

El workflow `release.yml` publica cada versión **solo**: un push que cambia el archivo `VERSION` de la raíz (en
`main` o en la rama de trabajo que lista el workflow) crea el tag `vX.Y.Z` en ese commit y el release, con notas
generadas de los commits. Si el release ya existe no hace nada y, si `VERSION` es menor que la última publicada,
falla. También corre al publicar a mano un release con tag `vX.Y.Z` (falla si no coincide con `VERSION`). Arma el
paquete con `infra/release/build.sh <versión>` y sube estos assets:

| Asset | Contenido |
|---|---|
| `growth-<v>.zip` | el paquete (abajo) |
| `growth-<v>.zip.sha256` | `<sha256 en hex>  growth-<v>.zip` |
| `growth-<v>.zip.sha256.sig` | **opcional:** firma ed25519 (64 bytes) del archivo `.sha256`, hecha con `openssl pkeyutl -sign -rawin` y la llave del secreto `GROWTH_RELEASE_SIGNING_KEY` (PEM). Sin el secreto, la versión se publica sin firma |
| `release.json` | el mismo de adentro del zip, para revisar espacio antes de descargar |
| `release-signing.pub.pem` | solo si se firmó: la llave pública (también va incrustada en `install.sh` y en la raíz del zip) |

**La firma es opcional.** Sin el secreto `GROWTH_RELEASE_SIGNING_KEY`, el workflow publica la versión sin `.sig` y
`install.sh` no lleva llave: el servidor valida solo el sha256. Si el servidor tiene
`/etc/growth/release-signing.pub.pem` (por `install.sh` con llave incrustada o `--pubkey`), la firma se **exige**: una
versión sin `.sig` o con una firma de otra llave no se instala. Para activarla después: crear la llave
(`infra/release/keygen.sh`), guardar el secreto y copiar la llave pública a `/etc/growth/release-signing.pub.pem`
(root, 0644) en cada servidor.
| `install.sh` | instalador para un servidor limpio |
| `imagina-growth-connector.zip` (y `-<versión>.zip`) | el plugin de WordPress, listo para subir en Plugins → Añadir nuevo → Subir plugin (`connector/bin/build-zip.sh`). El zip del hub lo trae también en `hub/public/downloads/` para el botón «Descargar plugin» del panel |

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
backup/                     copias y su verificación (infra/backup), se instalan en /usr/local/lib/growth/backup
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

`env` lista **todas** las variables que la versión necesita, con su valor por defecto seguro
(`growth-env repair` agrega las que falten sin tocar las que ya existen). Campos de cada entrada:

- `secret: true`: se genera solo si falta, con el formato de `generate`: `hex32` (por defecto), `base64_32` o
  `laravel_key` (`base64:` + 32 bytes, el de `APP_KEY`). Con `generate: "none"` o `null` (las llaves VAPID, las
  de Meta o Anthropic) queda vacía y la completa `install.sh` o el administrador.
- Los valores por defecto no usan `${VAR}`: se escriben literales (la configuración ya cae a `APP_URL` o `APP_NAME`
  cuando hace falta).
- `never_regenerate: true` (`APP_KEY`, `IGR_BLIND_INDEX_KEY`, `DB_PASSWORD`, `CLICKHOUSE_PASSWORD`,
  `REDIS_PASSWORD`): en una actualización nunca se generan; si faltan, la actualización se detiene (sin ellas los
  datos cifrados, los índices ciegos y las conexiones ya configuradas dejan de funcionar).

## 4. Salud y versión

- `GET /health/ready` (sin dominio, cualquier host): `200 {"status":"ok","version":"X.Y.Z","checks":{...}}` si
  MariaDB, ClickHouse y los dos Redis (`redis_queue` y `redis_cache`) responden; `503` si no. La versión sale del archivo `VERSION` de la raíz de la
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
   `update-request.processing.json` y lanza `systemd-run --unit=growth-update-<id> --collect
   /usr/local/lib/growth/bin/growth-update <versión>` (el script de root instalado desde la última versión
   verificada, nunca uno de `current/` ni de `shared/`). Al correr en su propia unidad transitoria, reiniciar
   `growth.target` no lo mata.
4. **Actualizar** (`growth-update`, root; lo que toca la app corre como `growth` con `runuser`), en este orden:
   1. candado `flock /run/lock/growth-update.lock` (si está tomado, termina sin hacer nada);
   2. **preflight:** espacio libre ≥ 3 × `unpacked_bytes` + tamaño de la base, e inodos ≥ 2 × `files`, en
      `/opt/growth`; si no alcanza, termina **sin descargar** el zip;
   3. **descargar** zip y `.sha256` (y el `.sig` si hay llave pública) a `shared/tmp`; **verificar** la firma con
      `/etc/growth/release-signing.pub.pem` (si existe) y luego el sha256 del zip;
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
   10. **scripts de root:** con el health-check bien, instala los `bin/growth-update*`, `bin/growth-env` y
       `backup/*` de la versión nueva en `/usr/local/lib/growth/` (atómico). Si volvió atrás, deja los anteriores;
   11. **limpieza:** deja 3 versiones (nunca borra `current` ni la anterior), 5 copias previas y borra
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
script descarga con lo que dice `updater.conf` (root) y, si hay llave, valida con la de `/etc/growth`; así una app
comprometida no puede instalar un zip de otro repositorio.

**En la app** (`app/Domain/Updates`, `config/growth.php`): `GROWTH_STATE_PATH` (por defecto
`/opt/growth/shared/state`; una ruta relativa se resuelve desde la raíz del hub), `GROWTH_UPDATE_REPO` y
`GROWTH_UPDATE_API`. Rutas del panel: `GET /api/platform/updates`, `POST /api/platform/updates/check` y
`POST /api/platform/updates/install {version, password}` (422 con la contraseña equivocada o una versión que no es
la última; 409 si ya hay un pedido o una actualización en curso).

## 6. Copias y vigilancia

- `growth-backup-mariadb.timer` (03:15 UTC): `mariadb-backup --backup` + `--prepare`, comprimida, binlogs
  cerrados, subida con `rclone` al remoto de `/etc/growth/backup.conf` (Storage Box, remoto cifrado).
- `growth-backup-clickhouse.timer` (03:45 UTC): `BACKUP DATABASE … TO Disk('backups', …)` nativo, completa los
  domingos e incremental los demás días, subida con `rclone`.
- `growth-backup-verify.timer` (domingos 05:00 UTC): restaura la última copia de MariaDB en una instancia
  temporal (otro datadir y puerto) y la de ClickHouse en una base temporal (`RESTORE … AS growth_restore_check`),
  compara conteos y borra todo al terminar.
- Los scripts de copias corren como root desde `/usr/local/lib/growth/backup/` y leen `/etc/growth/backup.conf` y
  `shared/.env` con un lector dotenv propio en bash (`growth-backup-lib.sh`), no con `source` ni con código de la
  versión. Para ClickHouse usan el usuario del `.env` (o `CLICKHOUSE_BACKUP_USER/PASSWORD` si existe), con
  `GRANT BACKUP ON igr.*` y permisos sobre `growth_restore_check.*`. MariaDB con `log-bin` (punto en el tiempo) y
  root por `unix_socket`.
- `growth-monitor.timer` (cada minuto): `artisan growth:monitor` mide el atraso de los streams de Redis, la cola
  pendiente de Horizon y la salud, y avisa a los heartbeats de Better Stack solo si todo está bien (si un
  heartbeat no llega, Better Stack alerta). Cada copia y la prueba de restauración también avisan a su heartbeat
  (y a `/fail` si fallan). Los heartbeats van en `/etc/growth/backup.conf` y en `shared/.env`, nunca en el repo.
- **Better Stack:** monitores de uptime sobre `https://<dominio>/health/ready` y `/health` (ingesta), y seis
  heartbeats: salud, streams y colas (cada minuto), copia de MariaDB y de ClickHouse (diarias) y verificación
  (semanal). Pasos en `infra/backup/README.md`.

## 7. Instalación (`install.sh`)

`curl -fsSL https://github.com/augusto97/Imagina-Growth/releases/latest/download/install.sh | sudo bash -s -- --domain growth.ejemplo.com`

Instala los repositorios oficiales y los paquetes (§1), crea el usuario `growth`, la estructura (§2), el
`.env` con secretos nuevos, las bases (MariaDB `igr` y usuario, ClickHouse `igr` y usuario), las dos
instancias de Redis, la configuración de PHP, las unidades y el firewall; escribe `/etc/growth/*`, el
Caddyfile (solo si no existe) y baja e instala la última versión con `growth-update --install`.
**Idempotente:** correrlo otra vez no pisa el `.env`, ni las bases, ni el Caddyfile, ni los datos, y nunca reemplaza
una versión ya instalada (para eso está el panel; `--version` solo cuenta en la primera instalación). Si se le pasa
`--domain` o `--email` y el Caddyfile ya existe, deja la propuesta en `/etc/caddy/Caddyfile.growth.new`.

**Un solo dominio y un solo registro DNS.** El panel, la API, la ingesta de los sitios (`/v1`, `/p`, que Caddy manda a
las réplicas de ingesta) y los enlaces rastreados, la baja y las preferencias van en `--domain`
(`IGR_INGEST_URL` = `IGR_LINKS_URL` = `https://<dominio>`, y `IGR_CUSTOM_DOMAINS_CNAME` = `<dominio>`). Las
instalaciones anteriores a 0.1.4 usaban `in.`, `go.` y `domains.`: si esos nombres no existen en el DNS, el
instalador los pasa al dominio principal (si existen, los respeta), y el plugin hace lo mismo por su cuenta cuando
el host de ingesta no resuelve. Al terminar, avisa qué nombres en uso no tienen registro DNS.

**Memoria y perfil de procesos.** `install.sh` mide la RAM y elige el perfil (se reescribe en cada corrida, así que
ampliar o reducir el servidor y volver a correrlo lo ajusta):

| | Pequeño (menos de ~3,5 GB) | Estándar |
|---|---|---|
| ClickHouse (`config.d/growth-memory.xml`) | 25 % de la RAM; cachés de marcas 1/64 y 1/128 | 30 %; 1/32 y 1/64 |
| MariaDB `innodb_buffer_pool_size` | 1/8 de la RAM (mínimo 128 MB) | 1/4 |
| Workers de ingesta | `growth-ingest@1` lee los 4 shards (drop-in `growth-profile.conf`); 2 a 4 enmascarados | uno por shard (`@1..4`) |
| Horizon (`IGR_WORKERS_PROFILE` en el `.env`) | `small`: 2 supervisores, 3 procesos de trabajo | `standard`: los supervisores de `hub/CLAUDE.md` §15 |
| `GROWTH_INGEST_WORKERS` en `updater.conf` | `"1"` | `"1 2 3 4"` |

En los dos perfiles ClickHouse va sin los logs de sistema que la app no usa (`trace_log`, `text_log`, `metric_log`,
`asynchronous_metric_log`, `query_thread_log`, `processors_profile_log`, `opentelemetry_span_log`, `latency_log`,
`error_log`); sin un límite, ClickHouse toma hasta el 90 % de la RAM y el kernel lo mata en bucle. Con menos de
1,4 GB el instalador se detiene. El perfil pequeño sirve para empezar (pocos sitios y poco tráfico); con carga real
conviene 4 GB o más. Los trabajos que necesitan otro número de intentos lo declaran en su clase (`$tries`), porque en
el perfil pequeño todas las colas cortas comparten un supervisor.

Si el servidor ya se quedó sin memoria (la app no responde), primero `systemctl stop growth.target clickhouse-server`
y después el instalador: con la memoria llena ni `apt` avanza. Al terminar, el instalador vuelve a arrancar la app
(`systemctl enable --now growth.target`) y rearma la caché de configuración, como `growth-update`.

**Python del ML:** `uv sync --frozen` usa el Python 3.12 de Ubuntu si cumple `requires-python`; si una versión
necesitara otro, `uv` lo baja de GitHub (el servidor necesita salida a GitHub).
