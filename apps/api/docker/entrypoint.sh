#!/bin/sh
# Container entrypoint for every role of this image.
#
# Caches configuration, routes and events at boot, when the real environment
# variables are present. Doing it at build time would bake the build host's
# values into the image.
#
# It deliberately does NOT run migrations: those are an explicit one-off deploy
# job (CLAUDE.md §9), so two replicas can never migrate concurrently.
set -eu

: "${APP_ENV:=production}"

if [ "${APP_ENV}" != "local" ] && [ "${APP_ENV}" != "testing" ]; then
  # A failure here must stop the container rather than serve a half-configured
  # app, so these are not guarded with "|| true".
  php artisan config:cache --no-interaction
  php artisan route:cache --no-interaction
  php artisan event:cache --no-interaction
else
  # In development the caches only get in the way of editing config.
  php artisan config:clear --no-interaction
  php artisan route:clear --no-interaction
fi

# Refuse to start on bad configuration, rather than failing on the first
# customer request. Runs after caching so it validates what will be served.
php artisan app:validate-config --no-interaction

# Fail fast and loudly if the storage tree is not writable: Laravel would
# otherwise fail later, per request, with a confusing error.
if [ ! -w storage/logs ]; then
  echo "storage/logs is not writable by $(id -un)" >&2
  exit 1
fi

exec "$@"
