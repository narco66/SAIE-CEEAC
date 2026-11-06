#!/bin/sh
# Sauvegarde de la pile Docker (CONT-01, CONT-02) : archivage continu des WAL par PostgreSQL (RPO ≤ 1 h grâce à
# archive_timeout), sauvegarde de base complète et copie du stockage applicatif chaque nuit, conservation 14 jours.
# L'état est écrit dans storage/app/backup-status.json, lu par la supervision (V40). La copie hors site, chiffrée,
# vers le support de la DSI est assurée par l'outil institutionnel à partir du volume « backups » (voir la procédure).
set -eu
: "${BACKUP_HOUR:=02}"
: "${RETENTION_DAYS:=14}"
# Le volume d'archive WAL doit appartenir à l'utilisateur postgres (uid 999 de l'image officielle) ; PostgreSQL réessaie
# les WAL non archivés tant que l'archivage échoue : aucune perte avant cette correction.
chown 999:999 /wal
mkdir -p /storage/app
status() { printf '{"last_success_at":"%s","last_wal_at":"%s","last_base":"%s"}\n' "$1" "$(ls -t /wal 2>/dev/null | head -1 | xargs -r -I{} date -u -r /wal/{} +%Y-%m-%dT%H:%M:%SZ)" "$2" > /storage/app/backup-status.json; }
while true; do
  if [ "$(date -u +%H)" = "$BACKUP_HOUR" ] || [ ! -f /storage/app/backup-status.json ]; then
    stamp=$(date -u +%Y%m%dT%H%M%SZ)
    target="/backups/$stamp"
    mkdir -p "$target"
    PGPASSWORD="$(cat /run/secrets/db_admin_password)" pg_basebackup -h postgres -U saie_admin -D "$target/base" -Ft -z -X none --checkpoint=fast
    tar -C /storage -czf "$target/storage.tgz" app/private
    sha256sum "$target"/base/*.tar.gz "$target/storage.tgz" > "$target/SHA256SUMS"
    find /backups -mindepth 1 -maxdepth 1 -type d -mtime +"$RETENTION_DAYS" -exec rm -rf {} +
    find /wal -type f -mtime +"$RETENTION_DAYS" -delete
    status "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$stamp"
    sleep 3600
  fi
  sleep 300
done
