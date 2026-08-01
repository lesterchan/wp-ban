/**
 * Settings -> Ban, driven the way an owner drives it.
 *
 * The screen is where an owner can do the most damage to themselves, so most of
 * this file is about the guards rather than about the fields. An entry that
 * matches the administrator who is saving it is dropped and said so; a range
 * that is not two addresses of the same family is refused rather than stored to
 * match nothing; a header name that is not the shape PHP gives $_SERVER keys is
 * discarded rather than trusted.
 *
 * Every assertion reads the stored row back rather than trusting the notice.
 * What bans a visitor is the row, not the form -- and the two failures a
 * screenshot cannot tell apart are a setting that saves and does nothing and a
 * setting that does something and will not save.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	IP_HEADER,
	LISTS,
	PLUGINS_URL,
	SETTINGS_URL,
	asVisitor,
	clearFixtureAnswer,
	expectBanned,
	fillLists,
	getStoredOptions,
	listField,
	openSettings,
	reset,
	saveExpectingError,
	saveSettings,
	setFixtureAnswer,
	setOptions,
	tabUrl,
	unique,
	wpEval,
} = require( './helpers.js' );

/** The lesser user both capability tests are argued from. */
const SUBSCRIBER = {
	username: 'ban_subscriber',
	password: 'correct-horse-battery-staple',
};

/**
 * A second browser, logged in as a subscriber.
 *
 * A fresh context rather than the shared one, because the suite's stored session
 * is the administrator's and swapping users inside it would leave whichever test
 * ran next signed in as the wrong person.
 *
 * @param {import('@playwright/test').Page} page         The administrator's page, for its browser.
 * @param {Object}                          requestUtils The e2e-test-utils request helper.
 * @return {Promise<Object>} The new `context` and the logged-in `subscriber` page.
 */
async function loginAsSubscriber( page, requestUtils ) {
	await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/users',
		data: {
			username: SUBSCRIBER.username,
			email: `${ SUBSCRIBER.username }@example.com`,
			password: SUBSCRIBER.password,
			roles: [ 'subscriber' ],
		},
	} ).catch( () => {} ); // Already there from an earlier run.

	const context = await page.context().browser().newContext( { storageState: undefined } );
	const subscriber = await context.newPage();

	await subscriber.goto( '/wp-login.php' );

	// wp-login.php focuses and selects #user_login on a 200ms timer, so that a
	// visitor can start typing. Filling across that moment puts the password
	// into the username box: Playwright focuses #user_pass, the timer takes
	// focus back and selects what is there, and the typed text replaces the
	// selection. Waiting for the timer's own effect is the signal that it has
	// already fired.
	await expect( subscriber.locator( '#user_login' ) ).toBeFocused();

	await subscriber.locator( '#user_login' ).fill( SUBSCRIBER.username );
	await subscriber.locator( '#user_pass' ).fill( SUBSCRIBER.password );
	await subscriber.locator( '#wp-submit' ).click();
	await expect( subscriber.locator( '#wpadminbar' ) ).toBeVisible();

	return { context, subscriber };
}

/**
 * What the screen says the current request's own details are.
 *
 * The proxy section prints them, and the self-ban guard is argued entirely from
 * them, so a test that wants to try banning itself has to ask the screen who it
 * thinks it is rather than guess.
 *
 * @param {import('@playwright/test').Page} page  Page showing the settings.
 * @param {string}                          label Row label, e.g. 'Your IP'.
 * @return {Promise<string>} The value beside that label.
 */
async function ownDetail( page, label ) {
	const row = page.locator( '#wpbody li' ).filter( { hasText: `${ label }:` } ).first();

	return ( await row.locator( 'strong' ).innerText() ).trim();
}

test.describe( 'The settings screen', () => {
	test.afterEach( async () => {
		// A ban list left behind does not merely change the next test's answer:
		// it serves the next test a 403 instead of a page.
		reset();
	} );

	test( 'the fixture really is a fresh install, and the screen offers six empty lists', async ( {
		page,
	} ) => {
		// The precondition the rest of the file leans on. With a row already in
		// place, "the entry saved" could be true before the test did anything.
		expect( getStoredOptions() ).toBe( false );

		await openSettings( page );

		for ( const key of LISTS ) {
			await expect( listField( page, key ) ).toHaveValue( '' );
		}

		await expect( page.locator( '#wp-ban-ip-header' ) ).toHaveValue( '' );
		await expect(
			page.locator( 'input[type="checkbox"][name="wp_ban_options[reverse_proxy]"]' ),
		).not.toBeChecked();

		// The screen tells the owner who it thinks they are, which is the whole
		// basis of the self-ban guard below.
		expect( await ownDetail( page, 'Your IP' ) ).not.toBe( '' );

		// The shipped message, which is a whole HTML document because it is
		// served instead of WordPress and has no theme behind it. On its own
		// tab, because a template is a wall of text that buries everything
		// above it.
		await openSettings( page, 'templates' );

		await expect( page.locator( '#wp-ban-message' ) ).toHaveValue( /You Are Banned\./ );
		await expect( page.locator( '#wp-ban-message' ) ).toHaveValue( /id="wp-ban-container"/ );
	} );

	test( 'the screen is three tabs, and each draws only what it owns', async ( { page } ) => {
		await openSettings( page, 'stats' );

		// Named for what they hold. "Ban Settings" would repeat the heading
		// directly above them.
		await expect( page.locator( '.nav-tab-wrapper' ) ).toHaveText( /StatsSettingsTemplates/ );

		// The counters, and nothing an owner could save by accident.
		await expect( page.locator( '.wp-list-table' ) ).toBeVisible();
		await expect( page.locator( '#wp-ban-message' ) ).toHaveCount( 0 );
		await expect( page.locator( '#wp-ban-list-ips' ) ).toHaveCount( 0 );

		await openSettings( page, 'settings' );

		await expect( page.locator( '#wp-ban-list-ips' ) ).toBeVisible();
		await expect( page.locator( '#wp-ban-message' ) ).toHaveCount( 0 );
		await expect( page.locator( '.wp-list-table' ) ).toHaveCount( 0 );

		await openSettings( page, 'templates' );

		await expect( page.locator( '#wp-ban-message' ) ).toBeVisible();
		await expect( page.locator( '#wp-ban-list-ips' ) ).toHaveCount( 0 );
		await expect( page.locator( '.wp-list-table' ) ).toHaveCount( 0 );
	} );

	test( 'the tab strip navigates, and the screen opens on Stats', async ( { page } ) => {
		await page.goto( SETTINGS_URL );

		// No tab in the URL: the counters are what somebody opens this screen
		// to look at, so they are what a bare link lands on.
		await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Stats' );

		await page.getByRole( 'link', { name: 'Templates', exact: true } ).click();

		await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Templates' );
		await expect( page.locator( '#wp-ban-message' ) ).toBeVisible();
	} );

	/**
	 * The regression the split invites, and it is silent.
	 *
	 * register_setting()'s sanitize_callback is handed only the fields the
	 * submitting form posted, so a sanitizer that returned just what it was
	 * given would blank the banned message the moment somebody edited a ban
	 * list -- with "Settings saved." on the screen and nothing to say the
	 * template had gone. Driven through the browser rather than through the
	 * option, because what does the damage is a real form post carrying one
	 * tab's fields and no others.
	 */
	test( 'saving one tab leaves the other tabs alone', async ( { page } ) => {
		const marker = unique( 'Keep me' );

		await openSettings( page, 'templates' );
		await page.locator( '#wp-ban-message' ).fill(
			`<html><body><div id="wp-ban-container"><p>${ marker }</p></div></body></html>`,
		);
		await saveSettings( page );

		// A save comes back to the tab it was submitted from, not to the first
		// one -- otherwise the notice lands on a screen showing other fields.
		await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Templates' );

		await openSettings( page, 'settings' );
		await fillLists( page, { ips: [ '203.0.113.7' ] } );
		await page.locator( '#wp-ban-ip-header' ).fill( 'HTTP_X_REAL_IP' );
		await saveSettings( page );

		await expect( page.locator( '.nav-tab-active' ) ).toHaveText( 'Settings' );

		const stored = getStoredOptions();

		expect( stored.message ).toContain( marker );
		expect( stored.lists.ips ).toEqual( [ '203.0.113.7' ] );

		// And back the other way: the Templates tab posts one field, and must
		// not empty the six lists it never showed.
		await openSettings( page, 'templates' );
		await page.locator( '#wp-ban-message' ).fill(
			'<html><body><div id="wp-ban-container"><p>Rewritten</p></div></body></html>',
		);
		await saveSettings( page );

		const after = getStoredOptions();

		expect( after.message ).toContain( 'Rewritten' );
		expect( after.lists.ips ).toEqual( [ '203.0.113.7' ] );
		expect( after.ip_header ).toBe( 'HTTP_X_REAL_IP' );
	} );

	test( 'all six lists save, one entry per line, and reach the row', async ( { page } ) => {
		await openSettings( page );

		await fillLists( page, {
			ips: [ '203.0.113.7', '203.0.113.*' ],
			ips_range: [ '203.0.113.10-203.0.113.20' ],
			hosts: [ '*.example.invalid' ],
			referers: [ 'https://*.spam.example/*' ],
			user_agents: [ 'E2EBadBot*' ],
			exclude_ips: [ '203.0.113.42' ],
		} );

		await saveSettings( page );

		// The far end, not the notice: a textarea is split on line breaks, the
		// blanks are dropped and duplicates collapse, so what is stored is a
		// different shape from what was typed and only the row says which.
		expect( getStoredOptions().lists ).toEqual( {
			ips: [ '203.0.113.7', '203.0.113.*' ],
			ips_range: [ '203.0.113.10-203.0.113.20' ],
			hosts: [ '*.example.invalid' ],
			referers: [ 'https://*.spam.example/*' ],
			user_agents: [ 'E2EBadBot*' ],
			exclude_ips: [ '203.0.113.42' ],
		} );

		// And the screen renders them back as lines, so an owner can edit what
		// they saved rather than retyping it.
		await openSettings( page );
		await expect( listField( page, 'ips' ) ).toHaveValue( '203.0.113.7\n203.0.113.*' );
	} );

	test( 'blank lines and duplicates are dropped rather than stored', async ( { page } ) => {
		await openSettings( page );

		await fillLists( page, {
			ips: [ '203.0.113.7', '', '   ', '203.0.113.7', '203.0.113.8' ],
		} );

		await saveSettings( page );

		// A duplicate is harmless to match against and untidy to look at; a
		// blank line is neither -- an empty pattern that reached matches_any()
		// would be asked about every request forever.
		expect( getStoredOptions().lists.ips ).toEqual( [ '203.0.113.7', '203.0.113.8' ] );
	} );

	test( 'an entry is stored as typed rather than HTML-escaped', async ( { page } ) => {
		await openSettings( page );

		await fillLists( page, { referers: [ 'https://spam.example/?a=1&b=2' ] } );
		await saveSettings( page );

		// Until 2.0.0 every entry was stored esc_html()'d, so a referrer with a
		// query string was kept as &amp; and could never match a real Referer
		// header -- and re-saving compounded it into &amp;amp;. These are
		// patterns matched against request data, not markup, and they are
		// escaped where they are printed instead.
		expect( getStoredOptions().lists.referers ).toEqual( [ 'https://spam.example/?a=1&b=2' ] );

		await openSettings( page );
		await expect( listField( page, 'referers' ) ).toHaveValue( 'https://spam.example/?a=1&b=2' );
	} );

	test( 'a range that is not two addresses of the same family is refused, and said so', async ( {
		page,
	} ) => {
		await openSettings( page );

		await fillLists( page, {
			ips_range: [
				'203.0.113.10-203.0.113.20',
				'not-a-range',
				'203.0.113.1-2001:db8::1',
				'203.0.113.5',
			],
		} );

		// A range that cannot be parsed matches nobody, so storing it would be a
		// ban an owner believes in and the plugin ignores. Rejecting it is only
		// half the answer; the other half is saying so, which is what the
		// settings error is for -- one per rejected entry, naming it.
		const notices = await saveExpectingError( page, 'wp_ban_bad_range' );

		await expect( notices ).toHaveCount( 3 );
		await expect( notices.first() ).toContainText( 'not two valid addresses' );

		expect( getStoredOptions().lists.ips_range ).toEqual( [ '203.0.113.10-203.0.113.20' ] );
	} );

	test( 'the trusted header is normalised, and nonsense is discarded', async ( { page } ) => {
		await openSettings( page );

		await page.locator( '#wp-ban-ip-header' ).fill( 'http_cf_connecting_ip' );
		await saveSettings( page );

		// The value becomes a $_SERVER key, so it is kept to the shape PHP gives
		// those keys rather than to whatever was typed. Upper case is the whole
		// normalisation, and without it a correctly-spelled header in the wrong
		// case would silently never be read.
		expect( getStoredOptions().ip_header ).toBe( 'HTTP_CF_CONNECTING_IP' );

		await openSettings( page );
		await page.locator( '#wp-ban-ip-header' ).fill( 'X-Forwarded-For; rm -rf /' );
		await saveSettings( page );

		// Anything outside [A-Za-z0-9_] is not a header name PHP would ever
		// produce, so it is dropped entirely rather than half-cleaned.
		expect( getStoredOptions().ip_header ).toBe( '' );
	} );

	test( 'the reverse proxy box saves, and can be unticked again', async ( { page } ) => {
		await openSettings( page );

		// By type as well as by name: a hidden input shares the checkbox's name,
		// which is what makes unticking it say anything at all.
		const box = page.locator( 'input[type="checkbox"][name="wp_ban_options[reverse_proxy]"]' );

		await expect(
			page.locator( 'input[type="hidden"][name="wp_ban_options[reverse_proxy]"]' ),
		).toHaveValue( '0' );

		await box.check();
		await saveSettings( page );

		expect( getStoredOptions().reverse_proxy ).toBe( true );

		// A checkbox that saves when ticked and cannot be unticked again is a
		// bug this collection has shipped before: an unchecked box posts nothing
		// at all, so a sanitizer that only reads the keys it was sent keeps the
		// old value forever. This screen's sanitizer does exactly that -- it
		// has to, because three tabs write one row -- so the hidden 0 above is
		// what keeps the box switchable.
		await openSettings( page );
		await expect( box ).toBeChecked();
		await box.uncheck();
		await saveSettings( page );

		expect( getStoredOptions().reverse_proxy ).toBe( false );
	} );

	test( 'the banned message saves, and an emptied one falls back to the shipped default', async ( {
		page,
	} ) => {
		const marker = unique( 'Go away' );

		await openSettings( page, 'templates' );

		await page.locator( '#wp-ban-message' ).fill(
			`<html><body><div id="wp-ban-container"><p>${ marker }</p></div></body></html>`,
		);
		await saveSettings( page );

		expect( getStoredOptions().message ).toContain( marker );

		// A site with no banned message would serve a blank page, which tells a
		// banned visitor nothing and an owner debugging it even less -- so an
		// empty box means "give me the shipped one back" rather than "store
		// nothing".
		await openSettings( page, 'templates' );
		await page.locator( '#wp-ban-message' ).fill( '   ' );
		await saveSettings( page );

		expect( getStoredOptions().message ).toContain( 'You Are Banned.' );
	} );

	test( 'a script in the banned message is stripped, and the document tags are kept', async ( {
		page,
	} ) => {
		await openSettings( page, 'templates' );

		await page.locator( '#wp-ban-message' ).fill(
			'<html><head><title>Gone</title></head><body><div id="wp-ban-container">' +
				'<p>Go away</p><script>window.__pwned = 1;</script></div></body></html>',
		);
		await saveSettings( page );

		const stored = getStoredOptions().message;

		// The allow-list is wp_kses_post() widened with the handful of tags a
		// standalone document needs -- html, head, body, meta, title, style --
		// because the message is served instead of WordPress and has no theme
		// behind it. Widened, not opened: a script is still a script.
		expect( stored ).toContain( '<title>Gone</title>' );
		expect( stored ).toContain( '<div id="wp-ban-container">' );
		expect( stored ).not.toContain( '<script' );
	} );

	test( 'Restore Default Template puts the shipped message back', async ( { page } ) => {
		await openSettings( page, 'templates' );

		const textarea = page.locator( '#wp-ban-message' );

		await textarea.fill( 'something else entirely' );
		await page.getByRole( 'button', { name: 'Restore Default Template' } ).click();

		// The default comes down through wp_localize_script(), so this is also
		// the guard on the localised value still being the template rather than
		// something that lost its markup on the way.
		await expect( textarea ).toHaveValue( /id="wp-ban-container"/ );
		await expect( textarea ).toHaveValue( /You Are Banned\./ );

		await saveSettings( page );

		expect( getStoredOptions().message ).toContain( 'You Are Banned.' );
	} );

	test( 'the preview renders the message with this request substituted in', async ( { page } ) => {
		setOptions( {
			message:
				'<html><body><div id="wp-ban-container"><p>You are %USER_IP%, seen %USER_ATTEMPTS_COUNT% times</p></div></body></html>',
		} );

		// Who the screen thinks this request is comes from the Settings tab,
		// where the proxy section prints it; the template and its preview live
		// on the next tab along.
		await openSettings( page, 'settings' );

		const ip = await ownDetail( page, 'Your IP' );

		await openSettings( page, 'templates' );

		// By the attribute the script binds to, not by the label. The label is
		// the thing under test -- it swaps to "Show Template" and back -- so a
		// locator that matched on it would stop resolving the moment the button
		// did its job, and report the success as a missing element.
		const button = page.locator( '[data-wp-ban-action="preview"]' );

		await button.click();

		// The preview is an AJAX round trip whose response is a whole HTML
		// document, which the script parses and lifts the container out of.
		// PHPUnit can call preview() and check the string; only a browser can
		// say whether what came back was put on the screen.
		const preview = page.locator( '#wp-ban-preview' );

		await expect( preview ).toBeVisible();
		await expect( preview ).toContainText( `You are ${ ip }` );
		await expect( page.locator( '#wp-ban-message' ) ).toBeHidden();

		// And it toggles back, with the button relabelled both ways -- a button
		// that said "Show Preview" while showing one would be its own bug.
		await expect( button ).toHaveText( 'Show Template' );

		await button.click();

		await expect( preview ).toBeHidden();
		await expect( page.locator( '#wp-ban-message' ) ).toBeVisible();
		await expect( button ).toHaveText( 'Show Preview' );
	} );

	test( 'the preview endpoint refuses a subscriber', async ( { page, requestUtils } ) => {
		const { context, subscriber } = await loginAsSubscriber( page, requestUtils );

		try {
			// wp_ajax_* fires for every authenticated role, subscribers
			// included, so the hook alone is not an authorisation check --
			// which is exactly the shape of AJAX handler that ships open. The
			// nonce is deliberately absent here: a request that gets past the
			// capability check would fail on the referer instead, and this test
			// would then pass for the wrong reason.
			const response = await subscriber.request.post( '/wp-admin/admin-ajax.php', {
				form: { action: 'wp_ban_preview' },
			} );

			expect( response.status() ).toBe( 403 );
		} finally {
			await context.close();
		}
	} );

	test( 'the Plugins screen carries a Settings link that goes to the screen', async ( { page } ) => {
		await page.goto( PLUGINS_URL );

		const row = page.locator( 'tr[data-slug="wp-ban"]' );

		await expect( row ).toHaveCount( 1 );
		await row.getByRole( 'link', { name: 'Settings' } ).click();

		await expect( page.getByRole( 'heading', { name: 'Ban Options' } ) ).toBeVisible();
	} );

	test( 'the success notice is printed once, not twice', async ( { page } ) => {
		await openSettings( page );
		await saveSettings( page );

		// A page registered with add_options_page() has options-head.php run
		// ahead of it, and that file already calls settings_errors(). A screen
		// that calls it again renders every queued notice a second time, and
		// common.js then relocates both into .wrap so they land one under the
		// other in what looks like the plugin's own markup. This screen
		// deliberately does not call it -- this is the guard on that.
		await expect( page.locator( '#setting-error-settings_updated' ) ).toHaveCount( 1 );
	} );

	test( 'a subscriber gets neither the menu item nor the screen, and an administrator gets both', async ( {
		page,
		requestUtils,
	} ) => {
		// Both directions on purpose. "The subscriber sees nothing" passes with
		// the plugin deactivated, because there is nothing to see either way;
		// the administrator half is what proves the gate is the capability.
		await page.goto( '/wp-admin/options-general.php' );
		await expect( page.locator( '#adminmenu' ) ).toContainText( 'WP-Ban' );

		await openSettings( page );

		const { context, subscriber } = await loginAsSubscriber( page, requestUtils );

		try {
			await subscriber.goto( '/wp-admin/index.php' );
			await expect( subscriber.locator( '#adminmenu' ).getByText( 'WP-Ban' ) ).toHaveCount( 0 );

			await subscriber.goto( SETTINGS_URL );
			await expect( subscriber.locator( 'body' ) ).toContainText(
				/not allowed to access this page/,
			);
		} finally {
			await context.close();
		}
	} );

	test( 'the capability filter is what decides, and it decides the menu, the screen and the preview', async ( {
		page,
		requestUtils,
	} ) => {
		// The filter is asked with three different contexts -- 'screen',
		// 'stats' and 'preview' -- so that a site delegating ban management
		// cannot accidentally open the form while leaving the preview shut, or
		// the other way about. One answer has to move all of them.
		setFixtureAnswer( 'capability', 'read' );

		const { context, subscriber } = await loginAsSubscriber( page, requestUtils );

		try {
			await subscriber.goto( '/wp-admin/index.php' );
			await expect( subscriber.locator( '#adminmenu' ) ).toContainText( 'WP-Ban' );

			await subscriber.goto( SETTINGS_URL );
			await expect( subscriber.getByRole( 'heading', { name: 'Ban Options' } ) ).toBeVisible();

			// The forms themselves, not just the wrapper: render() returns early
			// on a failed capability check, which would leave the heading
			// printed by nothing and every field absent. Both tabs, because the
			// gate is on the screen rather than on any one of them.
			await subscriber.goto( tabUrl( 'settings' ) );
			await expect( subscriber.locator( '#wp-ban-list-ips' ) ).toBeAttached();

			await subscriber.goto( tabUrl( 'templates' ) );
			await expect( subscriber.locator( '#wp-ban-message' ) ).toBeAttached();

			// And the preview, which is checked separately and could have been
			// left behind.
			const response = await subscriber.request.post( '/wp-admin/admin-ajax.php', {
				form: { action: 'wp_ban_preview' },
			} );

			// Past the capability gate and stopped by the nonce instead, which
			// is a different refusal from the 403 the test above gets: -1 with a
			// 403 is check_ajax_referer()'s answer, and reaching it at all is the
			// evidence the capability check let this user through.
			expect( await response.text() ).toContain( '-1' );
		} finally {
			// Inside a finally, because a filter left answering 'read' would
			// hand this screen to a subscriber for the rest of the run and
			// quietly invalidate the test above it.
			clearFixtureAnswer( 'capability' );
			await context.close();
		}
	} );
} );

test.describe( 'The self-ban guard', () => {
	test.afterEach( async () => {
		reset();
	} );

	test( 'an entry matching the administrator saving it is dropped, and said so', async ( {
		page,
	} ) => {
		await openSettings( page );

		const ip = await ownDetail( page, 'Your IP' );
		const agent = await ownDetail( page, 'Your user agent' );
		const site = await ownDetail( page, 'Site URL' );

		await fillLists( page, {
			ips: [ ip, '203.0.113.7' ],
			ips_range: [ '203.0.113.10-203.0.113.20' ],
			user_agents: [ agent, 'E2EBadBot*' ],
			referers: [ site, 'https://spam.example/*' ],
		} );

		// The owner is told, rather than left to wonder why the entry they typed
		// is not in the box any more -- one notice per dropped entry.
		const notices = await saveExpectingError( page, 'wp_ban_self' );

		await expect( notices ).toHaveCount( 3 );
		await expect( notices.first() ).toContainText( 'so it was not added to the ban list' );

		const lists = getStoredOptions().lists;

		// Until 2.0.0 this guard only ran when the current user's login was
		// literally "admin", so everybody else could lock themselves out of
		// their own site with one save. The harmless entries beside each one
		// have to survive, or the guard is just refusing to save.
		expect( lists.ips ).toEqual( [ '203.0.113.7' ] );
		expect( lists.user_agents ).toEqual( [ 'E2EBadBot*' ] );
		expect( lists.referers ).toEqual( [ 'https://spam.example/*' ] );
		expect( lists.ips_range ).toEqual( [ '203.0.113.10-203.0.113.20' ] );
	} );

	test( 'a range that would swallow the administrator is dropped and a neighbouring one is not', async ( {
		page,
	} ) => {
		await openSettings( page );

		const ip = await ownDetail( page, 'Your IP' );

		// A range is a different check from an address: an owner banning a whole
		// netblock will not notice their own address inside it, which is exactly
		// when this matters most. The range is built around whatever address the
		// screen reports, so it is the owner's own however the container is
		// addressed today.
		await fillLists( page, {
			ips_range: [ `${ ip }-${ ip }`, '203.0.113.10-203.0.113.20' ],
		} );

		const notices = await saveExpectingError( page, 'wp_ban_self' );

		await expect( notices.first() ).toContainText( 'falls inside the range' );

		expect( getStoredOptions().lists.ips_range ).toEqual( [ '203.0.113.10-203.0.113.20' ] );
	} );

	test( 'the protect-self filter is what drops it, and without it the ban takes effect', async ( {
		browser,
		page,
	} ) => {
		await openSettings( page );

		// The dangerous direction, kept safe by aiming it at a browser that is
		// not the one running the suite. This context has an administrator's
		// session and a user agent of its own, so with the guard switched off it
		// is the one that gets banned -- and the administrator context every
		// other test uses carries a different agent and is untouched.
		const agent = unique( 'E2ESelfBanner' );
		const context = await browser.newContext( {
			// The administrator's session, copied rather than re-logged-in, so
			// this context differs from the suite's only in its user agent --
			// which is the one thing the ban below matches on.
			storageState: await page.context().storageState(),
			userAgent: `${ agent }/1.0`,
		} );
		const other = await context.newPage();

		try {
			setFixtureAnswer( 'protect_self', false );

			// openSettings(), which goes to the Settings *tab*. Going to
			// SETTINGS_URL lands on Stats -- that tab is first and is the
			// default -- and Stats carries no list fields, so the fillLists()
			// below sat on fill( '#wp-ban-list-ips' ) until the 60s test
			// timeout. The heading assertion did not catch it: "Ban Options" is
			// the h1 of all three tabs. openSettings() also asserts which tab
			// is active, which is the check that was missing.
			await openSettings( other );

			// This browser's own user agent, typed into its own ban list, with
			// the guard filtered off.
			await fillLists( other, { user_agents: [ `${ agent }/1.0` ] } );
			await other.getByRole( 'button', { name: 'Save Changes' } ).click();

			// The redirect back to the settings screen is served the ban page
			// instead, which is exactly what the guard normally exists to
			// prevent. Waiting on this also waits out the save.
			await expect( other.locator( '#wp-ban-container' ) ).toContainText( 'You Are Banned' );

			// The row kept the entry, which is the filter being honoured. A
			// plugin that dropped it and 403'd for some other reason would fail
			// here rather than pass quietly.
			expect( getStoredOptions().lists.user_agents ).toEqual( [ `${ agent }/1.0` ] );

			// And the status is the ban's, not a coincidence.
			await expectBanned( other, SETTINGS_URL );

			// Meanwhile the suite's own administrator is unaffected: a different
			// user agent, and no other list matches it. Which is also what makes
			// this test safe to run at all.
			await openSettings( page );
			await expect( listField( page, 'user_agents' ) ).toHaveValue( `${ agent }/1.0` );
		} finally {
			// Belt and braces on top of the shared afterEach: this is the one
			// test in the suite that deliberately leaves a live ban behind, and
			// WP-CLI is exempt from the check, so this always runs.
			wpEval( "delete_option( 'wp_ban_options' ); echo '<<<done>>>';" );
			clearFixtureAnswer( 'protect_self' );
			await context.close();
		}
	} );

	test( 'the guard does not stop an owner banning somebody else through the same lists', async ( {
		browser,
		page,
	} ) => {
		await openSettings( page );

		// The whole point of the guard is that it drops only what matches the
		// person saving. A guard that was slightly too eager would be invisible
		// on the screen and would quietly refuse to ban anybody, so the far end
		// is a visitor who really is turned away by an entry typed into the form
		// rather than written into the row.
		await fillLists( page, { ips: [ '203.0.113.7' ] } );
		await page.locator( '#wp-ban-ip-header' ).fill( IP_HEADER );
		await saveSettings( page );

		const { context, page: visitor } = await asVisitor( browser, { ip: '203.0.113.7' } );

		try {
			await expectBanned( visitor, '/' );
		} finally {
			await context.close();
		}
	} );
} );
