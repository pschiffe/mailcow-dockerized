#!/bin/bash

set -euo pipefail

# Do not run if DBROUNDCUBE is not set
[[ ! "${DBROUNDCUBE:-}" ]] && exit 0

chown www-data:www-data /web/rc/logs /web/rc/temp
chown -R root:www-data /web/rc/config
chmod 0750 /web/rc/logs /web/rc/temp /web/rc/config
chmod 0640 /web/rc/config/config.inc.php

/web/rc/bin/initdb.sh --dir=/web/rc/SQL --update
