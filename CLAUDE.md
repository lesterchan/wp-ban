# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

WP-Ban follows `_standards/STANDARDS.md` in the parent folder, which is the
contract for all nineteen plugins in the collection. Where this file and that
one disagree, that one wins.

## What it is

Blocks visitors by IP, IP range, host name, referrer or user agent, serves them
a configurable message, and counts the attempts. One screen at
`Settings → Ban`, three tabs: **Stats** (a `WP_List_Table` of counters),
**Settings** (the six lists and the proxy options) and **Templates** (the banned
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
  each request. `WP_Ban_IP::address()` opts in three ways, narrowest first: the
  exact header named on the settings screen, the reverse-proxy checkbox, or
  `WP_BAN_TRUST_PROXY` / the `wp_ban_trust_proxy` filter. `REMOTE_ADDR` is the
  only address the visitor cannot choose.
* **wp-ban is the only one of the five proxy-header plugins with a "behind a
  reverse proxy" checkbox** as well as the header field. `_standards/RESUME.md`
  task #20 has an open question about whether the header field alone should
  carry the meaning; do not remove the checkbox unilaterally.
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

`test-migration.php` covers the eight-row fold-in and the un-escaping;
`test-ip.php` the range parsing and proxy resolution; `test-trust-proxy-constant.php`
the constant/filter interaction. `helper-exception.php` exists because the ban
path calls `exit`.

wp-ban was one of the five plugins green on the very first PHPUnit sweep.
`tests/e2e/` (5 specs, 55 tests) is among the twelve suites
`_standards/RESUME.md` lists as **never run to green** — verify before trusting.
