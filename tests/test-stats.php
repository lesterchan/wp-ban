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
	/**
	 * One key per distinct address, with no cap and no expiry -- and the whole
	 * array is unserialised, incremented and written back on every banned
	 * request, so the cost of a request grew with the number of addresses ever
	 * seen. Being banned is not an obstacle to arriving here: a visitor opts in
	 * by sending a banned user agent, which needs no IP match at all.
	 */
	public function test_the_address_breakdown_stops_growing_at_the_ceiling() {
		add_filter( 'wp_ban_max_tracked_addresses', static fn() => 10 );

		for ( $i = 0; $i < 200; $i++ ) {
			WP_Ban_Stats::record( '198.51.100.' . $i );
		}

		$stats = WP_Ban_Stats::get();

		$this->assertLessThanOrEqual( 10, count( $stats['users'] ), 'Two hundred addresses leave at most the ceiling.' );
		$this->assertSame( 200, $stats['count'], 'While the total, which is one integer, still counts every one of them.' );
	}

	public function test_the_addresses_turned_away_most_often_are_the_ones_kept() {
		add_filter( 'wp_ban_max_tracked_addresses', static fn() => 2 );

		for ( $i = 0; $i < 5; $i++ ) {
			WP_Ban_Stats::record( '203.0.113.1' );
		}

		WP_Ban_Stats::record( '203.0.113.2' );
		WP_Ban_Stats::record( '203.0.113.2' );
		WP_Ban_Stats::record( '203.0.113.3' );

		$stats = WP_Ban_Stats::get();

		$this->assertArrayHasKey( '203.0.113.1', $stats['users'], 'The worst offender is what the breakdown is for.' );
		$this->assertArrayHasKey( '203.0.113.3', $stats['users'], 'And the address being recorded right now is never the one dropped.' );
	}

	public function test_the_ceiling_can_be_removed() {
		add_filter( 'wp_ban_max_tracked_addresses', '__return_zero' );

		for ( $i = 0; $i < 30; $i++ ) {
			WP_Ban_Stats::record( '198.51.100.' . $i );
		}

		$this->assertCount( 30, WP_Ban_Stats::get()['users'], 'Zero restores the unbounded behaviour, for anyone who wants it.' );
	}

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
