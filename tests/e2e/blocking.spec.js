/**
 * The ban itself, from a browser that really is turned away.
 *
 * This is the half no unit test reaches. WP_Ban_IP::matches_wildcard() can be
 * called directly and asserted on all day; what cannot be is whether a request
 * arriving at the site is stopped, whether the response carries the status a
 * cache and a search engine will act on, and whether the visitor who is not on
 * any list still gets their page.
 *
 * Every test here is a pair. "The visitor was banned" on its own is worth
 * nothing -- a deactivated plugin bans nobody, and so does a broken one, and a
 * 403 from something else looks the same from here -- so each list is proved by
 * a visitor who matches being stopped and a visitor who does not being let
 * through, in the same test.
 *
 * The visitors are invented, never this browser. Their address arrives through
 * the plugin's own trusted-header setting, their user agent and Referer are set
 * on a context of their own, and every address used is from the RFC 5737
 * documentation ranges, which cannot ever belong to anything real. The
 * administrator's context sends none of those headers, so it resolves to
 * REMOTE_ADDR and matches nothing here.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	FAKE,
	IP_HEADER,
	asVisitor,
	ban,
	clearFixtureAnswer,
	expectAllowed,
	expectBanned,
	getStats,
	hostnameOf,
	lastDenial,
	reset,
	setFixtureAnswer,
	setOptions,
	unique,
} = require( './helpers.js' );

/** Somewhere on the site for a visitor to be turned away from. */
let postLink;

test.describe( 'A banned visitor', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();

		const post = await requestUtils.createPost( {
			title: 'Something to read',
			content: 'The site is open to everybody who is not banned.',
			status: 'publish',
		} );

		postLink = post.link;
	} );

	test.afterEach( async () => {
		// Non-negotiable in this suite. A ban list is not merely state that
		// changes the next test's answer: left in place it serves the next test
		// a 403 instead of a page, and the screen that would clear it is behind
		// the same 403. WP-CLI is exempt from the check, which is what makes
		// this able to run at all.
		reset();
	} );

	test( 'the fixture really works: the same page is served to one visitor and refused to another', async ( {
		browser,
	} ) => {
		ban( { ips: [ FAKE.banned ] } );

		// The precondition the whole file leans on: that the trusted-header
		// setting is what decides who the plugin thinks is calling. If the
		// header were ignored, both visitors below would resolve to the same
		// container address, and every test here would be asserting about a
		// distinction the site never made.
		const banned = await asVisitor( browser, { ip: FAKE.banned } );
		const allowed = await asVisitor( browser, { ip: FAKE.other } );

		try {
			await expectBanned( banned.page, postLink );
			await expectAllowed( allowed.page, postLink );

			// And the site is still a site for the second one, rather than a
			// page that merely lacks the ban container.
			await expect( allowed.page.locator( 'body' ) ).toContainText(
				'open to everybody who is not banned',
			);
		} finally {
			await banned.context.close();
			await allowed.context.close();
		}
	} );

	test( 'the ban page is a 403, and the status can be filtered back to 200', async ( { browser } ) => {
		ban( { ips: [ FAKE.banned ] } );

		const { context, page } = await asVisitor( browser, { ip: FAKE.banned } );

		try {
			await expectBanned( page, postLink, 403 );

			// Until 2.0.0 this was 200 OK, so caches stored the ban page as the
			// site's real content and search engines indexed it. The filter is
			// documented as the way back to that for a site that wants it, which
			// makes it the one place the old behaviour is still reachable -- and
			// the only way to prove the 403 is a decision rather than an
			// accident.
			setFixtureAnswer( 'status_code', 200 );

			await expectBanned( page, postLink, 200 );
		} finally {
			clearFixtureAnswer( 'status_code' );
			await context.close();
		}
	} );

	test( 'a wildcard bans the range it covers and nothing outside it', async ( { browser } ) => {
		ban( { ips: [ '203.0.113.*' ] } );

		const inside = await asVisitor( browser, { ip: FAKE.banned } );
		const outside = await asVisitor( browser, { ip: FAKE.other } );

		try {
			// 203.0.113.7 matches, 198.51.100.4 does not. The pattern is
			// anchored at both ends, so a wildcard is a wildcard and not a
			// substring search -- which is what "192.168.1.*" has to mean if it
			// is not to catch 192.168.11.5 as well.
			await expectBanned( inside.page, postLink );
			await expectAllowed( outside.page, postLink );
		} finally {
			await inside.context.close();
			await outside.context.close();
		}
	} );

	test( 'a dotted address is matched literally, not as a regular expression', async ( {
		browser,
	} ) => {
		ban( { ips: [ '203.0.113.7' ] } );

		// The dots in an IP address are regex metacharacters, and the pattern is
		// compiled into one. Without preg_quote() first, "203.0.113.7" would
		// also match "203a0b113c7" -- and, far worse, an entry a site meant
		// literally could be read as an expression that matches everything.
		const literal = await asVisitor( browser, { ip: '203.0.113.7' } );
		const lookalike = await asVisitor( browser, { ip: '203.0.113.17' } );

		try {
			await expectBanned( literal.page, postLink );
			await expectAllowed( lookalike.page, postLink );
		} finally {
			await literal.context.close();
			await lookalike.context.close();
		}
	} );

	test( 'an IP range bans what falls inside it, in both directions of the bound', async ( {
		browser,
	} ) => {
		ban( { ips_range: [ '203.0.113.10-203.0.113.20' ] } );

		const inside = await asVisitor( browser, { ip: FAKE.inRange } );
		const above = await asVisitor( browser, { ip: FAKE.outOfRange } );
		const below = await asVisitor( browser, { ip: '203.0.113.1' } );

		try {
			await expectBanned( inside.page, postLink );
			await expectAllowed( above.page, postLink );

			// Both ends, because the comparison is a byte-wise one on packed
			// addresses and an off-by-one at either bound is invisible from the
			// other. Before 2.0.0 this was ip2long(), which returns false for
			// anything it cannot parse -- and PHP reads `$ip >= false` as true,
			// so a range with one junk bound banned everybody below the other.
			await expectAllowed( below.page, postLink );
		} finally {
			await inside.context.close();
			await above.context.close();
			await below.context.close();
		}
	} );

	test( 'an excluded address is never banned, whatever else matches it', async ( { browser } ) => {
		ban( {
			ips: [ '203.0.113.*' ],
			ips_range: [ '203.0.113.1-203.0.113.254' ],
			exclude_ips: [ FAKE.excluded ],
		} );

		const excluded = await asVisitor( browser, { ip: FAKE.excluded } );
		const notExcluded = await asVisitor( browser, { ip: FAKE.banned } );

		try {
			// The exclude list is checked before anything else and returns
			// outright, so it beats a wildcard and a range at once. That is what
			// makes it usable as the "and never me" line a site adds after
			// banning a whole netblock.
			await expectAllowed( excluded.page, postLink );
			await expectBanned( notExcluded.page, postLink );
		} finally {
			await excluded.context.close();
			await notExcluded.context.close();
		}
	} );

	test( 'a user agent is banned by pattern, and an honest one is not', async ( { browser } ) => {
		const agent = unique( 'E2EBadBot' );

		ban( { user_agents: [ 'E2EBadBot*' ] } );

		const bot = await asVisitor( browser, { agent: `${ agent }/1.0` } );
		const human = await asVisitor( browser, { agent: 'E2EGoodBrowser/1.0' } );

		try {
			await expectBanned( bot.page, postLink );
			await expectAllowed( human.page, postLink );
		} finally {
			await bot.context.close();
			await human.context.close();
		}
	} );

	test( 'a referrer is banned by pattern, and an ordinary visitor is not', async ( { browser } ) => {
		ban( { referers: [ 'https://*.spam.example/*' ] } );

		const fromSpam = await asVisitor( browser, { referer: 'https://a.spam.example/landing' } );
		const fromNowhere = await asVisitor( browser, {} );

		try {
			await expectBanned( fromSpam.page, postLink );

			// A visitor with no Referer at all is the common case, and
			// matches_any() returns false for an empty subject rather than
			// letting a pattern of "*" swallow everybody who typed the address.
			await expectAllowed( fromNowhere.page, postLink );
		} finally {
			await fromSpam.context.close();
			await fromNowhere.context.close();
		}
	} );

	test( 'a host name is banned by pattern', async ( { browser } ) => {
		// What gethostbyaddr() makes of a documentation address depends on the
		// resolver the container happens to have, so the pattern is derived from
		// what the site itself reports rather than guessed. Asking is what keeps
		// this from being a test of somebody's DNS.
		const hostname = hostnameOf( FAKE.banned );

		expect( hostname ).not.toBe( '' );

		ban( { hosts: [ hostname ] } );

		const matching = await asVisitor( browser, { ip: FAKE.banned } );
		const other = await asVisitor( browser, { ip: FAKE.other } );

		try {
			await expectBanned( matching.page, postLink );
			await expectAllowed( other.page, postLink );
		} finally {
			await matching.context.close();
			await other.context.close();
		}
	} );

	test( 'every refusal is counted, per address and in total', async ( { browser } ) => {
		ban( { ips: [ FAKE.banned ] } );

		const { context, page } = await asVisitor( browser, { ip: FAKE.banned } );

		try {
			await expectBanned( page, postLink );
			await expectBanned( page, postLink );
			await expectBanned( page, postLink );

			// Three refusals, three counted, and the per-address counter is kept
			// apart from the total -- which is what lets the settings screen say
			// who is knocking as well as how often.
			const stats = getStats();

			expect( stats.count ).toBe( 3 );
			expect( stats.users[ FAKE.banned ] ).toBe( 3 );
		} finally {
			await context.close();
		}
	} );

	test( 'every token in the banned message is replaced with a real value', async ( { browser } ) => {
		// The shipped message says only "You Are Banned.", so a template
		// carrying all six tokens is the only way to watch them substituted.
		// Written straight into the row, which is where a real one lives after a
		// save.
		setOptions( {
			ip_header: IP_HEADER,
			lists: { ips: [ FAKE.banned ] },
			message:
				'<html><head><title>x</title></head><body><div id="wp-ban-container">' +
				'<p>site=%SITE_NAME% url=%SITE_URL% ip=%USER_IP% host=%USER_HOSTNAME% ' +
				'mine=%USER_ATTEMPTS_COUNT% all=%TOTAL_ATTEMPTS_COUNT%</p>' +
				'</div></body></html>',
		} );

		const { context, page } = await asVisitor( browser, { ip: FAKE.banned } );

		try {
			// The message is this test's own, so the default assertion about
			// the shipped wording would be looking for something that is not
			// there. What has to be true is that the ban page arrived.
			await expectBanned( page, postLink, 403, 'site=' );

			const container = page.locator( '#wp-ban-container' );

			// The far end, not the template. A token that was not replaced is
			// still printed -- it is only text -- so the assertion has to be that
			// the literal %USER_IP% is gone and the address is there instead.
			await expect( container ).toContainText( `ip=${ FAKE.banned }` );
			await expect( container ).toContainText( `host=${ hostnameOf( FAKE.banned ) }` );
			await expect( container ).toContainText( 'mine=1' );
			await expect( container ).toContainText( 'all=1' );
			await expect( container ).not.toContainText( '%USER_IP%' );
			await expect( container ).not.toContainText( '%TOTAL_ATTEMPTS_COUNT%' );
		} finally {
			await context.close();
		}
	} );

	test( 'the denial action is told who was turned away and with what status', async ( {
		browser,
	} ) => {
		ban( { ips: [ FAKE.banned ] } );

		const { context, page } = await asVisitor( browser, { ip: FAKE.banned } );

		try {
			await expectBanned( page, postLink );

			// The hook a site logs bans from, or feeds to fail2ban. It fires on a
			// request that ends in exit(), so the only way to see it is what it
			// left behind for the next request -- which the fixture mu-plugin
			// writes into a row of its own.
			expect( lastDenial() ).toEqual( { ip: FAKE.banned, status: 403 } );
		} finally {
			await context.close();
		}
	} );

	test( 'the enabled filter switches the whole check off, and back on', async ( { browser } ) => {
		ban( { ips: [ FAKE.banned ] } );

		const { context, page } = await asVisitor( browser, { ip: FAKE.banned } );

		try {
			await expectBanned( page, postLink );

			// The escape hatch for a site that wants bans on the front end and
			// not in wp-admin, or none at all during a migration. Both
			// directions in one test: "not banned" is true of a deactivated
			// plugin too, and the first assertion is what rules that out.
			setFixtureAnswer( 'enabled', false );

			await expectAllowed( page, postLink );

			clearFixtureAnswer( 'enabled' );

			await expectBanned( page, postLink );
		} finally {
			clearFixtureAnswer( 'enabled' );
			await context.close();
		}
	} );

	test( 'the address filter has the last word on who the visitor is', async ( { browser } ) => {
		ban( { ips: [ FAKE.banned ] } );

		// A visitor whose header says somebody harmless. The filter runs after
		// every other way of resolving the address, so a site that works out its
		// own answer -- from a CDN header nothing here has heard of, say -- gets
		// to overrule all of it.
		const { context, page } = await asVisitor( browser, { ip: FAKE.other } );

		try {
			await expectAllowed( page, postLink );

			setFixtureAnswer( 'ipaddress', FAKE.banned );

			await expectBanned( page, postLink );

			// And the identity the filter supplied is the one recorded, not the
			// one the header claimed -- otherwise the stats screen would name a
			// visitor the plugin never actually decided about.
			expect( getStats().users[ FAKE.banned ] ).toBe( 1 );
			expect( getStats().users[ FAKE.other ] ).toBeUndefined();
		} finally {
			clearFixtureAnswer( 'ipaddress' );
			await context.close();
		}
	} );

	test( 'a proxy header is ignored until the site opts in, and then the client hop is used', async ( {
		browser,
	} ) => {
		// No named header this time: this is the other opt-in, the reverse proxy
		// checkbox, and the headers it then trusts are the well-known ones.
		ban( { ips: [ '198.51.100.9' ] }, false );

		// CF-Connecting-IP rather than X-Forwarded-For, and the reason is worth
		// writing down. The Apache in front of PHP in the wp-env image consumes
		// X-Forwarded-For itself: it moves the last hop into REMOTE_ADDR and
		// hands PHP the remainder. So a test sending that header would find the
		// address already changed before the plugin was asked anything, and
		// would report the opt-in as broken when it was the environment doing
		// it. The plugin trusts either header equally once told to.
		const { context, page } = await asVisitor( browser, {
			headers: { 'CF-Connecting-IP': '10.0.0.1, 198.51.100.9' },
		} );

		try {
			// Every proxy header is set by the client, so honouring them by
			// default would let anybody walk past a ban by sending a different
			// value on each request. Off is the safe default and this is the
			// half that proves it is the default.
			await expectAllowed( page, postLink );

			setFixtureAnswer( 'trust_proxy', true );

			// And once trusted, the chain is read for the first hop that is not
			// a private address. Treating the whole header as the identity would
			// mean appending one more hop yields a different value, and the ban
			// is defeated again with the opt-in working as designed.
			await expectBanned( page, postLink );

			expect( getStats().users[ '198.51.100.9' ] ).toBe( 1 );
		} finally {
			clearFixtureAnswer( 'trust_proxy' );
			await context.close();
		}
	} );

	test( 'wp-admin is behind the same check as the front end', async ( { browser } ) => {
		const agent = unique( 'E2EAdminBanned' );

		ban( { user_agents: [ `${ agent }*` ] } );

		// The check hangs off init, which fires for wp-admin too. That is
		// deliberate and it is the sharpest edge in this plugin: a ban that
		// matched the owner would take the screen that undoes it away with
		// everything else. Better to know it does than to find out.
		const { context, page } = await asVisitor( browser, { agent: `${ agent }/1.0` } );

		try {
			await expectBanned( page, '/wp-login.php' );
			await expectBanned( page, '/wp-admin/' );
		} finally {
			await context.close();
		}
	} );
} );
