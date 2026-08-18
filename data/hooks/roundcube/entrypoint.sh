#!/bin/bash
set -e

if [[ -z "${DBROUNDCUBE:-}" ]]; then
  echo "DBROUNDCUBE is not set, skipping Roundcube..."
  exec sleep infinity
fi

if [[ -z "${ROUNDCUBE_DES_KEY:-}" ]]; then
  echo >&2 "DBROUNDCUBE is set but ROUNDCUBE_DES_KEY is missing; refusing to start Roundcube."
  exec sleep infinity
fi

exec /docker-entrypoint.sh "$@"
