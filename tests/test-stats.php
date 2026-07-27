<?php
/**
 * Ban attempt statistics.
 *
 * @package WP-Ban
 */

/**
 * @covers Ban_Stats
 */
class Test_Ban_Stats extends Ban_TestCase {

	public function test_a_missing_row_reads_as_empty() {
		$this->assertSame( 0, Ban_Stats::total() );
		$this->assertSame( array(), Ban_Stats::get()['users'] );
	}

	public function test_a_corrupt_row_reads_as_empty() {
		update_option( Ban_Stats::OPTION, 'nonsense' );

		$this->assertSame( 0, Ban_Stats::total() );
		$this->assertSame( array(), Ban_Stats::get()['users'] );
	}

	public function test_recording_increments_both_counters() {
		Ban_Stats::record( '203.0.113.1' );
		Ban_Stats::record( '203.0.113.1' );
		Ban_Stats::record( '203.0.113.2' );

		$this->assertSame( 3, Ban_Stats::total() );
		$this->assertSame( 2, Ban_Stats::attempts_for( '203.0.113.1' ) );
		$this->assertSame( 1, Ban_Stats::attempts_for( '203.0.113.2' ) );
	}

	public function test_an_empty_address_still_counts_towards_the_total() {
		Ban_Stats::record( '' );

		$this->assertSame( 1, Ban_Stats::total() );
		$this->assertArrayNotHasKey( '', Ban_Stats::get()['users'] );
	}

	public function test_forgetting_removes_only_the_named_addresses() {
		Ban_Stats::record( '203.0.113.1' );
		Ban_Stats::record( '203.0.113.2' );

		$this->assertSame( 1, Ban_Stats::forget( array( '203.0.113.1' ) ) );

		$this->assertSame( 0, Ban_Stats::attempts_for( '203.0.113.1' ) );
		$this->assertSame( 1, Ban_Stats::attempts_for( '203.0.113.2' ) );
		// The grand total is a running count of attempts, not of rows.
		$this->assertSame( 2, Ban_Stats::total() );
	}

	public function test_forgetting_an_unknown_address_is_a_no_op() {
		Ban_Stats::record( '203.0.113.1' );

		$this->assertSame( 0, Ban_Stats::forget( array( 'nope' ) ) );
		$this->assertSame( 1, Ban_Stats::attempts_for( '203.0.113.1' ) );
	}

	public function test_reset_clears_everything() {
		Ban_Stats::record( '203.0.113.1' );
		Ban_Stats::reset();

		$this->assertSame( 0, Ban_Stats::total() );
		$this->assertSame( array(), Ban_Stats::get()['users'] );
	}

	/**
	 * The row is written on every banned request and grows one entry per
	 * attacker, so it must never load on every page view.
	 */
	public function test_the_row_is_never_autoloaded() {
		global $wpdb;

		Ban_Stats::record( '203.0.113.1' );

		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Ban_Stats::OPTION )
		);

		$this->assertContains( $autoload, array( 'no', 'off' ), "autoload is {$autoload}" );
	}

	public function test_the_row_stays_out_of_autoload_after_repeated_writes() {
		global $wpdb;

		Ban_Stats::record( '203.0.113.1' );
		Ban_Stats::record( '203.0.113.2' );
		Ban_Stats::reset();
		Ban_Stats::record( '203.0.113.3' );

		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Ban_Stats::OPTION )
		);

		$this->assertContains( $autoload, array( 'no', 'off' ), "autoload is {$autoload}" );
	}

	public function test_demote_autoload_is_a_no_op_when_there_is_no_row() {
		delete_option( Ban_Stats::OPTION );

		Ban_Stats::demote_autoload();

		$this->assertFalse( get_option( Ban_Stats::OPTION, false ) );
	}
}
