<?php
/**
 * The WP-CLI command.
 *
 * @package WP-Ban
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and edits the ban lists and the attempt counters, from the command line.
 *
 * Registered as `wp ban` rather than `wp wp-ban`. A `wp-` prefix is a
 * wordpress.org directory convention for keeping one plugin's download page
 * apart from another's, not a command-naming one, and after the `wp` binary it
 * only stutters; WP-CLI's own ecosystem names commands after the brand -- `wp
 * wc`, `wp yoast`, `wp jetpack`. WP-CLI gives a collision to whichever plugin
 * registered last and a bare noun is claimable by anyone; that is the accepted
 * trade for a word as distinctive as `ban`.
 *
 * Every subcommand is something Settings -> Ban already does. Listing, adding
 * and removing entries is the six textareas on the Settings tab; `stats` and
 * `reset` are the counters and the bulk action on the Stats tab; `check` is the
 * matching the plugin does on every request, asked about an address of your
 * choosing instead of about the visitor. Nothing here reaches past that: the
 * banned message is a whole HTML document and belongs in the Templates tab
 * where it can be previewed, and the trusted-header setting is one field on a
 * screen that explains at length why naming the wrong header is dangerous.
 *
 * Two differences from the screen are worth knowing before scripting this.
 * The screen refuses to add an entry matching the administrator who is saving,
 * and a shell has no visitor to protect, so nothing is dropped here -- `wp ban
 * check` is how to find out what an entry would catch before adding it. And
 * the Stats tab shows a host name per row; this does not, because that is a
 * blocking DNS lookup per address and the screen only pays it for the twenty
 * rows it is displaying.
 */
class WP_Ban_Command extends WP_CLI_Command {

	/**
	 * List what is banned.
	 *
	 * ## OPTIONS
	 *
	 * [<type>]
	 * : Which list to show. Every list, if this is left out.
	 * ---
	 * options:
	 *   - ips
	 *   - ips_range
	 *   - hosts
	 *   - referers
	 *   - user_agents
	 *   - exclude_ips
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Everything the site bans, on one table.
	 *     $ wp ban list
	 *
	 *     # Just the banned addresses.
	 *     $ wp ban list ips
	 *
	 *     # How many user agents are banned.
	 *     $ wp ban list user_agents --format=count
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		$this->upgrade_first();

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$types  = isset( $args[0] ) ? array( $this->type_or_die( $args[0] ) ) : self::types();
		$rows   = array();

		foreach ( $types as $type ) {
			foreach ( WP_Ban_Options::list_of( $type ) as $entry ) {
				$rows[] = array(
					'type'  => $type,
					'entry' => $entry,
				);
			}
		}

		if ( empty( $rows ) ) {
			// An empty list is not an error. A site that bans nobody is the
			// state every site starts in and most of them stay in.
			WP_CLI::success( __( 'Nothing is on that list.', 'wp-ban' ) );

			return;
		}

		WP_CLI\Utils\format_items( $format, $rows, array( 'type', 'entry' ) );
	}

	/**
	 * Add entries to a ban list.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Which list to add to.
	 * ---
	 * options:
	 *   - ips
	 *   - ips_range
	 *   - hosts
	 *   - referers
	 *   - user_agents
	 *   - exclude_ips
	 * ---
	 *
	 * <entry>...
	 * : One or more entries. Every list but ips_range and exclude_ips takes *
	 * as a wildcard; a range is written start-end, and both ends must be
	 * addresses of the same kind.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp ban add ips 192.168.1.100
	 *
	 *     # Quoted, so the shell does not expand the star into filenames.
	 *     $ wp ban add ips '192.168.1.*'
	 *
	 *     $ wp ban add ips_range 192.168.1.1-192.168.1.255
	 *
	 *     $ wp ban add user_agents 'EmailSiphon*' 'ContactBot*'
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags passed to the command. Unused.
	 * @return void
	 */
	public function add( $args, $assoc_args ) {
		unset( $assoc_args );

		$this->upgrade_first();

		$type   = $this->type_or_die( array_shift( $args ) );
		$stored = WP_Ban_Options::list_of( $type );
		$adding = WP_Ban_Options::lines_to_list( $args );

		if ( 'ips_range' === $type ) {
			$split  = WP_Ban_Options::split_ranges( $adding );
			$adding = $split['valid'];

			foreach ( $split['invalid'] as $range ) {
				/*
				 * Rejected rather than stored, and loudly. Until 2.0.0 a range
				 * whose bounds did not parse was accepted and then matched every
				 * visitor at or below its upper bound, so the site banned its
				 * whole audience and said nothing.
				 */
				WP_CLI::warning(
					sprintf(
						/* translators: %s: the IP range that was rejected. */
						__( "The range '%s' is not two addresses of the same kind, so it was not added.", 'wp-ban' ),
						$range
					)
				);
			}
		}

		if ( empty( $adding ) ) {
			WP_CLI::error( __( 'Nothing was added: no usable entry was given.', 'wp-ban' ) );
		}

		$added = array_values( array_diff( $adding, $stored ) );

		if ( empty( $added ) ) {
			// Asking for something that is already true is not a failure, and a
			// script adding the same block list every night relies on it.
			WP_CLI::success( __( 'Every entry given is already on that list.', 'wp-ban' ) );

			return;
		}

		WP_Ban_Options::update_list( $type, array_merge( $stored, $added ) );

		WP_CLI::success(
			sprintf(
				/* translators: 1: number of entries added, 2: the ban list they went on. */
				_n( '%1$s entry added to %2$s.', '%1$s entries added to %2$s.', count( $added ), 'wp-ban' ),
				number_format_i18n( count( $added ) ),
				$type
			)
		);
	}

	/**
	 * Remove entries from a ban list.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Which list to remove from.
	 * ---
	 * options:
	 *   - ips
	 *   - ips_range
	 *   - hosts
	 *   - referers
	 *   - user_agents
	 *   - exclude_ips
	 * ---
	 *
	 * <entry>...
	 * : The entries to remove, written exactly as `wp ban list` shows them.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp ban remove ips 192.168.1.100
	 *
	 *     # In a script, where there is nobody to answer the prompt.
	 *     $ wp ban remove ips 192.168.1.100 --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function remove( $args, $assoc_args ) {
		$this->upgrade_first();

		$type     = $this->type_or_die( array_shift( $args ) );
		$stored   = WP_Ban_Options::list_of( $type );
		$asked    = WP_Ban_Options::lines_to_list( $args );
		$removing = array_values( array_intersect( $stored, $asked ) );

		if ( empty( $removing ) ) {
			// An entry has to match character for character, so a near miss is
			// worth stopping on rather than reporting as a successful no-op.
			WP_CLI::error(
				sprintf(
					/* translators: %s: the ban list that was searched. */
					__( 'None of those entries is on %s.', 'wp-ban' ),
					$type
				)
			);
		}

		// The list is the only record that an entry ever existed, and nothing
		// here can put one back, so this asks before it goes ahead.
		WP_CLI::confirm(
			sprintf(
				/* translators: 1: the entries being removed, 2: the ban list they are on. */
				__( 'Remove %1$s from %2$s?', 'wp-ban' ),
				implode( ', ', $removing ),
				$type
			),
			$assoc_args
		);

		WP_Ban_Options::update_list( $type, array_values( array_diff( $stored, $removing ) ) );

		WP_CLI::success(
			sprintf(
				/* translators: 1: number of entries removed, 2: the ban list they came off. */
				_n( '%1$s entry removed from %2$s.', '%1$s entries removed from %2$s.', count( $removing ), 'wp-ban' ),
				number_format_i18n( count( $removing ) ),
				$type
			)
		);
	}

	/**
	 * Say whether an address would be banned, and by which list.
	 *
	 * Worth having because the lists are patterns rather than addresses. A
	 * wildcard covers more than it looks like it does, a range covers a great
	 * deal more, and a range whose two ends are not addresses of the same kind
	 * used to cover everybody. This runs the same walk down the six lists that
	 * every request runs, against an address you name.
	 *
	 * Nothing is written and nothing is counted: an address checked here is not
	 * an attempt, so it does not turn up in `wp ban stats`.
	 *
	 * ## OPTIONS
	 *
	 * <ip>
	 * : The address to test.
	 *
	 * [--referer=<url>]
	 * : Test this referrer against the referrer list as well.
	 *
	 * [--user-agent=<string>]
	 * : Test this user agent against the user agent list as well.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Would this address get in?
	 *     $ wp ban check 192.168.1.55
	 *
	 *     # The whole request, not just its address.
	 *     $ wp ban check 203.0.113.9 --user-agent='EmailSiphon/1.0'
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function check( $args, $assoc_args ) {
		$this->upgrade_first();

		$ip = WP_Ban_IP::valid_ip( $args[0] );

		if ( '' === $ip ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: what was given instead of an address. */
					__( "'%s' is not an IP address.", 'wp-ban' ),
					$args[0]
				)
			);
		}

		$format     = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$referer    = isset( $assoc_args['referer'] ) ? (string) $assoc_args['referer'] : '';
		$user_agent = isset( $assoc_args['user-agent'] ) ? (string) $assoc_args['user-agent'] : '';

		$matched = WP_Ban_Verdict::matched_list( $ip, $referer, $user_agent );

		$rows = array(
			array(
				'field' => 'ip',
				'value' => $ip,
			),

			/*
			 * 'yes' and 'no' rather than translated words, and the list key
			 * rather than the label the screen prints. This subcommand exists to
			 * be tested by a script, and a script must not start reading every
			 * address as unbanned because the site was switched to another
			 * language.
			 */
			array(
				'field' => 'banned',
				'value' => '' === $matched ? 'no' : 'yes',
			),
			array(
				'field' => 'list',
				'value' => $matched,
			),
			array(
				// Reported separately because it is the answer to a different
				// question: an excluded address is not banned no matter what
				// else it matches, and knowing that is why it is not banned
				// saves reading all six lists to find out.
				'field' => 'excluded',
				'value' => WP_Ban_Verdict::is_excluded( $ip ) ? 'yes' : 'no',
			),
		);

		WP_CLI\Utils\format_items( $format, $rows, array( 'field', 'value' ) );
	}

	/**
	 * List how many times each address has been turned away.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp ban stats
	 *
	 *     # The addresses trying hardest, ten at a time.
	 *     $ wp ban stats --format=csv | head -11
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function stats( $args, $assoc_args ) {
		unset( $args );

		$this->upgrade_first();

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$stats  = WP_Ban_Stats::get();

		WP_CLI::log(
			sprintf(
				/* translators: %s: total number of attempts turned away. */
				__( 'Total attempts turned away: %s', 'wp-ban' ),
				number_format_i18n( WP_Ban_Stats::total() )
			)
		);

		if ( empty( $stats['users'] ) ) {
			// The total is printed above whatever happens, because it can be
			// non-zero with no rows behind it: a request whose address could not
			// be resolved is counted, but there is nothing to count it against.
			WP_CLI::success( __( 'No address has a counter of its own.', 'wp-ban' ) );

			return;
		}

		$rows = array();

		foreach ( $stats['users'] as $ip => $attempts ) {
			$rows[] = array(
				'ip'       => (string) $ip,
				'attempts' => (int) $attempts,
			);
		}

		// Most persistent first, which is the order the Stats tab opens on.
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['attempts'] <=> $a['attempts'];
			}
		);

		WP_CLI\Utils\format_items( $format, $rows, array( 'ip', 'attempts' ) );
	}

	/**
	 * Reset the ban attempt counters.
	 *
	 * ## OPTIONS
	 *
	 * [<ip>...]
	 * : Addresses to forget. Ignored when --all is given.
	 *
	 * [--all]
	 * : Forget every counter, and the total with them.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Forget one address, as the Stats tab's bulk action does.
	 *     $ wp ban reset 192.168.1.100
	 *
	 *     # Start the counting again.
	 *     $ wp ban reset --all --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags passed to the command.
	 * @return void
	 */
	public function reset( $args, $assoc_args ) {
		$this->upgrade_first();

		$all = ! empty( $assoc_args['all'] );

		if ( ! $all && empty( $args ) ) {
			// An empty address list would otherwise read as "forget nothing" and
			// report success, which is the wrong answer to a mistyped command.
			WP_CLI::error( __( 'Name at least one address, or pass --all.', 'wp-ban' ) );
		}

		$addresses = array_map( 'sanitize_text_field', $args );

		// A counter cannot be rebuilt from anything else the plugin stores, so
		// both halves of this ask first.
		WP_CLI::confirm(
			$all
				? __( 'Reset every ban stat and the total?', 'wp-ban' )
				/* translators: %s: list of addresses. */
				: sprintf( __( 'Reset the counters for %s?', 'wp-ban' ), implode( ', ', $addresses ) ),
			$assoc_args
		);

		if ( $all ) {
			WP_Ban_Stats::reset();

			WP_CLI::success( __( 'All ban stats were reset.', 'wp-ban' ) );

			return;
		}

		/*
		 * The total is left where it was, exactly as the Stats tab's bulk action
		 * leaves it. It counts what the site has turned away, not what the
		 * counters happen to add up to, and only --all claims to start over.
		 */
		$removed = WP_Ban_Stats::forget( $addresses );

		WP_CLI::success(
			sprintf(
				/* translators: %s: number of addresses whose counters were removed. */
				_n( '%s counter was reset.', '%s counters were reset.', $removed, 'wp-ban' ),
				number_format_i18n( $removed )
			)
		);
	}

	/**
	 * The six list keys, in the order the settings screen shows them.
	 *
	 * Read off the defaults rather than written out again, so a seventh list
	 * would be listable, addable and removable the moment it existed.
	 *
	 * @return string[]
	 */
	private static function types() {
		return array_keys( WP_Ban_Options::defaults()['lists'] );
	}

	/**
	 * Resolve a list name, or stop the command.
	 *
	 * @param string $type List name as it arrived on the command line.
	 * @return string The list key.
	 */
	private function type_or_die( $type ) {
		$type = (string) $type;

		if ( ! in_array( $type, self::types(), true ) ) {
			WP_CLI::error(
				sprintf(
					/* translators: 1: the list name that was asked for, 2: the list names there are. */
					__( 'There is no ban list called %1$s. The lists are: %2$s.', 'wp-ban' ),
					$type,
					implode( ', ', self::types() )
				)
			);
		}

		return $type;
	}

	/**
	 * Fold the pre-2.0.0 option rows in before reading or writing them.
	 *
	 * The migration is driven from admin_init, and WP-CLI never gets there:
	 * WP_Ban_Settings::init() is behind an is_admin() check, so on a site that
	 * has been updated but whose administrator has not opened wp-admin since,
	 * the six unprefixed list rows are still the live ones. Reading without
	 * this reports an empty ban list on a site that bans plenty; writing
	 * without it is worse, because the migration would later overwrite the
	 * entry from the legacy rows it is folding in, and nothing would say so.
	 *
	 * @return void
	 */
	private function upgrade_first() {
		WP_Ban_Options::maybe_upgrade();
	}
}
