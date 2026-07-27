# WP-Ban
Contributors: GamerZ  
Donate link: https://lesterchan.net/site/donation/  
Tags: ban, ip, hostname, referrer, bots  
Requires at least: 6.0  
Tested up to: 7.0  
Stable tag: 2.0.0  
Requires PHP: 7.4  
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ban users by IP, IP range, host name, user agent and referrer URL from visiting your WordPress blog.

## Description
Banned visitors are served a custom message instead of your site. You can ban by IP address, IP range, host name, user agent or referrer URL, exclude specific addresses from ever being banned, and see how many times each banned visitor has tried to get in. Wildcards are supported throughout.

### General Usage
Go to **Settings &rarr; Ban** and fill in the lists you want. Every list matches the whole value, so use `*` where you want a partial match: `192.168.1.*` bans that whole block, `EmailSiphon*` bans every user agent starting with that name.

IP ranges are written as `start-end`, one per line. IPv4 and IPv6 are both supported, but a single range cannot mix the two.

The plugin will not let you ban the address, host name or user agent you are currently browsing with.

### Development
* [https://github.com/lesterchan/wp-ban](https://github.com/lesterchan/wp-ban "https://github.com/lesterchan/wp-ban")

### Credits
* Plugin icon by [Dave Gandy](https://fontawesome.com) from [Flaticon](https://www.flaticon.com)

### Donations
* I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.

## Frequently Asked Questions

### Every visitor shows the same IP address, or IP bans do not apply
Your site is behind a reverse proxy, a load balancer or a CDN such as Cloudflare, so WordPress sees the proxy's address on every request rather than the visitor's.

By default WP-Ban only trusts `REMOTE_ADDR`, because every other header carrying an IP address is set by the client — trusting them unconditionally means anyone can walk past an IP ban by sending a different value on each request. There are three ways to opt in, narrowest first:

1. **Name the exact header** in the *Trusted header* field on the settings screen, for example `HTTP_CF_CONNECTING_IP`. Only that header is trusted. This is the safest option and the one to use if you know your stack.
2. **Tick *This site is behind a reverse proxy*** on the settings screen, which trusts the usual set of forwarding headers.
3. **Define the constant** in `wp-config.php`:

~~~php
define( 'WP_BAN_TRUST_PROXY', true );
~~~

For per-request control, filter it. The filter defaults to the constant, so the constant keeps working and the filter gets the last word:

~~~php
add_filter( 'wp_ban_trust_proxy', function ( $trust ) {
	// Only trust the header when the request really came from your balancer.
	return '10.0.0.5' === $_SERVER['REMOTE_ADDR'];
} );
~~~

Do not enable any of these if there is no proxy in front of WordPress: it makes every IP ban trivial to bypass.

### My monitoring or SEO tool reports the site returning 403
That is deliberate as of 2.0.0. The ban page used to be served as `200 OK`, which told search engines and caches that the ban page was your site's real content. It is now `403 Forbidden`.

If you need the old behaviour back:

~~~php
add_filter( 'wp_ban_status_code', function () {
	return 200;
} );
~~~

### Where did my settings go after updating to 2.0.0?
Nowhere — they were moved, not lost. WP-Ban used to keep its settings in eight separate rows in the options table; 2.0.0 consolidates them into one. The move runs automatically the first time an administrator loads wp-admin after the update, and it also repairs two long-standing storage faults on the way through (see the changelog).

### An IP range I entered was rejected
A range must be two valid addresses of the same type, separated by a hyphen, with no wildcards — `192.168.1.1-192.168.1.255` or `2001:db8::1-2001:db8::ffff`. Anything else is refused at save time and the screen says which entry it dropped. Before 2.0.0 a malformed range was accepted and then matched *every* visitor, so if you had one stored, everybody was being banned.

### How do I stop the ban check running for certain requests?
Return false from `wp_ban_enabled`:

~~~php
add_filter( 'wp_ban_enabled', function ( $enabled ) {
	return ! defined( 'REST_REQUEST' ) || ! REST_REQUEST;
} );
~~~

WP-CLI and cron are already skipped.

### Can I do something else when a visitor is banned?
Yes, `wp_ban_denied` fires just before the ban page is sent:

~~~php
add_action( 'wp_ban_denied', function ( $ip, $status ) {
	error_log( 'WP-Ban turned away ' . $ip );
}, 10, 2 );
~~~

### What variables can I use in the banned message?
`%SITE_NAME%`, `%SITE_URL%`, `%USER_IP%`, `%USER_HOSTNAME%`, `%USER_ATTEMPTS_COUNT%` and `%TOTAL_ATTEMPTS_COUNT%`. Your message must sit inside `<div id="wp-ban-container"></div>` so the preview button can find it.

## Changelog
### 2.0.0
* IMPORTANT: Proxy headers are no longer trusted by default. If your site is behind Cloudflare, a load balancer or any reverse proxy, name the header in the new *Trusted header* field, tick the reverse proxy box, or define `WP_BAN_TRUST_PROXY`. See the FAQ.
* IMPORTANT: The ban page is now served as `403 Forbidden` instead of `200 OK`. Filter `wp_ban_status_code` to restore the old behaviour. See the FAQ.
* IMPORTANT: The pre-2.0.0 global functions (`banned()`, `ban_get_ip()`, `print_banned_message()`, `process_ban()`, `is_admin_ip()`, `preg_match_wildcard()` and friends) have been removed. They were unprefixed and declared unconditionally; any code calling them must be updated.
* NEW: Requires WordPress 6.0 and PHP 7.4.
* NEW: Settings moved to the Settings API, and the eight option rows consolidated into one. Existing settings are migrated automatically.
* NEW: Ban stats are now a sortable, paginated list table with bulk delete. The old table rendered every recorded address on a single page.
* NEW: IPv6 IP ranges are supported.
* NEW: An optional trusted-header setting, plus the `WP_BAN_TRUST_PROXY` constant and `wp_ban_trust_proxy` filter.
* NEW: `wp_ban_enabled`, `wp_ban_denied`, `wp_ban_status_code`, `wp_ban_ipaddress` and `wp_ban_protect_self` hooks.
* NEW: Dropped jQuery; the admin script is vanilla JavaScript.
* NEW: The ban check no longer runs for WP-CLI or cron.
* FIXED: A malformed IP range banned every visitor. `ip2long()` returns false for an unparseable bound, and PHP compared the address against that boolean as true.
* FIXED: IPv6 ranges silently matched nobody.
* FIXED: Network activation and multisite uninstall fatalled, because `wp_get_sites()` was removed in WordPress 5.1. Both now page through every site rather than stopping at the hundredth.
* FIXED: Ban entries were stored HTML-escaped, so a referrer pattern containing `&` could never match a real Referer header, and re-saving compounded it. Existing entries are repaired by the migration.
* FIXED: The banned message was stored slashed and unslashed on every read. The migration corrects the stored value.
* FIXED: Bans stopped applying entirely when the reverse proxy box was ticked and no proxy header was present, or when the visitor's address was on a private network.
* FIXED: The banned message preview was readable by any logged-in user, including subscribers. It now requires `manage_options` and a nonce.
* FIXED: Self-ban protection only worked if your username was literally "admin". It now protects whoever is saving.
* FIXED: `banned_options` was never removed on uninstall.
* FIXED: The ban statistics row is no longer autoloaded on every request.
* FIXED: The plugin no longer hardcodes its own directory name, so it works installed under any folder.
* FIXED: Various PHP 8 warnings and deprecations.

### 1.69.2
* NEW: Don't allow to access ban-options.php directly

### 1.69.1
* NEW: Fixed XSS

### 1.69
* NEW: Bump WordPress 4.7
* FIXED: Notices

### 1.68
* NEW: Use translate.wordpress.org to translate the plugin
* NEW: Use HTML DOCTYPE
* FIXED: Remove get_language_attributes()

### 1.67
* FIXED: Notices

### 1.66
* FIXED: Cannot redeclare get_language_attributes()

### 1.65
* NEW: Supports WordPress Multisite Network Activation
* NEW: Uses native WordPress uninstall.php

### 1.64
* NEW: Added a new ban option 'reverse proxy' to allow user to choose whether to check against HTTP_X_FORWARDED_FOR header for IP. Props Tom Adams at dxw. This fixes [CVE-2014-6230](https://security.dxw.com/advisories/vulnerability-in-wp-ban-allows-visitors-to-bypass-the-ip-blacklist-in-some-configurations/)

### 1.63
* FIXED: Notices

### 1.62
* FIXED: Use a different modifier for preg_match() and use preg_quote() to escape regex

### 1.61
* FIXED: Replace ereg() with preg_match()

### 1.60
* NEW: AJAX Preview Of Current Banned Message
* NEW: Added nonce To Form
* FIXED: Don't Process Ban If Any Of The Conditions Are Empty

### 1.50
* NEW: Added "Your User Agent" Details
* NEW: Uses jQuery Framework
* FIXED: Uses $_SERVER['PHP_SELF'] With plugin_basename(__FILE__) Instead Of Just $_SERVER['REQUEST_URI']

### 1.40
* NEW: Works With WordPress 2.7 Only
* NEW: Changed Ban Admin Setting Location To 'WP-Admin -> Settings -> Ban'
* NEW: Right To Left Language Support by Kambiz R. Khojasteh
* NEW: Called ban_textdomain() In ban_init() by Kambiz R. Khojasteh
* NEW: Use language_attributes() To Get Attributes Of HTML Tag For Default Template by Kambiz R. Khojasteh

### 1.31
* NEW: Works With WordPress 2.6
* FIXED: Do Not Ban If IP is "unknown"

### 1.30
* NEW: Uses /wp-ban/ Folder Instead Of /ban/
* NEW: Uses wp-ban.php Instead Of ban.php
* NEW: Uses number_format_i18n()
* NEW: IPs Listed In Ban Stats Is Now Sorted Numerically
* NEW: Banned By User Agents (By: Jorge Garcia de Bustos)
* FIXED: "unknown" IPs (By: Jorge Garcia de Bustos)

### 1.20
* NEW: Ability To Uninstall WP-Ban
* NEW: Moved Ban Options From ban.php To ban-options.php

### 1.11
* NEW: Banned By Referer URL
* NEW: Ability To Exclude Specific IPs From Being Banned
* NEW: Added Template Variables For User Attempts Count And Total Attempts Count
