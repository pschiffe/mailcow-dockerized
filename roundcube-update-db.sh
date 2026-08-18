#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

. mailcow.conf

# Do not run if DBROUNDCUBE is not set
[[ ! "${DBROUNDCUBE:-}" ]] && exit 0

# Wait for the standalone Roundcube entrypoint to finish.
ROUNDCUBE_CONTAINER="$(docker ps -q \
  --filter "label=com.docker.compose.project=${COMPOSE_PROJECT_NAME:-mailcow-dockerized}" \
  --filter "label=com.docker.compose.service=roundcube-mailcow")"

if [[ -z "${ROUNDCUBE_CONTAINER}" ]]; then
  echo >&2 "Roundcube container is not running."
  exit 1
fi

for _ in {1..90}; do
  if docker exec "${ROUNDCUBE_CONTAINER}" /hooks/healthcheck.sh; then
    break
  fi
  sleep 2
done

if ! docker exec "${ROUNDCUBE_CONTAINER}" /hooks/healthcheck.sh; then
  echo >&2 "Roundcube container did not become ready."
  exit 1
fi

docker exec "${ROUNDCUBE_CONTAINER}" \
  /var/www/html/bin/initdb.sh --dir=/var/www/html/SQL --update
