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
class Ban {

	/**
	 * Sole instance.
	 *
	 * @var Ban|null
	 */
	private static $instance = null;

	/**
	 * Retrieve, creating on first call.
	 *
	 * @return Ban
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
			Ban_Settings::init();
		}
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

		Ban_Blocker::check();
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
			 * wp_get_sites() was removed in WordPress 5.1, so this used to
			 * fatal rather than merely skip sites. 'number' => 0 lifts
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
		Ban_Options::maybe_migrate();

		if ( false === get_option( Ban_Options::OPTION, false ) ) {
			add_option( Ban_Options::OPTION, Ban_Options::defaults() );
			update_option( Ban_Options::DB_VERSION_OPTION, Ban_Options::DB_VERSION );
		}
	}
}
