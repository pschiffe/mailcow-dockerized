# RoundCube Dovecot Client IP
Provides the HTTP client IP during IMAP connect to Dovecot in the form of an IMAP identifier for proper authentication penalty processing.

In reverse proxy configurations the actual client IP is resolved if the request originates from a trusted proxy.


## Installation
The plugin can either be installed via Composer or manually.
If the webmail server allows outgoing requests, Composer is the more comfortable option as it automatically resolves dependencies and simplifies updates.

### Using Composer
⚠️ Note that it is not recommended to run Composer as `root`.
Plugin installation requires script support enabled, as the RoundCube installer needs to move the downloaded plugin to the plugins directory and optionally can enable the plugin automatically.

1. Get [Composer][getcomposer].
1. Within the RoundCube webmail root directory, add a new dependency to the plugin.
   Use `dev-main` as the version constraint for the latest in-development version.
   ```shell
   php composer.phar require 'takerukoushirou/roundcube-dovecot_client_ip:^1.0'
   ```
1. Composer may ask whether to enable the plugin. Confirm with `y`.

To update the installed plugin to the latest version, simply run:
```shell
php composer.phar update --no-dev
```

### Manual
1. Download the latest release archive or checkout the latest release branch.
1. Extract the contents into a folder named `roundcube_dovecot_client_ip` within the `plugins` directory of your RoundCube installation.

There are no external dependencies.

Repeat manual installation for updates.
It may be advisable to keep multiple versions of the plugin in separate folders and use a symlink named `roundcube_dovecot_client_ip` from within the `plugins` directory to the latest version folder instead.


## Configuration

To enable the plugin, add `roundcube_dovecot_client_ip` to the `$config['plugins']` array in the RoundCube configuration file.  
When using Composer, the installation routine will ask whether to automatically enable the plugin.

### Options
On a fresh installation, navigate to the plugin directory and copy `config.inc.php.dist` to `config.inc.php`.  
When using Composer, a copy is automatically created.

Edit `config.inc.php` within the plugin directory as needed.  
All options and their accepted values are described in `config.inc.php.dist`.

### Dovecot IMAP
The following settings need to be applied to the Dovecot IMAP server:
1. Add the IP addresses or networks of your RoundCube webmail servers to the `login_trusted_networks` setting in your Dovecot IMAP configuration.
   This disables authentication penalty processing for the configured webmail server IPs and enables processing of the `X-Originating-IP` ident value with the actual client IP provided by this plugin.
1. Restart Dovecot IMAP.

Afterwards, Dovecot connection logs should show the actual client IP of users instead of the webmail server IP in the `rip` (remote IP) field.


## License
![GNU General Public License v3 logo][gpl-license-logo]  
[GNU General Public License v3][gpl-license] or higher.
See [LICENSE](LICENSE) file for details.

> This program is free software: you can redistribute it and/or modify
> it under the terms of the GNU General Public License as published by
> the Free Software Foundation, either version 3 of the License, or
> (at your option) any later version.


[getcomposer]: https://getcomposer.org/download/
[gpl-license]: https://www.gnu.org/licenses/gpl-3.0.en.html
[gpl-license-logo]: https://www.gnu.org/graphics/gplv3-88x31.png