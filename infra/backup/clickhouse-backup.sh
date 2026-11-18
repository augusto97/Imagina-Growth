#!/usr/bin/env bash
# ClickHouse: copia completa los domingos e incremental los demás días (BACKUP … SETTINGS base_backup).
# Cron en el host: 45 3 * * *  /srv/igr/infra/backup/clickhouse-backup.sh
set -euo pipefail
COMPOSE="docker compose -f /srv/igr/infra/docker-compose.prod.yml"
DB=${CLICKHOUSE_DATABASE:-igr}
REMOTE=${IGR_BACKUP_REMOTE:?define IGR_BACKUP_REMOTE}
TODAY=$(date -u +%Y%m%d)
LAST_FULL=$(date -u -d "last sunday" +%Y%m%d)
[ "$(date -u +%u)" = "7" ] && LAST_FULL=$TODAY

ch() { $COMPOSE exec -T clickhouse clickhouse-client --user "${CLICKHOUSE_USERNAME:-igr}" --password "$CLICKHOUSE_PASSWORD" --query "$1"; }

if [ "$LAST_FULL" = "$TODAY" ]; then
  ch "BACKUP DATABASE $DB TO Disk('backups', 'full-$TODAY.zip')"
else
  ch "BACKUP DATABASE $DB TO Disk('backups', 'incr-$TODAY.zip') SETTINGS base_backup = Disk('backups', 'full-$LAST_FULL.zip')"
fi

VOLUME=$(docker volume inspect -f '{{ .Mountpoint }}' imagina-growth_backups)
rclone copy "$VOLUME/clickhouse" "$REMOTE/clickhouse" --min-age 1m
find "$VOLUME/clickhouse" -name '*.zip' -mtime +8 -delete
echo "ClickHouse: copia $TODAY lista."
