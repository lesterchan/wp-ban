<?php
/**
 * Address resolution and pattern matching.
 *
 * @package WP-Ban
 */

/**
 * @covers WP_Ban_IP
 */
class WP_Ban_IP_Test extends WP_Ban_TestCase {

	/**
	 * Opt in to the usual seven forwarding headers.
	 *
	 * Since 2.0.0 removed the "This site is behind a reverse proxy." checkbox
	 * this is the only opt-in that walks PROXY_HEADERS -- the other names one
	 * header and trusts nothing else. WP_UnitTestCase backs the hook table up in
	 * set_up() and restores it in tear_down(), so the filter comes off by itself.
	 *
	 * @return void
	 */
	private function trust_the_usual_headers() {
		add_filter( 'wp_ban_trust_proxy', '__return_true' );
	}

	public function test_wildcards_match_the_whole_subject() {
		$this->assertTrue( WP_Ban_IP::matches_wildcard( '192.168.1.100', '192.168.1.100' ), 'An exact address matches itself.' );
		$this->assertTrue( WP_Ban_IP::matches_wildcard( '192.168.1.*', '192.168.1.55' ), 'A trailing wildcard matches any last octet.' );
		$this->assertTrue( WP_Ban_IP::matches_wildcard( '192.168.*.*', '192.168.9.55' ), 'Two wildcards match the last two octets.' );
		$this->assertFalse( WP_Ban_IP::matches_wildcard( '192.168.1.*', '10.0.0.1' ), 'A wildcard pattern does not reach into another subnet.' );
	}

	public function test_patterns_are_anchored_so_a_substring_does_not_match() {
		$this->assertFalse( WP_Ban_IP::matches_wildcard( 'EvilBot', 'NotEvilBotAtAll' ), 'The pattern is anchored, so a substring is not a match.' );
	}

	public function test_regex_metacharacters_in_a_pattern_are_literal() {
		// A dot must not behave as "any character", and a + must not repeat.
		$this->assertFalse( WP_Ban_IP::matches_wildcard( '1.2.3.4', '1X2X3X4' ), 'A dot in a pattern is a literal dot, not any character.' );
		$this->assertFalse( WP_Ban_IP::matches_wildcard( 'a+b', 'aaab' ), 'A plus in a pattern is a literal plus, not a repeat.' );
		$this->assertTrue( WP_Ban_IP::matches_wildcard( 'a+b', 'a+b' ), 'A literal a+b matches a+b and nothing else.' );
	}

	public function test_empty_pattern_or_subject_never_matches() {
		$this->assertFalse( WP_Ban_IP::matches_wildcard( '', 'anything' ), 'An empty pattern never matches.' );
		$this->assertFalse( WP_Ban_IP::matches_wildcard( '*', '' ), 'A wildcard never matches an empty subject.' );
		$this->assertFalse( WP_Ban_IP::matches_any( array( '*' ), '' ), 'matches_any never matches an empty subject either.' );
	}

	public function test_ranges_are_inclusive() {
		$this->assertTrue( WP_Ban_IP::in_range( '203.0.113.15', '203.0.113.10', '203.0.113.20' ), 'An address inside the bounds is in the range.' );
		$this->assertTrue( WP_Ban_IP::in_range( '203.0.113.10', '203.0.113.10', '203.0.113.20' ), 'The lower bound is inside the range.' );
		$this->assertTrue( WP_Ban_IP::in_range( '203.0.113.20', '203.0.113.10', '203.0.113.20' ), 'The upper bound is inside the range.' );
		$this->assertFalse( WP_Ban_IP::in_range( '203.0.113.9', '203.0.113.10', '203.0.113.20' ), 'One below the lower bound is outside the range.' );
		$this->assertFalse( WP_Ban_IP::in_range( '203.0.113.21', '203.0.113.10', '203.0.113.20' ), 'One above the upper bound is outside the range.' );
	}

	/**
	 * The regression this whole release exists for.
	 *
	 * Before 2.0.0, ip2long() returned false for an unparseable bound. PHP
	 * compares an int against a bool by casting the int to true, so
	 * `$ip >= false` was always true, and a range with a junk lower bound
	 * banned every address at or below its upper bound.
	 *
	 * @dataProvider data_malformed_ranges
	 *
	 * @param string $start Range start.
	 * @param string $end   Range end.
	 */
	public function test_a_malformed_range_matches_nobody( $start, $end ) {
		foreach ( array( '8.8.8.8', '1.1.1.1', '203.0.113.1', '2001:db8::1' ) as $ip ) {
			$this->assertFalse(
				WP_Ban_IP::in_range( $ip, $start, $end ),
				"{$ip} was matched by the malformed range {$start}-{$end}"
			);
		}
	}

	public function data_malformed_ranges() {
		return array(
			'junk lower bound' => array( 'garbage', '203.0.113.255' ),
			'junk upper bound' => array( '203.0.113.1', 'garbage' ),
			'both junk'        => array( 'not', 'an-ip' ),
			'empty lower'      => array( '', '203.0.113.255' ),
			'empty upper'      => array( '203.0.113.1', '' ),
		);
	}

	public function test_ipv6_ranges_work() {
		$this->assertTrue( WP_Ban_IP::in_range( '2001:db8::20', '2001:db8::10', '2001:db8::ff' ), 'An IPv6 address inside the bounds is in the range.' );
		$this->assertFalse( WP_Ban_IP::in_range( '2001:db8::1000', '2001:db8::10', '2001:db8::ff' ), 'An IPv6 address past the upper bound is outside the range.' );
	}

	public function test_a_range_cannot_mix_address_families() {
		$this->assertFalse( WP_Ban_IP::parse_range( '203.0.113.1-2001:db8::1' ), 'A range cannot mix IPv4 with IPv6.' );
		$this->assertFalse( WP_Ban_IP::in_range( '203.0.113.15', '2001:db8::10', '2001:db8::ff' ), 'An IPv4 address is never inside an IPv6 range.' );
		$this->assertFalse( WP_Ban_IP::in_range( '2001:db8::15', '203.0.113.1', '203.0.113.20' ), 'An IPv6 address is never inside an IPv4 range.' );
	}

	public function test_ranges_compare_correctly_above_127() {
		// ip2long() goes negative here on 32-bit builds; packed comparison does not.
		$this->assertTrue( WP_Ban_IP::in_range( '200.0.0.5', '200.0.0.1', '200.0.0.10' ), 'Addresses above 127 compare unsigned rather than as signed bytes.' );
		$this->assertTrue( WP_Ban_IP::in_range( '255.255.255.254', '200.0.0.1', '255.255.255.255' ), 'The top of the IPv4 space compares correctly.' );
	}

	public function test_parse_range_requires_exactly_two_bounds() {
		$this->assertFalse( WP_Ban_IP::parse_range( 'no-separator' ), 'A range needs a separator between its two bounds.' );
		$this->assertFalse( WP_Ban_IP::parse_range( '1.1.1.1' ), 'A single address is not a range.' );
		$this->assertSame(
			array( '203.0.113.10', '203.0.113.20' ),
			WP_Ban_IP::parse_range( '203.0.113.10-203.0.113.20' ),
			'A well-formed range parses into its two bounds.'
		);
	}

	public function test_in_any_range_skips_malformed_entries_and_keeps_looking() {
		$this->assertTrue(
			WP_Ban_IP::in_any_range( array( 'junk', '203.0.113.10-203.0.113.20' ), '203.0.113.15' ),
			'A malformed entry is skipped and the search carries on to the valid one.'
		);
	}

	public function test_remote_addr_is_the_default() {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.2';
		$_SERVER['HTTP_CLIENT_IP']       = '203.0.113.1';

		$this->set_options( array() );

		$this->assertSame( '198.51.100.7', WP_Ban_IP::address(), 'With nothing opted in, REMOTE_ADDR is the address.' );
	}

	/**
	 * Regression pin: wp_ban_stats is keyed by this value, so any change to how
	 * a well-formed address is normalised orphans every row already recorded.
	 *
	 * @dataProvider data_addresses
	 *
	 * @param string $address Address.
	 */
	public function test_a_well_formed_address_round_trips_byte_identical( $address ) {
		$_SERVER['REMOTE_ADDR'] = $address;
		$this->set_options( array() );

		$this->assertSame( $address, WP_Ban_IP::address(), 'A well-formed address comes back byte identical, not normalised into something else.' );
	}

	public function data_addresses() {
		return array(
			array( '203.0.113.44' ),
			array( '8.8.8.8' ),
			array( '127.0.0.1' ),
			array( '192.168.1.1' ),
			array( '2001:db8::1' ),
			array( '::1' ),
		);
	}

	public function test_a_trusted_proxy_honours_the_forwarding_headers() {
		$_SERVER['REMOTE_ADDR']    = '198.51.100.7';
		$_SERVER['HTTP_CLIENT_IP'] = '203.0.113.1';

		$this->set_options( array() );
		$this->trust_the_usual_headers();

		$this->assertSame( '203.0.113.1', WP_Ban_IP::address(), 'With proxies trusted, the forwarding header is read.' );
	}

	/**
	 * A named header is the whole of the opt-in: nothing else is read.
	 *
	 * The checkbox 2.0.0 removes meant "trust whichever of the seven turns up",
	 * so this is the case that used to be indistinguishable from it and is now
	 * the ordinary one. A header the site's own proxy does not set stays
	 * ignored, however plausible it looks.
	 */
	public function test_naming_a_header_trusts_that_header_and_no_other() {
		$_SERVER['REMOTE_ADDR']    = '198.51.100.7';
		$_SERVER['HTTP_CLIENT_IP'] = '203.0.113.1';

		$this->set_options( array( 'ip_header' => 'HTTP_X_FORWARDED_FOR' ) );

		$this->assertSame( '198.51.100.7', WP_Ban_IP::address(), 'Naming a header ignores the ones not named.' );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.44';

		$this->assertSame( '203.0.113.44', WP_Ban_IP::address(), 'And reads the one that was.' );
	}

	/**
	 * The chain is read from the right, because every hop appends: nginx's
	 * $proxy_add_x_forwarded_for is literally "$http_x_forwarded_for,
	 * $remote_addr", so what the visitor sent stays on the left and the address
	 * the proxy actually saw is added on the right.
	 */
	public function test_the_address_a_proxy_observed_is_read_from_the_end_of_the_chain() {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 203.0.113.44';

		$this->set_options( array() );
		$this->trust_the_usual_headers();

		$this->assertSame( '203.0.113.44', WP_Ban_IP::address(), 'The last entry is the one the nearest proxy wrote.' );
	}

	/**
	 * The one that distinguishes the two readings, and the one the old fixture
	 * could not: with a public address on the left, reading from the left gives
	 * the visitor whatever identity they asked for.
	 */
	public function test_a_visitor_cannot_choose_their_own_address_by_prefilling_the_chain() {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.123, 203.0.113.44';

		$this->set_options( array( 'ip_header' => 'HTTP_X_FORWARDED_FOR' ) );

		$this->assertSame( '203.0.113.44', WP_Ban_IP::address(), 'The address the proxy saw wins over the one the visitor sent.' );
		$this->assertNotSame( '192.0.2.123', WP_Ban_IP::address(), 'A banned visitor cannot rename themselves out of the ban.' );
	}

	/**
	 * The sharpest form of the same bug: is_excluded() is an exact compare
	 * against the resolved address, and a hit on the exclude list returns before
	 * any list is walked -- so a spoofable address bypassed the host, referrer
	 * and user-agent bans too, none of which are about IPs at all.
	 */
	public function test_a_spoofed_chain_cannot_reach_the_exclude_list() {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99, 203.0.113.44';

		$this->set_options(
			array(
				'ip_header' => 'HTTP_X_FORWARDED_FOR',
				'lists'     => array( 'exclude_ips' => array( '203.0.113.99' ) ),
			)
		);

		$this->assertSame( '203.0.113.44', WP_Ban_IP::address(), 'Naming an excluded address on the left of the chain does not adopt it.' );
	}

	public function test_a_site_behind_two_proxies_can_say_so() {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.44, 198.51.100.60, 198.51.100.61';

		$this->set_options( array( 'ip_header' => 'HTTP_X_FORWARDED_FOR' ) );

		add_filter( 'wp_ban_trusted_proxy_hops', static fn() => 2 );

		$this->assertSame( '198.51.100.60', WP_Ban_IP::address(), 'Two hops in front means the second entry from the end.' );
	}

	/**
	 * A pattern with several stars compiles to `^.*a.*b.*c$`, which backtracks
	 * quadratically when the subject nearly matches -- and the subjects are the
	 * user agent and the referrer, which the visitor writes. matched_list()
	 * walks both lists on every request, not only on banned ones.
	 */
	public function test_a_long_subject_is_capped_before_it_reaches_the_matcher() {
		$pattern = '*bot*crawl*spider*x';
		$subject = str_repeat( 'bot crawl spider ', 400 ) . 'x';

		$started = microtime( true );
		$matched = WP_Ban_IP::matches_wildcard( $pattern, $subject );
		$elapsed = microtime( true ) - $started;

		$this->assertFalse( $matched, 'The pattern does not match, which is the expensive answer to reach.' );
		$this->assertLessThan( 0.05, $elapsed, 'And reaching it does not cost measurable CPU.' );
	}

	/**
	 * filter_var() validates and hands the string back as it arrived, so one
	 * address had several spellings and the lists compare strings. Where the
	 * visitor picks the spelling -- any site that has named a proxy header --
	 * that is a ban evasion; where they do not, it is a silent miss, because a
	 * dual-stack server reporting REMOTE_ADDR as ::ffff:1.2.3.4 never matched
	 * the IPv4 entry its owner typed.
	 *
	 * @dataProvider data_equivalent_addresses
	 *
	 * @param string $written    How the address was written.
	 * @param string $canonical  The one spelling everything compares against.
	 */
	public function test_an_address_has_one_spelling( $written, $canonical ) {
		$this->assertSame( $canonical, WP_Ban_IP::valid_ip( $written ), $written . ' reduces to its canonical form.' );
	}

	/**
	 * Spellings of one address, and what they reduce to.
	 *
	 * @return array
	 */
	public function data_equivalent_addresses() {
		return array(
			'expanded IPv6'  => array( '2001:0db8:0000:0000:0000:0000:0000:0001', '2001:db8::1' ),
			'uppercase IPv6' => array( '2001:DB8::1', '2001:db8::1' ),
			'leading zeroes' => array( '0::1', '::1' ),
			'IPv4-mapped'    => array( '::ffff:203.0.113.5', '203.0.113.5' ),
			'plain IPv4'     => array( '203.0.113.5', '203.0.113.5' ),
		);
	}

	public function test_an_ipv4_mapped_address_matches_an_ipv4_ban() {
		$_SERVER['REMOTE_ADDR']          = '::ffff:203.0.113.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '';

		$this->set_options( array( 'lists' => array( 'ips' => array( '203.0.113.5' ) ) ) );

		$this->assertSame( '203.0.113.5', WP_Ban_IP::address(), 'The mapped form is the IPv4 address it stands for.' );
		$this->assertNotSame( '', WP_Ban_Verdict::matched_list( WP_Ban_IP::address(), '', '' ), 'So the ban the owner typed matches it.' );
	}

	public function test_an_ordinary_user_agent_still_matches_its_pattern() {
		$this->assertTrue(
			WP_Ban_IP::matches_wildcard( '*Googlebot*', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ),
			'The cap is far longer than any real user agent, so ordinary matching is untouched.'
		);
	}

	public function test_a_chain_shorter_than_the_configured_hops_falls_back() {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.44';

		$this->set_options( array( 'ip_header' => 'HTTP_X_FORWARDED_FOR' ) );

		add_filter( 'wp_ban_trusted_proxy_hops', static fn() => 3 );

		// Refusing beats guessing: the header did not come through as many
		// proxies as the site says it has, so nothing in it can be trusted.
		$this->assertSame( '198.51.100.7', WP_Ban_IP::address(), 'A chain too short for the configured hops falls back to REMOTE_ADDR.' );
	}

	public function test_an_unusable_chain_falls_back_to_remote_addr() {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'total garbage, not an ip';

		$this->set_options( array() );
		$this->trust_the_usual_headers();

		$this->assertSame( '198.51.100.7', WP_Ban_IP::address(), 'A chain with nothing usable in it falls back to REMOTE_ADDR.' );
	}

	/**
	 * Before 2.0.0 this returned '' and every ban silently stopped applying.
	 */
	public function test_a_private_remote_addr_still_resolves_with_proxy_on() {
		$_SERVER['REMOTE_ADDR'] = '192.168.5.5';

		$this->set_options( array() );
		$this->trust_the_usual_headers();

		$this->assertSame( '192.168.5.5', WP_Ban_IP::address(), 'A private REMOTE_ADDR still resolves rather than reading as nothing.' );
	}

	public function test_a_named_header_outranks_the_generic_list() {
		$_SERVER['REMOTE_ADDR']           = '198.51.100.7';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.77';
		$_SERVER['HTTP_CLIENT_IP']        = '203.0.113.1';

		$this->set_options( array( 'ip_header' => 'HTTP_CF_CONNECTING_IP' ) );
		$this->trust_the_usual_headers();

		$this->assertSame( '203.0.113.77', WP_Ban_IP::address(), 'A named header outranks the generic list rather than being one of it.' );
	}

	public function test_an_absent_named_header_falls_back() {
		$_SERVER['REMOTE_ADDR']    = '198.51.100.7';
		$_SERVER['HTTP_CLIENT_IP'] = '203.0.113.1';

		$this->set_options( array( 'ip_header' => 'HTTP_CF_CONNECTING_IP' ) );
		$this->trust_the_usual_headers();

		$this->assertSame( '203.0.113.1', WP_Ban_IP::address(), 'And when the named header is absent, the generic list is used.' );
	}

	/**
	 * And with no opt-in at all, a forwarding header is simply not read.
	 *
	 * The half the removed checkbox made easy to get wrong: it was the only
	 * control on the screen that could turn this on, and ticking it on a site
	 * with no proxy made every IP ban bypassable by anybody who could set a
	 * header -- which is everybody.
	 */
	public function test_a_forwarding_header_is_ignored_when_nothing_opted_in() {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.1';
		$_SERVER['HTTP_CLIENT_IP']       = '203.0.113.2';

		$this->set_options( array() );

		$this->assertSame( '198.51.100.7', WP_Ban_IP::address(), 'A forwarding header is ignored entirely until a site opts in; it is visitor-supplied.' );
	}

	public function test_the_trust_proxy_filter_enables_the_headers() {
		$_SERVER['REMOTE_ADDR']    = '198.51.100.7';
		$_SERVER['HTTP_CLIENT_IP'] = '203.0.113.1';

		$this->set_options( array() );

		$this->assertSame( '198.51.100.7', WP_Ban_IP::address(), 'Before the filter, the header is ignored.' );

		add_filter( 'wp_ban_trust_proxy', '__return_true' );
		$this->assertSame( '203.0.113.1', WP_Ban_IP::address(), 'After it, the header is read, so the filter is what opts in.' );
		remove_filter( 'wp_ban_trust_proxy', '__return_true' );
	}

	public function test_absent_request_headers_yield_empty_strings() {
		unset( $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_USER_AGENT'] );

		$this->assertSame( '', WP_Ban_IP::referer(), 'An absent referer header reads as an empty string rather than a notice.' );
		$this->assertSame( '', WP_Ban_IP::user_agent(), 'And an absent user agent.' );
	}

	public function test_hostname_of_an_invalid_address_is_empty() {
		$this->assertSame( '', WP_Ban_IP::hostname( 'not-an-ip' ), 'A hostname is not looked up for something that is not an address.' );
		$this->assertSame( '', WP_Ban_IP::hostname( '' ), 'Nor for an empty one.' );
	}
}
