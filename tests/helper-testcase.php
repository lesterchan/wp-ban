<?php
/**
 * Shared base class for the WP-Ban tests.
 *
 * @package WP-Ban
 */

/**
 * Resets the plugin's state between tests and provides the ban-check seam.
 */
abstract class WP_Ban_TestCase extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		/*
		 * wp_die() only throws WPDieException when something has filtered the
		 * handler. Outside an AJAX request it falls through to
		 * _default_wp_die_handler(), which calls die() and takes the whole test
		 * runner with it -- the suite stops mid-run with "-1" and no report.
		 */
		add_filter( 'wp_die_handler', array( __CLASS__, 'throwing_wp_die_handler' ) );
		add_filter( 'wp_die_ajax_handler', array( __CLASS__, 'throwing_wp_die_handler' ) );

		foreach ( array( 'REMOTE_ADDR', 'HTTP_USER_AGENT', 'HTTP_REFERER' ) as $key ) {
			unset( $_SERVER[ $key ] );
		}

		foreach ( WP_Ban_IP::PROXY_HEADERS as $header ) {
			unset( $_SERVER[ $header ] );
		}

		$_SERVER['REMOTE_ADDR'] = '198.51.100.200';

		delete_option( WP_Ban_Options::OPTION );
		delete_option( WP_Ban_Options::VERSION );
		delete_option( WP_Ban_Stats::OPTION );

		foreach ( array_keys( WP_Ban_Options::LEGACY_LIST_OPTIONS ) as $legacy ) {
			delete_option( $legacy );
		}

		delete_option( WP_Ban_Options::LEGACY_OPTION );
		delete_option( WP_Ban_Options::LEGACY_MESSAGE );
		delete_option( WP_Ban_Options::LEGACY_DB_VERSION );
		delete_option( WP_Ban_Stats::LEGACY_OPTION );

		WP_Ban_Options::flush_cache();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'wp_die_handler', array( __CLASS__, 'throwing_wp_die_handler' ) );
		remove_filter( 'wp_die_ajax_handler', array( __CLASS__, 'throwing_wp_die_handler' ) );

		WP_Ban_Options::flush_cache();

		parent::tear_down();
	}

	/**
	 * Name the handler wp_die() should use.
	 *
	 * @return string
	 */
	public static function throwing_wp_die_handler() {
		return array( __CLASS__, 'throw_on_wp_die' );
	}

	/**
	 * Turn a wp_die() into an exception the test can assert on.
	 *
	 * @param string|WP_Error $message Die message.
	 * @throws WPDieException Always.
	 * @return void
	 */
	public static function throw_on_wp_die( $message ) {
		throw new WPDieException( is_scalar( $message ) ? (string) $message : '' );
	}

	/**
	 * Write settings over the defaults.
	 *
	 * @param array $options Partial options.
	 * @return void
	 */
	protected function set_options( $options ) {
		$merged = WP_Ban_Options::defaults();

		if ( isset( $options['lists'] ) ) {
			$merged['lists'] = array_merge( $merged['lists'], $options['lists'] );
			unset( $options['lists'] );
		}

		// Written raw rather than through update_option(), so the sanitize
		// callback is only in play in the tests that are actually about it.
		update_option( WP_Ban_Options::OPTION, array_merge( $merged, $options ) );

		// Marks the install as already upgraded, so a later maybe_upgrade()
		// leaves what the test just wrote alone.
		update_option(
			WP_Ban_Options::VERSION,
			array(
				'plugin' => WP_BAN_VERSION,
				'db'     => WP_BAN_DB_VERSION,
			)
		);

		WP_Ban_Options::flush_cache();
	}

	/**
	 * Run uninstall.php the way WordPress does, repeatably.
	 *
	 * The uninstaller touches no schema, so it can be included - but it guards
	 * itself with a bare exit() when WP_UNINSTALL_PLUGIN is missing, which would
	 * take the runner down with it, and its function is declared unguarded, so
	 * the include has to be a require_once. That makes the second caller's
	 * include a silent no-op, which proves nothing; the deletion is therefore
	 * driven by calling the function afterwards, once per site.
	 *
	 * @return void
	 */
	protected function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-ban/wp-ban.php' );
		}

		require_once WP_BAN_DIR . 'uninstall.php';

		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				wp_ban_uninstall_site();
				restore_current_blog();
			}

			return;
		}

		wp_ban_uninstall_site();
	}

	/**
	 * Run the ban check and report whether the visitor was turned away.
	 *
	 * WP_Ban_Blocker::deny() ends in exit(), which would take the runner with it.
	 * wp_ban_denied fires immediately before that, so throwing from it is the
	 * seam that lets the decision be asserted without the process dying -- and
	 * it exercises the real entry point rather than a test-only branch.
	 *
	 * @return string|false The banned address, or false if the request passed.
	 */
	protected function run_ban_check() {
		$denied = false;

		$listener = static function ( $ip ) {
			throw new WP_Ban_Denied_Exception( (string) $ip );
		};

		add_action( 'wp_ban_denied', $listener );

		try {
			WP_Ban_Blocker::check();
		} catch ( WP_Ban_Denied_Exception $e ) {
			$denied = $e->getMessage();
		} finally {
			remove_action( 'wp_ban_denied', $listener );
		}

		return $denied;
	}
}
