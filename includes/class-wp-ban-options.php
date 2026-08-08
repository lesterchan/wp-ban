<?php
/**
 * Settings storage for WP-Ban.
 *
 * Before 2.0.0 the plugin scattered its settings across eight autoloaded
 * option rows -- banned_ips, banned_ips_range, banned_hosts, banned_referers,
 * banned_user_agents, banned_exclude_ips, banned_message and banned_options --
 * plus ban_db_version and banned_stats. Every one of those names was
 * unprefixed, and `banned_options` was close enough to a name another plugin
 * might reasonably pick to be worth losing.
 *
 * They are now three rows, all spelled wp_ban_*: wp_ban_options holds every
 * setting as one nested array, wp_ban_version holds the two upgrade markers,
 * and wp_ban_stats holds the attempt counters.
 *
 * The markers live outside the settings array on purpose. A sanitize callback
 * is a function from what the form posted to what gets stored, and the form
 * never posts a version marker -- so a marker kept in there has to be rescued
 * by hand on every save, and the first save that forgets to leaves the upgrade
 * running on every request forever. Separate rows make that impossible by
 * construction: the settings screen writes wp_ban_options, the upgrade routine
 * writes wp_ban_version, and neither can corrupt the other.
 *
 * wp_ban_stats stays out of the settings blob for a different reason: it is
 * written on every banned request and grows one entry per attacker, so folding
 * it in would mean rewriting the whole blob on every hit -- and autoloading an
 * unbounded row on every page view. It is the one row here that is not
 * autoloaded.
 *
 * @package WP-Ban
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, normalises and upgrades the plugin's settings.
 */
class WP_Ban_Options {

	/**
	 * Name of the single option row holding every setting. Autoloaded.
	 *
	 * @var string
	 */
	const OPTION = 'wp_ban_options';

	/**
	 * Upgrade markers row, holding 'plugin' and 'db'. Autoloaded.
	 *
	 * @var string
	 */
	const VERSION = 'wp_ban_version';

	/**
	 * The pre-2.0.0 settings row, folded into OPTION by the upgrade.
	 *
	 * @var string
	 */
	const LEGACY_OPTION = 'banned_options';

	/**
	 * The pre-2.0.0 banned message row.
	 *
	 * @var string
	 */
	const LEGACY_MESSAGE = 'banned_message';

	/**
	 * The pre-2.0.0 schema counter, replaced by the 'db' marker in VERSION.
	 *
	 * @var string
	 */
	const LEGACY_DB_VERSION = 'ban_db_version';

	/**
	 * The pre-2.0.0 list rows, and which list key each became.
	 *
	 * @var array<string, string>
	 */
	const LEGACY_LIST_OPTIONS = array(
		'banned_ips'         => 'ips',
		'banned_ips_range'   => 'ips_range',
		'banned_hosts'       => 'hosts',
		'banned_referers'    => 'referers',
		'banned_user_agents' => 'user_agents',
		'banned_exclude_ips' => 'exclude_ips',
	);

	/**
	 * Runtime cache, so a request that checks several lists reads one row once.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * The default banned message.
	 *
	 * A complete document, because it is served instead of WordPress and has no
	 * theme behind it. The one rule it needs lives in a <style> block rather
	 * than a style attribute, so a site owner editing this template can change
	 * it in one place -- and so the plugin ships no inline style anywhere.
	 *
	 * @return string
	 */
	public static function default_message() {
		return '<html>' . "\n"
			. '<head>' . "\n"
			. '<meta charset="utf-8">' . "\n"
			. '<title>%SITE_NAME% - %SITE_URL%</title>' . "\n"
			. '<style>#wp-ban-container { text-align: center; font-weight: bold; }</style>' . "\n"
			. '</head>' . "\n"
			. '<body>' . "\n"
			. '<div id="wp-ban-container">' . "\n"
			. '<p>' . __( 'You Are Banned.', 'wp-ban' ) . '</p>' . "\n"
			. '</div>' . "\n"
			. '</body>' . "\n"
			. '</html>';
	}

	/**
	 * Default values, and by extension the canonical shape of the option.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'ip_header' => '',
			'lists'     => array(
				'ips'         => array(),
				'ips_range'   => array(),
				'hosts'       => array(),
				'referers'    => array(),
				'user_agents' => array(),
				'exclude_ips' => array(),
			),
			'message'   => self::default_message(),
		);
	}

	/**
	 * The stored settings, merged over the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		// wp_parse_args() is shallow, so the nested list group is normalised
		// by hand rather than merged.
		$options = wp_parse_args( $stored, self::defaults() );

		$options['ip_header'] = is_string( $options['ip_header'] ) ? $options['ip_header'] : '';
		$options['message']   = is_string( $options['message'] ) ? $options['message'] : '';

		$lists = isset( $options['lists'] ) && is_array( $options['lists'] ) ? $options['lists'] : array();

		foreach ( self::defaults()['lists'] as $key => $default ) {
			$options['lists'][ $key ] = isset( $lists[ $key ] ) && is_array( $lists[ $key ] )
				? array_values( array_filter( array_map( 'strval', $lists[ $key ] ), 'strlen' ) )
				: $default;
		}

		self::$cache = $options;

		return $options;
	}

	/**
	 * One list of ban patterns.
	 *
	 * @param string $name One of ips, ips_range, hosts, referers, user_agents, exclude_ips.
	 * @return string[]
	 */
	public static function list_of( $name ) {
		$options = self::get();

		return isset( $options['lists'][ $name ] ) ? $options['lists'][ $name ] : array();
	}

	/**
	 * The banned message template.
	 *
	 * @return string
	 */
	public static function message() {
		$options = self::get();

		return $options['message'];
	}

	/**
	 * Forget the runtime cache.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Split a textarea's contents into a list of trimmed, non-empty lines.
	 *
	 * @param string $value Raw textarea value.
	 * @return string[]
	 */
	public static function lines_to_list( $value ) {
		/*
		 * The settings form posts a textarea, but the sanitize callback also
		 * runs for programmatic writes -- add_option() with the defaults on
		 * activation, or the common
		 * `$o = WP_Ban_Options::get(); $o['x'] = ...; update_option( ... )`.
		 * Those pass a list that is already split, and casting it to a string
		 * would warn and throw every entry away.
		 */
		$lines = is_array( $value ) ? $value : preg_split( '/\R/', (string) $value );
		$out   = array();

		foreach ( (array) $lines as $line ) {
			/*
			 * sanitize_text_field(), not esc_html(). Until 2.0.0 each entry was
			 * stored esc_html()'d, so a referrer pattern containing & was
			 * stored as &amp; and could never match a real Referer header --
			 * and re-saving compounded it into &amp;amp;. These are patterns
			 * matched against request data, not markup; they are escaped where
			 * they are printed instead.
			 */
			$line = sanitize_text_field( $line );

			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Render a list back into textarea contents.
	 *
	 * @param string $name List key.
	 * @return string
	 */
	public static function list_to_lines( $name ) {
		return implode( "\n", self::list_of( $name ) );
	}

	/**
	 * Sanitize the whole option array on save.
	 *
	 * Note that register_setting() hands the entire nested array to one
	 * callback, so this is the single place the form's input is validated.
	 *
	 * **It starts from what is stored, not from the defaults.** A
	 * sanitize_callback is handed only the fields the submitting form posted,
	 * and this screen is three tabs posting disjoint sets of them: the ban
	 * lists never travel with the banned message, and neither travels with the
	 * other. Returning only what was given would therefore blank the message
	 * template the moment somebody edited a ban list -- silently, with a
	 * "Settings saved." notice over the top of it. Anything this submission did
	 * not mention keeps the value it already had.
	 *
	 * That is also why every branch below is guarded by isset() rather than by
	 * a default: "absent" now means "not this tab's business", and only a key
	 * that actually arrived may overwrite anything.
	 *
	 * @param mixed $input Raw value submitted by the settings form.
	 * @return array
	 */
	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$clean = self::get();

		if ( isset( $input['ip_header'] ) ) {
			$header = sanitize_text_field( $input['ip_header'] );
			// A header name is used as a $_SERVER key, so keep it to the shape
			// PHP gives those keys rather than trusting whatever was typed.
			$clean['ip_header'] = preg_match( '/^[A-Za-z0-9_]+$/', $header ) ? strtoupper( $header ) : '';
		}

		$lists     = isset( $input['lists'] ) && is_array( $input['lists'] ) ? $input['lists'] : array();
		$submitted = array();

		foreach ( array_keys( self::defaults()['lists'] ) as $key ) {
			if ( ! isset( $lists[ $key ] ) ) {
				continue;
			}

			$clean['lists'][ $key ] = self::lines_to_list( $lists[ $key ] );
			$submitted[]            = $key;
		}

		if ( in_array( 'ips_range', $submitted, true ) ) {
			$clean['lists']['ips_range'] = self::filter_ranges( $clean['lists']['ips_range'] );
		}

		/*
		 * Only over the lists this submission carried. Re-running it over the
		 * stored ones would let a save from another tab quietly delete entries
		 * an owner added from a different address, and complain about them on a
		 * screen that never showed them.
		 */
		$clean = self::protect_self( $clean, $submitted );

		if ( isset( $input['message'] ) ) {
			$message = (string) $input['message'];

			$clean['message'] = '' === trim( $message ) ? self::default_message() : self::sanitize_message( $message );
		}

		self::flush_cache();

		return $clean;
	}

	/**
	 * Sort range entries into the ones that parse and the ones that do not.
	 *
	 * The rule and the complaint are separate on purpose. Before 2.0.0 a range
	 * whose bounds did not parse matched every visitor on earth, so refusing to
	 * store one is the fix and every writer has to apply it -- but the settings
	 * screen reports a rejection as an admin notice and the command reports it
	 * as a warning on stderr, and neither can use the other's. This is the rule;
	 * the two callers below and in WP_Ban_Command are the reporting.
	 *
	 * @param string[] $ranges Range entries.
	 * @return array{valid: string[], invalid: string[]}
	 */
	public static function split_ranges( $ranges ) {
		$out = array(
			'valid'   => array(),
			'invalid' => array(),
		);

		foreach ( (array) $ranges as $range ) {
			if ( WP_Ban_IP::parse_range( $range ) ) {
				$out['valid'][] = $range;
				continue;
			}

			$out['invalid'][] = $range;
		}

		return $out;
	}

	/**
	 * Drop range entries that are not two valid addresses of the same family.
	 *
	 * @param string[] $ranges Range entries.
	 * @return string[]
	 */
	private static function filter_ranges( $ranges ) {
		$split = self::split_ranges( $ranges );

		foreach ( $split['invalid'] as $range ) {
			add_settings_error(
				self::OPTION,
				'wp_ban_bad_range',
				sprintf(
					/* translators: %s: the IP range that was rejected. */
					__( 'The IP range &#8220;%s&#8221; is not two valid addresses of the same type, so it was not saved.', 'wp-ban' ),
					esc_html( $range )
				),
				'error'
			);
		}

		return $split['valid'];
	}

	/**
	 * Replace one ban list and store the result.
	 *
	 * The settings form does not come through here: it posts every list the tab
	 * is showing and the Settings API hands the lot to sanitize(). This is the
	 * writer for a caller holding one list and one change, which as of this
	 * release means WP_Ban_Command and nothing else. It applies the same two
	 * rules the form applies to that list -- entries are normalised by
	 * lines_to_list(), and a range that does not parse is dropped -- so the two
	 * paths cannot disagree about what a stored entry looks like.
	 *
	 * What it deliberately does not do is run protect_self(). That check is
	 * about the request doing the saving: it drops entries matching the address,
	 * host name and user agent of whoever is at the keyboard. A shell has none
	 * of those -- there is no REMOTE_ADDR for WP_Ban_IP::address() to read -- so
	 * it would compare every entry against an empty string, drop nothing, and
	 * charge a reverse DNS lookup for the privilege. The command says so in its
	 * own documentation rather than pretending to a protection it cannot give.
	 *
	 * @param string   $name    List key.
	 * @param string[] $entries Entries to store.
	 * @return string[] The entries as they were stored.
	 */
	public static function update_list( $name, $entries ) {
		$options = self::get();

		if ( ! isset( $options['lists'][ $name ] ) ) {
			return array();
		}

		$clean = self::lines_to_list( $entries );

		if ( 'ips_range' === $name ) {
			$split = self::split_ranges( $clean );
			$clean = $split['valid'];
		}

		$options['lists'][ $name ] = $clean;

		self::write( $options );
		self::flush_cache();

		return $clean;
	}

	/**
	 * Remove entries that would ban the administrator doing the saving.
	 *
	 * Until 2.0.0 this only ran when the current user's login was literally
	 * "admin", so everyone else could lock themselves out of their own site.
	 *
	 * @param array    $clean     Sanitized options.
	 * @param string[] $submitted List keys this submission actually carried.
	 * @return array
	 */
	private static function protect_self( $clean, $submitted ) {
		// Nothing was posted that could ban anybody, so there is nothing to
		// check -- and no reverse DNS lookup to pay for either.
		if ( empty( $submitted ) ) {
			return $clean;
		}

		/**
		 * Filters whether the current administrator is protected from banning themselves.
		 *
		 * @since 2.0.0
		 *
		 * @param bool $protect Whether to drop entries matching the current request.
		 */
		if ( ! apply_filters( 'wp_ban_protect_self', true ) ) {
			return $clean;
		}

		$ip       = WP_Ban_IP::address();
		$hostname = WP_Ban_IP::hostname( $ip );

		$checks = array(
			'ips'         => array(
				$ip,
				/* translators: %s: the IP address that was not added. */
				__( 'This IP &#8220;%s&#8221; is yours, so it was not added to the ban list.', 'wp-ban' ),
			),
			'hosts'       => array(
				$hostname,
				/* translators: %s: the host name that was not added. */
				__( 'This host name &#8220;%s&#8221; is yours, so it was not added to the ban list.', 'wp-ban' ),
			),
			'user_agents' => array(
				WP_Ban_IP::user_agent(),
				/* translators: %s: the user agent that was not added. */
				__( 'This user agent &#8220;%s&#8221; is the one you are browsing with, so it was not added to the ban list.', 'wp-ban' ),
			),
		);

		foreach ( $checks as $key => $check ) {
			list( $subject, $message ) = $check;

			if ( '' === $subject || ! in_array( $key, $submitted, true ) ) {
				continue;
			}

			$kept = array();

			foreach ( $clean['lists'][ $key ] as $pattern ) {
				if ( WP_Ban_IP::matches_wildcard( $pattern, $subject ) ) {
					self::self_ban_notice( $message, $pattern );
					continue;
				}

				$kept[] = $pattern;
			}

			$clean['lists'][ $key ] = $kept;
		}

		// Referrers are matched against this site's own URLs, not the visitor.
		if ( in_array( 'referers', $submitted, true ) ) {
			$kept = array();

			foreach ( $clean['lists']['referers'] as $pattern ) {
				if ( self::is_own_site( $pattern ) ) {
					self::self_ban_notice(
						/* translators: %s: the referrer pattern that was not added. */
						__( 'This referrer &#8220;%s&#8221; belongs to this site, so it was not added to the ban list.', 'wp-ban' ),
						$pattern
					);
					continue;
				}

				$kept[] = $pattern;
			}

			$clean['lists']['referers'] = $kept;
		}

		// A range is dropped only when it would swallow the current address.
		if ( in_array( 'ips_range', $submitted, true ) ) {
			$kept = array();

			foreach ( $clean['lists']['ips_range'] as $range ) {
				$bounds = WP_Ban_IP::parse_range( $range );

				if ( $bounds && '' !== $ip && WP_Ban_IP::in_range( $ip, $bounds[0], $bounds[1] ) ) {
					self::self_ban_notice(
						/* translators: %s: the IP range that was not added. */
						__( 'Your IP falls inside the range &#8220;%s&#8221;, so it was not added to the ban list.', 'wp-ban' ),
						$range
					);
					continue;
				}

				$kept[] = $range;
			}

			$clean['lists']['ips_range'] = $kept;
		}

		return $clean;
	}

	/**
	 * Queue a "not added, it is you" notice.
	 *
	 * @param string $message Translated message with a single %s.
	 * @param string $value   The rejected entry.
	 * @return void
	 */
	private static function self_ban_notice( $message, $value ) {
		add_settings_error(
			self::OPTION,
			'wp_ban_self',
			sprintf( $message, esc_html( $value ) ),
			'warning'
		);
	}

	/**
	 * Whether a referrer pattern matches this site's own address.
	 *
	 * @param string $pattern Referrer pattern.
	 * @return bool
	 */
	private static function is_own_site( $pattern ) {
		$urls = array( get_option( 'siteurl' ), get_option( 'home' ) );

		foreach ( $urls as $url ) {
			foreach ( array( $url, $url . '/' ) as $candidate ) {
				if ( WP_Ban_IP::matches_wildcard( $pattern, $candidate ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Filter a submitted ban message.
	 *
	 * The markup goes through kses, and then the CSS -- which kses has no
	 * opinion about at all. It filters `style` *attributes* through safecss_filter_attr(), but
	 * the body of a `<style>` element passes through as text, and this allow
	 * list has to permit that element because the shipped message centres itself
	 * with one.
	 *
	 * That matters on multisite, where a site administrator holds
	 * `manage_options` and deliberately does not hold `unfiltered_html`. Without
	 * this they could store `@import url(//somewhere-else/)` and a full-viewport
	 * overlay, and have every banned visitor fetch it -- which is the sort of
	 * thing `unfiltered_html` exists to keep away from them. Somebody who does
	 * hold it can already write arbitrary markup anywhere, so nothing is taken
	 * from them here.
	 *
	 * The rules are left alone; it is only the two ways CSS reaches the network
	 * that go. Layout, colour and typography all still work, which is what the
	 * element is in the default message for.
	 *
	 * @since 2.0.0
	 *
	 * @param string $message Submitted message.
	 * @return string
	 */
	public static function sanitize_message( $message ) {
		$message = wp_kses( $message, self::allowed_html() );

		if ( current_user_can( 'unfiltered_html' ) ) {
			return $message;
		}

		return (string) preg_replace_callback(
			'#(<style\b[^>]*>)(.*?)(</style>)#is',
			static function ( $matches ) {
				$css = preg_replace( '#@import\b[^;}]*;?#i', '', $matches[2] );
				$css = preg_replace( '#\burl\s*\([^)]*\)#i', 'none', $css );

				return $matches[1] . $css . $matches[3];
			},
			$message
		);
	}

	/**
	 * HTML permitted in the banned message.
	 *
	 * @return array
	 */
	public static function allowed_html() {
		$allowed = wp_kses_allowed_html( 'post' );

		$allowed['html']  = array( 'lang' => true );
		$allowed['head']  = array();
		$allowed['body']  = array();
		$allowed['meta']  = array( 'charset' => true );
		$allowed['title'] = array();
		$allowed['style'] = array( 'type' => true );

		return $allowed;
	}

	/**
	 * The stored upgrade markers, normalised to the two keys they may hold.
	 *
	 * @return array{plugin: string, db: string}
	 */
	public static function markers() {
		$stored = get_option( self::VERSION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'plugin' => isset( $stored['plugin'] ) ? (string) $stored['plugin'] : '',
			'db'     => isset( $stored['db'] ) ? (string) $stored['db'] : '',
		);
	}

	/**
	 * Bring the stored rows up to the running version.
	 *
	 * Gated on the stored markers rather than on "do the old rows still
	 * exist": an install that has already upgraded has no old rows, and would
	 * otherwise have defaults written straight over its settings.
	 *
	 * Driven from admin_init as well as activation, because activation does not
	 * fire when a plugin is updated -- which is how the overwhelming majority
	 * of installs will arrive here.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$markers = self::markers();

		if ( (int) $markers['db'] < (int) WP_BAN_DB_VERSION ) {
			self::migrate();
		} elseif ( WP_BAN_VERSION === $markers['plugin'] ) {
			return;
		} else {
			/*
			 * A new plugin version with the schema unchanged: re-normalise
			 * what is stored, so a shape tightened since the last release is
			 * repaired without waiting for someone to open the settings screen
			 * and press Save.
			 *
			 * Deliberately get() and not sanitize(). The sanitiser also drops
			 * entries matching whoever is browsing, which is right for a form
			 * post and quietly destructive for an unattended upgrade running
			 * on some administrator's first admin_init after an update.
			 */
			self::write( self::get() );
		}

		// Both markers in one write, so an upgrade that dies half way never
		// records itself as finished.
		update_option(
			self::VERSION,
			array(
				'plugin' => WP_BAN_VERSION,
				'db'     => WP_BAN_DB_VERSION,
			),
			true
		);

		self::flush_cache();
	}

	/**
	 * Write the settings row from inside an upgrade.
	 *
	 * `update_option()` declines to write a value equal to the one
	 * `get_option()` would return, and `register_setting()` is passed a
	 * `default`, which installs a `default_option_wp_ban_options` filter
	 * answering with the shipped defaults for a row that does not exist. So on
	 * an admin request -- the path every real update takes, because activation
	 * hooks do not fire on an update -- a migration whose result happens to
	 * equal the defaults writes nothing at all, while the legacy rows it read
	 * have already been deleted a few lines further down.
	 *
	 * Passing an explicit default to `get_option()` defeats the registered one,
	 * because `filter_default_option()` returns early when a default was passed.
	 * That is what lets an absent row be told from a defaulted one and added
	 * outright. `add_option()` runs the sanitize callback exactly as
	 * `update_option()` does, so nothing else about the write changes.
	 *
	 * Harmless in wp-ban as it stands, because `get()` treats a missing row and
	 * a defaults row identically, so nothing is lost either way. It stops being
	 * harmless the moment this plugin gains a setting whose *absence* means
	 * something different from its default -- and at that point the failure is
	 * silent, browser-only, and the legacy rows are already gone. §7.6.1 has the
	 * three sibling plugins this shape has already bitten;
	 * WP_Print_Options::write() is the reference.
	 *
	 * @param array $options The settings to store.
	 * @return void
	 */
	private static function write( array $options ) {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $options );

			return;
		}

		update_option( self::OPTION, $options );
	}

	/**
	 * Fold the pre-2.0.0 rows into wp_ban_options and wp_ban_stats.
	 *
	 * Ten rows become three, and every old name is deleted rather than left
	 * behind to be re-read by a later half-finished update.
	 *
	 * @return void
	 */
	private static function migrate() {
		$options = get_option( self::LEGACY_OPTION, null );

		// Nothing legacy to read on a fresh install; the defaults below then
		// stand in for it and the row is simply created.
		if ( null === $options ) {
			$options = get_option( self::OPTION, array() );
		}

		if ( ! is_array( $options ) ) {
			$options = array();
		}

		// wp_parse_args() is shallow, so the nested list group is guarded
		// rather than merged.
		$options = wp_parse_args( $options, self::defaults() );

		if ( ! isset( $options['lists'] ) || ! is_array( $options['lists'] ) ) {
			$options['lists'] = self::defaults()['lists'];
		}

		/*
		 * reverse_proxy was an int in the pre-2.0.0 row, and 2.0.0 removes the
		 * checkbox it drove: an empty ip_header already means "no proxy", so the
		 * box's only distinct meaning was "trust whichever of the seven headers
		 * in WP_Ban_IP::PROXY_HEADERS turns up" -- which is exactly what the
		 * field's own warning tells owners not to do.
		 *
		 * Dropping it cannot be silent. A site with the box ticked and no header
		 * named would fall back to REMOTE_ADDR, which on such a site is the
		 * proxy's own address: every visitor would resolve to one address, so
		 * every IP ban would match nobody or match the entire audience. So a
		 * ticked box that named no header is migrated to one header instead.
		 *
		 * HTTP_X_FORWARDED_FOR is that header. It is the one essentially every
		 * proxy and CDN sets -- Cloudflare sets it as well as its own
		 * CF-Connecting-IP -- it is the one the field's Example sentence names,
		 * and trusting one header is strictly narrower than walking seven.
		 *
		 * It is not a guess that is right everywhere: a site whose proxy sets
		 * only an exotic header, HTTP_CLIENT_IP say, has to name that header
		 * itself now. The Upgrade Notice says so in as many words. Naming the
		 * wrong header is a ban that stops matching, which an owner can see;
		 * walking all seven is a ban anyone can step around, which they cannot.
		 */
		$was_behind_a_proxy = ! empty( $options['reverse_proxy'] );

		unset( $options['reverse_proxy'] );

		$options['ip_header'] = is_string( $options['ip_header'] ) ? $options['ip_header'] : '';

		// A header the owner named outranks the fallback, always. They knew
		// which one their stack sets; this guess does not.
		if ( $was_behind_a_proxy && '' === $options['ip_header'] ) {
			$options['ip_header'] = 'HTTP_X_FORWARDED_FOR';
		}

		foreach ( self::LEGACY_LIST_OPTIONS as $legacy_option => $key ) {
			$value = get_option( $legacy_option, null );

			if ( null === $value ) {
				continue;
			}

			$options['lists'][ $key ] = self::decode_legacy_list( (array) $value );
		}

		$message = get_option( self::LEGACY_MESSAGE, null );

		if ( null !== $message ) {
			/*
			 * The message was stored still slashed -- it was passed to
			 * wp_kses() straight off $_POST -- and stripslashes()'d on every
			 * read. Reads no longer do that, so the slashes come off once here.
			 */
			$message = wp_unslash( (string) $message );

			$options['message'] = '' === trim( $message ) ? self::default_message() : $message;
		}

		self::write( $options );

		foreach ( array_keys( self::LEGACY_LIST_OPTIONS ) as $legacy_option ) {
			delete_option( $legacy_option );
		}

		delete_option( self::LEGACY_MESSAGE );
		delete_option( self::LEGACY_OPTION );
		delete_option( self::LEGACY_DB_VERSION );

		WP_Ban_Stats::migrate_legacy();
	}

	/**
	 * Undo the esc_html() the pre-2.0.0 save path applied to every entry.
	 *
	 * @param array $values Stored entries.
	 * @return string[]
	 */
	private static function decode_legacy_list( $values ) {
		$out = array();

		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			// Entries were stored esc_html()'d, which is why a referrer with a
			// query string never matched. Decode once, then treat as plain text.
			$value = sanitize_text_field( wp_specialchars_decode( (string) $value, ENT_QUOTES ) );

			if ( '' !== $value ) {
				$out[] = $value;
			}
		}

		return array_values( array_unique( $out ) );
	}
}
