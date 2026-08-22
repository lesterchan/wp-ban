<?php
/**
 * The WP_BAN_TRUST_PROXY constant.
 *
 * @package WP-Ban
 */

/**
 * The constant form of the proxy opt-in.
 *
 * Isolated on purpose: a constant cannot be undefined once set, so defining it
 * in the shared run would silently change what every other IP test asserts.
 *
 * PHPUnit reads these annotations from the CLASS docblock, not the file one.
 * They sat in the file docblock until this class gained a @covers block of its
 * own, at which point isolation silently stopped happening and the suite failed
 * with "Constant WP_BAN_TRUST_PROXY already defined".
 *
 * @covers WP_Ban_IP
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class WP_Ban_Trust_Proxy_Test extends WP_Ban_TestCase {

	public function test_the_constant_enables_the_proxy_headers() {
		define( 'WP_BAN_TRUST_PROXY', true );

		$_SERVER['REMOTE_ADDR']    = '198.51.100.7';
		$_SERVER['HTTP_CLIENT_IP'] = '203.0.113.1';

		// No header named: the constant alone is the opt-in, and since 2.0.0
		// removed the checkbox it is the only one that walks PROXY_HEADERS.
		$this->set_options( array() );

		$this->assertSame( '203.0.113.1', WP_Ban_IP::address(), 'The constant opts the site in to the forwarding headers.' );
	}

	/**
	 * The filter defaults TO the constant rather than replacing it, so a site
	 * can define it globally and still refuse the header per request.
	 */
	public function test_the_filter_gets_the_last_word_over_the_constant() {
		define( 'WP_BAN_TRUST_PROXY', true );

		$_SERVER['REMOTE_ADDR']    = '198.51.100.7';
		$_SERVER['HTTP_CLIENT_IP'] = '203.0.113.1';

		$this->set_options( array() );

		add_filter( 'wp_ban_trust_proxy', '__return_false' );
		$ip = WP_Ban_IP::address();
		remove_filter( 'wp_ban_trust_proxy', '__return_false' );

		$this->assertSame( '198.51.100.7', $ip, 'And the filter gets the last word over it, so a site can opt back out.' );
	}

	public function test_the_filter_receives_the_constant_as_its_default() {
		define( 'WP_BAN_TRUST_PROXY', true );

		$seen = null;

		add_filter(
			'wp_ban_trust_proxy',
			static function ( $trust ) use ( &$seen ) {
				$seen = $trust;
				return $trust;
			}
		);

		$this->set_options( array() );
		WP_Ban_IP::address();

		remove_all_filters( 'wp_ban_trust_proxy' );

		$this->assertTrue( $seen, 'The filter is handed the constant as its default value.' );
	}
}
