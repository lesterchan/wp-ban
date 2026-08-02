# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

WP-Ban follows `_standards/STANDARDS.md` in the parent folder, which is the
contract for all nineteen plugins in the collection. Where this file and that
one disagree, that one wins.

## What it is

Blocks visitors by IP, IP range, host name, referrer or user agent, serves them
a configurable message, and counts the attempts. One screen at
`Settings → Ban`, three tabs: **Stats** (a `WP_List_Table` of counters),
**Settings** (the six lists and the trusted header) and **Templates** (the banned
message).

`Settings → Ban` rather than a top-level menu is deliberate — §4.1 names this
plugin: a read-only table sitting beneath a settings form does not earn a
sidebar slot.

## Data

* `wp_ban_options` — settings, autoloaded. Absorbs eight pre-2.0.0 rows:
  `banned_options`, `banned_ips`, `banned_ips_range`, `banned_hosts`,
  `banned_referers`, `banned_user_agents`, `banned_exclude_ips`,
  `banned_message`.
* `wp_ban_stats` — **a row of its own, not autoloaded, and that is the point.**
  It is written on every banned request and grows one entry per distinct
  attacker; folding it into the settings blob would rewrite the whole blob on
  every hit and autoload an unbounded row on every request. Absorbs
  `banned_stats`.
* `wp_ban_version` — replaces `ban_db_version`.

The rename is user-facing: 1.69.2 is on wordpress.org with the old names.

## Traps

* **`STATS_NONCE` is `'bulk-' . STATS_PLURAL`, and must stay that way.**
  `WP_List_Table::display_tablenav()` emits its own `_wpnonce` for
  `bulk-{$plural}`; a second `wp_nonce_field()` in the same form does not add a
  check, it *replaces* one — both inputs are named `_wpnonce` and PHP keeps the
  last — so every bulk action failed its referer check. §4.2.1 cites this plugin
  as the reference for the problem.
* **Proxy headers are not trusted by default, and this is a security fix.**
  Every `HTTP_X_FORWARDED_*` header is set by the client, so honouring them
  unconditionally let anyone walk past an IP ban by sending a different value on
  each request. `WP_Ban_IP::address()` opts in two ways, narrowest first: the
  exact header named on the settings screen, or `WP_BAN_TRUST_PROXY` / the
  `wp_ban_trust_proxy` filter, which walk all seven of
  `WP_Ban_IP::PROXY_HEADERS`. `REMOTE_ADDR` is the only address the visitor
  cannot choose.
* **The "This site is behind a reverse proxy." checkbox is gone**, and it is the
  answer to `_standards/RESUME.md` task #20: wp-ban was the only one of the five
  proxy-aware plugins to have it, an empty header field already meant "no
  proxy", and the box's only distinct meaning was "trust whichever of the seven
  turns up" — the thing the field's own warning tells owners not to do. The
  constant and the filter stay; they are the documented escape hatch and were
  untouched by the removal.
* **`reverse_proxy` was a released setting, so the removal had to migrate.**
  `migrate()` folds a truthy `reverse_proxy` with an empty `ip_header` into
  `ip_header = 'HTTP_X_FORWARDED_FOR'`. Without that, such a site would fall
  back to `REMOTE_ADDR` — which behind a proxy is the *proxy's* address, so
  every visitor resolves to one address and every IP ban matches nobody or the
  entire audience. A header the owner had already named is never overwritten. A
  site whose proxy sets only an exotic header must name it themselves, and the
  Upgrade Notice says so.
* **Banned visitors get 403, not 200.** Until 2.0.0 the ban page was served 200
  OK, so search engines and caches treated it as the site's real content.
  `wp_ban_status_code` restores the old behaviour.
* **`parse_range()` rejects a range whose two ends are different address
  families**, by comparing `strlen( inet_pton() )` — 4 bytes for IPv4, 16 for
  IPv6. Before 2.0.0 a malformed range matched *every* visitor at or below its
  upper bound, banning the whole audience. Malformed ranges now match nobody and
  are dropped at the first save.
* **Entries used to be stored `esc_html()`'d**, so a referrer pattern containing
  `&` could never match a real `Referer` header, and re-saving compounded the
  encoding. The migration runs `wp_specialchars_decode( …, ENT_QUOTES )` over
  every entry (`class-wp-ban-options.php:674`+). Storage is
  `sanitize_text_field()`; escaping happens at the sink.
* **`wp_ban_protect_self` drops entries matching the saving administrator's own
  IP and hostname.** The check is skipped entirely when nothing bannable was
  posted — the reverse DNS lookup is not free.
* **`gethostbyaddr()` is a blocking DNS lookup**, so `WP_Ban_Blocker::check()`
  only calls it when there is a host-name list to match against. Do not hoist it.
* Every pre-2.0.0 global (`banned()`, `ban_get_ip()`, `process_ban()`,
  `preg_match_wildcard()`…) is gone with no shims. They were unprefixed and
  declared on every request.

## Tests

`test-migration.php` covers the eight-row fold-in, the un-escaping and the
`reverse_proxy` → `ip_header` retargeting; `test-ip.php` the range parsing and
proxy resolution; `test-trust-proxy-constant.php` the constant/filter
interaction. `helper-exception.php` exists because the ban path calls `exit`.

`WP_Ban_Settings::register()` calls `maybe_upgrade()` **before**
`register_setting()`, so one call migrates before either of that function's
filters exists — the easy ordering, and the one §7.6.1 warns about testing
against. `test-migration.php::migrate_on_admin_init()` therefore registers
first and seeds the legacy rows afterwards, which puts the fold-in on the far
side of both `sanitize_option_` and `default_option_`.

wp-ban was one of the five plugins green on the very first PHPUnit sweep.
`tests/e2e/` (5 specs, 55 tests) is among the twelve suites
`_standards/RESUME.md` lists as **never run to green** — verify before trusting.
