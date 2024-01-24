#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

# Do not run if roundcube-init-db.sh.done exists
[[ -f roundcube-init-db.sh.done ]] && exit 0

. mailcow.conf

# Do not run if DBROUNDCUBE is not set
[[ ! "${DBROUNDCUBE:-}" ]] && exit 0

# Wait for MySQL to be ready
sleep 15

# Create database and user
docker exec "$(docker ps -f name=mysql-mailcow -q)" mysql -u root -p"${DBROOT}" -e "
CREATE DATABASE IF NOT EXISTS roundcubemail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'roundcube'@'%' IDENTIFIED BY '${DBROUNDCUBE}';
GRANT ALL PRIVILEGES ON roundcubemail.* TO 'roundcube'@'%';
"

touch roundcube-init-db.sh.done
