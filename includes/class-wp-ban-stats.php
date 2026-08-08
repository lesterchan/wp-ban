<?php
/**
 * Ban attempt statistics.
 *
 * Kept in a row of its own rather than folded into wp_ban_options: it is
 * written on every banned request and grows one entry per distinct attacker,
 * so folding it into the settings blob would mean rewriting the whole blob on
 * every hit -- and autoloading an unbounded row on every request.
 *
 * @package WP-Ban
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the ban attempt counters.
 */
class WP_Ban_Stats {

	/**
	 * Option name. Never autoloaded.
	 *
	 * @var string
	 */
	const OPTION = 'wp_ban_stats';

	/**
	 * The pre-2.0.0 row name, moved to OPTION by the upgrade.
	 *
	 * @var string
	 */
	const LEGACY_OPTION = 'banned_stats';

	/**
	 * The stored statistics, normalised.
	 *
	 * @return array{users: array<string,int>, count: int}
	 */
	public static function get() {
		$stats = get_option( self::OPTION, array() );

		if ( ! is_array( $stats ) ) {
			$stats = array();
		}

		$users = isset( $stats['users'] ) && is_array( $stats['users'] ) ? $stats['users'] : array();

		return array(
			'users' => array_map( 'intval', $users ),
			'count' => isset( $stats['count'] ) ? (int) $stats['count'] : 0,
		);
	}

	/**
	 * Record one banned attempt.
	 *
	 * @param string $ip The banned visitor's address.
	 * @return array The updated statistics.
	 */
	public static function record( $ip ) {
		$stats = self::get();

		++$stats['count'];

		$key = '' === (string) $ip ? '' : (string) $ip;

		if ( '' !== $key ) {
			$stats['users'][ $key ] = isset( $stats['users'][ $key ] ) ? $stats['users'][ $key ] + 1 : 1;
			$stats['users']         = self::trim_users( $stats['users'], $key );
		}

		self::save( $stats );

		return $stats;
	}

	/**
	 * Keep the per-address map to a size a wp_options row can carry.
	 *
	 * One key per distinct address, with no cap, no pruning and no expiry --
	 * and the whole array is unserialised, incremented and written back on
	 * every banned request, so the cost of a request grew with the number of
	 * addresses ever seen.
	 *
	 * Both halves of that were reachable without any authentication. Being
	 * banned is not an obstacle to arriving here: a visitor opts in by sending
	 * a banned user agent or referrer, which needs no IP match at all, so any
	 * site with one entry in either list is exposed. And an attacker with a
	 * single IPv6 /64 -- standard on most hosting -- has more addresses than
	 * the row can hold.
	 *
	 * The total count is untouched; it is one integer and says what it always
	 * said. What is bounded is the breakdown, which is a diagnostic: the
	 * addresses turned away most often are the ones worth looking at, so the
	 * least frequent are what a full map drops.
	 *
	 * @since 2.0.0
	 *
	 * @param array  $users Address => attempts.
	 * @param string $keep  Address that must survive, whatever its count.
	 * @return array
	 */
	private static function trim_users( array $users, $keep ) {
		/**
		 * Filters how many addresses the banned-attempt breakdown remembers.
		 *
		 * The total count is not affected. Zero removes the ceiling, which
		 * restores the unbounded growth this exists to stop.
		 *
		 * @since 2.0.0
		 *
		 * @param int $max Addresses kept.
		 */
		$max = (int) apply_filters( 'wp_ban_max_tracked_addresses', 500 );

		if ( $max <= 0 || count( $users ) <= $max ) {
			return $users;
		}

		arsort( $users );

		$kept = array_slice( $users, 0, $max, true );

		// The address being recorded right now stays, even when it is a first
		// offence and every remembered address has been turned away more often
		// -- otherwise the row it was just given is dropped in the same breath.
		if ( ! isset( $kept[ $keep ] ) && isset( $users[ $keep ] ) ) {
			array_pop( $kept );
			$kept[ $keep ] = $users[ $keep ];
		}

		return $kept;
	}

	/**
	 * How many times one address has been turned away.
	 *
	 * @param string $ip Address.
	 * @return int
	 */
	public static function attempts_for( $ip ) {
		$stats = self::get();

		return isset( $stats['users'][ $ip ] ) ? (int) $stats['users'][ $ip ] : 0;
	}

	/**
	 * The grand total.
	 *
	 * @return int
	 */
	public static function total() {
		$stats = self::get();

		return $stats['count'];
	}

	/**
	 * Forget the counters for specific addresses.
	 *
	 * @param string[] $ips Addresses to clear.
	 * @return int How many were removed.
	 */
	public static function forget( $ips ) {
		$stats   = self::get();
		$removed = 0;

		foreach ( (array) $ips as $ip ) {
			if ( isset( $stats['users'][ $ip ] ) ) {
				unset( $stats['users'][ $ip ] );
				++$removed;
			}
		}

		if ( $removed > 0 ) {
			self::save( $stats );
		}

		return $removed;
	}

	/**
	 * Reset everything.
	 *
	 * @return void
	 */
	public static function reset() {
		self::save(
			array(
				'users' => array(),
				'count' => 0,
			)
		);
	}

	/**
	 * Write the counters, never autoloaded.
	 *
	 * @param array $stats Statistics.
	 * @return void
	 */
	private static function save( $stats ) {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $stats, '', false );

			return;
		}

		update_option( self::OPTION, $stats, false );
	}

	/**
	 * Move the pre-2.0.0 counters onto the prefixed row.
	 *
	 * The new row is created with add_option(), so it arrives outside the
	 * autoloaded set: until 2.0.0 an unbounded counter of every address ever
	 * turned away was read on every single page view of the site.
	 *
	 * @return void
	 */
	public static function migrate_legacy() {
		$stats = get_option( self::LEGACY_OPTION, null );

		if ( null === $stats ) {
			return;
		}

		delete_option( self::LEGACY_OPTION );

		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $stats, '', false );
		}
	}
}
