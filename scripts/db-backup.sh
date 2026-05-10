#!/bin/bash

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="$PROJECT_DIR/backups"
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")

source "$PROJECT_DIR/.env"

BACKUP_FILE="$BACKUP_DIR/connectflow_${TIMESTAMP}.sql.gz"

docker exec connectflow_db mysqldump \
    -u"${DB_USERNAME}" \
    -p"${DB_PASSWORD}" \
    --single-transaction \
    --routines \
    --triggers \
    --no-tablespaces \
    "${DB_DATABASE}" 2>/dev/null | gzip > "$BACKUP_FILE"

if [ -s "$BACKUP_FILE" ]; then
    echo "[$(date)] Backup created: $BACKUP_FILE ($(du -h "$BACKUP_FILE" | cut -f1))"
    find "$BACKUP_DIR" -name "connectflow_*.sql.gz" ! -name "$(basename "$BACKUP_FILE")" -delete
else
    echo "[$(date)] ERROR: Backup file is empty, removing"
    rm -f "$BACKUP_FILE"
    exit 1
fi
