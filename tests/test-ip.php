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
		$this->assertTrue( WP_Ban_IP::matches_wildcard( '192.168.1.100', '192.168.1.100' ) );
		$this->assertTrue( WP_Ban_IP::matches_wildcard( '192.168.1.*', '192.168.1.55' ) );
		$this->assertTrue( WP_Ban_IP::matches_wildcard( '192.168.*.*', '192.168.9.55' ) );
		$this->assertFalse( WP_Ban_IP::matches_wildcard( '192.168.1.*', '10.0.0.1' ) );
	}

	public function test_patterns_are_anchored_so_a_substring_does_not_match() {
		$this->assertFalse( WP_Ban_IP::matches_wildcard( 'EvilBot', 'NotEvilBotAtAll' ) );
	}

	public function test_regex_metacharacters_in_a_pattern_are_literal() {
		// A dot must not behave as "any character", and a + must not repeat.
		$this->assertFalse( WP_Ban_IP::matches_wildcard( '1.2.3.4', '1X2X3X4' ) );
		$this->assertFalse( WP_Ban_IP::matches_wildcard( 'a+b', 'aaab' ) );
		$this->assertTrue( WP_Ban_IP::matches_wildcard( 'a+b', 'a+b' ) );
	}

	public function test_empty_pattern_or_subject_never_matches() {
		$this->assertFalse( WP_Ban_IP::matches_wildcard( '', 'anything' ) );
		$this->assertFalse( WP_Ban_IP::matches_wildcard( '*', '' ) );
		$this->assertFalse( WP_Ban_IP::matches_any( array( '*' ), '' ) );
	}

	public function test_ranges_are_inclusive() {
		$this->assertTrue( WP_Ban_IP::in_range( '203.0.113.15', '203.0.113.10', '203.0.113.20' ) );
		$this->assertTrue( WP_Ban_IP::in_range( '203.0.113.10', '203.0.113.10', '203.0.113.20' ) );
		$this->assertTrue( WP_Ban_IP::in_range( '203.0.113.20', '203.0.113.10', '203.0.113.20' ) );
		$this->assertFalse( WP_Ban_IP::in_range( '203.0.113.9', '203.0.113.10', '203.0.113.20' ) );
		$this->assertFalse( WP_Ban_IP::in_range( '203.0.113.21', '203.0.113.10', '203.0.113.20' ) );
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
		$this->assertTrue( WP_Ban_IP::in_range( '2001:db8::20', '2001:db8::10', '2001:db8::ff' ) );
		$this->assertFalse( WP_Ban_IP::in_range( '2001:db8::1000', '2001:db8::10', '2001:db8::ff' ) );
	}

	public function test_a_range_cannot_mix_address_families() {
		$this->assertFalse( WP_Ban_IP::parse_range( '203.0.113.1-2001:db8::1' ) );
		$this->assertFalse( WP_Ban_IP::in_range( '203.0.113.15', '2001:db8::10', '2001:db8::ff' ) );
		$this->assertFalse( WP_Ban_IP::in_range( '2001:db8::15', '203.0.113.1', '203.0.113.20' ) );
	}

	public function test_ranges_compare_correctly_above_127() {
		// ip2long() goes negative here on 32-bit builds; packed comparison does not.
		$this->assertTrue( WP_Ban_IP::in_range( '200.0.0.5', '200.0.0.1', '200.0.0.10' ) );
		$this->assertTrue( WP_Ban_IP::in_range( '255.255.255.254', '200.0.0.1', '255.255.255.255' ) );
	}

	public function test_parse_range_requires_exactly_two_bounds() {
		$this->assertFalse( WP_Ban_IP::parse_range( 'no-separator' ) );
		$this->assertFalse( WP_Ban_IP::parse_range( '1.1.1.1' ) );
		$this->assertSame(
			array( '203.0.113.10', '203.0.113.20' ),
			WP_Ban_IP::parse_range( '203.0.113.10-203.0.113.20' )
		);
	}

	public function test_in_any_range_skips_malformed_entries_and_keeps_looking() {
		$this->assertTrue(
			WP_Ban_IP::in_any_range( array( 'junk', '203.0.113.10-203.0.113.20' ), '203.0.113.15' )
		);
	}

	public function test_remote_addr_is_the_default() {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.2';
		$_SERVER['HTTP_CLIENT_IP']       = '203.0.113.1';

		$this->set_options( array() );

		$this->assertSame( '198.51.100.7', WP_Ban_IP::address() );
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

		$this->assertSame( $address, WP_Ban_IP::address() );
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

		$this->assertSame( '203.0.113.1', WP_Ban_IP::address() );
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

		$this->assertSame( '198.51.100.7', WP_Ban_IP::address() );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.44';

		$this->assertSame( '203.0.113.44', WP_Ban_IP::address() );
	}

	public function test_private_hops_in_a_forwarded_chain_are_stepped_over() {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 203.0.113.44';

		$this->set_options( array() );
		$this->trust_the_usual_headers();

		$this->assertSame( '203.0.113.44', WP_Ban_IP::address() );
	}

	public function test_an_unusable_chain_falls_back_to_remote_addr() {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'total garbage, not an ip';

		$this->set_options( array() );
		$this->trust_the_usual_headers();

		$this->assertSame( '198.51.100.7', WP_Ban_IP::address() );
	}

	/**
	 * Before 2.0.0 this returned '' and every ban silently stopped applying.
	 */
	public function test_a_private_remote_addr_still_resolves_with_proxy_on() {
		$_SERVER['REMOTE_ADDR'] = '192.168.5.5';

		$this->set_options( array() );
		$this->trust_the_usual_headers();

		$this->assertSame( '192.168.5.5', WP_Ban_IP::address() );
	}

	public function test_a_named_header_outranks_the_generic_list() {
		$_SERVER['REMOTE_ADDR']           = '198.51.100.7';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.77';
		$_SERVER['HTTP_CLIENT_IP']        = '203.0.113.1';

		$this->set_options( array( 'ip_header' => 'HTTP_CF_CONNECTING_IP' ) );
		$this->trust_the_usual_headers();

		$this->assertSame( '203.0.113.77', WP_Ban_IP::address() );
	}

	public function test_an_absent_named_header_falls_back() {
		$_SERVER['REMOTE_ADDR']    = '198.51.100.7';
		$_SERVER['HTTP_CLIENT_IP'] = '203.0.113.1';

		$this->set_options( array( 'ip_header' => 'HTTP_CF_CONNECTING_IP' ) );
		$this->trust_the_usual_headers();

		$this->assertSame( '203.0.113.1', WP_Ban_IP::address() );
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

		$this->assertSame( '198.51.100.7', WP_Ban_IP::address() );
	}

	public function test_the_trust_proxy_filter_enables_the_headers() {
		$_SERVER['REMOTE_ADDR']    = '198.51.100.7';
		$_SERVER['HTTP_CLIENT_IP'] = '203.0.113.1';

		$this->set_options( array() );

		$this->assertSame( '198.51.100.7', WP_Ban_IP::address() );

		add_filter( 'wp_ban_trust_proxy', '__return_true' );
		$this->assertSame( '203.0.113.1', WP_Ban_IP::address() );
		remove_filter( 'wp_ban_trust_proxy', '__return_true' );
	}

	public function test_absent_request_headers_yield_empty_strings() {
		unset( $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_USER_AGENT'] );

		$this->assertSame( '', WP_Ban_IP::referer() );
		$this->assertSame( '', WP_Ban_IP::user_agent() );
	}

	public function test_hostname_of_an_invalid_address_is_empty() {
		$this->assertSame( '', WP_Ban_IP::hostname( 'not-an-ip' ) );
		$this->assertSame( '', WP_Ban_IP::hostname( '' ) );
	}
}
