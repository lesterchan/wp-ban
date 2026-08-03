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
		$this->assertSame( 0, WP_Ban_Stats::total(), 'With no row stored the total is zero.' );
		$this->assertSame( array(), WP_Ban_Stats::get()['users'], 'And there are no per-address counters.' );
	}

	public function test_a_corrupt_row_reads_as_empty() {
		update_option( WP_Ban_Stats::OPTION, 'nonsense' );

		$this->assertSame( 0, WP_Ban_Stats::total(), 'A corrupt row reads as zero rather than propagating.' );
		$this->assertSame( array(), WP_Ban_Stats::get()['users'], 'And as no counters.' );
	}

	public function test_recording_increments_both_counters() {
		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '203.0.113.2' );

		$this->assertSame( 3, WP_Ban_Stats::total(), 'The total counts every attempt.' );
		$this->assertSame( 2, WP_Ban_Stats::attempts_for( '203.0.113.1' ), 'While each address counts only its own.' );
		$this->assertSame( 1, WP_Ban_Stats::attempts_for( '203.0.113.2' ), 'So the per-address figures add up to the total.' );
	}

	public function test_an_empty_address_still_counts_towards_the_total() {
		WP_Ban_Stats::record( '' );

		$this->assertSame( 1, WP_Ban_Stats::total(), 'An attempt from an address that could not be read still counts towards the total.' );
		$this->assertArrayNotHasKey( '', WP_Ban_Stats::get()['users'], 'An empty address counts towards the total but is never keyed as a user.' );
	}

	public function test_forgetting_removes_only_the_named_addresses() {
		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '203.0.113.2' );

		$this->assertSame( 1, WP_Ban_Stats::forget( array( '203.0.113.1' ) ), 'Forgetting reports how many addresses it removed.' );

		$this->assertSame( 0, WP_Ban_Stats::attempts_for( '203.0.113.1' ), 'The named address is forgotten.' );
		$this->assertSame( 1, WP_Ban_Stats::attempts_for( '203.0.113.2' ), 'While the one beside it is not.' );
		// The grand total is a running count of attempts, not of rows.
		$this->assertSame( 2, WP_Ban_Stats::total(), 'And the total drops by what was forgotten rather than being recomputed from nothing.' );
	}

	public function test_forgetting_an_unknown_address_is_a_no_op() {
		WP_Ban_Stats::record( '203.0.113.1' );

		$this->assertSame( 0, WP_Ban_Stats::forget( array( 'nope' ) ), 'Forgetting an address that was never recorded removes nothing.' );
		$this->assertSame( 1, WP_Ban_Stats::attempts_for( '203.0.113.1' ), 'And leaves the recorded ones alone.' );
	}

	public function test_reset_clears_everything() {
		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::reset();

		$this->assertSame( 0, WP_Ban_Stats::total(), 'A reset clears the total.' );
		$this->assertSame( array(), WP_Ban_Stats::get()['users'], 'And every per-address counter with it.' );
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
		$this->assertSame( 4, WP_Ban_Stats::total(), 'Migrating carries the total onto the prefixed row.' );
		$this->assertSame( 4, WP_Ban_Stats::attempts_for( '203.0.113.7' ), 'And the per-address counters.' );
	}
}
