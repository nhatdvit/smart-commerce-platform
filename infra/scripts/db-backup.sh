#!/usr/bin/env bash
# Logical backup of the PostgreSQL database.
#
#   ./infra/scripts/db-backup.sh [output-dir]
#
# A docker volume is NOT a backup (docs/database/DATABASE_SPEC.md section 9). Copy the
# resulting file off-host and test a restore on a schedule; an untested backup
# is not a backup.
set -euo pipefail

OUT_DIR="${1:-infra/backups}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

# shellcheck source=/dev/null
set -a; [[ -f .env ]] && source .env; set +a

: "${POSTGRES_DB:?POSTGRES_DB is required}"
: "${POSTGRES_USER:?POSTGRES_USER is required}"

mkdir -p "$OUT_DIR"
TARGET="${OUT_DIR}/${POSTGRES_DB}-${STAMP}.dump"

echo "==> Dumping ${POSTGRES_DB} to ${TARGET}"
docker compose --env-file .env -f infra/compose.yaml exec -T postgres \
  pg_dump --format=custom --no-owner --no-privileges \
  --username "$POSTGRES_USER" "$POSTGRES_DB" > "$TARGET"

echo "==> Done: $(du -h "$TARGET" | cut -f1)"
echo "Restore with:"
echo "  docker compose --env-file .env -f infra/compose.yaml exec -T postgres \\"
echo "    pg_restore --clean --if-exists --no-owner --username $POSTGRES_USER \\"
echo "    --dbname $POSTGRES_DB < $TARGET"
