#!/bin/bash

if [[ -z "${DBROUNDCUBE:-}" ]]; then
  exit 0
fi

if [[ -z "${ROUNDCUBE_DES_KEY:-}" ]]; then
  exit 1
fi

php -r '$socket = @fsockopen("127.0.0.1", 8000, $errno, $errstr, 2); if ($socket) { fclose($socket); exit(0); } exit(1);'
