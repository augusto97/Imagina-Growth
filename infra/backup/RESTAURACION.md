# Restauración y simulacro mensual

> Una copia que nunca se restauró no es una copia. La prueba automática (`growth-backup-verify`, cada domingo)
> restaura la última copia en una instancia temporal y compara los conteos; además, **cada mes** se hace un
> simulacro completo en un servidor aparte y se deja constancia en la tabla del final.

Todo es nativo (systemd), sin Docker. Rutas y nombres: `docs/despliegue.md`. Las copias están en el remoto de
rclone de `/etc/growth/backup.conf` (`GROWTH_BACKUP_REMOTE`, p. ej. `storagebox-crypt:growth`):

```
<remoto>/mariadb/<AAAAMMDDTHHMMSSZ>.tar.zst          copia física preparada (mariadb-backup)
<remoto>/mariadb/<AAAAMMDDTHHMMSSZ>.manifest.json    conteos de antes y después de la copia
<remoto>/mariadb/binlogs/mariadb-bin.NNNNNN          binlogs cerrados (punto en el tiempo)
<remoto>/clickhouse/full-AAAAMMDD.zip                copia completa (domingos)
<remoto>/clickhouse/incr-AAAAMMDD.zip                incremental sobre la última completa
<remoto>/clickhouse/*.manifest.json
```

## 1. Preparar el servidor de destino

- **Simulacro:** un VPS temporal con Ubuntu 24.04 instalado con `install.sh` (otro dominio, otro `.env`, mismos
  remotos de rclone en **solo lectura**: copia `/root/.config/rclone/rclone.conf` y las dos contraseñas del
  remoto cifrado desde el gestor de contraseñas).
- **Desastre real:** el servidor nuevo instalado con `install.sh` y el `.env` original
  (`/opt/growth/shared/.env`) restaurado desde el gestor de contraseñas: **`APP_KEY` e `IGR_BLIND_INDEX_KEY`
  tienen que ser los mismos**, si no los datos cifrados y las búsquedas por correo o teléfono no funcionan.

Antes de restaurar, detén la app para que nada escriba:

```bash
systemctl stop growth.target
```

## 2. MariaDB

```bash
STAMP=20261122T031500Z                                     # la copia que vas a restaurar
mkdir -p /var/lib/growth-backups/restore && cd /var/lib/growth-backups/restore
rclone copy "<remoto>/mariadb" . --include "$STAMP.*"
tar -I zstd -xf "$STAMP.tar.zst"                           # queda la carpeta $STAMP, ya preparada

systemctl stop mariadb
mv /var/lib/mysql "/var/lib/mysql.antes-$(date +%s)"       # no borres nada hasta comprobar
mkdir /var/lib/mysql
mariadb-backup --copy-back --target-dir="/var/lib/growth-backups/restore/$STAMP"
chown -R mysql:mysql /var/lib/mysql
systemctl start mariadb
```

### Recuperar hasta un momento exacto (binlogs)

La copia trae la posición del binlog en que quedó: `cat $STAMP/mariadb_backup_binlog_info` (archivo y posición).
Para llegar, por ejemplo, a las 14:32 del día siguiente:

```bash
rclone copy "<remoto>/mariadb/binlogs" ./binlogs
# Desde el archivo y la posición de mariadb_backup_binlog_info, aplicando los binlogs siguientes en orden:
mariadb-binlog --start-position=<posición> --stop-datetime="2026-11-23 14:32:00" \
  binlogs/<archivo> binlogs/<los siguientes…> | mariadb
```

Los binlogs del día en curso que todavía no se cerraron están solo en el servidor original
(`/var/lib/mysql/mariadb-bin.*`): si sigue accesible, cópialos también.

## 3. ClickHouse

El disco `backups` de ClickHouse apunta a `/var/lib/growth-backups/clickhouse/`. Una incremental necesita su
completa en el mismo disco (el manifiesto dice cuál es, campo `base`).

```bash
cd /var/lib/growth-backups/clickhouse
rclone copy "<remoto>/clickhouse" . --include "incr-20261125.*" --include "full-20261122.*"
chown clickhouse:clickhouse ./*.zip
clickhouse-client --query "DROP DATABASE IF EXISTS igr SYNC"     # solo en el servidor de destino
clickhouse-client --query "RESTORE DATABASE igr FROM Disk('backups', 'incr-20261125.zip')"
```

ClickHouse no tiene binlogs: lo que llegó después de la última copia se pierde en ClickHouse. Si el plugin de
los sitios guardaba esos eventos en su outbox, se pueden volver a pedir con una importación histórica
(`wp igr backfill --from=AAAA-MM-DD` en cada sitio).

## 4. Volver a levantar y verificar

```bash
systemctl start growth.target
curl -fsS http://127.0.0.1:8080/health/ready        # status ok y la versión
```

- `growth-php /opt/growth/current/hub/artisan migrate:status`: sin migraciones pendientes inesperadas.
- `growth-php /opt/growth/current/hub/artisan igr:clickhouse:migrate`: «ClickHouse ya está al día».
- Compara con el manifiesto de la copia: `SELECT COUNT(*) FROM contacts`, `orders`, `workspaces`… y
  `SELECT count() FROM igr.events` tienen que quedar entre los valores `before` y `after`.
- En el panel: un espacio de trabajo con datos, la conciliación de un sitio y los eventos de las últimas 24 h de
  la copia.
- Mide el tiempo total (objetivo: menos de 2 horas).
- Cuando todo esté bien, borra `/var/lib/mysql.antes-*` y `/var/lib/growth-backups/restore`.

## 5. Registro del simulacro mensual

| Fecha | Copia restaurada | Tiempo | Resultado | Responsable |
|---|---|---|---|---|
| | | | | |
