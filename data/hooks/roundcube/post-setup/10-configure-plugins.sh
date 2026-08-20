#!/bin/bash
set -e

install -m 0640 /hooks/carddav.inc.php /var/www/html/plugins/carddav/config.inc.php
install -D -m 0640 /hooks/mailcow_http_authentication/mailcow_http_authentication.php /var/www/html/plugins/mailcow_http_authentication/mailcow_http_authentication.php
install -D -m 0640 /hooks/mailcow_preferences/mailcow_preferences.php /var/www/html/plugins/mailcow_preferences/mailcow_preferences.php
install -D -m 0640 /hooks/mailcow_preferences/localization/en_US.inc /var/www/html/plugins/mailcow_preferences/localization/en_US.inc
install -D -m 0640 /hooks/mailcow_preferences/localization/cs_CZ.inc /var/www/html/plugins/mailcow_preferences/localization/cs_CZ.inc
install -D -m 0640 /hooks/mailcow_preferences/localization/sk_SK.inc /var/www/html/plugins/mailcow_preferences/localization/sk_SK.inc
install -D -m 0640 /hooks/mailcow_preferences/skins/elastic/mailcow_preferences.css /var/www/html/plugins/mailcow_preferences/skins/elastic/mailcow_preferences.css
