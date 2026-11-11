#!/usr/bin/env bash
# Bring up a working local stack from a clean checkout.
#
#   ./infra/scripts/bootstrap-dev.sh
#
# Idempotent: safe to re-run. Does nothing destructive - it never drops a
# database or deletes a volume.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

COMPOSE=(docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml)

if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "Created .env from .env.example."
fi

# The API refuses to boot without an APP_KEY, so generate one before anything
# else needs the container to start. key:generate --show only prints it.
if ! grep -qE '^APP_KEY=base64:.+' .env; then
  echo "==> Generating APP_KEY"
  KEY="$("${COMPOSE[@]}" run --rm --no-deps api php artisan key:generate --show | tr -d '\r\n')"

  if [[ -z "$KEY" ]]; then
    echo "Could not generate APP_KEY. Build the api image first:" >&2
    echo "  ${COMPOSE[*]} build api" >&2
    exit 1
  fi

  # Portable in-place edit: BSD and GNU sed disagree about -i.
  tmp="$(mktemp)"
  sed "s|^APP_KEY=.*|APP_KEY=${KEY}|" .env > "$tmp" && mv "$tmp" .env
  echo "    APP_KEY written to .env"
fi

echo "==> Building images"
"${COMPOSE[@]}" build

echo "==> Starting PostgreSQL and Redis"
"${COMPOSE[@]}" up -d postgres redis

echo "==> Waiting for PostgreSQL"
for _ in $(seq 1 60); do
  if "${COMPOSE[@]}" exec -T postgres pg_isready -q 2>/dev/null; then
    break
  fi
  sleep 1
done

echo "==> Applying migrations"
"${COMPOSE[@]}" run --rm migrate

echo "==> Seeding reference and demo data"
"${COMPOSE[@]}" run --user www-data --rm --no-deps --entrypoint php api artisan db:seed --force --no-interaction

echo "==> Starting the rest of the stack"
"${COMPOSE[@]}" up -d

if command -v pnpm >/dev/null 2>&1; then
  echo "==> Installing JavaScript dependencies"
  pnpm install --frozen-lockfile
else
  echo "==> pnpm not found on PATH; skipping JavaScript install"
  echo "    The web apps run in containers either way."
fi

cat <<'DONE'

Local stack is ready.

  Storefront   http://localhost:8080
  Admin        http://admin.localhost:8080
  API          http://localhost:8080/api/v1
  Liveness     http://localhost:8080/health/live
  Readiness    http://localhost:8080/health/ready
  Horizon      http://admin.localhost:8080/horizon
  OpenAPI      http://admin.localhost:8080/docs/api

  pnpm api:check    Pint, PHPStan and Pest
  pnpm infra:logs   follow logs

Demo staff accounts (local only), password "password":
  owner@example.test  manager@example.test  cashier@example.test  kitchen@example.test
DONE
