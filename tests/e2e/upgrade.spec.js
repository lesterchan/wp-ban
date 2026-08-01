/**
 * The upgrade from the ten unprefixed rows a pre-2.0.0 install has.
 *
 * WP-Ban kept its settings in eight autoloaded option rows plus a schema counter
 * and an unbounded statistics row, all unprefixed -- `banned_options` was close
 * enough to a name another plugin might reasonably pick to be worth losing. Ten
 * rows become three, and every old name is deleted rather than left behind for a
 * later half-finished update to read again.
 *
 * The migration runs from two entry points and they are genuinely different.
 * Reactivating fires the activation hook; updating through the Plugins screen
 * never does, and leaves admin_init to run it alone -- which is how the
 * overwhelming majority of installs will arrive here. Both are exercised, and
 * the browser is what makes the second one real: nothing but an actual admin
 * request fires admin_init.
 *
 * One detail is worth stating because it decides how these fixtures are built:
 * every entry in a pre-2.0.0 list was stored esc_html()'d, which is why a
 * referrer with a query string could never match a real Referer header. The
 * migration decodes them once. A fixture written in plain ASCII would never
 * notice, so the fixtures here carry the ampersands and quotes that broke.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	FAKE,
	IP_HEADER,
	asVisitor,
	expectBanned,
	getStats,
	getStoredOptions,
	getVersionRow,
	listField,
	openSettings,
	reactivatePlugin,
	ensurePluginActive,
	reset,
	runningVersions,
	setOptions,
	wpEval,
} = require( './helpers.js' );

/**
 * Put the install into the shape a 1.x site is in.
 *
 * The three prefixed rows go away entirely and the ten unprefixed ones take
 * their place, because that is what the migration has to meet.
 *
 * @param {Object} rows Legacy rows, keyed by their old option names.
 * @return {void}
 */
function installLegacyRows( rows ) {
	const data = Buffer.from( JSON.stringify( rows ), 'utf8' ).toString( 'base64' );

	wpEval(
		`delete_option( 'wp_ban_options' );
		delete_option( 'wp_ban_version' );
		delete_option( 'wp_ban_stats' );
		foreach ( json_decode( base64_decode( '${ data }' ), true ) as $name => $value ) {
			update_option( $name, $value );
		}
		echo '<<<done>>>';`,
	);
}

/**
 * Which of the ten pre-2.0.0 rows are still there.
 *
 * @return {Object} Each name mapped to its stored value, or false once deleted.
 */
function getLegacyRows() {
	return JSON.parse(
		wpEval(
			`$names = array(
				'banned_options', 'banned_message', 'ban_db_version', 'banned_stats',
				'banned_ips', 'banned_ips_range', 'banned_hosts',
				'banned_referers', 'banned_user_agents', 'banned_exclude_ips'
			);
			$out = array();
			foreach ( $names as $name ) {
				$out[ $name ] = get_option( $name );
			}
			echo '<<<' . wp_json_encode( $out ) . '>>>';`,
		),
	);
}

/**
 * Whether an option row is autoloaded, as the database records it.
 *
 * @param {string} name Option name.
 * @return {string} 'yes', 'no', or '' when there is no row.
 */
function autoloadOf( name ) {
	return wpEval(
		`global $wpdb;
		echo '<<<' . (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", '${ name }'
		) ) . '>>>';`,
	);
}

/** A pre-2.0.0 install with something in every list. */
const LEGACY = {
	banned_options: { reverse_proxy: 1 },
	// Stored esc_html()'d, which is the shape that made a referrer with a query
	// string unmatchable. The migration decodes it once and stores plain text.
	banned_referers: [ 'https://spam.example/?a=1&amp;b=2' ],
	banned_ips: [ '203.0.113.7', '203.0.113.7', '' ],
	banned_ips_range: [ '203.0.113.10-203.0.113.20' ],
	banned_hosts: [ '*.example.invalid' ],
	banned_user_agents: [ 'BadBot&amp;Co*' ],
	banned_exclude_ips: [ '203.0.113.42' ],
	// Stored still slashed: it was passed to wp_kses() straight off $_POST and
	// stripslashes()'d on every read, and reads no longer do that.
	banned_message:
		'<html><body><div id="wp-ban-container"><p>O\\\'Brien says go away</p></div></body></html>',
	ban_db_version: '1',
	banned_stats: { users: { '203.0.113.7': 5 }, count: 12 },
};

test.describe( 'The pre-2.0.0 upgrade', () => {
	test.afterEach( async () => {
		reset();

		// This is the only file that ever switches the plugin off, and only for
		// as long as it takes to prove the activation hook runs. A failure part
		// way through would otherwise hand every later test a site with no
		// plugin, and the run would report a hundred symptoms of one cause.
		ensurePluginActive();
	} );

	test( 'the fixture really is a 1.x install, and one admin request folds all ten rows in', async ( {
		page,
	} ) => {
		installLegacyRows( LEGACY );

		// The precondition the rest of this file leans on. Without it a run in
		// which installLegacyRows() quietly did nothing would still go green,
		// because "the old rows are gone afterwards" is true of rows that were
		// never there.
		const before = getLegacyRows();

		expect( before.banned_options ).not.toBe( false );
		expect( before.banned_ips ).not.toBe( false );
		expect( getVersionRow() ).toBe( false );

		// No reactivation: this is the update-through-the-Plugins-screen path,
		// where the activation hook never fires and admin_init runs alone.
		await openSettings( page );

		const after = getLegacyRows();

		// Every one of the ten gone, not merely superseded. A row left behind is
		// a row a later half-finished update reads again -- and eight of them
		// were autoloaded on every request of the site.
		for ( const [ name, value ] of Object.entries( after ) ) {
			expect( value, `${ name } should have been deleted` ).toBe( false );
		}

		const stored = getStoredOptions();

		expect( stored.reverse_proxy ).toBe( true );
		expect( stored.lists.ips ).toEqual( [ '203.0.113.7' ] );
		expect( stored.lists.ips_range ).toEqual( [ '203.0.113.10-203.0.113.20' ] );
		expect( stored.lists.hosts ).toEqual( [ '*.example.invalid' ] );
		expect( stored.lists.exclude_ips ).toEqual( [ '203.0.113.42' ] );

		// Decoded once, and once only: an entry that stayed &amp; could never
		// match a real header, and one that was decoded twice would be a
		// different string again.
		expect( stored.lists.referers ).toEqual( [ 'https://spam.example/?a=1&b=2' ] );
		expect( stored.lists.user_agents ).toEqual( [ 'BadBot&Co*' ] );

		// The message comes across unslashed, because readers no longer strip.
		expect( stored.message ).toContain( "O'Brien says go away" );
		expect( stored.message ).not.toContain( "\\'" );

		expect( getVersionRow() ).toEqual( runningVersions() );
	} );

	test( 'the migrated settings are the settings the screen shows and the blocker acts on', async ( {
		browser,
		page,
	} ) => {
		installLegacyRows( LEGACY );

		await openSettings( page );

		// Present is not alive. A row can survive the migration in a shape the
		// screen cannot edit or the blocker cannot read, and the owner would
		// find out either by opening a form with their ban list missing from it
		// or by not being protected any more.
		await expect( listField( page, 'ips' ) ).toHaveValue( '203.0.113.7' );
		await expect( listField( page, 'referers' ) ).toHaveValue(
			'https://spam.example/?a=1&b=2',
		);
		await expect( page.locator( 'input[name="wp_ban_options[reverse_proxy]"]' ) ).toBeChecked();

		// And a visitor the migrated list names really is turned away. The
		// trusted header is added here rather than migrated, because a 1.x row
		// has no such setting -- everything else about the ban comes through the
		// upgrade.
		const stored = getStoredOptions();

		setOptions( { ...stored, ip_header: IP_HEADER } );

		const { context, page: visitor } = await asVisitor( browser, { ip: FAKE.banned } );

		try {
			await expectBanned( visitor, '/' );
		} finally {
			await context.close();
		}
	} );

	test( 'the counters move to their own row and stop being autoloaded', async ( { page } ) => {
		installLegacyRows( LEGACY );

		expect( autoloadOf( 'banned_stats' ) ).toBe( 'yes' );

		await openSettings( page );

		// The counters keep their numbers...
		expect( getStats() ).toEqual( { users: { '203.0.113.7': 5 }, count: 12 } );

		// ...and stop being read on every page view of the site. This is a row
		// that grows one entry per distinct attacker and has no upper bound, so
		// where it sits is not a detail: until 2.0.0 every visitor to the front
		// page paid for a list of every address ever turned away.
		expect( autoloadOf( 'wp_ban_stats' ) ).toBe( 'no' );

		await expect( page.locator( '#wpbody' ) ).toContainText( 'Total attempts turned away: 12' );
	} );

	test( 'reactivating runs the same migration, without an admin page in sight', async () => {
		installLegacyRows( LEGACY );

		// The other entry point, and the only one that fires the activation
		// hook. Deactivating and reactivating is what an owner does to "fix" a
		// plugin, so it has to reach the same migration from the same rows.
		reactivatePlugin();

		const after = getLegacyRows();

		for ( const [ name, value ] of Object.entries( after ) ) {
			expect( value, `${ name } should have been deleted` ).toBe( false );
		}

		expect( getStoredOptions().lists.ips ).toEqual( [ '203.0.113.7' ] );
		expect( getStats() ).toEqual( { users: { '203.0.113.7': 5 }, count: 12 } );
		expect( getVersionRow() ).toEqual( runningVersions() );
	} );

	test( 'a second activation, and the admin load after it, change nothing', async ( { page } ) => {
		installLegacyRows( LEGACY );

		reactivatePlugin();

		const once = { options: getStoredOptions(), stats: getStats(), versions: getVersionRow() };

		// Owners reactivate to fix things, sometimes twice. The second pass has
		// to be a bystander -- and so must the admin_init pass that follows a
		// real update, which is the combination every genuine upgrade produces.
		reactivatePlugin();

		expect( getStoredOptions() ).toEqual( once.options );
		expect( getStats() ).toEqual( once.stats );
		expect( getVersionRow() ).toEqual( once.versions );

		await openSettings( page );

		expect( getStoredOptions() ).toEqual( once.options );
		expect( getVersionRow() ).toEqual( once.versions );
	} );

	test( 'a fresh install is seeded rather than migrated', async ( { page } ) => {
		// No legacy rows at all, and no settings row either: the state a brand
		// new activation is in. The migration is gated on the markers rather
		// than on "do the old rows still exist", so this path runs the same code
		// with nothing to read -- and has to end with a usable row rather than
		// with nothing.
		wpEval(
			`delete_option( 'wp_ban_options' );
			delete_option( 'wp_ban_version' );
			echo '<<<done>>>';`,
		);

		reactivatePlugin();

		const stored = getStoredOptions();

		expect( stored.lists ).toEqual( {
			ips: [],
			ips_range: [],
			hosts: [],
			referers: [],
			user_agents: [],
			exclude_ips: [],
		} );
		expect( stored.message ).toContain( 'You Are Banned.' );
		expect( getVersionRow() ).toEqual( runningVersions() );

		await openSettings( page );

		await expect( listField( page, 'ips' ) ).toHaveValue( '' );
	} );

	test( 'a version bump with the schema unchanged re-normalises without dropping entries', async ( {
		page,
	} ) => {
		// The third branch of maybe_upgrade(), and the easiest to get wrong. The
		// schema counter is current but the plugin version is not, which is what
		// every release that only changed code looks like. It re-normalises what
		// is stored so a shape tightened since the last release is repaired
		// without waiting for somebody to press Save.
		//
		// Deliberately get() and not sanitize(), and the difference between the
		// two is what this asserts. The sanitiser blanks a header name that is
		// not the shape PHP gives $_SERVER keys and drops a range it cannot
		// parse, complaining about each; get() only normalises types. Storing a
		// row that would visibly lose both is therefore the cheapest proof of
		// which one ran -- and a far safer one than the other difference between
		// them, which is that the sanitiser also drops whatever matches whoever
		// is browsing.
		wpEval(
			`update_option( 'wp_ban_options', array(
				'ip_header' => 'not a valid header!',
				'lists'     => array(
					'ips'       => array( '203.0.113.7' ),
					'ips_range' => array( 'junk' ),
				),
			) );
			update_option( 'wp_ban_version', array( 'plugin' => '1.99.0', 'db' => WP_BAN_DB_VERSION ) );
			echo '<<<done>>>';`,
		);

		await openSettings( page );

		const stored = getStoredOptions();

		// Normalised: the missing keys are filled in from the defaults and the
		// list group is complete rather than the two keys that were written.
		expect( stored.message ).toContain( 'You Are Banned.' );
		expect( Object.keys( stored.lists ).sort() ).toEqual( [
			'exclude_ips',
			'hosts',
			'ips',
			'ips_range',
			'referers',
			'user_agents',
		] );
		expect( stored.lists.ips ).toEqual( [ '203.0.113.7' ] );

		// And untouched where only the sanitiser would have intervened. An
		// unattended upgrade that quietly edited an owner's ban lists is the
		// thing this branch exists to avoid.
		expect( stored.ip_header ).toBe( 'not a valid header!' );
		expect( stored.lists.ips_range ).toEqual( [ 'junk' ] );

		expect( getVersionRow() ).toEqual( runningVersions() );
	} );
} );
