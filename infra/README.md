# infra/

Despliegue del hub. La especificación está en `hub/CLAUDE.md` §18.

Archivos que se crearán en la Fase 0:
- `docker-compose.yml` (local): app, mariadb, clickhouse, redis, mailpit, wp-env con la tienda de prueba.
- `docker-compose.prod.yml`: app, ingest (Octane), horizon, scheduler, reverb, mariadb, clickhouse, redis, caddy y ml (Fase 4).
- `Caddyfile`: panel, ingesta, enlaces y dominios de clientes con TLS bajo demanda (endpoint `ask`).
- `backup/`: scripts de mariadb-backup y BACKUP de ClickHouse, con la guía del simulacro mensual de restauración.
- `.github/workflows/`: CI (pruebas, Larastan, tamaño del tracker) y despliegue.
