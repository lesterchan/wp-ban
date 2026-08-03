<?php
/**
 * Ban attempt statistics.
 *
 * @package WP-Ban
 */

/**
 * @covers WP_Ban_Stats
 */
class WP_Ban_Stats_Test extends WP_Ban_TestCase {

	public function test_a_missing_row_reads_as_empty() {
		$this->assertSame( 0, WP_Ban_Stats::total() );
		$this->assertSame( array(), WP_Ban_Stats::get()['users'] );
	}

	public function test_a_corrupt_row_reads_as_empty() {
		update_option( WP_Ban_Stats::OPTION, 'nonsense' );

		$this->assertSame( 0, WP_Ban_Stats::total() );
		$this->assertSame( array(), WP_Ban_Stats::get()['users'] );
	}

	public function test_recording_increments_both_counters() {
		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '203.0.113.2' );

		$this->assertSame( 3, WP_Ban_Stats::total() );
		$this->assertSame( 2, WP_Ban_Stats::attempts_for( '203.0.113.1' ) );
		$this->assertSame( 1, WP_Ban_Stats::attempts_for( '203.0.113.2' ) );
	}

	public function test_an_empty_address_still_counts_towards_the_total() {
		WP_Ban_Stats::record( '' );

		$this->assertSame( 1, WP_Ban_Stats::total() );
		$this->assertArrayNotHasKey( '', WP_Ban_Stats::get()['users'], 'An empty address counts towards the total but is never keyed as a user.' );
	}

	public function test_forgetting_removes_only_the_named_addresses() {
		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '203.0.113.2' );

		$this->assertSame( 1, WP_Ban_Stats::forget( array( '203.0.113.1' ) ) );

		$this->assertSame( 0, WP_Ban_Stats::attempts_for( '203.0.113.1' ) );
		$this->assertSame( 1, WP_Ban_Stats::attempts_for( '203.0.113.2' ) );
		// The grand total is a running count of attempts, not of rows.
		$this->assertSame( 2, WP_Ban_Stats::total() );
	}

	public function test_forgetting_an_unknown_address_is_a_no_op() {
		WP_Ban_Stats::record( '203.0.113.1' );

		$this->assertSame( 0, WP_Ban_Stats::forget( array( 'nope' ) ) );
		$this->assertSame( 1, WP_Ban_Stats::attempts_for( '203.0.113.1' ) );
	}

	public function test_reset_clears_everything() {
		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::reset();

		$this->assertSame( 0, WP_Ban_Stats::total() );
		$this->assertSame( array(), WP_Ban_Stats::get()['users'] );
	}

	/**
	 * The row is written on every banned request and grows one entry per
	 * attacker, so it must never load on every page view.
	 */
	public function test_the_row_is_never_autoloaded() {
		global $wpdb;

		WP_Ban_Stats::record( '203.0.113.1' );

		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", WP_Ban_Stats::OPTION )
		);

		$this->assertContains( $autoload, array( 'no', 'off' ), "autoload is {$autoload}" );
	}

	public function test_the_row_stays_out_of_autoload_after_repeated_writes() {
		global $wpdb;

		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '203.0.113.2' );
		WP_Ban_Stats::reset();
		WP_Ban_Stats::record( '203.0.113.3' );

		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", WP_Ban_Stats::OPTION )
		);

		$this->assertContains( $autoload, array( 'no', 'off' ), "autoload is {$autoload}" );
	}

	public function test_migrating_is_a_no_op_when_there_is_no_legacy_row() {
		delete_option( WP_Ban_Stats::LEGACY_OPTION );
		delete_option( WP_Ban_Stats::OPTION );

		WP_Ban_Stats::migrate_legacy();

		$this->assertFalse( get_option( WP_Ban_Stats::OPTION, false ), 'nothing to move means no row to create' );
	}

	public function test_migrating_moves_the_counters_onto_the_prefixed_row() {
		delete_option( WP_Ban_Stats::OPTION );

		update_option(
			WP_Ban_Stats::LEGACY_OPTION,
			array(
				'users' => array( '203.0.113.7' => 4 ),
				'count' => 4,
			)
		);

		WP_Ban_Stats::migrate_legacy();

		$this->assertFalse( get_option( WP_Ban_Stats::LEGACY_OPTION, false ), 'banned_stats survived the move' );
		$this->assertSame( 4, WP_Ban_Stats::total() );
		$this->assertSame( 4, WP_Ban_Stats::attempts_for( '203.0.113.7' ) );
	}
}
