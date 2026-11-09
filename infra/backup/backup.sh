#!/bin/sh
# Sauvegarde de la pile Docker (CONT-01, CONT-02) :
#   - archivage continu des WAL par PostgreSQL (RPO base ≤ 1 h grâce à archive_timeout) ;
#   - copie incrémentale HORAIRE des fichiers privés dans « files-current » (RPO fichiers ≤ 1 h) ;
#   - sauvegarde de base complète et archive complète du stockage applicatif chaque nuit, conservées 14 jours.
# L'état est écrit dans storage/app/backup-status.json, lu par la supervision (V40). La copie hors site, chiffrée,
# vers le support de la DSI est assurée par l'outil institutionnel à partir des volumes « backups » et « walarchive ».
# BACKUP_SOURCE_ONLY=1 charge seulement les fonctions (tests), sans rien lancer.
set -eu
: "${BACKUP_HOUR:=02}"
: "${RETENTION_DAYS:=14}"
: "${BACKUP_DIR:=/backups}"
: "${STORAGE_DIR:=/storage}"
: "${WAL_DIR:=/wal}"
FILES_DIR="$BACKUP_DIR/files-current"

now_utc() { date -u +%Y-%m-%dT%H:%M:%SZ; }

# Copie incrémentale : seuls les fichiers nouveaux ou modifiés sont copiés. Un fichier supprimé de l'application reste
# dans la copie : cette étape n'efface jamais rien.
copy_files() {
  mkdir -p "$FILES_DIR"
  cp -a -u "$STORAGE_DIR/app/private/." "$FILES_DIR/"
  now_utc > "$BACKUP_DIR/.last-files"
}

# Sauvegardes complètes plus anciennes que la rétention ; la copie horaire « files-current » n'est jamais purgée.
prune() {
  find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d ! -name files-current -mtime +"$RETENTION_DAYS" -exec rm -rf {} +
  find "$WAL_DIR" -type f -mtime +"$RETENTION_DAYS" -delete
}

full_backup() {
  stamp=$(date -u +%Y%m%dT%H%M%SZ)
  target="$BACKUP_DIR/$stamp"
  mkdir -p "$target"
  PGPASSWORD="$(cat /run/secrets/db_admin_password)" pg_basebackup -h postgres -U saie_admin -D "$target/base" -Ft -z -X none --checkpoint=fast
  tar -C "$STORAGE_DIR" -czf "$target/storage.tgz" app/private
  sha256sum "$target"/base/*.tar.gz "$target/storage.tgz" > "$target/SHA256SUMS"
  prune
  echo "$stamp" > "$BACKUP_DIR/.last-base"
  date -u +%Y%m%d > "$BACKUP_DIR/.last-base-day"
  now_utc > "$BACKUP_DIR/.last-success"
}

write_status() {
  last_wal=$(ls -t "$WAL_DIR" 2>/dev/null | head -1)
  wal_at=""
  if [ -n "$last_wal" ]; then wal_at=$(date -u -r "$WAL_DIR/$last_wal" +%Y-%m-%dT%H:%M:%SZ); fi
  printf '{"last_success_at":"%s","last_wal_at":"%s","last_base":"%s","last_files_at":"%s"}\n' \
    "$(cat "$BACKUP_DIR/.last-success" 2>/dev/null || true)" "$wal_at" \
    "$(cat "$BACKUP_DIR/.last-base" 2>/dev/null || true)" "$(cat "$BACKUP_DIR/.last-files" 2>/dev/null || true)" \
    > "$STORAGE_DIR/app/backup-status.json"
}

if [ "${BACKUP_SOURCE_ONLY:-0}" = 1 ]; then return 0; fi

# Le volume d'archive WAL doit appartenir à l'utilisateur postgres (uid 999 de l'image officielle) ; PostgreSQL réessaie
# les WAL non archivés tant que l'archivage échoue : aucune perte avant cette correction.
chown 999:999 "$WAL_DIR"
mkdir -p "$STORAGE_DIR/app"
while true; do
  # Sauvegarde complète : une fois par nuit à BACKUP_HOUR (UTC), et au premier démarrage.
  if [ ! -f "$BACKUP_DIR/.last-success" ] || { [ "$(date -u +%H)" = "$BACKUP_HOUR" ] && [ "$(cat "$BACKUP_DIR/.last-base-day" 2>/dev/null || true)" != "$(date -u +%Y%m%d)" ]; }; then
    full_backup
  fi
  # Copie des fichiers : une fois par heure.
  if [ "$(cat "$BACKUP_DIR/.files-hour" 2>/dev/null || true)" != "$(date -u +%Y%m%d%H)" ]; then
    copy_files
    date -u +%Y%m%d%H > "$BACKUP_DIR/.files-hour"
  fi
  write_status
  sleep 300
done
