#!/usr/bin/env bash
# Backup notturno del database (cron di root, vedi provision.sh).
#
# Il dump resta 14 giorni in /var/backups/photodaily. Se è configurato un
# remote rclone chiamato "photodaily-backup" (per esempio un bucket R2
# dedicato), il dump viene copiato anche lì: il backup automatico di OVH
# salva l'intera VPS, ma sta comunque presso lo stesso fornitore.
#
# Le foto non servono qui: stanno già su R2.
set -euo pipefail

DB_NAME=photodaily
DIR=/var/backups/photodaily
KEEP_DAYS=14
FILE=$DIR/db-$(date +%Y%m%d-%H%M).sql.gz

mkdir -p "$DIR"
chmod 700 "$DIR"

# root entra in MySQL col socket locale, senza password.
mysqldump --single-transaction --quick --routines --no-tablespaces "$DB_NAME" | gzip > "$FILE.tmp"
mv "$FILE.tmp" "$FILE"

find "$DIR" -name 'db-*.sql.gz' -mtime +$KEEP_DAYS -delete

if command -v rclone >/dev/null && rclone listremotes | grep -qx 'photodaily-backup:'; then
    rclone copy "$FILE" photodaily-backup:photodaily-backups/db/ --quiet
fi
