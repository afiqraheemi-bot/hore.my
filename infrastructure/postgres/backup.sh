#!/usr/bin/env bash
set -euo pipefail

# Backs up one database from the running Postgres container using
# pg_dump's custom format (-Fc): compressed, and restorable with
# pg_restore (restore.sh) independent of table order/dependencies.
#
# Usage: backup.sh <database-name> <output-path>
#
# Env overrides: POSTGRES_CONTAINER (default horemy-postgres-1),
# POSTGRES_USER (default postgres).

CONTAINER="${POSTGRES_CONTAINER:-horemy-postgres-1}"
DB_USER="${POSTGRES_USER:-postgres}"

DB_NAME="${1:?Usage: backup.sh <database-name> <output-path>}"
OUTPUT_PATH="${2:?Usage: backup.sh <database-name> <output-path>}"

mkdir -p "$(dirname "$OUTPUT_PATH")"

docker exec "$CONTAINER" pg_dump -U "$DB_USER" -Fc "$DB_NAME" >"$OUTPUT_PATH"

SIZE=$(du -h "$OUTPUT_PATH" | cut -f1)
echo "Backed up \"$DB_NAME\" from container \"$CONTAINER\" to $OUTPUT_PATH ($SIZE)"
