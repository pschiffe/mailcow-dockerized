#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

. mailcow.conf

# Do not run if DBROUNDCUBE is not set
[[ ! "${DBROUNDCUBE:-}" ]] && exit 0

# Wait for MySQL to be ready
sleep 15

docker exec "$(docker ps -f name=php-fpm-mailcow -q)" /web/rc/bin/update.sh -v 1.6
