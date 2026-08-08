# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What it is

Blocks visitors by IP, IP range, host name, referrer or user agent, serves them
a configurable message, and counts the attempts. One screen at
`Settings → Ban`, three tabs: **Stats** (a `WP_List_Table` of counters),
**Settings** (the six lists and the trusted header) and **Templates** (the banned
message).

`Settings → Ban` rather than a top-level menu is deliberate: a read-only table
sitting beneath a settings form does not earn a sidebar slot.

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
* `wp_ban_version` — the `plugin` and `db` upgrade markers, replacing
  `ban_db_version`. Keep them out of the settings array: a marker in there has
  to be rescued from the stored value on every save, because the settings form
  never posts one.

The rename is user-facing: 1.69.2 is on wordpress.org with the old names.

## Traps

* **`STATS_NONCE` is `'bulk-' . STATS_PLURAL`, and must stay that way.**
  `WP_List_Table::display_tablenav()` emits its own `_wpnonce` for
  `bulk-{$plural}`; a second `wp_nonce_field()` in the same form does not add a
  check, it *replaces* one — both inputs are named `_wpnonce` and PHP keeps the
  last — so every bulk action failed its referer check.
* **Proxy headers are not trusted by default, and this is a security fix.**
  Every `HTTP_X_FORWARDED_*` header is set by the client, so honouring them
  unconditionally let anyone walk past an IP ban by sending a different value on
  each request. `WP_Ban_IP::address()` opts in two ways, narrowest first: the
  exact header named on the settings screen, or `WP_BAN_TRUST_PROXY` / the
  `wp_ban_trust_proxy` filter, which walk all seven of
  `WP_Ban_IP::PROXY_HEADERS`. `REMOTE_ADDR` is the only address the visitor
  cannot choose.
* **The "This site is behind a reverse proxy." checkbox is gone.** An empty
  header field already meant "no proxy", and the box's only distinct meaning was
  "trust whichever of the seven turns up" — the thing the field's own warning
  tells owners not to do. The constant and the filter stay; they are the
  documented escape hatch and were untouched by the removal.
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

## WP-CLI, and the class the ban decision moved into

`wp ban list|add|remove|check|stats|reset`. **No REST namespace, deliberately.**
The one `admin-ajax.php` action the plugin registers is `wp_ban_preview`, it has
no `nopriv` twin, and it serves the settings screen's own preview to an
administrator who is already on that screen — there is no client for it that is
not a browser following a link, so a route would be surface invented rather than
surface earned.

**`WP_Ban_Verdict` exists because the decision and the consequence were one
walk.** `WP_Ban_Blocker::check()` worked its way down the six lists calling
`deny()` from the middle of it, and `deny()` records a statistic, prints a whole
HTML document and exits — so there was no way to ask whether an address would be
banned without banning it. The blocker now turns a verdict into a ban page and
`wp ban check` turns the same verdict into a line of output. Keep the lazy
`gethostbyaddr()` where it is: the host-name list is the only reason to pay for
a reverse DNS lookup, and hoisting it puts a blocking call on every request to
every site that leaves that list empty.

**Every subcommand calls `WP_Ban_Options::maybe_upgrade()` first**, because the
migration is driven from `admin_init` and WP-CLI never gets there —
`WP_Ban_Settings::init()` is behind an `is_admin()` check. Reading without it
reports an empty ban list on a site that bans plenty; writing without it is
worse, because the fold-in would later overwrite the new entry from the legacy
rows it is absorbing, and nothing would say so.

**`WP_Ban_Options::update_list()` is the programmatic writer and does not run
`protect_self()`.** That check is about the request doing the saving — it drops
entries matching the address, host name and user agent of whoever is at the
keyboard — and a shell has none of those, so it would compare every entry
against an empty string, drop nothing, and charge a reverse DNS lookup for it.
The command documents the gap instead of pretending to a protection it cannot
give. What it does share with the form is the range rule: `split_ranges()` is
the single implementation, and the screen turns a rejection into an admin notice
while the command turns it into a warning.

## Testing the migration, and the ordering that makes it easy

`WP_Ban_Settings::register()` calls `maybe_upgrade()` **before**
`register_setting()`, so on an ordinary admin request the fold-in runs before
either of that function's filters exists. That is the easy path, and a test that
takes it proves less than it looks:

* With `register_setting()` already run, the sanitize callback is attached to
  the settings row and a `default` — where one is passed — makes an absent row
  read back as the defaults rather than `false`. A migration that reads the row
  bare then skips its fold-in while deleting the legacy rows anyway.
* `test-migration.php::migrate_on_admin_init()` therefore registers first and
  seeds the legacy rows afterwards, which puts the fold-in on the far side of
  both `sanitize_option_` and `default_option_`.
* Read the row **raw** when the question is "was it written": the options
  accessor merges the defaults, so it cannot tell a written row from an absent
  one.

## Tests

`bin/test.sh` runs PHPUnit, `bin/test-multisite.sh` the network pass, and
`bin/test-e2e.sh` the Playwright suite. **Run them rather than trusting a note
about their last result** — CI is the authority, and this file cannot be.

`test-migration.php` covers the eight-row fold-in, the un-escaping and the
`reverse_proxy` → `ip_header` retargeting; `test-ip.php` the range parsing and
proxy resolution; `test-trust-proxy-constant.php` the constant/filter
interaction; `test-cli.php` every subcommand, against a range, a wildcard and a
plain address. `helper-exception.php` exists because the ban path calls `exit`,
and the three `helper-wp-cli*.php` files stand in for WP-CLI, which is not
loaded in a PHPUnit run — their `error()` and `confirm()` throw, because the
real ones exit.
