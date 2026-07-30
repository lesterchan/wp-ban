# WP-Ban
Contributors: GamerZ  
Donate link: https://lesterchan.net/site/donation/  
Tags: ban, ip, hostname, referrer, bots  
Requires at least: 6.8  
Tested up to: 7.0  
Stable tag: 2.0.0  
Requires PHP: 8.2  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ban users by IP, IP range, host name, user agent and referrer URL from visiting your WordPress blog.

## Description
Banned visitors are served a custom message instead of your site. You can ban by IP address, IP range, host name, user agent or referrer URL, exclude specific addresses from ever being banned, and see how many times each banned visitor has tried to get in. Wildcards are supported throughout.

Everything is configured under `Settings -> Ban`. The plugin will not let you ban the address, host name or user agent you are currently browsing with, so a wildcard that would lock you out of your own site is refused at save time and the screen says which entry it dropped.

### Features
* Ban by IP address, IP range, host name, user agent or referrer URL
* Wildcards in every list except IP ranges and the exclude list
* IPv4 and IPv6, including IPv6 ranges
* An exclude list that wins over every other list
* Your own banned message, as a complete HTML document, with a live preview
* A sortable, paginated count of how many times each banned visitor has tried
* Self-ban protection that protects whoever is saving, not just a user called "admin"

### Donations
I spent most of my free time creating, updating, maintaining and supporting these plugins, if you really love my plugins and could spare me a couple of bucks, I will really appreciate it. If not feel free to use it without any obligations.

## Usage
Go to `Settings -> Ban` and fill in the lists you want. Every list matches the whole value, so use `*` where you want a partial match: `192.168.1.*` bans that whole block, `EmailSiphon*` bans every user agent starting with that name.

IP ranges are written as `start-end`, one per line. IPv4 and IPv6 are both supported, but a single range cannot mix the two.

Ban stats sit below the settings on the same screen, twenty rows at a time, sortable by address or by attempts. Tick the rows you want cleared and use the bulk action, or tick **Reset every IP ban stat and the total** to start again.

### Filters
Use `wp_ban_enabled` to skip the ban check for some requests:

```php
add_filter( 'wp_ban_enabled', function ( $enabled ) {
	return ! defined( 'REST_REQUEST' ) || ! REST_REQUEST;
} );
```

Use `wp_ban_denied` to do something else when a visitor is turned away:

```php
add_action( 'wp_ban_denied', function ( $ip, $status ) {
	error_log( 'WP-Ban turned away ' . $ip );
}, 10, 2 );
```

Use `wp_ban_capability` to hand the screen to a capability other than `manage_options`:

```php
add_filter( 'wp_ban_capability', function ( $capability, $context ) {
	return 'edit_pages';
}, 10, 2 );
```

The other four are `wp_ban_ipaddress` (the address a request is attributed to), `wp_ban_status_code` (the HTTP status the ban page is served with), `wp_ban_protect_self` (whether self-ban protection runs) and `wp_ban_trust_proxy` (whether the usual forwarding headers may be trusted).

## Frequently Asked Questions

### Every visitor shows the same IP address, or IP bans do not apply
Your site is behind a reverse proxy, a load balancer or a CDN such as Cloudflare, so WordPress sees the proxy's address on every request rather than the visitor's.

By default WP-Ban only trusts `REMOTE_ADDR`, because every other header carrying an IP address is set by the client — trusting them unconditionally means anyone can walk past an IP ban by sending a different value on each request. There are three ways to opt in, narrowest first:

1. **Name the exact header** in the *Trusted header* field on the settings screen, for example `HTTP_CF_CONNECTING_IP`. Only that header is trusted. This is the safest option and the one to use if you know your stack.
2. **Tick *This site is behind a reverse proxy*** on the settings screen, which trusts the usual set of forwarding headers.
3. **Define the constant** in `wp-config.php`:

```php
define( 'WP_BAN_TRUST_PROXY', true );
```

For per-request control, filter it. The filter defaults to the constant, so the constant keeps working and the filter gets the last word:

```php
add_filter( 'wp_ban_trust_proxy', function ( $trust ) {
	// Only trust the header when the request really came from your balancer.
	return '10.0.0.5' === $_SERVER['REMOTE_ADDR'];
} );
```

Do not enable any of these if there is no proxy in front of WordPress: it makes every IP ban trivial to bypass.

### My monitoring or SEO tool reports the site returning 403
That is deliberate as of 2.0.0. The ban page used to be served as `200 OK`, which told search engines and caches that the ban page was your site's real content. It is now `403 Forbidden`.

If you need the old behaviour back:

```php
add_filter( 'wp_ban_status_code', function () {
	return 200;
} );
```

### Where did my settings go after updating to 2.0.0?
Nowhere — they were moved, not lost. WP-Ban used to keep its settings in eight separate rows in the options table, under names with no plugin prefix on them at all. 2.0.0 consolidates them into one row called `wp_ban_options`. The move runs automatically the first time an administrator loads wp-admin after the update, and it also repairs two long-standing storage faults on the way through (see the changelog).

### An IP range I entered was rejected
A range must be two valid addresses of the same type, separated by a hyphen, with no wildcards — `192.168.1.1-192.168.1.255` or `2001:db8::1-2001:db8::ffff`. Anything else is refused at save time and the screen says which entry it dropped. Before 2.0.0 a malformed range was accepted and then matched *every* visitor, so if you had one stored, everybody was being banned.

### What variables can I use in the banned message?
`%SITE_NAME%`, `%SITE_URL%`, `%USER_IP%`, `%USER_HOSTNAME%`, `%USER_ATTEMPTS_COUNT%` and `%TOTAL_ATTEMPTS_COUNT%`. Your message must sit inside `<div id="wp-ban-container"></div>` so the preview button can find it.

### What does the plugin store in my database?
Three rows: `wp_ban_options` for the settings, `wp_ban_version` for the version it last ran, and `wp_ban_stats` for the attempt counters. Only the first two are autoloaded. Deleting the plugin from the Plugins screen removes all three, and every pre-2.0.0 row as well.

## Screenshots

1. Ban Options
2. Ban Lists
3. Banned Message and its preview
4. Ban Stats

## Changelog
### 2.0.0
* BREAKING: Requires WordPress 6.8 and PHP 8.2, up from 6.0 and 7.4. A site on an older stack is not offered the update at all.
* BREAKING: Proxy headers are no longer trusted by default. If your site is behind Cloudflare, a load balancer or any reverse proxy, name the header in the new *Trusted header* field, tick the reverse proxy box, or define `WP_BAN_TRUST_PROXY`. See the FAQ.
* BREAKING: The ban page is now served as `403 Forbidden` instead of `200 OK`. Filter `wp_ban_status_code` to restore the old behaviour. See the FAQ.
* BREAKING: The pre-2.0.0 global functions (`banned()`, `ban_get_ip()`, `print_banned_message()`, `process_ban()`, `is_admin_ip()`, `preg_match_wildcard()` and friends) have been removed. They were unprefixed and declared unconditionally; any code calling them must be updated.
* BREAKING: The option rows are renamed. `banned_options` is now `wp_ban_options`, `banned_stats` is now `wp_ban_stats`, and `ban_db_version` is replaced by `wp_ban_version`. The ten pre-2.0.0 rows are folded into those three automatically and then deleted.
* NEW: Settings moved to the Settings API, under `Settings -> Ban`.
* NEW: Ban stats are now a sortable, paginated list table with bulk delete. The old table rendered every recorded address on a single page.
* NEW: IPv6 IP ranges are supported.
* NEW: An optional trusted-header setting, plus the `WP_BAN_TRUST_PROXY` constant and `wp_ban_trust_proxy` filter.
* NEW: `wp_ban_capability`, `wp_ban_denied`, `wp_ban_enabled`, `wp_ban_ipaddress`, `wp_ban_protect_self` and `wp_ban_status_code` hooks.
* NEW: Dropped jQuery; the admin script is vanilla JavaScript.
* NEW: The ban check no longer runs for WP-CLI or cron.
* CHANGED: Restructured into `includes/`, with every class prefixed `WP_Ban_`.
* CHANGED: The ban stats row is no longer autoloaded, so an unbounded list of every address ever turned away is no longer read on every page view.
* FIXED: A malformed IP range banned every visitor. `ip2long()` returns false for an unparseable bound, and PHP compared the address against that boolean as true.
* FIXED: IPv6 ranges silently matched nobody.
* FIXED: Network activation and multisite uninstall fatalled, because `wp_get_sites()` was removed in WordPress 5.1. Both now page through every site rather than stopping at the hundredth.
* FIXED: Ban entries were stored HTML-escaped, so a referrer pattern containing `&` could never match a real Referer header, and re-saving compounded it. Existing entries are repaired by the upgrade.
* FIXED: The banned message was stored slashed and unslashed on every read. The upgrade corrects the stored value.
* FIXED: Bans stopped applying entirely when the reverse proxy box was ticked and no proxy header was present, or when the visitor's address was on a private network.
* FIXED: The banned message preview was readable by any logged-in user, including subscribers. It now requires `manage_options` and a nonce.
* FIXED: Self-ban protection only worked if your username was literally "admin". It now protects whoever is saving.
* FIXED: The settings row was never removed on uninstall.
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

## Upgrade Notice
### 2.0.0
The first release since 1.69.2, and five things about it are worth knowing before you update.

**Your site must be on WordPress 6.8 or later and PHP 8.2 or later.** Anything older will simply not be offered the update. Check `WP-Admin -> Tools -> Site Health -> Info -> Server` for your PHP version; if it is below 8.2, ask your host to move you up. PHP 8.1 and everything before it stopped receiving security fixes.

**Proxy headers are no longer trusted unless you say so.** Until 2.0.0 the plugin would read `HTTP_X_FORWARDED_FOR` and friends whenever the reverse proxy box was ticked, and those headers are set by the visitor — so on a site with no proxy in front of it, anyone could walk past an IP ban by sending a different value on each request. If your site really is behind Cloudflare, a load balancer or any other proxy, open `Settings -> Ban` after updating and either name the exact header your proxy sets in the new *Trusted header* field, or re-tick *This site is behind a reverse proxy*. If it is not behind a proxy, do nothing: bans will simply start working properly.

**Banned visitors now get a 403, not a 200.** Uptime monitors and SEO tools that were quietly treating your ban page as your site's real content will start reporting 403 for banned addresses. That is the point. Add a filter on `wp_ban_status_code` returning 200 if you need the old behaviour.

**Your settings move to new rows in the database, and the old ones are deleted.** The eight rows the plugin used — `banned_options`, `banned_ips`, `banned_ips_range`, `banned_hosts`, `banned_referers`, `banned_user_agents`, `banned_exclude_ips` and `banned_message` — become one `wp_ban_options` row; `banned_stats` becomes `wp_ban_stats`, and `ban_db_version` becomes `wp_ban_version`. Nothing is lost and there is nothing to do: the move runs by itself the first time an administrator loads wp-admin after the update. If you have a backup script, a migration tool or a `wp-config.php` snippet that names any of the old rows, point it at the new ones.

**Every function the plugin used to declare is gone.** `banned()`, `ban_get_ip()`, `print_banned_message()`, `process_ban()`, `is_admin_ip()` and `preg_match_wildcard()` were global, unprefixed and declared on every request, and none of them was ever a documented API. If a theme or a snippet in your site calls one, it will fatal after updating. The replacements are static methods on `WP_Ban_IP` and `WP_Ban_Options`, and there are now six filters and one action — `wp_ban_capability`, `wp_ban_denied`, `wp_ban_enabled`, `wp_ban_ipaddress`, `wp_ban_protect_self`, `wp_ban_status_code` and `wp_ban_trust_proxy` — which are the supported way in.

Two smaller things. Your ban entries are repaired on the way through: they used to be stored HTML-escaped, so a referrer pattern containing `&` could never match a real `Referer` header, and re-saving it made it worse each time. And if you had a malformed IP range stored — anything that was not two valid addresses of the same type — it was matching *every* visitor at or below its upper bound, so your whole audience was being banned. It is dropped at the first save, and no longer matches anybody in the meantime.
