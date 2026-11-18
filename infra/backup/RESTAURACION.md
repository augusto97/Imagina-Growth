# Simulacro mensual de restauración

> hub/CLAUDE.md §18: se hace **cada mes** y se deja constancia en la tabla del final. Una copia que nunca se restauró no es una copia.

## 1. Preparar un entorno aislado
1. Crear un VPS temporal (o usar staging) con Docker y `rclone` apuntando al mismo remoto (solo lectura).
2. Clonar el repositorio y copiar `infra/docker-compose.prod.yml` con un `.env` de prueba (otras contraseñas, `APP_ENV=staging`).

## 2. MariaDB
```bash
rclone copy "$IGR_BACKUP_REMOTE/mariadb/<STAMP>.tar.gz" /srv/restore/
tar -xzf /srv/restore/<STAMP>.tar.gz -C /srv/restore/
docker compose -f infra/docker-compose.prod.yml stop mariadb
docker run --rm -v imagina-growth_mariadb:/var/lib/mysql -v /srv/restore:/restore mariadb:11.4 \
  sh -c "rm -rf /var/lib/mysql/* && mariadb-backup --copy-back --target-dir=/restore/<STAMP> && chown -R mysql:mysql /var/lib/mysql"
docker compose -f infra/docker-compose.prod.yml start mariadb
```
Para llegar a un punto en el tiempo, aplicar los binlogs posteriores con `mariadb-binlog --stop-datetime=… | mariadb`.

## 3. ClickHouse
```bash
rclone copy "$IGR_BACKUP_REMOTE/clickhouse" /var/lib/docker/volumes/imagina-growth_backups/_data/clickhouse
clickhouse-client --query "RESTORE DATABASE igr FROM Disk('backups', 'incr-<FECHA>.zip')"
```
La restauración de una incremental trae sola su copia completa base.

## 4. Verificar
- `php artisan migrate:status` sin migraciones pendientes inesperadas.
- `php artisan igr:clickhouse:migrate` responde «ClickHouse ya está al día».
- En el panel: un espacio de trabajo con datos, la conciliación de un sitio y el conteo de eventos de las últimas 24 h de la copia.
- Comparar `SELECT count() FROM events` y `SELECT COUNT(*) FROM orders` con los valores anotados el día de la copia.
- Medir el tiempo total (objetivo: menos de 2 horas).

## 5. Registro

| Fecha | Copia restaurada | Tiempo | Resultado | Responsable |
|---|---|---|---|---|
| | | | | |
