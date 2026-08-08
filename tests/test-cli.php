<?php
/**
 * Tests for the `wp ban` WP-CLI command.
 *
 * @package WP-Ban
 */

/**
 * The command edits the ban lists and clears the counters with no browser, no
 * nonce and no settings form in front of it, so every subcommand is pinned here.
 *
 * The WP_CLI facade these tests read is the stand-in from helper-wp-cli.php: it
 * records what the command reported instead of printing it, its error() throws,
 * because the real one exits and every line after a call to it is unreachable,
 * and its confirm() throws unless --yes was passed, which is the non-interactive
 * case a script actually meets.
 */
class WP_Ban_CLI_Test extends WP_Ban_TestCase {

	/**
	 * Clears everything the stand-in recorded for the previous test.
	 */
	public function set_up() {
		parent::set_up();

		WP_CLI::$successes     = array();
		WP_CLI::$warnings      = array();
		WP_CLI::$logs          = array();
		WP_CLI::$confirmations = array();
		WP_CLI::$commands      = array();
		WP_CLI::$items         = array();
	}

	/**
	 * Runs one subcommand the way WP-CLI would.
	 *
	 * @param string $subcommand Method to call.
	 * @param array  $args       Positional arguments.
	 * @param array  $assoc_args Associative arguments.
	 * @return void
	 */
	protected function run_command( $subcommand, $args = array(), $assoc_args = array() ) {
		$command = new WP_Ban_Command();
		$command->$subcommand( $args, $assoc_args );
	}

	/**
	 * The rows the last format_items() call was given.
	 *
	 * @return array
	 */
	protected function listed_rows() {
		$this->assertNotEmpty( WP_CLI::$items, 'The command formatted a table.' );

		$last = end( WP_CLI::$items );

		return $last['items'];
	}

	/**
	 * The last field/value table, as a map of field to value.
	 *
	 * @return array
	 */
	protected function reported_fields() {
		return wp_list_pluck( $this->listed_rows(), 'value', 'field' );
	}

	// --- registration ----------------------------------------------------

	/**
	 * The command registers under the bare noun, not the plugin slug.
	 *
	 * @return void
	 */
	public function test_the_command_registers_as_ban() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		WP_Ban::register_command();

		$this->assertArrayHasKey( 'ban', WP_CLI::$commands, 'The command is registered as `wp ban`.' );
		$this->assertSame( 'WP_Ban_Command', WP_CLI::$commands['ban'], 'WP_Ban_Command is what handles it.' );
		$this->assertArrayNotHasKey( 'wp-ban', WP_CLI::$commands, 'The plugin slug is not also claimed as a command.' );
	}

	// --- list ------------------------------------------------------------

	/**
	 * With no list named, every list is shown at once.
	 *
	 * @return void
	 */
	public function test_list_shows_every_list_when_none_is_named() {
		$this->set_options(
			array(
				'lists' => array(
					'ips'         => array( '192.168.77.10' ),
					'user_agents' => array( 'EmailSiphon*' ),
				),
			)
		);

		$this->run_command( 'list_' );

		$rows = $this->listed_rows();

		$this->assertCount( 2, $rows, 'Both entries are listed, from two different lists.' );
		$this->assertSame(
			array( 'ips', 'user_agents' ),
			wp_list_pluck( $rows, 'type' ),
			'Each row says which list it came off, in the order the settings screen shows them.'
		);
		$this->assertSame(
			array( '192.168.77.10', 'EmailSiphon*' ),
			wp_list_pluck( $rows, 'entry' ),
			'And carries the entry exactly as it is stored.'
		);
	}

	/**
	 * Naming a list narrows the table to it.
	 *
	 * @return void
	 */
	public function test_list_shows_only_the_list_it_was_given() {
		$this->set_options(
			array(
				'lists' => array(
					'ips'      => array( '192.168.77.10' ),
					'referers' => array( 'http://*.spam.test' ),
				),
			)
		);

		$this->run_command( 'list_', array( 'referers' ) );

		$rows = $this->listed_rows();

		$this->assertCount( 1, $rows, 'Only the named list is shown.' );
		$this->assertSame( 'http://*.spam.test', $rows[0]['entry'], 'And it is the referrer, not the address.' );
	}

	/**
	 * A site that bans nobody is reported as a success, not an error.
	 *
	 * @return void
	 */
	public function test_list_with_nothing_banned_is_not_an_error() {
		$this->set_options( array() );

		$this->run_command( 'list_' );

		$this->assertNotEmpty( WP_CLI::$successes, 'Finding nothing is reported on the success channel.' );
		$this->assertEmpty( WP_CLI::$items, 'No table is printed when there is nothing to put in it.' );
	}

	/**
	 * A list name the plugin does not have stops the command.
	 *
	 * @return void
	 */
	public function test_list_errors_on_a_list_that_does_not_exist() {
		$this->expectException( RuntimeException::class );

		$this->run_command( 'list_', array( 'ip' ) );
	}

	// --- add -------------------------------------------------------------

	/**
	 * Adding stores the entry where the settings screen would have put it.
	 *
	 * @return void
	 */
	public function test_add_puts_the_entry_on_the_list() {
		$this->set_options( array() );

		$this->run_command( 'add', array( 'ips', '192.168.77.10' ) );

		$this->assertSame(
			array( '192.168.77.10' ),
			WP_Ban_Options::list_of( 'ips' ),
			'The address is on the banned IP list afterwards.'
		);
		$this->assertNotEmpty( WP_CLI::$successes, 'And the command says so.' );
	}

	/**
	 * Adding appends; it does not replace what is already banned.
	 *
	 * @return void
	 */
	public function test_add_keeps_the_entries_already_stored() {
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.77.10' ) ) ) );

		$this->run_command( 'add', array( 'ips', '203.0.113.9', '198.51.100.4' ) );

		$this->assertSame(
			array( '192.168.77.10', '203.0.113.9', '198.51.100.4' ),
			WP_Ban_Options::list_of( 'ips' ),
			'The new entries go on the end and the old one is still there.'
		);
	}

	/**
	 * Adding one list leaves the other five where they were.
	 *
	 * @return void
	 */
	public function test_add_touches_only_the_list_it_was_given() {
		$this->set_options( array( 'lists' => array( 'user_agents' => array( 'EmailSiphon*' ) ) ) );

		$this->run_command( 'add', array( 'ips', '192.168.77.10' ) );

		$this->assertSame(
			array( 'EmailSiphon*' ),
			WP_Ban_Options::list_of( 'user_agents' ),
			'The user agent list is untouched by a change to the IP list.'
		);
	}

	/**
	 * Adding something already banned is a success, not a duplicate.
	 *
	 * @return void
	 */
	public function test_add_does_not_repeat_an_entry_already_listed() {
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.77.10' ) ) ) );

		$this->run_command( 'add', array( 'ips', '192.168.77.10' ) );

		$this->assertSame(
			array( '192.168.77.10' ),
			WP_Ban_Options::list_of( 'ips' ),
			'The list still holds one copy of it.'
		);
		$this->assertNotEmpty( WP_CLI::$successes, 'Asking for something already true is reported as a success.' );
	}

	/**
	 * A wildcard is stored as typed, because that is what makes it a pattern.
	 *
	 * @return void
	 */
	public function test_add_stores_a_wildcard_unaltered() {
		$this->set_options( array() );

		$this->run_command( 'add', array( 'ips', '192.168.1.*' ) );

		$this->assertSame(
			array( '192.168.1.*' ),
			WP_Ban_Options::list_of( 'ips' ),
			'The star survives storage; escaping it would stop it matching anything.'
		);
	}

	/**
	 * A range whose ends are not two addresses of the same kind is refused.
	 *
	 * This is the bug the 2.0.0 release exists for: such a range used to be
	 * stored, and then matched every visitor at or below its upper bound.
	 *
	 * @return void
	 */
	public function test_add_refuses_a_malformed_range_and_says_so() {
		$this->set_options( array() );

		$this->run_command( 'add', array( 'ips_range', 'garbage-203.0.113.255' ) );

		$this->assertSame( array(), WP_Ban_Options::list_of( 'ips_range' ), 'The malformed range was not stored.' );
		$this->assertNotEmpty( WP_CLI::$warnings, 'And the command warned about the entry it dropped.' );
	}

	/**
	 * A range mixing IPv4 and IPv6 is refused for the same reason.
	 *
	 * @return void
	 */
	public function test_add_refuses_a_range_that_mixes_address_families() {
		$this->set_options( array() );

		$this->run_command( 'add', array( 'ips_range', '192.168.1.1-2001:db8::ffff' ) );

		$this->assertSame( array(), WP_Ban_Options::list_of( 'ips_range' ), 'A range cannot have one end of each kind.' );
		$this->assertNotEmpty( WP_CLI::$warnings, 'And that is reported rather than stored quietly.' );
	}

	/**
	 * One bad range in a call does not take the good ones down with it.
	 *
	 * @return void
	 */
	public function test_add_keeps_the_usable_ranges_in_a_mixed_call() {
		$this->set_options( array() );

		$this->run_command( 'add', array( 'ips_range', '192.168.1.1-192.168.1.255', 'garbage-203.0.113.255', '2001:db8::1-2001:db8::ffff' ) );

		$this->assertSame(
			array( '192.168.1.1-192.168.1.255', '2001:db8::1-2001:db8::ffff' ),
			WP_Ban_Options::list_of( 'ips_range' ),
			'Both usable ranges are stored, IPv6 included, and only the third was dropped.'
		);
		$this->assertCount( 1, WP_CLI::$warnings, 'Exactly one entry was complained about.' );
	}

	/**
	 * A call whose every entry was refused stops rather than reporting success.
	 *
	 * @return void
	 */
	public function test_add_errors_when_nothing_usable_was_given() {
		$this->set_options( array() );

		try {
			$this->run_command( 'add', array( 'ips_range', 'garbage-203.0.113.255' ) );
			$this->fail( 'The command stops when it has nothing left to add.' );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		$this->assertSame( array(), WP_Ban_Options::list_of( 'ips_range' ), 'And nothing was stored.' );
	}

	// --- remove ----------------------------------------------------------

	/**
	 * Removing takes the entry off and leaves the rest of the list alone.
	 *
	 * @return void
	 */
	public function test_remove_takes_the_entry_off_the_list() {
		$this->set_options(
			array(
				'lists' => array(
					'ips' => array( '192.168.77.10', '203.0.113.9' ),
				),
			)
		);

		$this->run_command( 'remove', array( 'ips', '192.168.77.10' ), array( 'yes' => true ) );

		$this->assertSame(
			array( '203.0.113.9' ),
			WP_Ban_Options::list_of( 'ips' ),
			'The named entry is gone and the bystander is not.'
		);
		$this->assertNotEmpty( WP_CLI::$successes, 'And the command says what it did.' );
	}

	/**
	 * Without --yes the command asks, and a script that cannot answer removes
	 * nothing.
	 *
	 * @return void
	 */
	public function test_remove_without_yes_asks_first_and_removes_nothing() {
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.77.10' ) ) ) );

		try {
			$this->run_command( 'remove', array( 'ips', '192.168.77.10' ) );
			$this->fail( 'The command stops at the confirmation instead of removing.' );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		$this->assertNotEmpty( WP_CLI::$confirmations, 'It asked before doing anything.' );
		$this->assertSame(
			array( '192.168.77.10' ),
			WP_Ban_Options::list_of( 'ips' ),
			'And the entry is still banned.'
		);
	}

	/**
	 * An entry that is not on the list stops the command.
	 *
	 * An entry has to match character for character, so a near miss is worth
	 * stopping on rather than reporting as a successful no-op.
	 *
	 * @return void
	 */
	public function test_remove_errors_when_the_entry_is_not_listed() {
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.77.10' ) ) ) );

		try {
			$this->run_command( 'remove', array( 'ips', '192.168.77.11' ), array( 'yes' => true ) );
			$this->fail( 'Removing something that is not there is an error.' );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		$this->assertSame(
			array( '192.168.77.10' ),
			WP_Ban_Options::list_of( 'ips' ),
			'And the list is exactly as it was.'
		);
		$this->assertEmpty( WP_CLI::$confirmations, 'It did not even ask, having nothing to remove.' );
	}

	/**
	 * Removing from one list leaves the other five where they were.
	 *
	 * @return void
	 */
	public function test_remove_touches_only_the_list_it_was_given() {
		$this->set_options(
			array(
				'lists' => array(
					'ips'         => array( '192.168.77.10' ),
					'exclude_ips' => array( '192.168.77.10' ),
				),
			)
		);

		$this->run_command( 'remove', array( 'ips', '192.168.77.10' ), array( 'yes' => true ) );

		$this->assertSame(
			array( '192.168.77.10' ),
			WP_Ban_Options::list_of( 'exclude_ips' ),
			'The same address on the exclude list is untouched.'
		);
	}

	// --- check -----------------------------------------------------------

	/**
	 * A plain listed address is reported as banned, by the list that banned it.
	 *
	 * @return void
	 */
	public function test_check_reports_a_listed_address_as_banned() {
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.77.10' ) ) ) );

		$this->run_command( 'check', array( '192.168.77.10' ) );

		$reported = $this->reported_fields();

		$this->assertSame( '192.168.77.10', $reported['ip'], 'The address it was asked about is the one it reports on.' );
		$this->assertSame( 'yes', $reported['banned'], 'It is banned.' );
		$this->assertSame( 'ips', $reported['list'], 'And the banned IP list is what banned it.' );
	}

	/**
	 * A wildcard covers more than it looks like it does, which is the point.
	 *
	 * @return void
	 */
	public function test_check_reports_an_address_a_wildcard_covers() {
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.1.*' ) ) ) );

		$this->run_command( 'check', array( '192.168.1.55' ) );

		$this->assertSame( 'yes', $this->reported_fields()['banned'], 'An address the wildcard covers is banned.' );

		$this->run_command( 'check', array( '192.168.2.55' ) );

		$this->assertSame( 'no', $this->reported_fields()['banned'], 'And one just outside it is not.' );
	}

	/**
	 * A range is reported as the range list, not as the address list.
	 *
	 * @return void
	 */
	public function test_check_reports_an_address_inside_a_range() {
		$this->set_options( array( 'lists' => array( 'ips_range' => array( '203.0.113.10-203.0.113.20' ) ) ) );

		$this->run_command( 'check', array( '203.0.113.15' ) );

		$reported = $this->reported_fields();

		$this->assertSame( 'yes', $reported['banned'], 'An address inside the range is banned.' );
		$this->assertSame( 'ips_range', $reported['list'], 'And the range list is named as the reason.' );
	}

	/**
	 * A range that never should have been stored bans nobody.
	 *
	 * `add` refuses to store one of these, but a site upgrading from before
	 * 2.0.0 can still have one in the row, so the check has to agree.
	 *
	 * @return void
	 */
	public function test_check_reports_nobody_banned_by_a_malformed_stored_range() {
		$this->set_options( array( 'lists' => array( 'ips_range' => array( 'garbage-203.0.113.255' ) ) ) );

		foreach ( array( '8.8.8.8', '203.0.113.1', '198.51.100.7' ) as $ip ) {
			$this->run_command( 'check', array( $ip ) );

			$this->assertSame( 'no', $this->reported_fields()['banned'], "{$ip} was reported as banned by a malformed range" );
		}
	}

	/**
	 * An address on no list is reported as unbanned, with no reason to give.
	 *
	 * @return void
	 */
	public function test_check_reports_an_unlisted_address_as_not_banned() {
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.77.10' ) ) ) );

		$this->run_command( 'check', array( '203.0.113.1' ) );

		$reported = $this->reported_fields();

		$this->assertSame( 'no', $reported['banned'], 'An address on no list is not banned.' );
		$this->assertSame( '', $reported['list'], 'And no list is named, because none matched.' );
	}

	/**
	 * The exclude list wins over everything, and the check says why.
	 *
	 * @return void
	 */
	public function test_check_reports_an_excluded_address_as_not_banned() {
		$this->set_options(
			array(
				'lists' => array(
					'ips'         => array( '198.51.100.5' ),
					'exclude_ips' => array( '198.51.100.5' ),
				),
			)
		);

		$this->run_command( 'check', array( '198.51.100.5' ) );

		$reported = $this->reported_fields();

		$this->assertSame( 'no', $reported['banned'], 'An excluded address is not banned however many lists it is on.' );
		$this->assertSame( 'yes', $reported['excluded'], 'And the exclusion is reported, so the reason is not a mystery.' );
	}

	/**
	 * A user agent is checked when one is passed, and only then.
	 *
	 * @return void
	 */
	public function test_check_matches_a_user_agent_when_one_is_given() {
		$this->set_options( array( 'lists' => array( 'user_agents' => array( 'EmailSiphon*' ) ) ) );

		$this->run_command( 'check', array( '203.0.113.1' ) );

		$this->assertSame( 'no', $this->reported_fields()['banned'], 'The address alone matches nothing.' );

		$this->run_command( 'check', array( '203.0.113.1' ), array( 'user-agent' => 'EmailSiphon/1.0' ) );

		$reported = $this->reported_fields();

		$this->assertSame( 'yes', $reported['banned'], 'The same address sending a banned user agent is turned away.' );
		$this->assertSame( 'user_agents', $reported['list'], 'And the user agent list is named as the reason.' );
	}

	/**
	 * A referrer is checked the same way.
	 *
	 * @return void
	 */
	public function test_check_matches_a_referrer_when_one_is_given() {
		$this->set_options( array( 'lists' => array( 'referers' => array( 'http://*.spam.test/path?a=1&b=2' ) ) ) );

		$this->run_command( 'check', array( '203.0.113.1' ), array( 'referer' => 'http://bad.spam.test/path?a=1&b=2' ) );

		$reported = $this->reported_fields();

		$this->assertSame( 'yes', $reported['banned'], 'A banned referrer turns the visitor away, whatever their address.' );
		$this->assertSame( 'referers', $reported['list'], 'And the referrer list is named as the reason.' );
	}

	/**
	 * Checking counts nothing, so a script polling it does not fill the stats.
	 *
	 * @return void
	 */
	public function test_check_records_no_attempt() {
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.77.10' ) ) ) );

		$this->run_command( 'check', array( '192.168.77.10' ) );

		$this->assertSame( 0, WP_Ban_Stats::total(), 'A check is a question, not an attempt, so nothing is counted.' );
		$this->assertSame( 0, WP_Ban_Stats::attempts_for( '192.168.77.10' ), 'Nor against the address that was asked about.' );
	}

	/**
	 * Something that is not an address stops the command.
	 *
	 * @return void
	 */
	public function test_check_errors_on_something_that_is_not_an_address() {
		$this->set_options( array() );

		$this->expectException( RuntimeException::class );

		$this->run_command( 'check', array( 'example.com' ) );
	}

	// --- stats -----------------------------------------------------------

	/**
	 * The counters are listed, hardest-trying address first.
	 *
	 * @return void
	 */
	public function test_stats_lists_each_address_most_persistent_first() {
		$this->set_options( array() );

		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '192.168.77.10' );
		WP_Ban_Stats::record( '192.168.77.10' );
		WP_Ban_Stats::record( '192.168.77.10' );

		$this->run_command( 'stats' );

		$rows = $this->listed_rows();

		$this->assertCount( 2, $rows, 'One row per address that has been turned away.' );
		$this->assertSame( '192.168.77.10', $rows[0]['ip'], 'The address trying hardest is listed first.' );
		$this->assertSame( 3, $rows[0]['attempts'], 'With its own count beside it.' );
		$this->assertSame( 1, $rows[1]['attempts'], 'And the quieter one follows.' );
	}

	/**
	 * The total is printed above the table, as the screen prints it above its.
	 *
	 * @return void
	 */
	public function test_stats_prints_the_total() {
		$this->set_options( array() );

		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '192.168.77.10' );

		$this->run_command( 'stats' );

		$this->assertNotEmpty( WP_CLI::$logs, 'The total is printed.' );
		$this->assertStringContainsString( '2', end( WP_CLI::$logs ), 'And it is the number of attempts the site has turned away.' );
	}

	/**
	 * A site that has turned nobody away is reported as a success.
	 *
	 * @return void
	 */
	public function test_stats_with_no_attempts_is_not_an_error() {
		$this->set_options( array() );

		$this->run_command( 'stats' );

		$this->assertNotEmpty( WP_CLI::$successes, 'Having nothing to report is reported on the success channel.' );
		$this->assertEmpty( WP_CLI::$items, 'No table is printed when there is nothing to put in it.' );
	}

	// --- reset -----------------------------------------------------------

	/**
	 * --all clears every counter and the total with them.
	 *
	 * @return void
	 */
	public function test_reset_all_clears_every_counter_and_the_total() {
		$this->set_options( array() );

		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '192.168.77.10' );

		$this->run_command(
			'reset',
			array(),
			array(
				'all' => true,
				'yes' => true,
			)
		);

		$this->assertSame( 0, WP_Ban_Stats::total(), 'The total starts again from nothing.' );
		$this->assertSame( 0, WP_Ban_Stats::attempts_for( '203.0.113.1' ), 'And no address keeps a counter.' );
	}

	/**
	 * Naming addresses forgets those and leaves the total where it was.
	 *
	 * That is what the Stats tab's bulk action does: the total counts what the
	 * site has turned away, not what the surviving counters add up to.
	 *
	 * @return void
	 */
	public function test_reset_forgets_the_named_address_and_keeps_the_total() {
		$this->set_options( array() );

		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '192.168.77.10' );

		$this->run_command( 'reset', array( '203.0.113.1' ), array( 'yes' => true ) );

		$this->assertSame( 0, WP_Ban_Stats::attempts_for( '203.0.113.1' ), 'The named address is forgotten.' );
		$this->assertSame( 1, WP_Ban_Stats::attempts_for( '192.168.77.10' ), 'The one not named keeps its counter.' );
		$this->assertSame( 2, WP_Ban_Stats::total(), 'And the total is left alone, exactly as the bulk action leaves it.' );
	}

	/**
	 * Without --yes the command asks, and a script that cannot answer forgets
	 * nothing.
	 *
	 * @return void
	 */
	public function test_reset_without_yes_asks_first_and_forgets_nothing() {
		$this->set_options( array() );

		WP_Ban_Stats::record( '203.0.113.1' );

		try {
			$this->run_command( 'reset', array(), array( 'all' => true ) );
			$this->fail( 'The command stops at the confirmation instead of resetting.' );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		$this->assertNotEmpty( WP_CLI::$confirmations, 'It asked before doing anything.' );
		$this->assertSame( 1, WP_Ban_Stats::total(), 'And the counters are where they were.' );
	}

	/**
	 * Neither an address nor --all is a mistyped command, not an empty one.
	 *
	 * @return void
	 */
	public function test_reset_errors_when_given_neither_an_address_nor_all() {
		$this->set_options( array() );

		WP_Ban_Stats::record( '203.0.113.1' );

		try {
			$this->run_command( 'reset' );
			$this->fail( 'Resetting nothing in particular is an error.' );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		$this->assertSame( 1, WP_Ban_Stats::total(), 'And nothing was reset.' );
		$this->assertEmpty( WP_CLI::$confirmations, 'It did not ask, having been given nothing to confirm.' );
	}

	// --- the pre-2.0.0 rows ----------------------------------------------

	/**
	 * A command folds the pre-2.0.0 rows in before it reads them.
	 *
	 * The migration is driven from admin_init and WP-CLI never gets there, so
	 * without this a site updated but not yet visited in wp-admin would report
	 * an empty ban list while banning plenty -- and a write would be overwritten
	 * by the fold-in the first time somebody did open the screen.
	 *
	 * @return void
	 */
	public function test_a_subcommand_folds_in_the_legacy_rows_first() {
		update_option( 'banned_ips', array( '192.168.5.5' ) );

		$this->run_command( 'list_', array( 'ips' ) );

		$this->assertSame(
			array(
				array(
					'type'  => 'ips',
					'entry' => '192.168.5.5',
				),
			),
			$this->listed_rows(),
			'The entry from the pre-2.0.0 row is listed.'
		);
		$this->assertFalse( get_option( 'banned_ips', false ), 'And the row it came from has been folded in and deleted.' );
	}
}
