<?php
/**
 * The 2.0.0 migration: eight option rows into one.
 *
 * @package WP-Ban
 */

/**
 * @covers WP_Ban_Options
 */
class Test_Ban_Migration extends WP_Ban_TestCase {

	/**
	 * Put a pre-2.0.0 install in place.
	 *
	 * @return void
	 */
	private function seed_legacy() {
		update_option( 'banned_ips', array( '192.168.77.10', '10.1.*.*' ) );
		update_option( 'banned_ips_range', array( '203.0.113.10-203.0.113.20' ) );
		update_option( 'banned_hosts', array( '*.banned-host.test' ) );
		// Stored esc_html()'d by the old save path, which is why it never matched.
		update_option( 'banned_referers', array( 'http://*.spam.test/path?a=1&amp;b=2' ) );
		update_option( 'banned_user_agents', array( 'EvilBot*' ) );
		update_option( 'banned_exclude_ips', array( '198.51.100.5' ) );
		// The 1.64 shape: a flat array holding one int.
		update_option( 'banned_options', array( 'reverse_proxy' => 1 ) );
		// Stored still slashed, and stripslashes()'d on every read.
		update_option( 'banned_message', addslashes( "<div id=\"wp-ban-container\"><p>It's you.</p></div>" ) );

		delete_option( WP_Ban_Options::DB_VERSION_OPTION );

		WP_Ban_Options::flush_cache();
	}

	public function test_the_lists_move_into_the_consolidated_row() {
		$this->seed_legacy();

		WP_Ban_Options::maybe_migrate();
		WP_Ban_Options::flush_cache();

		$this->assertSame( array( '192.168.77.10', '10.1.*.*' ), WP_Ban_Options::list_of( 'ips' ) );
		$this->assertSame( array( '203.0.113.10-203.0.113.20' ), WP_Ban_Options::list_of( 'ips_range' ) );
		$this->assertSame( array( '*.banned-host.test' ), WP_Ban_Options::list_of( 'hosts' ) );
		$this->assertSame( array( 'EvilBot*' ), WP_Ban_Options::list_of( 'user_agents' ) );
		$this->assertSame( array( '198.51.100.5' ), WP_Ban_Options::list_of( 'exclude_ips' ) );
	}

	/**
	 * The headline data fix: entries were stored esc_html()'d, so a referrer
	 * pattern with a query string could never match a real Referer header.
	 */
	public function test_html_entities_in_stored_entries_are_decoded() {
		$this->seed_legacy();

		WP_Ban_Options::maybe_migrate();
		WP_Ban_Options::flush_cache();

		$this->assertSame(
			array( 'http://*.spam.test/path?a=1&b=2' ),
			WP_Ban_Options::list_of( 'referers' )
		);
	}

	/**
	 * A decoded referrer pattern must actually match the header it describes.
	 */
	public function test_a_migrated_referrer_pattern_matches_a_real_header() {
		$this->seed_legacy();

		WP_Ban_Options::maybe_migrate();
		WP_Ban_Options::flush_cache();

		$this->assertTrue(
			WP_Ban_IP::matches_any(
				WP_Ban_Options::list_of( 'referers' ),
				'http://bad.spam.test/path?a=1&b=2'
			)
		);
	}

	public function test_the_message_is_unslashed_exactly_once() {
		$this->seed_legacy();

		WP_Ban_Options::maybe_migrate();
		WP_Ban_Options::flush_cache();

		$this->assertSame( '<div id="wp-ban-container"><p>It\'s you.</p></div>', WP_Ban_Options::message() );
		$this->assertStringNotContainsString( '\\', WP_Ban_Options::message() );
	}

	public function test_the_reused_row_keeps_its_existing_value() {
		$this->seed_legacy();

		WP_Ban_Options::maybe_migrate();
		WP_Ban_Options::flush_cache();

		$options = WP_Ban_Options::get();

		$this->assertTrue( $options['reverse_proxy'], 'reverse_proxy was lost by the consolidation' );
	}

	public function test_legacy_rows_are_deleted_but_not_the_one_reused() {
		$this->seed_legacy();

		WP_Ban_Options::maybe_migrate();

		foreach ( array_keys( WP_Ban_Options::LEGACY_LIST_OPTIONS ) as $legacy ) {
			$this->assertFalse( get_option( $legacy, false ), "{$legacy} survived the migration" );
		}

		$this->assertFalse( get_option( 'banned_message', false ) );

		// banned_options is the row being consolidated INTO. Deleting it here
		// would throw away everything the migration just wrote.
		$this->assertNotFalse( get_option( WP_Ban_Options::OPTION, false ) );
	}

	public function test_the_statistics_row_is_left_alone() {
		$this->seed_legacy();
		update_option(
			WP_Ban_Stats::OPTION,
			array(
				'users' => array( '203.0.113.99' => 7 ),
				'count' => 7,
			)
		);

		WP_Ban_Options::maybe_migrate();

		$this->assertSame( 7, WP_Ban_Stats::total() );
		$this->assertSame( 7, WP_Ban_Stats::attempts_for( '203.0.113.99' ) );
	}

	public function test_the_schema_version_is_recorded() {
		$this->seed_legacy();

		WP_Ban_Options::maybe_migrate();

		$this->assertSame( WP_Ban_Options::DB_VERSION, (int) get_option( WP_Ban_Options::DB_VERSION_OPTION ) );
	}

	/**
	 * Gated on the version, not on "do the old keys still exist" -- an install
	 * that has already migrated has no old keys, and would otherwise have
	 * defaults written straight over its settings.
	 */
	public function test_running_the_migration_twice_does_not_reset_settings() {
		$this->seed_legacy();

		WP_Ban_Options::maybe_migrate();
		WP_Ban_Options::flush_cache();

		$after_first = WP_Ban_Options::get();

		WP_Ban_Options::maybe_migrate();
		WP_Ban_Options::flush_cache();

		$this->assertSame( $after_first, WP_Ban_Options::get() );
		$this->assertSame( array( 'http://*.spam.test/path?a=1&b=2' ), WP_Ban_Options::list_of( 'referers' ) );
	}

	public function test_a_migrated_install_is_not_touched_again() {
		$this->set_options( array( 'lists' => array( 'ips' => array( '1.2.3.4' ) ) ) );

		// A stray legacy row left behind by a half-finished update must not be
		// allowed to overwrite settings that have already been consolidated.
		update_option( 'banned_ips', array( 'should-not-win' ) );

		WP_Ban_Options::maybe_migrate();
		WP_Ban_Options::flush_cache();

		$this->assertSame( array( '1.2.3.4' ), WP_Ban_Options::list_of( 'ips' ) );
	}

	public function test_a_fresh_install_needs_no_legacy_rows() {
		delete_option( WP_Ban_Options::DB_VERSION_OPTION );

		WP_Ban_Options::maybe_migrate();
		WP_Ban_Options::flush_cache();

		$this->assertSame( array(), WP_Ban_Options::list_of( 'ips' ) );
		$this->assertNotEmpty( WP_Ban_Options::message() );
		$this->assertSame( WP_Ban_Options::DB_VERSION, (int) get_option( WP_Ban_Options::DB_VERSION_OPTION ) );
	}

	/**
	 * The statistics row is written on every banned request and grows one entry
	 * per attacker, so it must not load on every page view.
	 */
	public function test_the_statistics_row_is_demoted_out_of_autoload() {
		global $wpdb;

		$this->seed_legacy();
		update_option(
			WP_Ban_Stats::OPTION,
			array(
				'users' => array(),
				'count' => 0,
			)
		);

		WP_Ban_Options::maybe_migrate();

		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", WP_Ban_Stats::OPTION )
		);

		$this->assertContains( $autoload, array( 'no', 'off' ), "autoload is {$autoload}" );
	}

	public function test_the_migration_is_wired_to_admin_init() {
		// Activation does not fire when a plugin is updated, so admin_init is
		// what actually migrates the overwhelming majority of installs.
		// is_admin() is false under the test bootstrap, so the wiring that
		// WP_Ban::__construct() would do on an admin request is done here.
		WP_Ban_Settings::init();

		$this->assertNotFalse(
			has_action( 'admin_init', array( 'WP_Ban_Settings', 'register' ) ),
			'nothing would migrate an install that updated rather than reactivated'
		);
	}

	/**
	 * Half two: the hook has to actually perform the migration.
	 */
	public function test_running_the_admin_init_callback_migrates() {
		$this->seed_legacy();

		WP_Ban_Settings::register();
		WP_Ban_Options::flush_cache();

		$this->assertSame( array( '192.168.77.10', '10.1.*.*' ), WP_Ban_Options::list_of( 'ips' ) );
		$this->assertSame( WP_Ban_Options::DB_VERSION, (int) get_option( WP_Ban_Options::DB_VERSION_OPTION ) );
	}
}
