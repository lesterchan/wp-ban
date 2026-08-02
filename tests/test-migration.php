<?php
/**
 * The 2.0.0 upgrade: ten unprefixed option rows into three wp_ban_* ones.
 *
 * @package WP-Ban
 */

/**
 * @covers WP_Ban_Options
 */
class WP_Ban_Migration_Test extends WP_Ban_TestCase {

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
		update_option(
			'banned_stats',
			array(
				'users' => array( '203.0.113.99' => 7 ),
				'count' => 7,
			)
		);
		update_option( 'ban_db_version', 1 );

		delete_option( WP_Ban_Options::VERSION );

		WP_Ban_Options::flush_cache();
	}

	public function test_the_lists_move_into_the_consolidated_row() {
		$this->seed_legacy();

		WP_Ban_Options::maybe_upgrade();
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

		WP_Ban_Options::maybe_upgrade();
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

		WP_Ban_Options::maybe_upgrade();
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

		WP_Ban_Options::maybe_upgrade();
		WP_Ban_Options::flush_cache();

		$this->assertSame( '<div id="wp-ban-container"><p>It\'s you.</p></div>', WP_Ban_Options::message() );
		$this->assertStringNotContainsString( '\\', WP_Ban_Options::message() );
	}

	/**
	 * Migrate a 1.x install the way an update through the Plugins screen does,
	 * with register_setting() already in force.
	 *
	 * WP_Ban_Settings::register() calls maybe_upgrade() before
	 * register_setting(), so one call would fold the old rows in before either
	 * of that function's filters existed -- and a migration proved only against
	 * that ordering is proved against WP-CLI. Registering first and seeding
	 * afterwards puts the fold-in on the far side of both: every
	 * update_option() it makes then runs through the settings screen's
	 * sanitiser, and get_option() answers with the shipped defaults for a row
	 * that was never written. That is the harder half, and it costs two lines.
	 *
	 * @param array $banned_options The 1.x banned_options row to migrate.
	 * @return void
	 */
	private function migrate_on_admin_init( $banned_options ) {
		WP_Ban_Settings::register();

		$this->seed_legacy();
		update_option( 'banned_options', $banned_options );

		WP_Ban_Settings::register();
		WP_Ban_Options::flush_cache();
	}

	/**
	 * The setting 2.0.0 retires, and the only one whose removal could cost a
	 * site its bans.
	 *
	 * A 1.x site with the box ticked and no header named has to come out of the
	 * upgrade naming one. Left to fall back it would resolve every visitor from
	 * REMOTE_ADDR, which on a site behind a proxy is the proxy's own address --
	 * so every visitor would look like the same machine and every IP ban would
	 * match nobody, or match the entire audience.
	 */
	public function test_a_ticked_reverse_proxy_box_with_no_header_migrates_to_x_forwarded_for() {
		$this->migrate_on_admin_init( array( 'reverse_proxy' => 1 ) );

		$this->assertSame( 'HTTP_X_FORWARDED_FOR', WP_Ban_Options::get()['ip_header'] );

		// Raw as well as through get(): with a default registered, a row that
		// was never written is indistinguishable from one holding the defaults,
		// and this value has to actually be in the database.
		$stored = get_option( WP_Ban_Options::OPTION, false );

		$this->assertIsArray( $stored, 'the migrated settings row was never written' );
		$this->assertSame( 'HTTP_X_FORWARDED_FOR', $stored['ip_header'] );
	}

	/**
	 * Present is not alive: the migrated header must be one the install reads.
	 */
	public function test_the_migrated_header_is_the_one_the_install_then_reads() {
		$this->migrate_on_admin_init( array( 'reverse_proxy' => 1 ) );

		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.44';

		$this->assertSame( '203.0.113.44', WP_Ban_IP::address() );
	}

	/**
	 * A header already named outranks the fallback, and is not overwritten.
	 *
	 * A 1.x row could not carry ip_header, but the row this reads is whatever
	 * the site has under banned_options -- an install part way through an
	 * earlier attempt at this upgrade, or one an owner edited. Guessing over a
	 * header somebody chose is worse than not guessing at all: they knew which
	 * one their stack sets, and the migration does not.
	 */
	public function test_a_header_already_named_survives_the_fallback() {
		$this->migrate_on_admin_init(
			array(
				'reverse_proxy' => 1,
				'ip_header'     => 'HTTP_CLIENT_IP',
			)
		);

		$this->assertSame( 'HTTP_CLIENT_IP', WP_Ban_Options::get()['ip_header'] );
	}

	/**
	 * An unticked box already meant what a blank field means, so it stays blank.
	 */
	public function test_an_unticked_reverse_proxy_box_names_no_header() {
		$this->migrate_on_admin_init( array( 'reverse_proxy' => 0 ) );

		$this->assertSame( '', WP_Ban_Options::get()['ip_header'] );
	}

	public function test_the_retired_setting_is_not_carried_into_the_new_row() {
		$this->migrate_on_admin_init( array( 'reverse_proxy' => 1 ) );

		$stored = get_option( WP_Ban_Options::OPTION, false );

		$this->assertIsArray( $stored );
		$this->assertArrayNotHasKey( 'reverse_proxy', $stored, 'the retired setting was folded in rather than dropped' );
		$this->assertArrayNotHasKey( 'reverse_proxy', WP_Ban_Options::get() );
	}

	public function test_every_unprefixed_row_is_deleted() {
		$this->seed_legacy();

		WP_Ban_Options::maybe_upgrade();

		$legacy_rows = array_merge(
			array_keys( WP_Ban_Options::LEGACY_LIST_OPTIONS ),
			array(
				WP_Ban_Options::LEGACY_OPTION,
				WP_Ban_Options::LEGACY_MESSAGE,
				WP_Ban_Options::LEGACY_DB_VERSION,
				WP_Ban_Stats::LEGACY_OPTION,
			)
		);

		foreach ( $legacy_rows as $legacy ) {
			$this->assertFalse( get_option( $legacy, false ), "{$legacy} survived the upgrade" );
		}
	}

	public function test_the_three_prefixed_rows_hold_the_values() {
		$this->seed_legacy();

		WP_Ban_Options::maybe_upgrade();
		WP_Ban_Options::flush_cache();

		$this->assertNotFalse( get_option( WP_Ban_Options::OPTION, false ), 'the settings row was not created' );
		$this->assertSame( array( '192.168.77.10', '10.1.*.*' ), WP_Ban_Options::list_of( 'ips' ) );
		$this->assertSame( 7, WP_Ban_Stats::total(), 'the counters did not move to wp_ban_stats' );
		$this->assertSame( 7, WP_Ban_Stats::attempts_for( '203.0.113.99' ) );
	}

	public function test_a_statistics_row_already_on_the_new_name_is_left_alone() {
		$this->seed_legacy();
		update_option(
			WP_Ban_Stats::OPTION,
			array(
				'users' => array( '198.51.100.9' => 3 ),
				'count' => 3,
			)
		);

		WP_Ban_Options::maybe_upgrade();

		$this->assertSame( 3, WP_Ban_Stats::total(), 'the legacy counters overwrote the current ones' );
		$this->assertSame( 0, WP_Ban_Stats::attempts_for( '203.0.113.99' ) );
	}

	public function test_both_version_markers_are_recorded_together() {
		$this->seed_legacy();

		WP_Ban_Options::maybe_upgrade();

		$markers = get_option( WP_Ban_Options::VERSION );

		$this->assertSame(
			array(
				'plugin' => WP_BAN_VERSION,
				'db'     => WP_BAN_DB_VERSION,
			),
			$markers,
			'wp_ban_version must hold both markers and nothing else'
		);
	}

	/**
	 * Gated on the version, not on "do the old keys still exist" -- an install
	 * that has already migrated has no old keys, and would otherwise have
	 * defaults written straight over its settings.
	 */
	public function test_running_the_migration_twice_does_not_reset_settings() {
		$this->seed_legacy();

		WP_Ban_Options::maybe_upgrade();
		WP_Ban_Options::flush_cache();

		$after_first = WP_Ban_Options::get();

		WP_Ban_Options::maybe_upgrade();
		WP_Ban_Options::flush_cache();

		$this->assertSame( $after_first, WP_Ban_Options::get() );
		$this->assertSame( array( 'http://*.spam.test/path?a=1&b=2' ), WP_Ban_Options::list_of( 'referers' ) );
	}

	public function test_a_migrated_install_is_not_touched_again() {
		$this->set_options( array( 'lists' => array( 'ips' => array( '1.2.3.4' ) ) ) );

		// A stray legacy row left behind by a half-finished update must not be
		// allowed to overwrite settings that have already been consolidated.
		update_option( 'banned_ips', array( 'should-not-win' ) );

		WP_Ban_Options::maybe_upgrade();
		WP_Ban_Options::flush_cache();

		$this->assertSame( array( '1.2.3.4' ), WP_Ban_Options::list_of( 'ips' ) );
	}

	/**
	 * The 'plugin' marker's own job: driving the non-schema upgrade steps.
	 *
	 * A release that changes no row shape still has to be able to repair a
	 * settings row whose shape has been tightened, and it must do so without
	 * running the sanitiser -- which would drop entries matching whoever
	 * happens to be loading wp-admin at the time.
	 */
	public function test_a_new_plugin_version_alone_renormalises_the_settings_row() {
		$this->set_options( array( 'lists' => array( 'ips' => array( '8.8.8.8' ) ) ) );

		update_option(
			WP_Ban_Options::VERSION,
			array(
				'plugin' => '1.99.0',
				'db'     => WP_BAN_DB_VERSION,
			)
		);

		// A row that has lost its list group entirely, as a partial write would
		// leave it.
		update_option( WP_Ban_Options::OPTION, array( 'ip_header' => 'HTTP_X_FORWARDED_FOR' ) );
		WP_Ban_Options::flush_cache();

		WP_Ban_Options::maybe_upgrade();
		WP_Ban_Options::flush_cache();

		$stored = get_option( WP_Ban_Options::OPTION );

		$this->assertArrayHasKey( 'lists', $stored, 'the stored row was not renormalised' );
		$this->assertSame( array(), WP_Ban_Options::list_of( 'hosts' ) );
		$this->assertSame( WP_BAN_VERSION, WP_Ban_Options::markers()['plugin'] );
	}

	public function test_a_fresh_install_needs_no_legacy_rows() {
		delete_option( WP_Ban_Options::VERSION );

		WP_Ban_Options::maybe_upgrade();
		WP_Ban_Options::flush_cache();

		$this->assertSame( array(), WP_Ban_Options::list_of( 'ips' ) );
		$this->assertNotEmpty( WP_Ban_Options::message() );
		$this->assertNotFalse( get_option( WP_Ban_Options::OPTION, false ), 'a fresh install must still get its settings row' );
		$this->assertSame( WP_BAN_DB_VERSION, WP_Ban_Options::markers()['db'] );
	}

	/**
	 * The statistics row is written on every banned request and grows one entry
	 * per attacker, so it must not load on every page view.
	 */
	public function test_the_statistics_row_arrives_outside_autoload() {
		global $wpdb;

		$this->seed_legacy();

		WP_Ban_Options::maybe_upgrade();

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
		$this->assertSame( WP_BAN_DB_VERSION, WP_Ban_Options::markers()['db'] );
	}
}
