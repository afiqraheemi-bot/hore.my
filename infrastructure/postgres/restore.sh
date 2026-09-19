#!/usr/bin/env bash
set -euo pipefail

# Restores one database inside the running Postgres container from a
# pg_dump -Fc archive (backup.sh's own output format): drops and
# recreates <database-name>, then pg_restores into it.
#
# DESTRUCTIVE to <database-name> only — every other database on the
# same Postgres server (including the main application database) is
# untouched. Refuses to run against a database name that does not end
# in "_test" unless FORCE=1 is set, mirroring this repo's own
# Tests\TestCase safeguard (infrastructure/postgres/init-test-database.sql)
# against silently wiping real data.
#
# Usage: restore.sh <database-name> <input-path>
#
# Env overrides: POSTGRES_CONTAINER (default horemy-postgres-1),
# POSTGRES_USER (default postgres), FORCE=1 to allow a non-"_test" name.

CONTAINER="${POSTGRES_CONTAINER:-horemy-postgres-1}"
DB_USER="${POSTGRES_USER:-postgres}"
FORCE="${FORCE:-0}"

DB_NAME="${1:?Usage: restore.sh <database-name> <input-path>}"
INPUT_PATH="${2:?Usage: restore.sh <database-name> <input-path>}"

if [[ "$DB_NAME" != *_test && "$FORCE" != "1" ]]; then
  echo "Refusing to restore into \"$DB_NAME\": its name does not end in \"_test\"." >&2
  echo "This guards against overwriting a real database by mistake. Set FORCE=1 to override." >&2
  exit 1
fi

if [ ! -f "$INPUT_PATH" ]; then
  echo "Backup file not found: $INPUT_PATH" >&2
  exit 1
fi

docker exec "$CONTAINER" psql -U "$DB_USER" -d postgres -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS \"$DB_NAME\";"
docker exec "$CONTAINER" psql -U "$DB_USER" -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE \"$DB_NAME\";"
docker exec -i "$CONTAINER" pg_restore -U "$DB_USER" -d "$DB_NAME" --no-owner --no-privileges <"$INPUT_PATH"

echo "Restored \"$DB_NAME\" in container \"$CONTAINER\" from $INPUT_PATH"
