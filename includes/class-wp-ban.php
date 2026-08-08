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
	 * Retrieve, creating on first call.
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
	 *
	 * The activation hook is registered from the constructor, which runs at
	 * file-load time -- where WordPress requires it to be.
	 */
	private function __construct() {
		register_activation_hook( WP_BAN_MAIN_FILE, array( __CLASS__, 'activate' ) );

		add_action( 'init', array( __CLASS__, 'check' ) );

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
	 * @param bool $network_wide Whether the plugin is being activated network wide.
	 * @return void
	 */
	public static function activate( $network_wide ) {
		if ( is_multisite() && $network_wide ) {
			/*
			 * wp_get_sites() has been deprecated since WordPress 4.6 and is
			 * itself capped at 100 sites, so this used to skip them silently.
			 * 'number' => 0 lifts
			 * WP_Site_Query's default cap of 100, which would otherwise leave
			 * every site past the hundredth unconfigured while still reporting
			 * a successful activation.
			 */
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				// switch_to_blog() pushes onto a stack, so the restore belongs
				// inside the loop -- one restore at the end unwinds it by one.
				switch_to_blog( (int) $site_id );

				self::activate_site();

				restore_current_blog();
			}

			return;
		}

		self::activate_site();
	}

	/**
	 * Set the plugin up for the current site.
	 *
	 * @return void
	 */
	private static function activate_site() {
		// maybe_upgrade() creates the settings row from the defaults when there
		// is nothing legacy to fold in, so a fresh install needs nothing else.
		WP_Ban_Options::maybe_upgrade();
	}
}
