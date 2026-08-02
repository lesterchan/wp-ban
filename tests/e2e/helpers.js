/**
 * Shared steps for the WP-Ban end-to-end suite.
 *
 * This plugin is the one in the collection that can lock the test run out of the
 * site it is testing. Its check hangs off `init`, which fires for wp-admin and
 * admin-ajax just as it does for the front end, so an entry that matches the
 * browser running these tests turns every later assertion into a 403 -- and the
 * screen that would undo it is behind the same 403.
 *
 * Two things keep that from happening, and every test here leans on both.
 *
 * The first is that a banned visitor is never this browser as WordPress sees it.
 * A test that needs somebody banned invents them: a browser context of its own
 * with its own user agent, its own Referer, and an address supplied through the
 * plugin's own trusted-header setting. The administrator's context sends none of
 * those, so it resolves to REMOTE_ADDR and matches nothing.
 *
 * The second is that WP-CLI is exempt from the check -- WP_Ban::check() returns
 * early for it, so that a misconfigured ban cannot take an owner's recovery
 * tools away. That is what makes wpEval() below a reliable way out: however
 * thoroughly a test bans the browser, the clean-up still runs.
 */

const { execFileSync } = require( 'child_process' );
const path = require( 'path' );

const { expect } = require( '@wordpress/e2e-test-utils-playwright' );

/** The plugin root, which is where wp-env reads .wp-env.json from. */
const PLUGIN_ROOT = path.join( __dirname, '../..' );

const SETTINGS_URL = '/wp-admin/options-general.php?page=wp-ban';
const PLUGINS_URL = '/wp-admin/plugins.php';

/**
 * The screen's three tabs, by the slug the URL names them with.
 *
 * One page, one settings group and one option row behind all three -- the tab
 * decides only which sections are drawn. Stats is first and is the default,
 * because the counters are what somebody opens this screen to look at.
 */
const TABS = {
	stats: 'Stats',
	settings: 'Settings',
	templates: 'Templates',
};

/**
 * The address of one tab.
 *
 * @param {string} tab Tab slug.
 * @return {string} The URL.
 */
function tabUrl( tab ) {
	return `${ SETTINGS_URL }&tab=${ tab }`;
}

/** The option row every setting lives in. */
const OPTION = 'wp_ban_options';

/** The option row holding the two upgrade markers. */
const VERSION_OPTION = 'wp_ban_version';

/** The option row holding the attempt counters. */
const STATS_OPTION = 'wp_ban_stats';

/** The plugin file, as WordPress names it on the Plugins screen. */
const PLUGIN_FILE = 'wp-ban/wp-ban.php';

/**
 * The header the suite hands the plugin a made-up address through.
 *
 * Named rather than one of the usual proxy headers on purpose. The plugin's
 * narrowest opt-in is "trust exactly this header and nothing else", so a suite
 * that used it is testing the safest configuration a real site can have -- and a
 * header nothing else sends cannot be set by accident on an admin request.
 */
const IP_HEADER = 'HTTP_X_E2E_IP';

/** The same header as a browser spells it. */
const IP_HEADER_NAME = 'X-E2E-IP';

/**
 * Addresses the suite bans.
 *
 * All from the documentation ranges reserved by RFC 5737, so none of them can
 * ever be the address of anything real -- including, one day, this machine.
 */
const FAKE = {
	banned: '203.0.113.7',
	other: '198.51.100.4',
	inRange: '203.0.113.15',
	outOfRange: '203.0.113.99',
	excluded: '203.0.113.42',
};

/** The six ban lists, by the key they are stored under. */
const LISTS = [ 'ips', 'ips_range', 'hosts', 'referers', 'user_agents', 'exclude_ips' ];

/**
 * Run PHP inside the tests environment and hand back what it printed.
 *
 * The code is base64'd rather than passed as itself: a banned user agent holding
 * quotes, angle brackets and a script tag is exactly the sort of string that
 * arrives at the other end subtly different, and a fixture that is not the
 * payload byte for byte proves nothing about escaping it.
 *
 * This is also the suite's way out of a ban it set itself: WP_Ban::check()
 * returns early under WP-CLI, so this keeps working when nothing else does.
 *
 * @param {string} code PHP to evaluate, without an opening tag.
 * @return {string} Whatever the code echoed between its markers.
 */
function wpEval( code ) {
	const encoded = Buffer.from( code, 'utf8' ).toString( 'base64' );

	const output = execFileSync(
		'npx',
		[
			'--yes',
			'@wordpress/env',
			'run',
			'tests-cli',
			'wp',
			'eval',
			`eval( base64_decode( '${ encoded }' ) );`,
		],
		{ cwd: PLUGIN_ROOT, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] },
	);

	// wp-env prints its own progress around the command's output, so the code
	// wraps what it wants to return in markers rather than the caller trying to
	// tell the two apart by position.
	const matched = output.match( /<<<([\s\S]*?)>>>/ );

	return matched ? matched[ 1 ] : '';
}

/**
 * Write the settings row exactly as given, without going near the sanitizer.
 *
 * WP-CLI never runs register_setting(), so nothing filters this on the way in --
 * which is the point twice over. It is the row a compromised install has, and it
 * is the only way to store an entry the sanitizer would have dropped for being
 * the administrator's own.
 *
 * Only the keys handed in are stored; WP_Ban_Options::get() merges the defaults
 * and normalises the nested lists on read, so a partial row is a shape real
 * installs have.
 *
 * @param {Object} options Option keys to store.
 * @return {void}
 */
function setOptions( options ) {
	const data = Buffer.from( JSON.stringify( options ), 'utf8' ).toString( 'base64' );

	wpEval(
		`update_option( '${ OPTION }', json_decode( base64_decode( '${ data }' ), true ) );
		echo '<<<done>>>';`,
	);
}

/**
 * The settings row as the database holds it, before any defaults are merged in.
 *
 * @return {Object|false} The stored array, or false when there is no row.
 */
function getStoredOptions() {
	return JSON.parse( wpEval( `echo '<<<' . wp_json_encode( get_option( '${ OPTION }' ) ) . '>>>';` ) );
}

/**
 * Store a set of ban lists and, optionally, the header they are matched through.
 *
 * The shape every blocking test starts from, in one container round trip: the
 * lists to match on, and the trusted-header setting that lets a test hand the
 * plugin an address without touching the one this machine really has.
 *
 * @param {Object}  lists      Ban lists, keyed as the option stores them.
 * @param {boolean} [byHeader] Whether to trust the suite's own address header.
 * @return {void}
 */
function ban( lists, byHeader = true ) {
	setOptions( {
		reverse_proxy: false,
		ip_header: byHeader ? IP_HEADER : '',
		lists: {
			ips: [],
			ips_range: [],
			hosts: [],
			referers: [],
			user_agents: [],
			exclude_ips: [],
			...lists,
		},
	} );
}

/**
 * Put the plugin back into a state that bans nobody.
 *
 * Called from afterEach everywhere, because a ban list is global state with
 * teeth: left behind, it does not merely change the next test's assertions, it
 * serves the next test a 403 instead of a page.
 *
 * The stats row goes too, so a test that counts attempts starts from zero
 * rather than from whatever the run so far has accumulated.
 *
 * @return {void}
 */
function reset() {
	wpEval(
		`delete_option( '${ OPTION }' );
		delete_option( '${ STATS_OPTION }' );
		delete_option( 'wp_ban_e2e_last_denial' );
		foreach ( array( 'enabled', 'ipaddress', 'trust_proxy', 'status_code', 'protect_self', 'capability' ) as $answer ) {
			delete_option( 'wp_ban_e2e_' . $answer );
		}
		echo '<<<done>>>';`,
	);
}

/**
 * Set one of the answers the fixture mu-plugin gives the plugin's filters.
 *
 * The answer is stored as its JSON text, never as its own type. Storing the
 * boolean false writes nothing at all -- update_option() reads the old value
 * first, gets false because the row is absent, sees it matches what it was
 * given and returns without touching the database -- so every "switch this
 * filter off" answer would have been silently invisible. The mu-plugin decodes
 * on the way back out.
 *
 * @param {string} name  Filter name, without the wp_ban_e2e_ prefix.
 * @param {*}      value Anything JSON can carry.
 * @return {void}
 */
function setFixtureAnswer( name, value ) {
	const data = Buffer.from( JSON.stringify( value ), 'utf8' ).toString( 'base64' );

	wpEval(
		`update_option( 'wp_ban_e2e_' . '${ name }', base64_decode( '${ data }' ), false );
		echo '<<<done>>>';`,
	);
}

/**
 * Stop one of the fixture filters answering.
 *
 * @param {string} name Filter name, without the wp_ban_e2e_ prefix.
 * @return {void}
 */
function clearFixtureAnswer( name ) {
	wpEval( `delete_option( 'wp_ban_e2e_' . '${ name }' ); echo '<<<done>>>';` );
}

/**
 * What the wp_ban_denied action was told, on the last request that fired it.
 *
 * @return {Object|false} The recorded ip and status, or false if it never fired.
 */
function lastDenial() {
	return JSON.parse(
		wpEval( "echo '<<<' . wp_json_encode( get_option( 'wp_ban_e2e_last_denial' ) ) . '>>>';" ),
	);
}

/**
 * The attempt counters, as the database holds them.
 *
 * @return {Object|false} The stored array, or false when nothing has been recorded.
 */
function getStats() {
	return JSON.parse(
		wpEval( `echo '<<<' . wp_json_encode( get_option( '${ STATS_OPTION }' ) ) . '>>>';` ),
	);
}

/**
 * Write the attempt counters directly.
 *
 * The stats table needs more rows than a test could plausibly earn by being
 * banned twenty-one times, so the counters that prove pagination and sorting are
 * put there rather than accumulated.
 *
 * @param {Object} stats The 'users' map and the 'count' total.
 * @return {void}
 */
function setStats( stats ) {
	const data = Buffer.from( JSON.stringify( stats ), 'utf8' ).toString( 'base64' );

	wpEval(
		`update_option( '${ STATS_OPTION }', json_decode( base64_decode( '${ data }' ), true ), false );
		echo '<<<done>>>';`,
	);
}

/**
 * What the plugin makes of an address's reverse DNS, asked of the site itself.
 *
 * The host-name list is matched against gethostbyaddr()'s answer, and what that
 * answer is for a made-up address depends on the resolver the container happens
 * to have. Asking rather than assuming is what keeps the host-name test from
 * being a test of somebody's DNS.
 *
 * @param {string} ip Address to resolve.
 * @return {string} The host name, which is the address itself when there is no PTR record.
 */
function hostnameOf( ip ) {
	return wpEval( `echo '<<<' . WP_Ban_IP::hostname( '${ ip }' ) . '>>>';` );
}

/**
 * The upgrade markers, as the database holds them.
 *
 * @return {Object|false} The stored array, or false when there is no row.
 */
function getVersionRow() {
	return JSON.parse(
		wpEval( `echo '<<<' . wp_json_encode( get_option( '${ VERSION_OPTION }' ) ) . '>>>';` ),
	);
}

/**
 * The version numbers the running code expects to find stamped.
 *
 * Read from the install rather than written down here, so a version bump that
 * changed nothing about the migration does not fail a test about the migration.
 *
 * @return {{plugin: string, db: string}} The two markers.
 */
function runningVersions() {
	return JSON.parse(
		wpEval(
			`echo '<<<' . wp_json_encode( array(
				'plugin' => WP_BAN_VERSION,
				'db'     => WP_BAN_DB_VERSION,
			) ) . '>>>';`,
		),
	);
}

/**
 * Deactivate and reactivate the plugin, which is the path that fires activate().
 *
 * The other entry point into the upgrade routine, and genuinely a different one:
 * updating through the Plugins screen never fires the activation hook and leaves
 * admin_init to run the migration alone.
 *
 * @return {void}
 */
function reactivatePlugin() {
	wpEval(
		`require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( '${ PLUGIN_FILE }' );
		activate_plugin( '${ PLUGIN_FILE }' );
		echo '<<<done>>>';`,
	);
}

/**
 * Put the plugin back on, whatever a test left behind.
 *
 * @return {void}
 */
function ensurePluginActive() {
	wpEval(
		`require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! is_plugin_active( '${ PLUGIN_FILE }' ) ) {
			activate_plugin( '${ PLUGIN_FILE }' );
		}
		echo '<<<done>>>';`,
	);
}

/**
 * A logged-out browser pretending to be somebody in particular.
 *
 * The identity is entirely in the headers, which is what keeps a ban aimed at it
 * from touching the administrator's own context: a different user agent, a
 * Referer of its own, and an address handed over through the header the plugin
 * has been told to trust.
 *
 * @param {import('@playwright/test').Browser} browser       The browser under test.
 * @param {Object}                             who           Who to be.
 * @param {string}                             [who.ip]      Address to claim through the trusted header.
 * @param {string}                             [who.agent]   User agent to send.
 * @param {string}                             [who.referer] Referer to send.
 * @return {Promise<Object>} The new `context` and its `page`.
 */
async function asVisitor( browser, who = {} ) {
	const headers = {};

	if ( who.ip ) {
		headers[ IP_HEADER_NAME ] = who.ip;
	}

	if ( who.referer ) {
		headers.Referer = who.referer;
	}

	Object.assign( headers, who.headers || {} );

	const context = await browser.newContext( {
		// Logged out, because a banned visitor is not a user of the site -- and
		// because inheriting the suite's administrator session would put the
		// admin bar and its requests on every page this context loads.
		storageState: { cookies: [], origins: [] },
		userAgent: who.agent,
		extraHTTPHeaders: headers,
	} );

	return { context, page: await context.newPage() };
}

/**
 * Assert a request was turned away, and hand back the response.
 *
 * The status is the half a text assertion misses. Until 2.0.0 the ban page was
 * served with 200 OK, so caches stored it as the site's real content and search
 * engines indexed it; the message reading "You Are Banned." was true and the
 * response was still wrong.
 *
 * @param {import('@playwright/test').Page} page   Page to navigate.
 * @param {string}                          url    Where to go.
 * @param {number}                          status Status expected.
 * @param {string}                          text   Something the ban page must say.
 * @return {Promise<import('@playwright/test').Response>} The response.
 */
async function expectBanned( page, url, status = 403, text = 'You Are Banned' ) {
	const response = await page.goto( url );

	expect( response.status() ).toBe( status );
	await expect( page.locator( '#wp-ban-container' ) ).toContainText( text );

	return response;
}

/**
 * Assert a request went through to the site.
 *
 * The other half of every blocking test. "The ban page is absent" is true of a
 * plugin that has been deactivated, so the pair is what proves the list is being
 * read rather than ignored.
 *
 * @param {import('@playwright/test').Page} page Page to navigate.
 * @param {string}                          url  Where to go.
 * @return {Promise<void>} Resolves once the page has loaded.
 */
async function expectAllowed( page, url ) {
	const response = await page.goto( url );

	expect( response.status() ).toBe( 200 );
	await expect( page.locator( '#wp-ban-container' ) ).toHaveCount( 0 );
}

/**
 * Open one tab of the settings screen.
 *
 * Defaults to Settings rather than to the screen's own default, because that is
 * the tab almost every test here is about -- and because asking for a tab by
 * name is what makes a test that lands on the wrong one fail here, once, rather
 * than twenty assertions later looking like a missing field.
 *
 * @param {import('@playwright/test').Page} page  Page under test.
 * @param {string}                          [tab] Tab slug.
 * @return {Promise<void>} Resolves once the screen is up on that tab.
 */
async function openSettings( page, tab = 'settings' ) {
	await page.goto( tabUrl( tab ) );

	await expect( page.getByRole( 'heading', { name: 'Ban Settings' } ) ).toBeVisible();

	// The tab strip is navigation, so the active tab is the one that says so --
	// not merely the one whose fields happen to be on screen.
	await expect( page.locator( '.nav-tab-active' ) ).toHaveText( TABS[ tab ] );
}

/**
 * Save the settings form and wait for WordPress to confirm it.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @return {Promise<void>} Resolves once "Settings saved." is on screen.
 */
async function saveSettings( page ) {
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();

	// The notice rather than the redirect: options.php sends the browser back
	// whether or not anything was written, so arriving here again says nothing.
	await expect( page.locator( '#setting-error-settings_updated' ) ).toContainText( 'Settings saved.' );
}

/**
 * Save the settings form when the sanitizer is expected to complain.
 *
 * options.php only adds "Settings saved." when nothing else queued an error, so
 * a save that queues one never says it saved -- and waiting for that notice is
 * waiting for something that will not arrive. Waiting for the complaint itself
 * is both the right signal and the assertion the test wanted anyway.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @param {string}                          code The settings error's code.
 * @return {Promise<import('@playwright/test').Locator>} The notices with that code.
 */
async function saveExpectingError( page, code ) {
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();

	const notices = page.locator( `#setting-error-${ code }` );

	await expect( notices.first() ).toBeVisible();

	// And not the success notice, which options.php deliberately withholds when
	// anything else complained. A screen showing both would be telling an owner
	// their entry saved and was rejected in the same breath.
	await expect( page.locator( '#setting-error-settings_updated' ) ).toHaveCount( 0 );

	return notices;
}

/**
 * One of the six ban list textareas.
 *
 * @param {import('@playwright/test').Page} page Page showing the settings.
 * @param {string}                          key  List key.
 * @return {import('@playwright/test').Locator} The textarea.
 */
function listField( page, key ) {
	return page.locator( `#wp-ban-list-${ key }` );
}

/**
 * Fill every list textarea, so no test depends on what was in the others.
 *
 * The form posts all six on every save, and the sanitizer reads all six, so a
 * test that typed into one and left the rest is still asserting on the other
 * five whether it meant to or not.
 *
 * @param {import('@playwright/test').Page} page  Page showing the settings.
 * @param {Object}                          lists Lines for each list, by key.
 * @return {Promise<void>} Resolves once every textarea holds what it should.
 */
async function fillLists( page, lists = {} ) {
	for ( const key of LISTS ) {
		const value = lists[ key ];

		await listField( page, key ).fill( Array.isArray( value ) ? value.join( '\n' ) : value || '' );
	}
}

/**
 * A string no earlier run can have used.
 *
 * @param {string} base What it should say.
 * @return {string} That, plus enough to tell this run from the last.
 */
function unique( base ) {
	return `${ base }-${ Date.now().toString( 36 ) }`;
}

module.exports = {
	FAKE,
	IP_HEADER,
	IP_HEADER_NAME,
	LISTS,
	OPTION,
	PLUGINS_URL,
	PLUGIN_FILE,
	SETTINGS_URL,
	STATS_OPTION,
	TABS,
	VERSION_OPTION,
	asVisitor,
	ban,
	clearFixtureAnswer,
	ensurePluginActive,
	expectAllowed,
	expectBanned,
	fillLists,
	getStats,
	getStoredOptions,
	getVersionRow,
	hostnameOf,
	lastDenial,
	listField,
	openSettings,
	reactivatePlugin,
	reset,
	runningVersions,
	saveExpectingError,
	saveSettings,
	setFixtureAnswer,
	setOptions,
	setStats,
	tabUrl,
	unique,
	wpEval,
};
