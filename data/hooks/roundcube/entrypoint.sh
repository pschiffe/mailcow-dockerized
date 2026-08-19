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

if [[ "${1:-}" == apache2* ]]; then
  set -- "$@" \
    -C "LoadModule remoteip_module /usr/lib/apache2/modules/mod_remoteip.so" \
    -C "RemoteIPHeader X-Forwarded-For" \
    -C "RemoteIPInternalProxy ${IPV4_NETWORK:-172.22.1}.0/24" \
    -C "RemoteIPInternalProxy ${IPV6_NETWORK:-fd4d:6169:6c63:6f77::/64}" \
    -C 'SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on'
fi

exec /docker-entrypoint.sh "$@"
