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
* BREAKING: Requires WordPress 6.8 and PHP 8.2, up from 6.0 and 7.4.
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

## Upgrade Notice

### 2.0.0

Requires WordPress 6.8 and PHP 8.2.

**Proxy headers are no longer trusted unless you say so.** Until 2.0.0 the plugin read `HTTP_X_FORWARDED_FOR` and friends whenever the reverse proxy box was ticked, and those headers are set by the visitor — so on a site with no proxy in front of it, anyone could walk past an IP ban by sending a different value on each request. If your site is behind a proxy, open `Settings -> Ban` and either name the exact header your proxy sets in the new *Trusted header* field, or re-tick *This site is behind a reverse proxy*. Otherwise do nothing.

**Banned visitors get a 403, not a 200.** Uptime monitors and SEO tools that were treating the ban page as real content will start reporting 403 for banned addresses. Filter `wp_ban_status_code` to return 200 for the old behaviour.

**Settings migrate on the first admin page load, and the old rows are deleted.** `banned_options`, `banned_ips`, `banned_ips_range`, `banned_hosts`, `banned_referers`, `banned_user_agents`, `banned_exclude_ips` and `banned_message` become one `wp_ban_options` row; `banned_stats` becomes `wp_ban_stats`; `ban_db_version` becomes `wp_ban_version`. Point any backup script, migration tool or `wp-config.php` snippet naming an old row at the new one.

**Every global function the plugin declared is gone.** `banned()`, `ban_get_ip()`, `print_banned_message()`, `process_ban()`, `is_admin_ip()` and `preg_match_wildcard()` were unprefixed, declared on every request and never a documented API; calling one now fatals. The replacements are static methods on `WP_Ban_IP` and `WP_Ban_Options`, plus six filters and one action: `wp_ban_capability`, `wp_ban_denied`, `wp_ban_enabled`, `wp_ban_ipaddress`, `wp_ban_protect_self`, `wp_ban_status_code` and `wp_ban_trust_proxy`.

**Two fixes applied to stored entries on the way through.** Entries were stored HTML-escaped, so a referrer pattern containing `&` could never match a real `Referer` header, and re-saving compounded it. And a malformed IP range — anything that was not two valid addresses of the same type — matched *every* visitor at or below its upper bound, banning the whole audience. Malformed ranges no longer match anybody, and are dropped at the first save.
