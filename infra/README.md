# infra/

Despliegue del hub. La especificación está en `hub/CLAUDE.md` §18.

| Archivo | Para qué |
|---|---|
| `docker-compose.yml` | Entorno local: hub, worker de ingesta, Horizon, scheduler, MariaDB 11.4, ClickHouse 25.8, Redis 7, Mailpit y (perfil `store`) la tienda WooCommerce de prueba con el plugin montado |
| `docker-compose.prod.yml` | Producción en el VPS de Hetzner dedicado: `app`, `ingest` (réplicas aparte), un `ingest-worker` por shard, `horizon`, `scheduler`, MariaDB, ClickHouse, Redis y Caddy. `reverb` (Fase 2) y `ml` (Fase 4) se agregan con su fase |
| `docker/hub/Dockerfile` | Imagen del hub con FrankenPHP y PHP 8.4 (`dev` y `prod`) |
| `Caddyfile` | Panel, ingesta, enlaces y dominios de los clientes con TLS bajo demanda (pregunta a `/internal/caddy/ask`) |
| `backup/` | `mariadb-backup.sh` (diario + binlogs), `clickhouse-backup.sh` (completa los domingos, incremental el resto) y la guía del simulacro mensual `RESTAURACION.md` |
| `../.github/workflows/ci.yml` | CI: pruebas, Larastan, Pint, frontend y connector |

## Local con Docker

```bash
cp hub/.env.example hub/.env           # y completa APP_KEY e IGR_BLIND_INDEX_KEY (ver el comentario del archivo)
docker compose -f infra/docker-compose.yml up -d
docker compose -f infra/docker-compose.yml exec hub composer install
docker compose -f infra/docker-compose.yml exec hub php artisan migrate
docker compose -f infra/docker-compose.yml exec hub php artisan igr:clickhouse:migrate
docker compose -f infra/docker-compose.yml --profile frontend up -d vite     # SPA con recarga en caliente
```

Datos de prueba sin WordPress: `php artisan igr:seed-demo --process` crea «Pinturas Demo» (usuario `demo@growth.test`, contraseña `demo-growth-2026`) con 2.000 clientes y 10.000 pedidos que pasan por el pipeline de ingesta, y muestra la conciliación.

Tienda WooCommerce de prueba conectada al hub local:

```bash
docker compose -f infra/docker-compose.yml --profile store up -d
docker compose -f infra/docker-compose.yml run --rm wpcli igr-setup 10000 2000   # WordPress + WooCommerce + plugin + tienda demo
```

Luego entra a `http://localhost:8080/wp-admin` (admin / admin), **Imagina Growth → Conectar**.

## Local sin Docker

Sirve cualquier MariaDB 11, ClickHouse 25.x y Redis 7. Ajusta `hub/.env` y corre `php artisan serve` y `php artisan igr:ingest:work` en dos terminales.

## Producción

1. VPS de Hetzner dedicado al hub, Docker y `rclone` (remoto cifrado hacia el Storage Box o S3).
2. `/srv/igr/.env` con `APP_ENV=production`, `APP_KEY`, `IGR_BLIND_INDEX_KEY` (nunca se cambia sin reindexar), credenciales de bases de datos y `IGR_INGEST_SHARDS=4`.
3. Construir y desplegar:
   ```bash
   docker build -f infra/docker/hub/Dockerfile --target prod -t imagina-growth/hub:$(git rev-parse --short HEAD) .
   IGR_IMAGE=imagina-growth/hub:<sha> docker compose -f infra/docker-compose.prod.yml up -d
   docker compose -f infra/docker-compose.prod.yml exec app php artisan migrate --force      # migraciones compatibles hacia atrás
   docker compose -f infra/docker-compose.prod.yml exec app php artisan igr:clickhouse:migrate
   docker compose -f infra/docker-compose.prod.yml exec horizon php artisan horizon:terminate
   ```
4. Cron del host: `mariadb-backup.sh` (03:15 UTC) y `clickhouse-backup.sh` (03:45 UTC). Simulacro de restauración cada mes (`backup/RESTAURACION.md`).
5. Better Stack revisa `https://in.growth.imagina.cloud/health` (MariaDB, ClickHouse, Redis y retraso del stream).
