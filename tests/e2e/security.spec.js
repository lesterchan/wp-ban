/**
 * The stored XSS regressions, in a real browser.
 *
 * WP-Ban stores strings chosen by the person it is defending against. A banned
 * user agent and a banned referrer are patterns copied out of a log, and what is
 * in that log was written by whoever sent the request; a recorded address on the
 * stats screen came off the wire. All of them are then printed back into
 * wp-admin -- into textareas, into a list table, into a checkbox value -- which
 * is the shape of the bug where an attacker's payload runs in the administrator's
 * session while they are reading about the attack.
 *
 * The fixtures go straight into the option rows. Sanitising on the way in is the
 * assumption under test, not a step to reproduce: this is the row a pre-2.0.0
 * release, a WP-CLI one-liner or a compromised install already left behind.
 *
 * The assertion has two halves everywhere. The sentinel the payload would set
 * must never become defined, and the payload's text must still be on the page --
 * escaping that ate the value entirely passes the first half and is its own bug,
 * because a ban entry that silently vanished is one.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	FAKE,
	IP_HEADER,
	asVisitor,
	expectBanned,
	getStats,
	listField,
	openSettings,
	reset,
	setOptions,
	setStats,
} = require( './helpers.js' );

const SCRIPT_PAYLOAD = '<script>window.__pwned = 1;</script>';
const IMG_PAYLOAD = '<img src=x onerror="window.__pwned = 1">';
const ATTR_PAYLOAD = '" onmouseover="window.__pwned = 1';

/**
 * Whether any payload managed to run.
 *
 * @param {import('@playwright/test').Page} page Page to ask.
 * @return {Promise<boolean>} True if the sentinel was set.
 */
function pwned( page ) {
	return page.evaluate( () => window.__pwned === 1 );
}

/**
 * How many script elements on the page carry one of the payloads.
 *
 * Counted by their contents rather than by their presence: wp-admin is full of
 * scripts of its own, so "no script tags" is not a statement anybody could make
 * about an admin screen. What matters is that none of them came from the row.
 *
 * @param {import('@playwright/test').Page} page Page to search.
 * @return {Promise<number>} How many scripts mention the sentinel.
 */
function injectedScripts( page ) {
	return page.evaluate(
		() =>
			Array.from( document.querySelectorAll( 'script' ) ).filter( ( script ) =>
				script.textContent.includes( '__pwned' ),
			).length,
	);
}

test.describe( 'A hostile ban list stays inert', () => {
	test.afterEach( async () => {
		reset();
	} );

	test( 'the settings screen renders hostile entries as text in every list', async ( { page } ) => {
		const lists = {
			ips: [ `203.0.113.7 ${ SCRIPT_PAYLOAD }` ],
			ips_range: [ '203.0.113.10-203.0.113.20' ],
			hosts: [ `evil.example ${ IMG_PAYLOAD }` ],
			referers: [ `https://spam.example/${ ATTR_PAYLOAD }` ],
			user_agents: [ `BadBot ${ SCRIPT_PAYLOAD }` ],
			exclude_ips: [ `203.0.113.42 ${ ATTR_PAYLOAD }` ],
		};

		setOptions( { lists } );

		await openSettings( page );

		expect( await pwned( page ) ).toBe( false );

		// Nothing that runs, anywhere on the screen. A textarea's contents are
		// text to the parser, so the danger is an unescaped </textarea> ending
		// it early -- which is why esc_textarea() is the sink and why this
		// checks the document rather than the field.
		expect( await injectedScripts( page ) ).toBe( 0 );
		await expect( page.locator( '#wpbody [onerror]' ) ).toHaveCount( 0 );
		await expect( page.locator( '#wpbody [onmouseover]' ) ).toHaveCount( 0 );

		// And every value survived, byte for byte. A ban list that quietly
		// mangles what it was given stops matching the thing it was copied from,
		// which is a bug that looks exactly like the plugin not working.
		for ( const [ key, value ] of Object.entries( lists ) ) {
			await expect( listField( page, key ) ).toHaveValue( value.join( '\n' ) );
		}
	} );

	test( 'the stats table renders a hostile address as text, in the cell and in the checkbox', async ( {
		page,
	} ) => {
		const hostile = `203.0.113.7 ${ ATTR_PAYLOAD }`;

		// An address only ever reaches this row by having been the visitor's, so
		// a row shaped like this is what a pre-2.0.0 install that trusted a
		// proxy header has. The checkbox value is the dangerous half: it is
		// printed into an attribute rather than into text, and it is not the
		// copy anybody looks at.
		setStats( { users: { [ hostile ]: 3, [ `${ FAKE.other } ${ IMG_PAYLOAD }` ]: 1 }, count: 4 } );

		await openSettings( page );

		expect( await pwned( page ) ).toBe( false );

		expect( await injectedScripts( page ) ).toBe( 0 );
		await expect( page.locator( '.wp-list-table [onerror]' ) ).toHaveCount( 0 );
		await expect( page.locator( '.wp-list-table [onmouseover]' ) ).toHaveCount( 0 );

		await expect( page.locator( '.wp-list-table' ) ).toContainText( 'onmouseover' );
		await expect( page.locator( '.wp-list-table' ) ).toContainText( 'onerror' );

		// The checkbox carries the address itself, byte for byte -- which is what
		// makes the bulk reset able to forget the right row. Read out of the DOM
		// rather than matched with an attribute selector: the payload's own
		// double quote would end the selector early and the locator would match
		// nothing while looking as though it had matched the wrong thing.
		const values = await page
			.locator( 'input[name="ips[]"]' )
			.evaluateAll( ( inputs ) => inputs.map( ( input ) => input.value ) );

		expect( values ).toContain( hostile );
	} );

	test( 'a hostile banned message cannot run in the page it is served as', async ( { browser } ) => {
		setOptions( {
			ip_header: IP_HEADER,
			lists: { ips: [ FAKE.banned ] },
			message:
				'<html><body><div id="wp-ban-container">' +
				`<p>Go away ${ SCRIPT_PAYLOAD } ${ IMG_PAYLOAD }</p>` +
				'</div></body></html>',
		} );

		const { context, page } = await asVisitor( browser, { ip: FAKE.banned } );

		try {
			// The message is this test's own, so the default assertion about the
			// shipped wording would be looking for something that is not there.
			await expectBanned( page, '/', 403, 'Go away' );

			// The ban page is the one document this plugin serves instead of
			// WordPress, and the message it is built from is stored HTML. It is
			// echoed through wp_kses() with the plugin's own allow-list at the
			// point of output, which is what has to hold even for a row the
			// sanitizer never saw.
			expect( await pwned( page ) ).toBe( false );

			await expect( page.locator( 'script' ) ).toHaveCount( 0 );
			await expect( page.locator( '[onerror]' ) ).toHaveCount( 0 );

			// The text survives -- a message that escaped its own contents away
			// would tell a banned visitor nothing at all.
			await expect( page.locator( '#wp-ban-container' ) ).toContainText( 'Go away' );
		} finally {
			await context.close();
		}
	} );

	test( 'a hostile message cannot run in the preview either', async ( { page } ) => {
		setOptions( {
			message:
				'<html><body><div id="wp-ban-container">' +
				`<p>Go away ${ SCRIPT_PAYLOAD } ${ IMG_PAYLOAD }</p>` +
				'</div></body></html>',
		} );

		await openSettings( page );

		await page.getByRole( 'button', { name: 'Show Preview' } ).click();

		const preview = page.locator( '#wp-ban-preview' );

		await expect( preview ).toBeVisible();

		// A separate test from the one above, and not a duplicate of it: the
		// preview is markup returned by AJAX, parsed by the plugin's own script
		// and grafted into the administrator's page. A payload can be inert
		// where it is served to a stranger and live where it is shown to the
		// only user on the site who can change anything.
		expect( await pwned( page ) ).toBe( false );

		await expect( preview.locator( 'script' ) ).toHaveCount( 0 );
		await expect( preview.locator( '[onerror]' ) ).toHaveCount( 0 );
		await expect( preview ).toContainText( 'Go away' );
	} );

	test( 'a payload sent as an address never becomes a row on the stats screen', async ( {
		browser,
		page,
	} ) => {
		setOptions( {
			ip_header: IP_HEADER,
			lists: { user_agents: [ 'E2EPayloadBot*' ] },
		} );

		// The version of this file that needs no compromise first. The attacker
		// controls the trusted header, and what that header says becomes the
		// identity the ban is recorded against and printed on the settings
		// screen -- so if anything but an address could get through, every
		// banned bot would be able to put markup in front of the administrator.
		const visitor = await asVisitor( browser, {
			ip: `${ SCRIPT_PAYLOAD }`,
			agent: `E2EPayloadBot ${ ATTR_PAYLOAD }`,
		} );

		try {
			await expectBanned( visitor.page, '/' );
		} finally {
			await visitor.context.close();
		}

		await openSettings( page );

		expect( await pwned( page ) ).toBe( false );

		expect( await injectedScripts( page ) ).toBe( 0 );
		await expect( page.locator( '#wpbody [onmouseover]' ) ).toHaveCount( 0 );

		// The header was not an address, so it was not believed: the request was
		// attributed to REMOTE_ADDR instead and that is what the counter names.
		// Every recorded key is a real address, which is the property that makes
		// this screen safe to print rather than the escaping alone.
		const recorded = Object.keys( getStats().users );

		expect( recorded.length ).toBeGreaterThan( 0 );

		for ( const ip of recorded ) {
			expect( ip ).toMatch( /^[0-9a-fA-F.:]+$/ );
		}
	} );
} );
