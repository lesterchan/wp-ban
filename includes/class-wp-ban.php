<?php
/**
 * WP-Ban bootstrap.
 *
 * @package WP-Ban
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the ban check and the admin screen up.
 */
class WP_Ban {

	/**
	 * Sole instance.
	 *
	 * @var WP_Ban|null
	 */
	private static $instance = null;

	/**
	 * Get the instance, creating it on first call.
	 *
	 * @return WP_Ban
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		// Must be registered at file-load time, which is when this runs.
		register_activation_hook( WP_BAN_MAIN_FILE, array( __CLASS__, 'activate' ) );

		add_action( 'init', array( __CLASS__, 'check' ) );

		// Activation does not fire on a plugin update, which is the single
		// most common reason a migration never runs -- and an automatic
		// background update runs on cron, which never reaches admin_init.
		add_action( 'init', array( 'WP_Ban_Options', 'maybe_upgrade' ), 5 );

		if ( is_admin() ) {
			WP_Ban_Settings::init();
		}

		self::register_command();
	}

	/**
	 * Register the WP-CLI command.
	 *
	 * The class file is required here rather than at plugin load because it
	 * extends WP_CLI_Command, which only exists when WP-CLI is the one running
	 * WordPress. Requiring it unconditionally is a fatal error on every web
	 * request.
	 *
	 * @return void
	 */
	public static function register_command() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		require_once WP_BAN_DIR . 'includes/class-wp-ban-command.php';

		WP_CLI::add_command( 'ban', 'WP_Ban_Command' );
	}

	/**
	 * Run the ban check.
	 *
	 * WP-CLI and cron have no visitor to ban, and banning them would lock an
	 * administrator out of their own recovery tools.
	 *
	 * @return void
	 */
	public static function check() {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return;
		}

		WP_Ban_Blocker::check();
	}

	/**
	 * Set the plugin up on activation.
	 *
	 * @param bool $network_wide Whether the plugin is being activated network-wide.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			// 'number' => 0 lifts WP_Site_Query's default cap of 100, which would otherwise skip every site past the hundredth while reporting success.
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				// Inside the loop: switch_to_blog() pushes onto a stack, so restoring once after the loop unwinds it by exactly one.
				switch_to_blog( (int) $site_id );

				self::install();

				restore_current_blog();
			}

			return;
		}

		self::install();
	}

	/**
	 * Set the plugin up for the current site.
	 *
	 * @return void
	 */
	private static function install() {
		// maybe_upgrade() creates the settings row from the defaults when there
		// is nothing legacy to fold in, so a fresh install needs nothing else.
		WP_Ban_Options::maybe_upgrade();
	}
}
