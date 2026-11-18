#!/usr/bin/env bash
# Copia diaria de MariaDB con mariadb-backup (+ binlogs para recuperar a un punto en el tiempo).
# Cron en el host: 15 3 * * *  /srv/igr/infra/backup/mariadb-backup.sh
# Requiere: rclone configurado con el remoto IGR_BACKUP_REMOTE (Hetzner Storage Box o S3).
set -euo pipefail
COMPOSE="docker compose -f /srv/igr/infra/docker-compose.prod.yml"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
DIR=/backups/mariadb/$STAMP
REMOTE=${IGR_BACKUP_REMOTE:?define IGR_BACKUP_REMOTE, p. ej. storagebox:igr}
KEEP_DAYS=${IGR_BACKUP_KEEP_DAYS:-30}

$COMPOSE exec -T mariadb sh -c "mariadb-backup --backup --user=root --password=\"\$MARIADB_ROOT_PASSWORD\" --target-dir=$DIR && mariadb-backup --prepare --target-dir=$DIR"
$COMPOSE exec -T mariadb sh -c "cd /backups/mariadb && tar -czf $STAMP.tar.gz $STAMP && rm -rf $STAMP"
# Binlogs cerrados desde la última copia.
$COMPOSE exec -T mariadb sh -c "mkdir -p /backups/mariadb/binlogs && cp -n /var/lib/mysql/mariadb-bin.[0-9]* /backups/mariadb/binlogs/ 2>/dev/null || true"

VOLUME=$(docker volume inspect -f '{{ .Mountpoint }}' imagina-growth_backups)
# Las copias van cifradas al remoto (remoto rclone de tipo crypt).
rclone copy "$VOLUME/mariadb" "$REMOTE/mariadb" --min-age 1m
find "$VOLUME/mariadb" -maxdepth 1 -name '*.tar.gz' -mtime +2 -delete
rclone delete "$REMOTE/mariadb" --min-age "${KEEP_DAYS}d" --include '*.tar.gz'
echo "MariaDB: copia $STAMP lista."
