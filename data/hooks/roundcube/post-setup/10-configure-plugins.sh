#!/bin/bash
set -e

install -m 0640 /hooks/carddav.inc.php /var/www/html/plugins/carddav/config.inc.php
install -D -m 0640 /hooks/mailcow_http_authentication/mailcow_http_authentication.php /var/www/html/plugins/mailcow_http_authentication/mailcow_http_authentication.php
install -D -m 0640 /hooks/mailcow_preferences/mailcow_preferences.php /var/www/html/plugins/mailcow_preferences/mailcow_preferences.php
install -D -m 0640 /hooks/mailcow_preferences/mailcow_preferences.js /var/www/html/plugins/mailcow_preferences/mailcow_preferences.js
install -D -m 0640 /hooks/mailcow_preferences/skins/elastic/mailcow_preferences.css /var/www/html/plugins/mailcow_preferences/skins/elastic/mailcow_preferences.css
