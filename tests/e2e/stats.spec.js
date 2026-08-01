/**
 * The ban statistics table, and the two ways of clearing it.
 *
 * Before 2.0.0 this was a hand-rolled table that rendered every recorded address
 * on one page, so a site that had turned away a few thousand bots got a settings
 * screen it could not load. It is core's WP_List_Table now, which means
 * pagination, sortable column headers and a bulk action -- and every one of
 * those is a thing that works in the abstract and fails on the page.
 *
 * The counters are written straight into their row rather than earned by being
 * banned twenty-one times: what is under test here is the table, and a fixture
 * that took twenty-one HTTP requests to build would be testing the blocker
 * again, slowly.
 *
 * The reset is a destructive action, so it is tested from both ends -- the
 * selected rows and the reset-everything box -- and in both cases against the
 * stored row rather than the notice, because a screen that says it reset
 * something and did not is the failure worth catching.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { getStats, openSettings, reset, setStats } = require( './helpers.js' );

/**
 * A counter row with a predictable number of addresses.
 *
 * The addresses are from the RFC 5737 documentation ranges and are only ever
 * read back out of the option: nothing here bans anybody.
 *
 * @param {number} howMany How many addresses to invent.
 * @return {Object} The stats row, and the addresses in it.
 */
function inventStats( howMany ) {
	const users = {};
	let count = 0;

	for ( let i = 1; i <= howMany; i++ ) {
		// Attempts ascending with the address, so sorting by either column has
		// an unambiguous answer and the two orders are not the same order.
		users[ `203.0.113.${ i }` ] = i;
		count += i;
	}

	return { users, count };
}

test.describe( 'The ban stats table', () => {
	test.afterEach( async () => {
		reset();
	} );

	test( 'the fixture really is an empty table, and rows appear once there are any', async ( {
		page,
	} ) => {
		// Both halves in one test. "No rows" is true with the plugin
		// deactivated as well, so on its own it proves nothing; the second half
		// is what makes this fail when the plugin is gone.
		await openSettings( page );

		await expect( page.locator( '.wp-list-table' ) ).toContainText( 'No attempts.' );
		await expect( page.locator( '#wpbody' ) ).toContainText( 'Total attempts turned away: 0' );

		setStats( { users: { '203.0.113.7': 4 }, count: 9 } );

		await openSettings( page );

		await expect( page.locator( '.wp-list-table tbody tr' ) ).toHaveCount( 1 );
		await expect( page.locator( '.wp-list-table' ) ).toContainText( '203.0.113.7' );
		await expect( page.locator( '.wp-list-table' ) ).toContainText( '4' );

		// The total is not the sum of the rows and is not meant to be: a
		// refusal with no resolvable address is counted but has nobody to
		// attribute it to, which is why the two are stored separately.
		await expect( page.locator( '#wpbody' ) ).toContainText( 'Total attempts turned away: 9' );
	} );

	test( 'the table paginates rather than printing every address it has', async ( { page } ) => {
		setStats( inventStats( 25 ) );

		await openSettings( page );

		// Twenty per page is core's default for this table, so twenty-five rows
		// is the smallest fixture with a second page. The precondition is
		// asserted rather than assumed: on a one-page table, "the second page
		// holds the rest" would be vacuously true.
		await expect( page.locator( '.wp-list-table tbody tr' ) ).toHaveCount( 20 );
		await expect( page.locator( '.tablenav-pages' ).first() ).toContainText( '25 items' );

		// Clicked, not constructed. The tests environment ships plain
		// permalinks and this is an admin screen either way, but a paginated
		// URL assembled by hand stops testing the link the plugin rendered.
		await page.locator( '.tablenav-pages a.next-page' ).first().click();

		await expect( page.locator( '.wp-list-table tbody tr' ) ).toHaveCount( 5 );
	} );

	test( 'the sortable headers reorder the rows', async ( { page } ) => {
		setStats( inventStats( 3 ) );

		await openSettings( page );

		const rows = page.locator( '.wp-list-table tbody tr .column-ip' );

		// By the column's id rather than by the link's name. WP_List_Table puts
		// a sorting indicator inside the anchor and the primary column carries a
		// "Show more details" toggle, so neither accessible name is reliably the
		// column label -- and the ids are the column keys the plugin declared,
		// which is what the test is actually about.
		const header = ( column ) => page.locator( `th#${ column } a` ).first();

		// Attempts descending is the default, and it is the right default: the
		// address knocking hardest is the one an owner wants at the top.
		await expect( rows.first() ).toContainText( '203.0.113.3' );

		// Address order, which sorts naturally rather than as strings -- so .10
		// would come after .9 rather than after .1.
		await header( 'ip' ).click();

		await expect( rows.first() ).toContainText( '203.0.113.1' );
		await expect( rows.last() ).toContainText( '203.0.113.3' );

		// Back to attempts, which the plugin declared descending-first: coming
		// from another column, one click means "most attempts", not "reverse of
		// whatever you were looking at".
		await header( 'attempts' ).click();

		await expect( rows.first() ).toContainText( '203.0.113.3' );

		// And the same header again flips it, because now it is the column the
		// table is already sorted by.
		await header( 'attempts' ).click();

		await expect( rows.first() ).toContainText( '203.0.113.1' );
	} );

	test( 'the bulk action forgets the selected addresses and nothing else', async ( { page } ) => {
		setStats( { users: { '203.0.113.1': 2, '203.0.113.2': 3, '203.0.113.3': 4 }, count: 9 } );

		await openSettings( page );

		await page.locator( 'input[name="ips[]"][value="203.0.113.2"]' ).check();
		await page.locator( 'select[name="action"]' ).selectOption( 'reset' );
		await page.getByRole( 'button', { name: 'Apply' } ).first().click();

		await expect( page.locator( '.notice-success' ).first() ).toContainText(
			'The selected ban stats were reset',
		);

		// The row, not the notice. Forgetting one address must leave the others
		// and the total alone: the total is the site's whole history and
		// clearing one attacker's counter is not a claim about it.
		const stats = getStats();

		expect( stats.users[ '203.0.113.2' ] ).toBeUndefined();
		expect( stats.users[ '203.0.113.1' ] ).toBe( 2 );
		expect( stats.users[ '203.0.113.3' ] ).toBe( 4 );
		expect( stats.count ).toBe( 9 );
	} );

	test( 'the reset-everything box clears the rows and the total', async ( { page } ) => {
		setStats( inventStats( 3 ) );

		await openSettings( page );

		await page.locator( 'input[name="reset_all"]' ).check();
		await page.getByRole( 'button', { name: 'Reset Ban Stats' } ).click();

		await expect( page.locator( '.notice-success' ).first() ).toContainText(
			'All ban stats were reset',
		);

		expect( getStats() ).toEqual( { users: [], count: 0 } );

		await expect( page.locator( '.wp-list-table' ) ).toContainText( 'No attempts.' );
	} );

	test( 'the notice is shown once, and a refresh does not replay the reset', async ( { page } ) => {
		setStats( inventStats( 3 ) );

		await openSettings( page );

		await page.locator( 'input[name="reset_all"]' ).check();
		await page.getByRole( 'button', { name: 'Reset Ban Stats' } ).click();

		await expect( page.locator( '.notice-success' ).first() ).toContainText(
			'All ban stats were reset',
		);

		// Post/Redirect/Get, so the browser is on a GET by now and a refresh
		// cannot resubmit. The notice travels in a short-lived transient rather
		// than a query argument for the same reason: a marker in the URL
		// survives being bookmarked, and the screen would then claim to have
		// reset something every time that bookmark was opened.
		setStats( inventStats( 2 ) );

		await page.reload();

		await expect( page.locator( '.notice-success' ) ).toHaveCount( 0 );
		expect( getStats().count ).toBe( 3 );
	} );

	test( 'a reset without the nonce is refused', async ( { page } ) => {
		setStats( inventStats( 3 ) );

		// The form's nonce is the one WP_List_Table emits for its bulk action,
		// which is why the plugin does not add a second wp_nonce_field(): both
		// inputs would be named _wpnonce and the last one posted would win,
		// which is how every bulk action here used to fail its referer check.
		// Posting without it has to be refused rather than quietly obeyed.
		const response = await page.request.post( '/wp-admin/options-general.php?page=wp-ban', {
			form: { action: 'reset', reset_all: '1' },
		} );

		expect( response.status() ).toBe( 403 );

		// And the far end: the counters are still there.
		expect( getStats().count ).toBe( 6 );
	} );
} );
