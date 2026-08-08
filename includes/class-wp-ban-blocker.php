<?php
/**
 * The ban check itself.
 *
 * @package WP-Ban
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the ban page to a request WP_Ban_Verdict has ruled against.
 *
 * Which list a request matches is WP_Ban_Verdict's question; what happens to a
 * request that matches one is this class's, and the two are apart because the
 * consequence here is terminal -- deny() records a statistic, prints a whole
 * HTML document and exits, so a caller wanting only the decision could not use
 * it.
 */
class WP_Ban_Blocker {

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

		$ip = WP_Ban_IP::address();

		// The walk down the six lists lives in WP_Ban_Verdict, so that `wp ban
		// check` can ask the same question without the answer being a ban page.
		$matched = WP_Ban_Verdict::matched_list(
			$ip,
			WP_Ban_IP::referer(),
			WP_Ban_IP::user_agent()
		);

		if ( '' !== $matched ) {
			self::deny( $ip );
		}
	}

	/**
	 * Record the attempt, serve the ban page and stop.
	 *
	 * @param string $ip Visitor address.
	 * @return void
	 */
	private static function deny( $ip ) {
		$stats = WP_Ban_Stats::record( $ip );

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
				WP_Ban_IP::hostname( $ip ),
				number_format_i18n( $stats['count'] ),
			),
			WP_Ban_Options::message()
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
		echo wp_kses( $message, WP_Ban_Options::allowed_html() );

		exit;
	}

	/**
	 * Render the banned message for the settings screen preview.
	 *
	 * @return string
	 */
	public static function preview() {
		$ip = WP_Ban_IP::address();

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
				number_format_i18n( WP_Ban_Stats::attempts_for( $ip ) ),
				$ip,
				WP_Ban_IP::hostname( $ip ),
				number_format_i18n( WP_Ban_Stats::total() ),
			),
			WP_Ban_Options::message()
		);
	}
}
