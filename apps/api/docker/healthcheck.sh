#!/bin/sh
# Container-level health only: is this PHP-FPM pool answering?
#
# Readiness for traffic is a different question and lives at
# GET /health/ready, which the orchestrator and the gateway use.
set -eu

REPLY=$(SCRIPT_NAME=/fpm-ping \
        SCRIPT_FILENAME=/fpm-ping \
        REQUEST_METHOD=GET \
        cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null || true)

case "$REPLY" in
  *pong*) exit 0 ;;
  *) echo "php-fpm did not answer /fpm-ping" >&2; exit 1 ;;
esac
