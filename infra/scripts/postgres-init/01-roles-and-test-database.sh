#!/bin/sh
# Runs once, on first initialisation of an empty PostgreSQL data directory.
#
# Creates two things the application cannot create for itself:
#
#   1. the migration role - separate from the runtime role, because the API and
#      worker need no DDL rights. Least privilege means a SQL injection in a
#      request handler cannot drop a table (CLAUDE.md §7).
#
#   2. the test database - integration and feature tests run against real
#      PostgreSQL, never SQLite, because row locking, partial indexes, CHECK
#      constraints and TIMESTAMPTZ behaviour are load-bearing (CLAUDE.md §12).
#
# It is NOT a substitute for migrations and creates no application tables.
set -eu

: "${POSTGRES_DB:?POSTGRES_DB is required}"
: "${POSTGRES_USER:?POSTGRES_USER is required}"

MIGRATION_USER="${MIGRATION_DB_USERNAME:-scp_migrator}"
MIGRATION_PASSWORD="${MIGRATION_DB_PASSWORD:-${POSTGRES_PASSWORD}}"
TEST_DB="${POSTGRES_DB}_test"

psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --set ON_ERROR_STOP=1 <<SQL
-- Migration role: owns the schema and may change it.
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${MIGRATION_USER}') THEN
        CREATE ROLE ${MIGRATION_USER} LOGIN PASSWORD '${MIGRATION_PASSWORD}';
    END IF;
END
\$\$;

GRANT ALL PRIVILEGES ON DATABASE ${POSTGRES_DB} TO ${MIGRATION_USER};
GRANT ALL ON SCHEMA public TO ${MIGRATION_USER};

-- The runtime role reads and writes rows but does not own the schema.
-- Default privileges apply to tables the migration role creates later.
ALTER DEFAULT PRIVILEGES FOR ROLE ${MIGRATION_USER} IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ${POSTGRES_USER};
ALTER DEFAULT PRIVILEGES FOR ROLE ${MIGRATION_USER} IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO ${POSTGRES_USER};
SQL

# Separate database so a test run can truncate freely without touching
# development data.
psql --username "$POSTGRES_USER" --dbname postgres --set ON_ERROR_STOP=1 <<SQL
SELECT 'CREATE DATABASE ${TEST_DB} OWNER ${POSTGRES_USER}'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = '${TEST_DB}')\gexec
SQL

echo "postgres-init: ensured role ${MIGRATION_USER} and database ${TEST_DB}"
