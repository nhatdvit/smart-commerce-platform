#!/usr/bin/env bash
# Regenerate the committed OpenAPI document at docs/api/openapi.json.
#
#   ./infra/scripts/export-openapi.sh        (or: pnpm api:openapi)
#
# Run this whenever an endpoint, Form Request or API Resource changes:
# CLAUDE.md §8 requires the OpenAPI document to be maintained, and §12 requires
# it updated in the same change as the code. CI diffs the result, so a stale
# document fails the build rather than quietly misleading clients.
#
# The export runs inside the api container because Scramble reads the real
# routes and config. It does NOT go through the gateway: a relative server URL
# would otherwise be resolved against whichever Host header was used, and the
# committed file would claim the API lives on that hostname.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

COMPOSE=(docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml)
TARGET="docs/api/openapi.json"
IN_CONTAINER="storage/app/openapi.json"

if ! "${COMPOSE[@]}" ps --status running --services 2>/dev/null | grep -qx api; then
  echo "The api service is not running. Start it first:" >&2
  echo "  pnpm infra:up" >&2
  exit 1
fi

echo "==> Generating the document"
"${COMPOSE[@]}" exec -T api php artisan config:clear --no-interaction >/dev/null
"${COMPOSE[@]}" exec -T api php artisan scramble:export --path="${IN_CONTAINER}" >/dev/null

# Pretty-print with stable flags so a diff shows contract changes rather than
# formatting noise, and so Vietnamese text and URLs stay readable.
echo "==> Formatting"
"${COMPOSE[@]}" exec -T api php -r '
    $path = $argv[1];
    $document = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents($path, json_encode(
        $document,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
' "${IN_CONTAINER}"

echo "==> Copying to ${TARGET}"
mkdir -p "$(dirname "${TARGET}")"
CONTAINER_ID="$("${COMPOSE[@]}" ps -q api)"
docker cp "${CONTAINER_ID}:/var/www/html/${IN_CONTAINER}" "${TARGET}"

echo "==> Done: $(wc -c < "${TARGET}") bytes"
echo "    Review the diff before committing - it is the client-facing contract."
