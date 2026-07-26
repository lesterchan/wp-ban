<?php
/**
 * The ban check itself.
 *
 * @package WP-Ban
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the current request is banned, and serves the ban page.
 */
class Ban_Blocker {

	/**
	 * Run the ban check for this request.
	 *
	 * @return void
	 */
	public static function check() {
		/**
		 * Filters whether the ban check runs for this request.
		 *
		 * @since 2.0.0
		 *
		 * @param bool $enabled Whether to run the check.
		 */
		if ( ! apply_filters( 'wp_ban_enabled', true ) ) {
			return;
		}

		$ip = Ban_IP::address();

		if ( self::is_excluded( $ip ) ) {
			return;
		}

		if ( '' !== $ip ) {
			if ( Ban_IP::matches_any( Ban_Options::list_of( 'ips' ), $ip ) ) {
				self::deny( $ip );
			}

			if ( Ban_IP::in_any_range( Ban_Options::list_of( 'ips_range' ), $ip ) ) {
				self::deny( $ip );
			}
		}

		$hosts = Ban_Options::list_of( 'hosts' );

		// gethostbyaddr() is a blocking DNS lookup, so it only happens when
		// there is a host name list to match against.
		if ( ! empty( $hosts ) && Ban_IP::matches_any( $hosts, Ban_IP::hostname( $ip ) ) ) {
			self::deny( $ip );
		}

		if ( Ban_IP::matches_any( Ban_Options::list_of( 'referers' ), Ban_IP::referer() ) ) {
			self::deny( $ip );
		}

		if ( Ban_IP::matches_any( Ban_Options::list_of( 'user_agents' ), Ban_IP::user_agent() ) ) {
			self::deny( $ip );
		}
	}

	/**
	 * Whether this address is on the exclude list.
	 *
	 * @param string $ip Visitor address.
	 * @return bool
	 */
	private static function is_excluded( $ip ) {
		if ( '' === $ip ) {
			return false;
		}

		foreach ( Ban_Options::list_of( 'exclude_ips' ) as $excluded ) {
			if ( $ip === $excluded ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Record the attempt, serve the ban page and stop.
	 *
	 * @param string $ip Visitor address.
	 * @return void
	 */
	private static function deny( $ip ) {
		$stats = Ban_Stats::record( $ip );

		$message = str_replace(
			array(
				'%SITE_NAME%',
				'%SITE_URL%',
				'%USER_ATTEMPTS_COUNT%',
				'%USER_IP%',
				'%USER_HOSTNAME%',
				'%TOTAL_ATTEMPTS_COUNT%',
			),
			array(
				get_option( 'blogname' ),
				get_option( 'siteurl' ),
				number_format_i18n( isset( $stats['users'][ $ip ] ) ? $stats['users'][ $ip ] : 0 ),
				$ip,
				Ban_IP::hostname( $ip ),
				number_format_i18n( $stats['count'] ),
			),
			Ban_Options::message()
		);

		/**
		 * Filters the HTTP status the ban page is served with.
		 *
		 * Until 2.0.0 this was 200 OK, so search engines and caches treated the
		 * ban page as the site's real content. Return 200 to restore that.
		 *
		 * @since 2.0.0
		 *
		 * @param int    $status HTTP status code.
		 * @param string $ip     The banned visitor's address.
		 */
		$status = (int) apply_filters( 'wp_ban_status_code', 403, $ip );

		if ( ! headers_sent() ) {
			status_header( $status );
			nocache_headers();
		}

		/**
		 * Fires immediately before the ban page is sent.
		 *
		 * @since 2.0.0
		 *
		 * @param string $ip     The banned visitor's address.
		 * @param int    $status HTTP status code being sent.
		 */
		do_action( 'wp_ban_denied', $ip, $status );

		echo '<!DOCTYPE html>' . "\n";

		// The message is stored through wp_kses() with the plugin's own allow
		// list, so it is already safe HTML and must not be escaped again --
		// esc_html() here would print the markup as visible text.
		echo wp_kses( $message, Ban_Options::allowed_html() );

		exit;
	}

	/**
	 * Render the banned message for the settings screen preview.
	 *
	 * @return string
	 */
	public static function preview() {
		$ip = Ban_IP::address();

		return str_replace(
			array(
				'%SITE_NAME%',
				'%SITE_URL%',
				'%USER_ATTEMPTS_COUNT%',
				'%USER_IP%',
				'%USER_HOSTNAME%',
				'%TOTAL_ATTEMPTS_COUNT%',
			),
			array(
				get_option( 'blogname' ),
				get_option( 'siteurl' ),
				number_format_i18n( Ban_Stats::attempts_for( $ip ) ),
				$ip,
				Ban_IP::hostname( $ip ),
				number_format_i18n( Ban_Stats::total() ),
			),
			Ban_Options::message()
		);
	}
}
