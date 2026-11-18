#!/usr/bin/env bash
# Comandos falsos para probar las copias sin MariaDB, ClickHouse, rclone ni Better Stack reales.
# Cada uno anota su llamada en $CALLS. Se crean en $FAKEBIN con make_fakes.

make_fakes() {
    # Nunca fuera de la carpeta temporal de la prueba: estos comandos reemplazarían a los reales.
    case ${FAKEBIN:-} in /tmp/growth-backup-test.?*/bin) ;; *) echo "FAKEBIN inválido: «${FAKEBIN:-}»" >&2; exit 2 ;; esac
    mkdir -p "$FAKEBIN"

    # mariadb-backup: --backup crea un datadir de mentira, --prepare lo marca. FAKE_FAIL=mariadb-backup falla.
    cat >"$FAKEBIN/mariadb-backup" <<'SH'
#!/usr/bin/env bash
echo "mariadb-backup $*" >>"$CALLS"
[ "${FAKE_FAIL:-}" = "mariadb-backup" ] && exit 3
for a in "$@"; do case $a in --target-dir=*) dir=${a#--target-dir=} ;; esac; done
case " $* " in
  *" --backup "*) mkdir -p "$dir/igr"; echo "datos" >"$dir/ibdata1"; echo "tabla" >"$dir/igr/contacts.ibd" ;;
  *" --prepare "*) touch "$dir/prepared" ;;
esac
SH

    # mariadb: responde las consultas que hacen los scripts. Con --socket=…restore-check, responde la
    # instancia temporal (FAKE_RESTORED_*).
    cat >"$FAKEBIN/mariadb" <<'SH'
#!/usr/bin/env bash
q=""; restored=0
while [ $# -gt 0 ]; do
  case $1 in
    -e) q=$2; shift ;;
    --socket=*restore-check*) restored=1 ;;
  esac
  shift
done
echo "mariadb[$restored] $q" >>"$CALLS"
case $q in
  "SHOW BINARY LOGS")
    [ "${FAKE_BINLOGS:-1}" = "1" ] || exit 1
    printf 'mariadb-bin.000001\t100\nmariadb-bin.000002\t200\nmariadb-bin.000003\t300\n' ;;
  "SELECT @@datadir") echo "$FAKE_DATADIR/" ;;
  *"table_schema = 'igr' AND table_name = '"*)
    t=${q##*table_name = \'}; t=${t%\'*}
    case " ${FAKE_KEY_TABLES:-workspaces users contacts orders} " in *" $t "*) echo 1 ;; *) echo 0 ;; esac ;;
  *"information_schema.tables WHERE table_schema = 'igr'"*)
    if [ $restored = 1 ]; then echo "${FAKE_RESTORED_TABLES:-${FAKE_TABLES:-120}}"; else echo "${FAKE_TABLES:-120}"; fi ;;
  "SELECT COUNT(*) FROM \`igr\`.\`"*)
    t=${q#*\`igr\`.\`}; t=${t%\`}
    var=FAKE_COUNT_$t; rvar=FAKE_RESTORED_COUNT_$t
    if [ $restored = 1 ]; then echo "${!rvar:-${!var:-10}}"; else echo "${!var:-10}"; fi ;;
  *) echo 0 ;;
esac
SH

    # mariadbd: «arranca» la instancia temporal: crea el socket y el pid y se queda corriendo.
    cat >"$FAKEBIN/mariadbd" <<'SH'
#!/usr/bin/env bash
echo "mariadbd $*" >>"$CALLS"
for a in "$@"; do
  case $a in --socket=*) sock=${a#--socket=} ;; --pid-file=*) pid=${a#--pid-file=} ;; --datadir=*) data=${a#--datadir=} ;; esac
done
[ -f "$data/prepared" ] || { echo "datadir sin preparar" >>"$CALLS"; exit 1; }
echo $$ >"$pid"
touch "$sock"
exec -a growth-fake-mariadbd sleep 300
SH

    # mariadb-admin: ping responde si el socket existe; shutdown mata la instancia temporal.
    cat >"$FAKEBIN/mariadb-admin" <<'SH'
#!/usr/bin/env bash
for a in "$@"; do case $a in --socket=*) sock=${a#--socket=} ;; esac; done
echo "mariadb-admin $*" >>"$CALLS"
case " $* " in
  *" ping "*) [ -e "$sock" ] ;;
  *" shutdown "*) pidf=$(dirname "$sock")/mariadbd.pid; [ -f "$pidf" ] && kill "$(cat "$pidf")" 2>/dev/null; rm -f "$sock" ;;
esac
SH

    # clickhouse-client: BACKUP crea el zip en el disco «backups»; conteos con FAKE_CH_*.
    # Guarda el archivo de configuración que recibió para revisar que la contraseña va ahí y no en la línea.
    cat >"$FAKEBIN/clickhouse-client" <<'SH'
#!/usr/bin/env bash
cfg=""; q=""
while [ $# -gt 0 ]; do
  case $1 in --config-file) cfg=$2; shift ;; --query) q=$2; shift ;; esac
  shift
done
echo "clickhouse $q" >>"$CALLS"
echo "argv: $*" >>"$CALLS"
if [ -n "$cfg" ]; then
  cp "$cfg" "$TMPROOT/last-ch-config.xml"
  stat -c '%a' "$cfg" >"$TMPROOT/last-ch-config.mode"
fi
disk=$GROWTH_BACKUP_DIR/clickhouse
case $q in
  "BACKUP DATABASE"*)
    name=${q#*Disk(\'backups\', \'}; name=${name%%\'*}
    echo "parcial" >"$disk/$name"
    [ "${FAKE_FAIL:-}" = "ch-backup" ] && exit 4
    echo "copia" >"$disk/$name" ;;
  "RESTORE DATABASE"*)
    name=${q#*Disk(\'backups\', \'}; name=${name%%\'*}
    [ -f "$disk/$name" ] || { echo "no existe $name" >&2; exit 5; } ;;
  *"FROM system.tables WHERE database = 'growth_restore_check'"*) echo "${FAKE_CH_RESTORED_TABLES:-${FAKE_CH_TABLES:-5}}" ;;
  *"FROM system.tables WHERE database = 'igr'"*) echo "${FAKE_CH_TABLES:-5}" ;;
  *"\`growth_restore_check\`.events"*) echo "${FAKE_CH_RESTORED_EVENTS:-${FAKE_CH_EVENTS:-1000}}" ;;
  *"\`igr\`.events"*) echo "${FAKE_CH_EVENTS:-1000}" ;;
  "DROP DATABASE"*) : ;;
  *) echo 0 ;;
esac
SH

    # rclone: el remoto «remote:…» es la carpeta $FAKE_REMOTE/….
    cat >"$FAKEBIN/rclone" <<'SH'
#!/usr/bin/env bash
echo "rclone $*" >>"$CALLS"
map() { case $1 in remote:*) echo "$FAKE_REMOTE/${1#remote:}" ;; *) echo "$1" ;; esac; }
cmd=$1; shift
args=(); includes=(); minage=""
while [ $# -gt 0 ]; do
  case $1 in
    --include) includes+=("$2"); shift ;;
    --min-age) minage=$2; shift ;;
    --*) ;;
    *) args+=("$1") ;;
  esac
  shift
done
matches() { local f=$1 p; [ ${#includes[@]} -eq 0 ] && return 0; for p in "${includes[@]}"; do
  if [[ $p == */\*\* ]]; then [[ $f == "${p%/\*\*}"/* ]] && return 0; else [[ $f == $p ]] && return 0; fi; done; return 1; }
case $cmd in
  copy)
    src=$(map "${args[0]}"); dst=$(map "${args[1]}"); mkdir -p "$dst"
    (cd "$src" && find . -type f | sed 's|^\./||') | while IFS= read -r f; do
      if matches "$f"; then mkdir -p "$dst/$(dirname "$f")"; cp -p "$src/$f" "$dst/$f"; fi
    done ;;
  copyto)
    src=$(map "${args[0]}"); dst=$(map "${args[1]}"); mkdir -p "$(dirname "$dst")"; cp -p "$src" "$dst" ;;
  lsf)
    dir=$(map "${args[0]}"); [ -d "$dir" ] || exit 0
    (cd "$dir" && find . -maxdepth 1 -type f | sed 's|^\./||') | while IFS= read -r f; do if matches "$f"; then echo "$f"; fi; done ;;
  delete)
    dir=$(map "${args[0]}"); days=${minage%d}; [ -d "$dir" ] || exit 0
    (cd "$dir" && find . -type f -mmin +$((days * 1440)) | sed 's|^\./||') | while IFS= read -r f; do
      if matches "$f"; then rm -f "$dir/$f"; fi
    done ;;
esac
SH

    # curl: anota la URL (el último argumento). FAKE_CURL_EXIT simula una caída de Better Stack.
    cat >"$FAKEBIN/curl" <<'SH'
#!/usr/bin/env bash
echo "curl ${*: -1}" >>"$CALLS"
exit "${FAKE_CURL_EXIT:-0}"
SH

    chmod +x "$FAKEBIN"/*
}
