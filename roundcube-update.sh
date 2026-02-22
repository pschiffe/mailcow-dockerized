#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
RC_DIR="${SCRIPT_DIR}/data/web/rc"
TARGET_VERSION=""
KEEP_TMP=0
APACHE_MIME_TYPES_URL="http://svn.apache.org/repos/asf/httpd/httpd/trunk/docs/conf/mime.types"

usage() {
  cat <<'USAGE'
Usage: roundcube-update.sh [options]

Update Roundcube files in data/web/rc to the latest stable 1.6.x release.
This script uses the downloaded upstream installto.sh, refreshes config/mime.types from Apache SVN,
runs composer update --no-dev -o --ignore-platform-reqs, and intentionally skips DB migrations.

Options:
  --version <1.6.x>       Use a specific Roundcube version (example: 1.6.13)
  --roundcube-dir <path>  Override Roundcube directory (default: ./data/web/rc)
  --keep-tmp              Keep temporary download/extract directory
  -h, --help              Show this help
USAGE
}

require_cmd() {
  local cmd="$1"
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "Error: required command not found: $cmd" >&2
    exit 1
  fi
}

version_ge() {
  # Returns success if $1 >= $2 (semantic-ish compare using sort -V)
  [[ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n1)" == "$1" ]]
}

extract_current_version() {
  local iniset="$1/program/include/iniset.php"
  [[ -f "$iniset" ]] || return 1

  grep -Eo "define\('RCMAIL_VERSION', '[^']+'\)" "$iniset" \
    | sed -E "s/.*'([^']+)'.*/\1/" \
    | head -n1
}

detect_latest_16x() {
  local releases_api version
  releases_api="$(curl -fsSL \
    -H 'Accept: application/vnd.github+json' \
    -H 'User-Agent: roundcube-update-script' \
    'https://api.github.com/repos/roundcube/roundcubemail/releases?per_page=100')"

  version="$(printf '%s\n' "$releases_api" \
    | grep -Eo '"tag_name"[[:space:]]*:[[:space:]]*"1\.6\.[0-9]+"' \
    | head -n1 \
    | sed -E 's/.*"(1\.6\.[0-9]+)"/\1/')"

  [[ -n "$version" ]] || return 1
  printf '%s\n' "$version"
}

patch_installto_skip_db_update() {
  local source_installto="$1"
  local patched_installto="$2"
  local match_count

  local update_call_pattern="bin/update.sh --version=\$oldversion"
  match_count="$(grep -c "$update_call_pattern" "$source_installto" || true)"
  if [[ "$match_count" != "1" ]]; then
    echo "Error: expected exactly one update.sh call in upstream installto.sh, found $match_count." >&2
    echo "Upstream installto.sh likely changed. Review required before continuing." >&2
    exit 1
  fi

  awk '
    /bin\/update\.sh --version=\$oldversion/ {
      print "    echo \"Skipping target update.sh execution (DB migrations disabled by roundcube-update.sh).\\n\";"
      next
    }
    { print }
  ' "$source_installto" > "$patched_installto"
}

update_apache_mime_types() {
  local rc_dir="$1"
  local tmp_file="$2"
  local target_file="$rc_dir/config/mime.types"

  echo "Updating $target_file from $APACHE_MIME_TYPES_URL"
  curl -fL --retry 3 --retry-delay 2 -o "$tmp_file" "$APACHE_MIME_TYPES_URL"

  if [[ ! -s "$tmp_file" ]]; then
    echo "Error: downloaded mime.types file is empty" >&2
    exit 1
  fi

  if ! grep -Eq '^[[:space:]]*text/plain([[:space:]]|$)' "$tmp_file"; then
    echo "Error: downloaded mime.types content validation failed" >&2
    exit 1
  fi

  mv "$tmp_file" "$target_file"
  chmod 0644 "$target_file"
}

apply_mailcow_15_to_16_config_migration() {
  local cfg="$1/config/config.inc.php"

  if [[ ! -f "$cfg" ]]; then
    echo "Notice: config file not found, skipping mailcow 1.5->1.6 key migration." >&2
    return 0
  fi

  sed -i \
    -e "s|^[[:space:]]*\\\$config\['default_host'\][[:space:]]*=.*;|\\\$config['imap_host'] = 'dovecot:143';|" \
    -e "/^[[:space:]]*\\\$config\['default_port'\][[:space:]]*=.*;/d" \
    -e "s|^[[:space:]]*\\\$config\['smtp_server'\][[:space:]]*=.*;|\\\$config['smtp_host'] = 'postfix:588';|" \
    -e "/^[[:space:]]*\\\$config\['smtp_port'\][[:space:]]*=.*;/d" \
    -e "s|^[[:space:]]*\\\$config\['managesieve_host'\][[:space:]]*=.*;|\\\$config['managesieve_host'] = 'dovecot:4190';|" \
    -e "/^[[:space:]]*\\\$config\['managesieve_port'\][[:space:]]*=.*;/d" \
    "$cfg"

  echo "Applied mailcow Roundcube 1.5 -> 1.6 config key migration in $cfg"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --version)
      [[ $# -ge 2 ]] || { echo "Error: --version requires a value" >&2; exit 1; }
      TARGET_VERSION="$2"
      shift 2
      ;;
    --roundcube-dir)
      [[ $# -ge 2 ]] || { echo "Error: --roundcube-dir requires a value" >&2; exit 1; }
      RC_DIR="$2"
      shift 2
      ;;
    --keep-tmp)
      KEEP_TMP=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Error: unknown argument: $1" >&2
      usage
      exit 1
      ;;
  esac
done

require_cmd awk
require_cmd composer
require_cmd curl
require_cmd grep
require_cmd mktemp
require_cmd php
require_cmd rsync
require_cmd sed
require_cmd sort
require_cmd tar

if [[ ! -d "$RC_DIR" ]]; then
  echo "Error: Roundcube directory not found: $RC_DIR" >&2
  exit 1
fi

CURRENT_VERSION="$(extract_current_version "$RC_DIR" || true)"
if [[ -z "$CURRENT_VERSION" ]]; then
  echo "Error: could not detect current Roundcube version in $RC_DIR" >&2
  exit 1
fi

if [[ -n "$TARGET_VERSION" ]]; then
  if [[ ! "$TARGET_VERSION" =~ ^1\.6\.[0-9]+$ ]]; then
    echo "Error: --version must be a stable 1.6.x version (example: 1.6.13)" >&2
    exit 1
  fi
else
  TARGET_VERSION="$(detect_latest_16x || true)"
  if [[ -z "$TARGET_VERSION" ]]; then
    echo "Error: failed to detect latest Roundcube 1.6.x release from GitHub" >&2
    exit 1
  fi
fi

if [[ "$CURRENT_VERSION" == "$TARGET_VERSION" ]]; then
  echo "Roundcube is already up to date ($CURRENT_VERSION)."
  exit 0
fi

if ! version_ge "$TARGET_VERSION" "$CURRENT_VERSION"; then
  echo "Error: target version ($TARGET_VERSION) is older than installed version ($CURRENT_VERSION)" >&2
  exit 1
fi

echo "Updating Roundcube files in: $RC_DIR"
echo "Current version: $CURRENT_VERSION"
echo "Target version:  $TARGET_VERSION"

TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/roundcube-update.XXXXXX")"
ARCHIVE_PATH="$TMP_DIR/roundcubemail-${TARGET_VERSION}-complete.tar.gz"
SOURCE_DIR="$TMP_DIR/roundcubemail-${TARGET_VERSION}"
PATCHED_INSTALLTO="$SOURCE_DIR/bin/installto-no-db.php"
MIME_TYPES_TMP="$TMP_DIR/mime.types"

cleanup() {
  if [[ "$KEEP_TMP" -eq 1 ]]; then
    echo "Temporary files kept at: $TMP_DIR"
  else
    rm -rf "$TMP_DIR"
  fi
}
trap cleanup EXIT

DOWNLOAD_URL="https://github.com/roundcube/roundcubemail/releases/download/${TARGET_VERSION}/roundcubemail-${TARGET_VERSION}-complete.tar.gz"

echo "Downloading $DOWNLOAD_URL"
curl -fL --retry 3 --retry-delay 2 -o "$ARCHIVE_PATH" "$DOWNLOAD_URL"

echo "Extracting archive"
tar -xzf "$ARCHIVE_PATH" -C "$TMP_DIR"

if [[ ! -d "$SOURCE_DIR" ]]; then
  echo "Error: extracted source directory not found: $SOURCE_DIR" >&2
  exit 1
fi
if [[ ! -f "$SOURCE_DIR/bin/installto.sh" ]]; then
  echo "Error: upstream installto.sh not found in archive: $SOURCE_DIR/bin/installto.sh" >&2
  exit 1
fi

echo "Preparing upstream installto.sh (DB update disabled)"
patch_installto_skip_db_update "$SOURCE_DIR/bin/installto.sh" "$PATCHED_INSTALLTO"

echo "Running upstream installto.sh"
php "$PATCHED_INSTALLTO" -y "$RC_DIR"

# UPGRADING manual step: ensure this config default exists in target.
if [[ -f "$SOURCE_DIR/config/mimetypes.php" ]]; then
  rsync -a "$SOURCE_DIR/config/mimetypes.php" "$RC_DIR/config/mimetypes.php"
fi

update_apache_mime_types "$RC_DIR" "$MIME_TYPES_TMP"

echo "Running composer update --no-dev -o --ignore-platform-reqs"
composer --working-dir "$RC_DIR" update --no-dev -o --ignore-platform-reqs

if [[ "$CURRENT_VERSION" =~ ^1\.5\.[0-9]+$ && "$TARGET_VERSION" =~ ^1\.6\.[0-9]+$ ]]; then
  apply_mailcow_15_to_16_config_migration "$RC_DIR"
fi

NEW_VERSION="$(extract_current_version "$RC_DIR" || true)"

echo
echo "Roundcube file update complete: $CURRENT_VERSION -> ${NEW_VERSION:-$TARGET_VERSION}"
echo "Used upstream installto.sh from roundcubemail-${TARGET_VERSION}-complete.tar.gz"
echo "Updated config/mime.types from $APACHE_MIME_TYPES_URL"
echo "Database update was intentionally skipped (bin/update.sh not executed)."
echo "Docker actions were intentionally skipped."
