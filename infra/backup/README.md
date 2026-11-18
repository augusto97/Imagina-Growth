# Copias de seguridad y vigilancia

Contrato: `docs/despliegue.md` §6. Todo corre con systemd en el servidor, sin Docker.

| Tarea | Unidad | Cuándo | Qué hace |
|---|---|---|---|
| Copia de MariaDB | `growth-backup-mariadb.timer` | 03:15 UTC | `mariadb-backup --backup` y `--prepare`, comprimida con zstd, más los binlogs cerrados (recuperar a un punto en el tiempo); manifiesto con conteos; sube con rclone |
| Copia de ClickHouse | `growth-backup-clickhouse.timer` | 03:45 UTC | `BACKUP DATABASE` nativo: completa los domingos (o si no hay ninguna), incremental sobre la última completa los demás días; manifiesto; sube con rclone |
| Prueba de restauración | `growth-backup-verify.timer` | domingos 05:00 UTC | baja la última copia, la restaura en una instancia temporal de MariaDB (127.0.0.1:3307) y en la base temporal `growth_restore_check` de ClickHouse, compara con el manifiesto y borra todo |
| Vigilancia | `growth-monitor.timer` | cada minuto | `growth:monitor --ping`: salud, atraso de los streams de Redis, Horizon y colas; avisa a Better Stack solo de lo que está bien |

Los scripts (`growth-backup-mariadb`, `growth-backup-clickhouse`, `growth-backup-verify` y su librería
`growth-backup-lib.sh`) se instalan como root en `/usr/local/lib/growth/backup/`. La configuración está en
`/etc/growth/backup.conf` (plantilla: `backup.conf.example`) y las credenciales de ClickHouse, en el `.env` de la
app o en `backup.conf`. Las copias quedan en `/var/lib/growth-backups/{mariadb,clickhouse}` antes de subirse.

Cada copia avisa a su heartbeat de Better Stack al terminar bien y a `<url>/fail` si falla (también por un error
de configuración). Sin URL, no avisa.

## Storage Box de Hetzner con rclone (una sola vez)

Las copias van **cifradas**: un remoto `sftp` hacia el Storage Box y, encima, un remoto `crypt`. Los nombres de
abajo son ejemplos; las contraseñas no van en el repositorio.

1. En la consola de Hetzner, en el Storage Box: activa **SSH** (puerto **23**) y, si quieres, crea una subcuenta
   solo para estas copias. Anota el usuario (`uXXXXXX` o `uXXXXXX-subY`) y el host (`uXXXXXX.your-storagebox.de`).
2. En el servidor, como root, crea una llave SSH para las copias y agrégala al Storage Box:
   ```bash
   ssh-keygen -t ed25519 -N "" -f /root/.ssh/storagebox
   cat /root/.ssh/storagebox.pub | ssh -p 23 uXXXXXX@uXXXXXX.your-storagebox.de install-ssh-key
   ```
3. `rclone config` (como root) → **New remote**:
   - nombre `storagebox`, tipo `sftp`, host `uXXXXXX.your-storagebox.de`, usuario `uXXXXXX`, puerto `23`,
     `key_file = /root/.ssh/storagebox`, sin contraseña;
   - **New remote** otra vez: nombre `storagebox-crypt`, tipo `crypt`, `remote = storagebox:growth-backups`,
     cifrado de nombres `standard`, y dos contraseñas largas generadas por rclone.
   - **Guarda esas dos contraseñas en el gestor de contraseñas del equipo.** Sin ellas las copias no se pueden
     leer, ni siquiera desde el Storage Box.
4. Prueba: `rclone mkdir storagebox-crypt:growth && rclone lsd storagebox-crypt:` y pon
   `GROWTH_BACKUP_REMOTE=storagebox-crypt:growth` en `/etc/growth/backup.conf`.
5. Corre una vez cada tarea a mano y mira el journal:
   ```bash
   systemctl start growth-backup-mariadb growth-backup-clickhouse growth-backup-verify
   journalctl -u growth-backup-mariadb -u growth-backup-clickhouse -u growth-backup-verify -n 100
   ```

La configuración de rclone queda en `/root/.config/rclone/rclone.conf` (0600).

## Better Stack

- **Monitores de disponibilidad (Uptime → Monitors):** `https://<dominio del panel>/health/ready` (la app, su
  versión y las bases) y `https://<dominio de ingesta>/health` (ingesta y atraso de los streams), cada minuto.
- **Heartbeats (Uptime → Heartbeats)**, con el periodo y la gracia indicados:

  | Heartbeat | Dónde va la URL | Periodo | Gracia |
  |---|---|---|---|
  | Copia de MariaDB | `BETTERSTACK_HEARTBEAT_BACKUP_MARIADB` en `/etc/growth/backup.conf` | 1 día | 2 h |
  | Copia de ClickHouse | `BETTERSTACK_HEARTBEAT_BACKUP_CLICKHOUSE` en `/etc/growth/backup.conf` | 1 día | 2 h |
  | Prueba de restauración | `BETTERSTACK_HEARTBEAT_BACKUP_VERIFY` en `/etc/growth/backup.conf` | 7 días | 6 h |
  | Salud (MariaDB, ClickHouse, Redis) | `BETTERSTACK_HEARTBEAT_HEALTH` en `/opt/growth/shared/.env` | 1 min | 3 min |
  | Streams de ingesta al día | `BETTERSTACK_HEARTBEAT_STREAMS` en `/opt/growth/shared/.env` | 1 min | 5 min |
  | Horizon y colas | `BETTERSTACK_HEARTBEAT_QUEUES` en `/opt/growth/shared/.env` | 1 min | 5 min |

- Umbrales de `growth:monitor` (en el `.env`): `GROWTH_MONITOR_MAX_STREAM_LAG` (5000 eventos sin procesar),
  `GROWTH_MONITOR_MAX_STREAM_AGE` (120 s para el más viejo) y `GROWTH_MONITOR_MAX_QUEUE_BACKLOG` (10000 trabajos).
- Para ver lo que midió: `journalctl -u growth-monitor -n 5` (una línea JSON por minuto) o
  `growth-php /opt/growth/current/hub/artisan growth:monitor` a mano.

## Pruebas

`bash infra/tests/backup/run.sh` prueba los tres scripts con comandos falsos (sin bases reales): copia y subida,
fallas con aviso a `/fail` y sin basura, retención, la prueba de restauración (también cuando un conteo no
coincide o la instancia temporal no arranca) y el lector de `.env` con valores raros. Corre en el CI (job
`backup-scripts`) junto con shellcheck.
