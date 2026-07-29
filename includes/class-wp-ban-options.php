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
			'reverse_proxy' => false,
			'ip_header'     => '',
			'lists'         => array(
				'ips'         => array(),
				'ips_range'   => array(),
				'hosts'       => array(),
				'referers'    => array(),
				'user_agents' => array(),
				'exclude_ips' => array(),
			),
			'message'       => self::default_message(),
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

		$options['reverse_proxy'] = ! empty( $options['reverse_proxy'] );
		$options['ip_header']     = is_string( $options['ip_header'] ) ? $options['ip_header'] : '';
		$options['message']       = is_string( $options['message'] ) ? $options['message'] : '';

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
	 * @param mixed $input Raw value submitted by the settings form.
	 * @return array
	 */
	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$clean = self::defaults();

		// An unchecked checkbox is absent from the POST body entirely.
		$clean['reverse_proxy'] = ! empty( $input['reverse_proxy'] );

		$header = isset( $input['ip_header'] ) ? sanitize_text_field( $input['ip_header'] ) : '';
		// A header name is used as a $_SERVER key, so keep it to the shape PHP
		// gives those keys rather than trusting whatever was typed.
		$clean['ip_header'] = preg_match( '/^[A-Za-z0-9_]+$/', $header ) ? strtoupper( $header ) : '';

		$lists = isset( $input['lists'] ) && is_array( $input['lists'] ) ? $input['lists'] : array();

		foreach ( array_keys( $clean['lists'] ) as $key ) {
			$clean['lists'][ $key ] = self::lines_to_list( isset( $lists[ $key ] ) ? $lists[ $key ] : '' );
		}

		$clean['lists']['ips_range'] = self::filter_ranges( $clean['lists']['ips_range'] );

		$clean = self::protect_self( $clean );

		$message = isset( $input['message'] ) ? (string) $input['message'] : '';

		$clean['message'] = '' === trim( $message ) ? self::default_message() : wp_kses( $message, self::allowed_html() );

		self::flush_cache();

		return $clean;
	}

	/**
	 * Drop range entries that are not two valid addresses of the same family.
	 *
	 * @param string[] $ranges Range entries.
	 * @return string[]
	 */
	private static function filter_ranges( $ranges ) {
		$out = array();

		foreach ( $ranges as $range ) {
			if ( WP_Ban_IP::parse_range( $range ) ) {
				$out[] = $range;
				continue;
			}

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

		return $out;
	}

	/**
	 * Remove entries that would ban the administrator doing the saving.
	 *
	 * Until 2.0.0 this only ran when the current user's login was literally
	 * "admin", so everyone else could lock themselves out of their own site.
	 *
	 * @param array $clean Sanitized options.
	 * @return array
	 */
	private static function protect_self( $clean ) {
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

			if ( '' === $subject ) {
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

		// A range is dropped only when it would swallow the current address.
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
			update_option( self::OPTION, self::get() );
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

		// reverse_proxy was an int in the pre-2.0.0 row.
		$options['reverse_proxy'] = ! empty( $options['reverse_proxy'] );

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

		update_option( self::OPTION, $options );

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
