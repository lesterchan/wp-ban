<?php
/**
 * Ban attempt statistics.
 *
 * Kept in a row of its own rather than folded into banned_options: it is
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
class Ban_Stats {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'banned_stats';

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
		}

		self::save( $stats );

		return $stats;
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
	 * Take an existing row out of the autoloaded set.
	 *
	 * Passing $autoload to update_option() is ignored when the value has not
	 * changed, so the row is deleted and re-added instead.
	 *
	 * @return void
	 */
	public static function demote_autoload() {
		$stats = get_option( self::OPTION, null );

		if ( null === $stats ) {
			return;
		}

		delete_option( self::OPTION );
		add_option( self::OPTION, $stats, '', false );
	}
}
