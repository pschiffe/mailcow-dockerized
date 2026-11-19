# mailcow: dockerized - 🐮 + 🐋 = 💕

[![Translation status](https://translate.mailcow.email/widgets/mailcow-dockerized/-/translation/svg-badge.svg)](https://translate.mailcow.email/engage/mailcow-dockerized/)
[![Twitter URL](https://img.shields.io/twitter/url/https/twitter.com/mailcow_email.svg?style=social&label=Follow%20%40mailcow_email)](https://twitter.com/mailcow_email)
![Mastodon Follow](https://img.shields.io/mastodon/follow/109388212176073348?domain=https%3A%2F%2Fmailcow.social&label=Follow%20%40doncow%40mailcow.social&link=https%3A%2F%2Fmailcow.social%2F%40doncow)


## Want to support mailcow?

Please [consider a support contract with Servercow](https://www.servercow.de/mailcow?lang=en#support) to support further development. _We_ support _you_ while _you_ support _us_. :)

You can also [get a SAL](https://www.servercow.de/mailcow?lang=en#sal) which is a one-time payment with no liabilities or returning fees.

Or just spread the word: moo.

## Many thanks to our GitHub Sponsors ❤️
A big thank you to everyone supporting us on GitHub Sponsors—your contributions mean the world to us! Special thanks to the following amazing supporters:

### 100$/Month Sponsors
  <a href="https://www.colba.net/" target=_blank><img
    src="https://avatars.githubusercontent.com/u/204464723" height="58"
  /></a>
  <a href="https://www.maehdros.com/" target=_blank><img
    src="https://avatars.githubusercontent.com/u/173894712" height="58"
  /></a>
  <a href="https://garske-systems.de/" target=_blank><img
    src="https://garske-systems.de/img/logo_min.png" height="58"
  /></a>

### 50$/Month Sponsors
  <a href="https://github.com/vnukhr" target=_blank><img
    src="https://avatars.githubusercontent.com/u/7805987?s=52&v=4" height="58"
  /></a><a href="https://github.com/humiico" target=_blank><img
    src="https://avatars.githubusercontent.com/u/110134705?s=52&v=4" height="58"
  /></a>

## Info, documentation and support

Please see [the official documentation](https://docs.mailcow.email/) for installation and support instructions. 🐄

🐛 **If you found a critical security issue, please mail us to [info at servercow.de](mailto:info@servercow.de).**

## Custom Roundcube SSO

Roundcube at `/rc/` requires a completed mailbox-owner session. Each SSO request
rechecks that the mailbox and domain are active, the mailbox is not a resource,
and it has not been converted to a shared mailbox. A newly required password
change sends the local mailbox owner back to mailcow before Roundcube can open
again. For OIDC sessions, Roundcube also verifies that the provider still accepts
the session and that the identity still belongs to this mailbox.
The identity provider must issue a refresh token to the mailcow OIDC client on
authorization-code login. Enable the provider's refresh-token grant and request
`offline_access` if the provider requires that scope. Without a refresh token,
mailcow login can succeed but Roundcube access remains blocked.
Redis session locking serializes concurrent requests from the same browser so
they cannot reuse a rotated OIDC refresh token. Run
`docker compose up -d --no-deps php-fpm-mailcow` to apply the new ini mount;
a restart alone does not add it.

Login recovery returns HTTP 403 for a revoked mailbox instead of redirecting
back to Roundcube. If the eligibility query fails, the SSO secret is missing,
or the OIDC provider is unavailable, `/rc/` returns HTTP 503 without forwarding
the request. An unavailable provider or malformed userinfo response does not
log the user out of mailcow. If mailcow no longer has a refresh token, Roundcube
offers a new provider login without ending the existing mailcow session. Choosing
**Sign in again** returns to `/rc/` after login; **return to mailcow** does not.
Opening mailcow in another tab follows its normal landing page during reauthentication. Recovery shows
provider configuration guidance only after a verified login returns no refresh token.

Dovecot trusts only Roundcube's fixed address, `172.22.1.251/32`, configured in
`data/conf/dovecot/extra.conf`. This deployment uses the default mailcow IPv4
network. Restart `dovecot-mailcow` after changing the trust configuration.

Roundcube receives mailcow's effective client scheme, preserving forwarded HTTPS
when an external reverse proxy terminates TLS. Reload or restart `nginx-mailcow`
after changing the proxy configuration.

## Cowmunity

[mailcow community](https://community.mailcow.email)

[Telegram mailcow channel](https://telegram.me/mailcow)

[Telegram mailcow Off-Topic channel](https://t.me/mailcowOfftopic)

[Official 𝕏 (Twitter) Account](https://twitter.com/mailcow_email)

[Official Mastodon Account](https://mailcow.social/@doncow)

Telegram desktop clients are available for [multiple platforms](https://desktop.telegram.org). You can search the groups history for keywords.

## Misc

**Important**: mailcow makes use of various open-source software. Please assure you agree with their license before using mailcow.
Any part of mailcow itself is released under **GNU General Public License, Version 3**.

mailcow is a registered word mark of The Infrastructure Company GmbH, Parkstr. 42, 47877 Willich, Germany.

The project is managed and maintained by The Infrastructure Company GmbH.

Originated from @andryyy (André)
