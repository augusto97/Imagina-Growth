# Despliegue nativo (systemd, versiones en zip y actualización desde el panel)

> Contrato del despliegue de producción del hub. Reemplaza a Docker Compose: el hub corre como servicios
> de systemd en un VPS de Ubuntu 24.04 o 26.04 LTS, cada versión es un zip publicado en GitHub Releases y el
> administrador de la plataforma actualiza con un clic. Lo que diga este documento manda sobre los
> scripts de `infra/`; si cambian, se cambia aquí en el mismo commit.

## 1. Componentes y puertos

Todo escucha en `127.0.0.1` salvo Caddy. El firewall (ufw) solo abre SSH, `80/tcp`, `443/tcp` y `443/udp`.

| Servicio (systemd) | Qué corre | Puerto |
|---|---|---|
| `growth-app` | FrankenPHP (`frankenphp run` con `config/frankenphp/Caddyfile`, admin apagado): panel, API, SPA, webhooks, páginas públicas. Los archivos del build (`/build/assets/*`, con hash en el nombre) salen con `Cache-Control: public, max-age=31536000, immutable` solo si existen (docs/rendimiento.md §4) | 8080 |
| `growth-ingest-api@1`, `@2` | FrankenPHP: ingesta `/v1/*` y `/p/*` (dos réplicas detrás de Caddy, que las balancea con chequeo en `/up` para no sacarlas si ClickHouse va lento) | 8081, 8082 (`8080 + instancia`) |
| `growth-ingest@1` … `@4` | `artisan igr:ingest:work --shard=<instancia − 1>` | — |
| `growth-horizon` | `artisan horizon` | — |
| `growth-reverb` | `artisan reverb:start` | 8085 |
| `growth-scheduler` | `artisan schedule:work` | — |
| `growth-ml` | uvicorn (`hub/ml`, entorno de `uv`), 2 workers | 8095 |
| `mariadb` | MariaDB LTS del repositorio oficial: 11.4 en Ubuntu 24.04 y 11.8 en 26.04 (el repositorio no publica 11.4 para 26.04) | 3306 |
| `clickhouse-server` | ClickHouse 25.8 (repositorio oficial, fijado a 25.8.*) | 8123 HTTP, 9000 nativo |
| `redis-server@queue` | Redis del repositorio oficial, fijado por sistema: `7.4.*` en Ubuntu 24.04 (si no, instala Redis 8) y `8.*` en 26.04 (no hay Redis 7 para 26.04); la app usa lo mismo en ambas: colas, streams de ingesta, candados. `noeviction` + AOF (`everysec`), sin instantáneas RDB y con `maxmemory` del 20% de la RAM (mínimo 192 MB): lleno, rechaza escrituras y el plugin reintenta, en vez de que el kernel mate procesos; `growth:monitor` avisa al 85%. El worker de ingesta recorta del stream lo ya confirmado. La plantilla `redis-server@.service` es nuestra: el paquete oficial no la trae | 6379 |
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
    .env                              0600     arranque y secretos de la instalación (nunca en el repo); lo demás, Plataforma → Ajustes (§9)
    storage/                          Laravel storage: app/, framework/{cache,sessions,views}, logs/
    state/                            root:growth 1775 (sticky): pedidos del panel y estado que escribe root
      update-request.json             growth 0600  pedido de actualización (§5)
      update-status.json …            root 0644    estado, pedido en proceso e historial del actualizador (§5)
      latest-release.json             growth       la última versión publicada que vio el panel (§5)
      backup-request.json             growth 0600  pedido de Plataforma → Copias de seguridad; root lo toma enseguida (§6)
      backup-status.json              root 0644    destino, llave pública, último pedido y última prueba, sin secretos (§6)
      backup-job-<tarea>.json         root 0644    resultado de cada copia: mariadb, clickhouse y verify (§6)
    backups/pre-update/               copia de MariaDB antes de cada actualización (se conservan 5)
    tmp/                              descargas en curso
    cache/uv/  python/                caché de uv y Pythons administrados por uv
    venvs/<versión>/                  entorno de Python del ML de cada versión (`hub/ml/.venv` apunta aquí)
/usr/local/lib/growth/                root:root 0755  scripts que corren como root (copiados de la versión verificada)
  bin/growth-update  bin/growth-update-dispatch  bin/growth-env
  backup/growth-backup-{mariadb,clickhouse,verify,dispatch}  backup/growth-backup-lib.sh
/etc/growth/                          root:root 0755
  updater.conf                        0644     repositorio y URL de la API de releases (§5)
  release-signing.pub.pem             0644     llave pública ed25519 que valida las versiones (opcional, §3)
  clickhouse-default.pass             0600     contraseña aleatoria del usuario `default` de ClickHouse (nadie lo usa)
  backup.conf                         0600     destino, retención y heartbeats de Better Stack (§6; lo escribe el panel)
  backup-rclone.conf                  0600     remotos de rclone del panel: sftp al Storage Box y crypt encima (§6)
  backup-ssh/                         0700     llave SSH de las copias (id_ed25519 0600, se genera una vez) y known_hosts
/var/lib/growth/<unidad>/             estado interno de cada proceso de FrankenPHP
/var/lib/growth-backup/               root 0700    pedido de copias mientras se atiende, candado y estado interno (§6)
/var/lib/growth-backups/              root 0711    copias nocturnas antes de subirlas
  mariadb/                            root 0700
  clickhouse/                         clickhouse 0700 (disco `backups` de ClickHouse)
```

**Regla de seguridad:** root nunca ejecuta nada de una ruta que `growth` pueda escribir. Las versiones son de
root y de solo lectura para la app; los scripts de root viven en `/usr/local/lib/growth/` y se reemplazan solo
con los de una versión verificada (sha256, y la firma si el servidor tiene llave pública) que pasó el health-check
(uno que todavía no existe, como el despachador de las copias en un servidor anterior, lo instala `growth-system`
desde la versión verificada, que es de root; §6.1).

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
  de Meta o Anthropic) queda vacía: el administrador las pone en Plataforma → Ajustes (§9), donde el valor del panel
  manda sobre el del `.env`. El `.env` sigue siendo el respaldo de esos ajustes, pero nadie necesita editarlo.
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
- **Detalle solo para la máquina o con token** (S16): lo que llega por Caddy (siempre con `X-Forwarded-For`) o desde
  otra IP recibe solo `{"status":"ok"}` o `{"status":"degraded"}` con el mismo 200/503. La versión, los `checks` y los
  streams salen cuando la conexión viene de 127.0.0.1 o ::1 sin encabezados de proxy (el health-check de
  `growth-update`, que llama a `http://127.0.0.1:<puerto>`) o con `IGR_HEALTH_TOKEN` en `X-IGR-Health-Token` o
  `Authorization: Bearer` (un monitor externo que quiera el detalle). Los monitores de Better Stack solo miran el
  código de respuesta y siguen igual.
- El health-check del actualizador exige `status = ok` **y** `version` igual a la versión instalada en
  `growth-app` (8080) y en cada `growth-ingest-api@N`, para no confundirse con un proceso viejo.

## 5. Actualización desde el panel

1. **Detectar:** `growth:updates:check` (cada hora, en el scheduler) consulta
   `GET {GROWTH_UPDATE_API}/repos/{GROWTH_UPDATE_REPO}/releases/latest` y guarda el resultado en
   `shared/state/latest-release.json`. El panel `/plataforma` muestra la versión actual, la nueva y sus notas.
2. **Pedir:** solo un administrador de la plataforma (`is_platform_admin`, con 2FA y confirmando su contraseña).
   La app escribe (atómico: temporal + rename) `shared/state/update-request.json`:
   `{"version":"0.2.0","requested_by":"usr_…","requested_at":"…","id":"upd_…"}`. Si ya hay un pedido o una
   actualización en curso, responde 409. La app **no** actualiza nada. Un pedido que ya terminó (el estado final
   es de ese mismo pedido o posterior a cuando se aceptó) o que el servidor no tomó en 15 minutos
   (`GROWTH_UPDATE_REQUEST_STALE_MINUTES`) no cuenta como «en curso» ni bloquea volver a pedir; `growth-system`
   (en cada arranque de `growth-app`, si el candado de `growth-update` está libre) borra el pedido en proceso que
   haya quedado y reactiva `growth-update.path` si se cayó. `growth-update` borra el pedido apenas escribe el
   estado final.
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
   0. **nunca hacia atrás:** una versión menor que la instalada se rechaza (volver atrás solo lo hace el propio
      actualizador cuando la nueva falla);
   3. **descargar** zip y `.sha256` (y el `.sig` si hay llave pública) a `/var/lib/growth-update` (solo de root;
      nunca a `shared/`, que «growth» puede escribir); **verificar** la firma con
      `/etc/growth/release-signing.pub.pem` (si existe) y luego el sha256 del zip;
   4. **extraer** en `releases/<v>.partial` y renombrar a `releases/<v>`; **enlazar** `shared/` (§2);
      `uv sync --frozen --no-dev` en `hub/ml`; reparar `shared/.env` con `release.json` (`growth-env repair`);
   5. `config:cache`, `route:cache`, `view:cache` y `event:cache` con la versión nueva;
   6. **copia de MariaDB** (`mariadb-dump --single-transaction`, comprimida) en `shared/backups/pre-update`;
   7. **migraciones** de MariaDB (`migrate --force`) y de ClickHouse (`igr:clickhouse:migrate`), con el código
      nuevo, antes del cambio; tienen que ser compatibles con la versión anterior. Antes espera a que MariaDB
      responda (hasta 2 minutos) e `igr:clickhouse:migrate` espera a ClickHouse (`--wait`, 120 s): en un servidor
      pequeño el kernel puede reiniciarlos por falta de memoria a mitad de la actualización. Por lo mismo, en el
      perfil pequeño el ML se detiene desde la extracción hasta el reinicio (y vuelve a arrancar si algo falla), y
      la copia de MariaDB se comprime con un solo hilo;
   8. **cambio** atómico del symlink (`ln -s` a un temporal y `mv -T`); instala las unidades de `systemd/` si
      cambiaron (`daemon-reload`); aplica la **configuración del sistema** de la versión (`bin/growth-system apply
      --update`, más abajo) y **reinicia sin cortes**: `growth-ingest-api@1`, espera su health-check,
      luego `@2`; `growth-ingest@1..4` de a uno (SIGTERM: terminan el lote); `growth-horizon` (SIGTERM: termina
      los trabajos); `growth-scheduler`, `growth-reverb`, `growth-ml` y `growth-app` (Caddy reintenta
      mientras reinicia, `lb_try_duration`);
   9. **health-check** (§4) hasta `GROWTH_HEALTH_TIMEOUT` segundos (120). Si falla: vuelve el symlink a la
      versión anterior y reinicia otra vez en el mismo orden. Las migraciones no se revierten (son compatibles);
      la copia queda para restaurar a mano;
   10. **scripts de root:** con el health-check bien, instala los `bin/growth-update*`, `bin/growth-env` y
       `backup/*` de la versión nueva en `/usr/local/lib/growth/` (atómico). Si volvió atrás, deja los anteriores;
   11. **limpieza:** deja 3 versiones (nunca borra `current` ni la anterior), 5 copias previas y borra
       `*.partial` y descargas viejas.
   Si falla en cualquier paso antes del cambio, borra el zip y la carpeta a medio extraer y deja `current` como
   estaba.
5. **Estado:** el script escribe `shared/state/update-status.json` en cada paso y agrega una línea a
   `shared/state/update-history.jsonl` al terminar, siempre con un temporal + rename y como archivos de root
   (legibles por el panel). `shared/state` es `root:growth 1775` (sticky): el panel deja sus pedidos, pero no
   puede reemplazar ni borrar lo de root. Formato:

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
  domingos e incremental los demás días, subida con `rclone`. Su unidad va después de la de MariaDB
  (`After=growth-backup-mariadb.service`): si la de MariaDB sigue corriendo, espera; «copia ahora» las corre en orden.
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
  (y a `/fail` si fallan). Los heartbeats de las copias los guarda el panel en `/etc/growth/backup.conf` (§6.1); los
  de `growth:monitor` (salud, streams y colas) y sus umbrales, en Plataforma → Ajustes → Monitoreo (§9; de respaldo,
  `shared/.env`). Nunca en el repo.
- **Better Stack:** monitores de uptime sobre `https://<dominio>/health/ready` y `/health` (ingesta), y seis
  heartbeats: salud, streams y colas (cada minuto), copia de MariaDB y de ClickHouse (diarias) y verificación
  (semanal). Pasos en `infra/backup/README.md`.

### 6.1 Copias desde el panel (Plataforma → Copias de seguridad, F18)

Nada se configura por consola: el superadmin (con 2FA) guarda el destino, prueba la conexión, lanza una copia y ve
el resultado de cada una desde `/plataforma` → «Copias de seguridad». La app nunca corre como root: deja un pedido y
lo atiende un despachador de root, igual que el actualizador (§5).

**Destino:** un Storage Box de Hetzner por SFTP (servidor `uXXXXXX.your-storagebox.de`, usuario, puerto 23 por
defecto y carpeta). Entra con una **llave SSH que genera el servidor** (recomendado; el panel muestra la pública y
los pasos) o con la contraseña del Storage Box (guardada solo del lado de root). Las copias van **cifradas** con una
frase de cifrado que el dueño escribe (o genera en el navegador) y guarda en su gestor de contraseñas: sin ella las
copias no se pueden leer. Además: días a conservar y los tres heartbeats de Better Stack de las copias.

**Puente app → root:**

1. **Pedido** (`PUT /api/platform/backups/settings`, `POST …/test`, `…/run`, `…/install-key`, `…/refresh`;
   `App\Domain\Backups`): la app valida con las mismas listas blancas que root, pide la contraseña del panel para
   guardar el destino o instalar la llave, y escribe (temporal + rename, **0600** de growth)
   `shared/state/backup-request.json`. Responde 409 si hay otro pedido sin atender (uno que root no tomó en
   10 minutos ya no bloquea: el panel avisa que el servidor no lo tomó). Los secretos se leen del cuerpo tal cual
   (sin el recorte de espacios de Laravel) y nunca vuelven en una respuesta ni van a `audit_logs`.

   ```json
   {"id": "bkp_…", "action": "configure", "requested_by": "usr_…", "requested_at": "…",
    "config": {"host": "u123456.your-storagebox.de", "port": 23, "user": "u123456", "path": "imagina-growth",
               "auth": "key", "keep_days": 30,
               "heartbeats": {"mariadb": "https://uptime.betterstack.com/api/v1/heartbeat/…", "clickhouse": null, "verify": ""}},
    "secrets": {"password": null, "encryption_password": "…"}}
   ```

   `action`: `configure | test | backup_now | install_key | refresh`. Heartbeats: una URL la pone, `""` la quita y
   `null` deja la guardada. Secretos: `null` conserva el guardado (`encryption_password` es obligatoria la primera
   vez; `password`, con `auth = password` si no hay una). `install_key` solo trae `secrets.password`.
2. **Despacho:** `growth-backup.path` (root) vigila el pedido y arranca `growth-backup-dispatch.service`
   (`/usr/local/lib/growth/backup/growth-backup-dispatch`, oneshot, hasta 5 min). El despachador:
   - toma un candado (`/var/lib/growth-backup/dispatch.lock`) y **mueve** el pedido a `/var/lib/growth-backup`
     (root 0700) con `mv`, que no sigue symlinks: un symlink, una carpeta o un FIFO se rechazan y desde ahí la app ya
     no lo puede cambiar; máximo 32 KB;
   - valida cada campo del lado de root, sin confiar en el JSON: `id` `^bkp_[A-Za-z0-9]{6,40}$`, servidor (nombre
     de host o IPv4, nunca empieza con guion), usuario `^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$`, carpeta relativa (hasta
     5 partes que empiezan con letra o número: sin `..`, `.ssh` ni `/`), puerto 1–65535, días 1–365, heartbeats
     `https://uptime.betterstack.com/api/v1/heartbeat/<token>` (o `betteruptime.com`), y secretos sin caracteres de
     control (la frase, 12 a 256);
   - `configure`: genera la llave `/etc/growth/backup-ssh/id_ed25519` **si falta** (nunca la reemplaza), anota la
     identidad del servidor con `ssh-keyscan` (`known_hosts`; se confía en ella al guardar el destino), escribe
     `/etc/growth/backup-rclone.conf` (remoto `growth-storagebox` sftp con la llave o la contraseña y
     `known_hosts_file`, y `growth-backup` crypt sobre la carpeta, nombres cifrados) y `/etc/growth/backup.conf`
     (`GROWTH_BACKUP_REMOTE=growth-backup:growth`, `GROWTH_RCLONE_CONFIG`, el destino, la retención y los heartbeats;
     conserva lo puesto a mano que el panel no maneja: `GROWTH_BACKUP_LOCAL_DAYS`, `GROWTH_VERIFY_FROM_REMOTE` y
     `CLICKHOUSE_BACKUP_*`), los dos **0600** con un temporal + rename; enciende los tres timers y prueba la conexión;
   - `test`: crea la carpeta, escribe un archivo pequeño (cifrado) y lo borra, y anota el espacio libre;
   - `backup_now`: si no hay una copia corriendo, deja las dos «en cola» y `systemctl start --no-block
     growth-backup-mariadb.service growth-backup-clickhouse.service`;
   - `install_key`: con la contraseña (de un solo uso, en una configuración temporal 0600 que se borra) agrega la
     llave pública a `.ssh/authorized_keys` del Storage Box sin borrar las otras y prueba con la llave;
   - los secretos nunca van en la línea de comandos (`rclone obscure -` los lee de la entrada) ni en los logs.
3. **Estado** (root 0644, temporal + rename en la carpeta sticky: la app lo lee y no lo puede reemplazar):
   `backup-status.json` (sin secretos: de cada uno solo si está):

   ```json
   {"version": 1, "updated_at": "…", "configured": true, "managed": true, "manual_remote": null,
    "destination": {"host": "…", "port": 23, "user": "…", "path": "…", "auth": "key", "keep_days": 30},
    "secrets": {"password": false, "encryption": true}, "heartbeats": {"mariadb": true, "clickhouse": false, "verify": false},
    "timers_enabled": true, "ssh_key": {"public": "ssh-ed25519 …", "fingerprint": "SHA256:…", "created_at": "…"},
    "host_key": {"fingerprint": "SHA256:…"},
    "last_request": {"id": "bkp_…", "action": "test", "requested_by": "usr_…", "state": "running|succeeded|failed",
                     "code": null, "message": null, "started_at": "…", "finished_at": "…"},
    "last_test": {"ok": true, "code": null, "message": null, "at": "…", "free_bytes": 1099511627776}}
   ```

   y cada copia deja `backup-job-{mariadb,clickhouse,verify}.json`: `state` (`queued | running | succeeded |
   failed`), fechas, `name`, `size_bytes`, `code` y `message` si falló, y `last_success` (la última buena). Los
   códigos (`auth_failed`, `connection_refused`, `timeout`, `host_not_found`, `host_key_changed`, `upload_failed`,
   `verify_mismatch`, `missing_tool`…) los explica el panel en palabras. El próximo horario lo calcula la app con los
   mismos horarios de los timers (`growth.backups.schedule`).

**Cómo llega a un servidor que ya está en 0.1.9, sin consola:** con el botón de actualizar. El `growth-update` de
0.1.9 copia las unidades nuevas (`growth-backup.path`, `growth-backup-dispatch.service`) y corre el
`bin/growth-system apply --update` de la versión nueva, que instala el despachador y su librería en
`/usr/local/lib/growth/backup` **si faltan** (nunca reemplaza uno que ya está), habilita y arranca la unidad `.path` y
publica el primer estado (así el panel ve también una configuración hecha a mano). Después del health-check, el
mismo `growth-update` reemplaza los `backup/*` con los de la versión sana, como siempre. `growth-system.service`
(`--hook`) hace lo mismo si la versión la instala un actualizador más viejo. `install.sh` lo habilita en una
instalación nueva. Si la unidad todavía no existe, el panel dice que el servidor no respondió y pide actualizar.

Una configuración hecha a mano (remoto en `/root/.config/rclone/rclone.conf`) sigue funcionando: el panel la muestra,
la prueba y lanza copias con ella; guardar un destino en el panel la reemplaza. Para restaurar en otro servidor hacen
falta la carpeta del Storage Box y la frase de cifrado (`infra/backup/RESTAURACION.md`).

## 7. Instalación (`install.sh`)

`curl -fsSL https://github.com/augusto97/Imagina-Growth/releases/latest/download/install.sh | sudo bash -s -- --domain growth.ejemplo.com`

Instala los repositorios oficiales y los paquetes (§1), crea el usuario `growth`, la estructura (§2), el
`.env` con secretos nuevos, las bases (MariaDB `igr` y usuario, ClickHouse `igr` y usuario), las dos
instancias de Redis, la configuración de PHP, las unidades y el firewall; escribe `/etc/growth/*`, el
Caddyfile (si no existe; después lo mantiene `growth-system`) y baja e instala la última versión con `growth-update --install`.
**Idempotente:** correrlo otra vez no pisa el `.env`, ni las bases, ni el Caddyfile, ni los datos, y nunca reemplaza
una versión ya instalada (para eso está el panel; `--version` solo cuenta en la primera instalación). Si se le pasa
`--domain` o `--email` y el Caddyfile ya existe, deja la propuesta en `/etc/caddy/Caddyfile.growth.new`.

**Un solo dominio y un solo registro DNS.** El panel, la API, la ingesta de los sitios (`/v1`, `/p`, que Caddy manda a
las réplicas de ingesta) y los enlaces rastreados, la baja y las preferencias van en `--domain`
(`IGR_INGEST_URL` = `IGR_LINKS_URL` = `https://<dominio>`, y `IGR_CUSTOM_DOMAINS_CNAME` = `<dominio>`). Las
instalaciones anteriores a 0.1.4 usaban `in.`, `go.` y `domains.`: si esos nombres no existen en el DNS, el
instalador los pasa al dominio principal (si existen, los respeta), y el plugin hace lo mismo por su cuenta cuando
el host de ingesta no resuelve. Al terminar, avisa qué nombres en uso no tienen registro DNS.

**Configuración del sistema (`bin/growth-system`): nunca hace falta la consola para recibirla.** Todo lo que depende
de la versión y de la RAM vive en un solo script de la versión: el perfil de procesos (`IGR_WORKERS_PROFILE` en el
`.env`), la memoria y la configuración de MariaDB, ClickHouse y Redis, las prioridades ante falta de memoria, los
workers de ingesta, los permisos de `shared/state`, el Caddyfile y el despachador de las copias del panel (§6.1). Lo aplican:

- `install.sh` (`apply --install`), en la primera instalación y si se vuelve a correr;
- `growth-update` en cada actualización desde el panel (`apply --update`, después del cambio y antes de reiniciar;
  el resultado se ve en el paso «Activar la versión nueva»);
- la unidad `growth-system.service` (`apply --hook`), que corre antes de `growth-app`: así una versión nueva aplica
  su configuración aunque la instale un actualizador anterior que no conoce el script (copia las unidades nuevas y
  reinicia `growth-app`). Si ya se aplicó para esa versión y esa RAM (`/var/lib/growth-system/applied`), termina
  enseguida.

Es idempotente: solo escribe lo que cambió y aplica en caliente lo que se puede (`maxmemory` y `save` de Redis con
`CONFIG SET`, `innodb_buffer_pool_size` con `SET GLOBAL`, `oom_score_adj` de los procesos en marcha); ClickHouse se
reinicia solo si cambió su configuración, y si no vuelve a responder en ~90 s se restaura la configuración anterior,
se reinicia otra vez y no se sella (la próxima corrida lo reintenta). Nunca hace fallar la actualización: si algo no se puede aplicar, lo avisa
y sigue. `growth-system status` muestra el perfil sin cambiar nada. Ampliar o reducir el servidor se ajusta solo en
la próxima actualización (o al reiniciar `growth-app`).

**Caddyfile al día sin consola (lote 4).** `growth-system` vuelve a escribir `/etc/caddy/Caddyfile` con la plantilla
de la versión (`caddy/Caddyfile`; el dominio sale de `APP_URL` y el correo de Let's Encrypt del Caddyfile actual)
**solo si nadie lo editó a mano**: si, sin comentarios, espacios, correo ni dominio, es igual a la plantilla actual o
a una anterior (`caddy/history/`), o es el que escribió la última vez (`/var/lib/growth-system/caddyfile.sha256`).
Antes de instalarlo lo valida con `caddy validate`, guarda el anterior en `Caddyfile.growth.bak` y recarga Caddy; si
la recarga falla vuelve al anterior. Si se editó a mano, no lo toca: la versión nueva queda en
`Caddyfile.growth.new` y lo avisa una vez. Los subdominios `in.` y `go.` de las instalaciones anteriores a 0.1.4 se
conservan en el bloque principal. Lo que trae la plantilla:
- **IP real detrás de Cloudflare (S8):** `servers { trusted_proxies static <rangos de Cloudflare> }` y
  `client_ip_headers CF-Connecting-IP X-Forwarded-For`; la IP del visitante solo se toma de esos encabezados si la
  petición llega desde una IP de Cloudflare. Los rangos se descargan de `cloudflare.com/ips-v4` e `ips-v6` en cada
  aplicación (`/var/lib/growth-system/cloudflare-ips`; sin internet, la última lista o `caddy/cloudflare-ips.txt`).
  Hacia la app, `X-Forwarded-For` lleva solo la IP ya resuelta (`header_up X-Forwarded-For {client_ip}`) y el hub
  confía únicamente en Caddy (`IGR_TRUSTED_PROXIES`, por defecto `127.0.0.1` y `::1`).
- **Registros sin datos sensibles (S7):** IP con máscara, la consulta borrada entera (códigos de OAuth, carritos,
  correos del restablecimiento), los tokens de las rutas públicas (`/u/`, `/preferencias/`, `/r/`, `/r/nps/`,
  `/invitacion/`, `/restablecer/`, `/c/`) como `***`, y sin `Cookie`, `Authorization`, firmas, `Referer`,
  `CF-Connecting-IP`, `X-Forwarded-For`, `Set-Cookie` ni `Location`.

**Memoria y perfil de procesos.** `growth-system` mide la RAM y elige el perfil. La RAM es, en este orden: la fijada a
mano (`GROWTH_MEMORY_MB` en `/etc/growth/updater.conf`; el instalador la guarda si se la pasaron), o la de
`/proc/meminfo` recortada al límite del cgroup de PID 1 (el menor `memory.max` hasta la raíz, o
`memory.limit_in_bytes` en cgroups v1): en VPS que en realidad son contenedores (LXC), `meminfo` muestra la RAM de
la máquina anfitriona.

| | Pequeño (menos de ~3,5 GB) | Estándar |
|---|---|---|
| ClickHouse (`config.d/growth-memory.xml`) | 25 % de la RAM; cachés de marcas 1/64 y 1/128; sin caché sin comprimir y caché de expresiones de 16 MB (los pools de fondo no se tocan: ClickHouse valida sus mínimos al arrancar) | 30 %; 1/32 y 1/64 |
| MariaDB `innodb_buffer_pool_size` | 1/8 de la RAM (mínimo 128 MB) | 1/4 |
| Workers de ingesta | `growth-ingest@1` lee los 4 shards (drop-in `growth-profile.conf`); 2 a 4 enmascarados | uno por shard (`@1..4`) |
| Horizon (`IGR_WORKERS_PROFILE` en el `.env`) | `small`: 2 supervisores, 3 procesos de trabajo | `standard`: los supervisores de `hub/CLAUDE.md` §15 |
| `GROWTH_INGEST_WORKERS` en `updater.conf` | `"1"` | `"1 2 3 4"` |
| Hilos de PHP por proceso web (`bin/growth-web` lee `IGR_WORKERS_PROFILE`) | 2 | el doble de CPU |
| Procesos del ML (`bin/growth-ml`) | 1 | 2 |
| Redis de colas `maxmemory` | 20 % de la RAM (mínimo 192 MB) | 20 % |

En los dos perfiles ClickHouse va sin los logs de sistema que la app no usa (`trace_log`, `text_log`, `metric_log`,
`asynchronous_metric_log`, `query_thread_log`, `processors_profile_log`, `opentelemetry_span_log`, `latency_log`,
`error_log`); sin un límite, ClickHouse toma hasta el 90 % de la RAM y el kernel lo mata en bucle. Con menos de
1,4 GB el instalador se detiene. El perfil pequeño sirve para empezar (pocos sitios y poco tráfico); con carga real
conviene 4 GB o más. Los trabajos que necesitan otro número de intentos lo declaran en su clase (`$tries`), porque en
el perfil pequeño todas las colas cortas comparten un supervisor.

**Prioridad ante falta de memoria** (`OOMScoreAdjust`): primero el ML (900), después Horizon (300) y ClickHouse
(200, drop-in de `growth-system`); el panel (-100), la ingesta (-200), MariaDB (-300) y los Redis (-400) son lo
último. Todo se aplica al actualizar desde el panel.

Si el servidor ya se quedó sin memoria (la app no responde), primero `systemctl stop growth.target clickhouse-server`
y después el instalador: con la memoria llena ni `apt` avanza. Al terminar, el instalador vuelve a arrancar la app
(`systemctl enable --now growth.target`) y rearma la caché de configuración, como `growth-update`.

**Primer administrador.** Lo único que se hace en el servidor después de instalar es crear a la primera persona que
administra la plataforma: `growth-php artisan igr:admin:create admin@ejemplo.com` (o promueve, si ya existe). Pide la
contraseña dos veces sin mostrarla; con `--generate` la genera y la muestra una sola vez (sin terminal, `--generate` es
obligatorio). Sus cuentas sin plan quedan con el plan `plataforma`. Al entrar, se le pide la verificación en dos pasos.
Desde ahí, todo lo demás se hace en el panel (§9), sin consola ni archivos:

- **Plataforma → Ajustes:** correo, Amazon SES, las apps de Meta, Google y TikTok, la IA, registro y seguridad, avisos
  push, facturación, monitoreo, retención y la fuente de las tasas de cambio; también los demás administradores, los
  festivos y las tarifas de WhatsApp.
- **Plataforma → Instalación** dice qué falta y lleva a la sección que lo arregla (lo mismo que `growth-php artisan
  igr:doctor`, que sale con código 1 si hay errores; `--json` para scripts).
- **«Preparar ahora»** (Ajustes → Amazon SES) deja listo el SES de la plataforma en el momento (lo hace solo cada
  noche; §8). Antes: `igr:ses:setup`, que sigue existiendo.
- **«Actualizar ahora»** (Plataforma → Tasas de cambio) trae las tasas del día (lo hace solo el programador dos veces
  al día; la fuente se cambia en Ajustes → Tasas de cambio, por defecto el acceso libre de ExchangeRate-API, sin llave).
  Antes: `igr:fx:update`, que sigue existiendo.

**Python del ML:** `uv sync --frozen` usa el Python 3.12 de Ubuntu si cumple `requires-python`; si una versión
necesitara otro, `uv` lo baja de GitHub (el servidor necesita salida a GitHub).

## 8. Amazon SES de la plataforma

Las credenciales y los datos de esta sección van en **Plataforma → Ajustes → Amazon SES** (§9; de respaldo,
`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `IGR_SES_REGION`, `IGR_SES_SNS_TOPIC_ARN`, `IGR_SES_CONFIGURATION_SET` e
`IGR_SES_RATE` del `.env`). Los clientes que envían correo con el SES de la plataforma verifican su dominio en esa cuenta
de AWS, y todo correo sale con el configuration set (`igr-events`). Sus eventos (entregas, rebotes, quejas, aperturas,
rechazos) llegan por un tema de SNS a `https://<panel>/webhooks/ses`.

1. **Tema de SNS** estándar (SES no admite FIFO) en la misma región de SES, con su ARN en el campo «Tema de SNS de los
   avisos» y una suscripción HTTPS a `https://<panel>/webhooks/ses` (el hub confirma la suscripción solo; el panel
   muestra la dirección para copiarla). Su política debe dejar publicar a SES:
   `{"Effect": "Allow", "Principal": {"Service": "ses.amazonaws.com"}, "Action": "sns:Publish", "Resource": "<ARN del tema>",
   "Condition": {"StringEquals": {"AWS:SourceAccount": "<cuenta>", "AWS:SourceArn": "arn:aws:ses:<región>:<cuenta>:configuration-set/igr-events"}}}`
   (https://docs.aws.amazon.com/ses/latest/dg/event-publishing-add-event-destination-sns.html).
2. **Usuario de IAM** de la clave de acceso de AWS, además de enviar (`ses:SendEmail` sobre las
   identidades y el configuration set) y crear identidades (`ses:CreateEmailIdentity`, `ses:GetEmailIdentity`), necesita
   `ses:CreateConfigurationSet`, `ses:GetConfigurationSet`, `ses:GetConfigurationSetEventDestinations`,
   `ses:CreateConfigurationSetEventDestination` y `ses:UpdateConfigurationSetEventDestination` sobre
   `arn:aws:ses:<región>:<cuenta>:configuration-set/igr-events`
   (https://docs.aws.amazon.com/service-authorization/latest/reference/list_sesv2.html).
3. El configuration set y su destino de eventos `igr-sns` se crean solos (o se corrige el destino si apunta a otro
   tema; idempotente): cada noche a las 03:47 si hay credenciales de SES, al conectar un dominio por SES y al probar un
   canal SES. No hace falta la consola: «Preparar ahora» en Plataforma → Ajustes → Amazon SES lo hace en el momento
   (antes, `growth-php artisan igr:ses:setup`, que sigue existiendo). Si a IAM le falta un permiso, el panel y Plataforma
   → Instalación dicen cuál.

## 9. Ajustes de la plataforma (el `.env` solo para el arranque)

Desde la F17 de la auditoría (lote 4), todo lo que un administrador puede necesitar cambiar se hace en **Plataforma → Ajustes** (solo
superadmin, siempre con la verificación en dos pasos), sin consola ni archivos. El `.env` del servidor queda para el
arranque, y lo escribe el instalador: nadie necesita tocarlo.

**Qué queda en el `.env` (lo escribe `install.sh` o `growth-env repair`):** `APP_KEY` y `IGR_BLIND_INDEX_KEY`, `APP_URL`
y `APP_ENV`/`APP_DEBUG`, las bases de datos (MariaDB y ClickHouse), los dos Redis, Reverb, la sesión y Sanctum, el
perfil de procesos (`IGR_WORKERS_PROFILE`), el servicio de predicciones (`IGR_ML_URL`, `IGR_ML_TOKEN`), los registros,
el actualizador (`GROWTH_*`) y los proxies de confianza. Tampoco se editan en el panel, a propósito:

- **Los dominios de ingesta y de enlaces** (`IGR_INGEST_URL`, `IGR_LINKS_URL`, `IGR_CUSTOM_DOMAINS_CNAME`): todo va en el
  dominio del panel (§7). Cambiarlos sin su registro DNS y su bloque de Caddy deja a los sitios sin enviar datos y a los
  enlaces de los mensajes sin abrir.
- **Las versiones de las API** de Meta, Google y TikTok: vienen fijadas en cada versión de la plataforma y se ponen al
  día con el botón de Actualizaciones.

**Qué está en el panel** (registro `App\Domain\Platform\Settings\PlatformSettingsRegistry`, con el `.env` de respaldo):

| Sección | Ajustes | Respaldo en el `.env` |
|---|---|---|
| Correo de la plataforma | cómo se envía (SMTP, Amazon SES o solo registro), servidor, puerto, cifrado, usuario, contraseña, EHLO, remitente y nombre; botón «Enviar correo de prueba» | `MAIL_*` |
| Amazon SES | llave y secreto de AWS, región, tema de SNS, configuration set, correos por segundo; botón «Preparar ahora» | `AWS_*`, `IGR_SES_*` |
| Meta | identificador y clave secreta de la app, token de verificación del webhook («Generar»), configuraciones de Embedded Signup y de Login for Business; las direcciones para registrar en la app | `IGR_META_*` |
| Google / TikTok | cliente y secreto (y el developer token de Ads, ya opcional) / app y secreto | `IGR_GOOGLE_*`, `IGR_TIKTOK_*` |
| IA | encendida, clave de Anthropic, tope mensual por cliente | `IGR_AI_ENABLED`, `IGR_ANTHROPIC_API_KEY`, `IGR_AI_MONTHLY_BUDGET_USD` |
| Registro y seguridad | registro abierto, 2FA obligatoria, plan de una cuenta nueva, token de `/health` («Generar»), aceptar la firma v1 del plugin (con los sitios que faltan) | `IGR_OPEN_REGISTRATION`, `IGR_REQUIRE_2FA`, `IGR_DEFAULT_PLAN`, `IGR_HEALTH_TOKEN`, `IGR_SIGNATURE_ACCEPT_V1` |
| Avisos en el celular | llaves VAPID («Generar llaves»; la privada nunca se muestra) y contacto | `IGR_PUSH_VAPID_*` |
| Facturación | correo de facturación, prefijo de las facturas, enlace de pago (`{number}`, `{total}`, `{currency}`) | `IGR_PLATFORM_BILLING_EMAIL`, `IGR_INVOICE_PREFIX`, `IGR_PAYMENT_MANUAL_URL` |
| Monitoreo | heartbeats de salud, streams y colas de Better Stack y los umbrales de `growth:monitor` | `BETTERSTACK_HEARTBEAT_*`, `GROWTH_MONITOR_*` |
| Retención | días de mensajes (desde 90), trabajos fallidos, pasos de los flujos y turnos del agente | `IGR_RETENTION_*` |
| Tasas de cambio | dirección de la fuente | `IGR_FX_URL` |

Y lo que antes eran comandos: **Administradores** (agregar o quitar, con la contraseña de quien lo hace; antes
`igr:admin:create`), **Festivos** (agregar uno decretado o quitar uno de la ley por país y año; antes `igr:holidays`),
**Tarifas de WhatsApp** (la tabla de Meta por mercado y tipo, con su fecha de vigencia, y «Cargar las tarifas
incluidas»; antes `igr:wa-rates --seed` y editar el código) y, en Plataforma → Planes, **«Agregar los planes incluidos
que falten»** (antes `igr:plans:sync --missing`). Los comandos siguen existiendo.

**Cómo funciona:**

- Tabla `platform_settings`: una fila por ajuste; los secretos van cifrados con la llave de la app y nunca vuelven al
  navegador (solo «configurado» y, si son largos, sus últimos 4 caracteres). Cada cambio queda en `audit_logs`
  (`platform.settings_updated`) con las claves, nunca con los valores.
- **El valor del panel manda; vacío vuelve al `.env`** (o al valor por defecto). El respaldo se toma de la configuración
  que cargó el proceso, así que funciona igual con `config:cache`; `config:cache` nunca guarda en disco los valores del
  panel.
- **Se aplica al arrancar cada proceso** (cada petición de FrankenPHP, cada comando, cada `schedule:run` del
  programador, que corre en un proceso nuevo cada minuto) y **en caliente** en los procesos largos: antes de cada
  trabajo de Horizon y cada 5 segundos en los workers de ingesta se compara la versión guardada en la caché; si cambió,
  se vuelve a aplicar y se olvida lo que se armó con la configuración anterior (los mailers, el cliente de la IA, los
  festivos en memoria). Nadie reinicia nada. Reverb no lee ninguno de estos ajustes.
- Sin la tabla (instalación, migraciones) o sin base, rige el `.env` y nada falla.
