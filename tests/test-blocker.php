<?php
/**
 * The ban check: who gets turned away and who does not.
 *
 * @package WP-Ban
 */

/**
 * @covers WP_Ban_Blocker
 */
class Test_Ban_Blocker extends WP_Ban_TestCase {

	public function test_a_listed_ip_is_banned() {
		$_SERVER['REMOTE_ADDR'] = '192.168.77.10';
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.77.10' ) ) ) );

		$this->assertSame( '192.168.77.10', $this->run_ban_check() );
	}

	public function test_an_unlisted_ip_passes() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.77.10' ) ) ) );

		$this->assertFalse( $this->run_ban_check() );
	}

	public function test_a_wildcard_ip_ban_is_still_selective() {
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.1.*' ) ) ) );

		$_SERVER['REMOTE_ADDR'] = '192.168.1.55';
		$this->assertSame( '192.168.1.55', $this->run_ban_check() );

		$_SERVER['REMOTE_ADDR'] = '192.168.2.55';
		$this->assertFalse( $this->run_ban_check() );
	}

	public function test_a_listed_range_is_banned() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.15';
		$this->set_options( array( 'lists' => array( 'ips_range' => array( '203.0.113.10-203.0.113.20' ) ) ) );

		$this->assertSame( '203.0.113.15', $this->run_ban_check() );
	}

	public function test_an_ipv6_range_is_banned() {
		$_SERVER['REMOTE_ADDR'] = '2001:db8::20';
		$this->set_options( array( 'lists' => array( 'ips_range' => array( '2001:db8::10-2001:db8::ff' ) ) ) );

		$this->assertSame( '2001:db8::20', $this->run_ban_check() );
	}

	/**
	 * The bug this release exists for: a stored range whose lower bound does
	 * not parse used to match every visitor on earth.
	 */
	public function test_a_malformed_stored_range_does_not_ban_the_whole_internet() {
		$this->set_options( array( 'lists' => array( 'ips_range' => array( 'garbage-203.0.113.255' ) ) ) );

		foreach ( array( '8.8.8.8', '1.1.1.1', '203.0.113.1', '198.51.100.7' ) as $ip ) {
			$_SERVER['REMOTE_ADDR'] = $ip;
			$this->assertFalse( $this->run_ban_check(), "{$ip} was banned by a malformed range" );
		}
	}

	public function test_a_banned_referrer_is_turned_away() {
		$_SERVER['REMOTE_ADDR']  = '203.0.113.1';
		$_SERVER['HTTP_REFERER'] = 'http://bad.spam.test/path?a=1&b=2';

		$this->set_options( array( 'lists' => array( 'referers' => array( 'http://*.spam.test/path?a=1&b=2' ) ) ) );

		$this->assertSame( '203.0.113.1', $this->run_ban_check() );
	}

	public function test_a_banned_user_agent_is_turned_away() {
		$_SERVER['REMOTE_ADDR']     = '203.0.113.1';
		$_SERVER['HTTP_USER_AGENT'] = 'EvilBot/2.0';

		$this->set_options( array( 'lists' => array( 'user_agents' => array( 'EvilBot*' ) ) ) );

		$this->assertSame( '203.0.113.1', $this->run_ban_check() );
	}

	public function test_an_excluded_ip_is_never_banned() {
		$_SERVER['REMOTE_ADDR']     = '198.51.100.5';
		$_SERVER['HTTP_USER_AGENT'] = 'EvilBot/2.0';

		$this->set_options(
			array(
				'lists' => array(
					'ips'         => array( '198.51.100.5' ),
					'user_agents' => array( 'EvilBot*' ),
					'exclude_ips' => array( '198.51.100.5' ),
				),
			)
		);

		$this->assertFalse( $this->run_ban_check(), 'the exclude list must win over every other list' );
	}

	public function test_empty_lists_ban_nobody() {
		$_SERVER['REMOTE_ADDR']     = '203.0.113.1';
		$_SERVER['HTTP_REFERER']    = 'http://example.test/';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';

		$this->set_options( array() );

		$this->assertFalse( $this->run_ban_check() );
	}

	public function test_a_ban_records_a_statistic() {
		$_SERVER['REMOTE_ADDR'] = '192.168.77.10';
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.77.10' ) ) ) );

		$this->run_ban_check();
		$this->run_ban_check();

		$this->assertSame( 2, WP_Ban_Stats::attempts_for( '192.168.77.10' ) );
		$this->assertSame( 2, WP_Ban_Stats::total() );
	}

	public function test_a_visitor_who_passes_records_nothing() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.77.10' ) ) ) );

		$this->run_ban_check();

		$this->assertSame( 0, WP_Ban_Stats::total() );
		$this->assertSame( 0, WP_Ban_Stats::attempts_for( '203.0.113.1' ) );
	}

	public function test_the_check_can_be_switched_off_by_filter() {
		$_SERVER['REMOTE_ADDR'] = '192.168.77.10';
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.77.10' ) ) ) );

		add_filter( 'wp_ban_enabled', '__return_false' );
		$denied = $this->run_ban_check();
		remove_filter( 'wp_ban_enabled', '__return_false' );

		$this->assertFalse( $denied );
	}

	public function test_the_status_code_defaults_to_403_and_is_filterable() {
		$_SERVER['REMOTE_ADDR'] = '192.168.77.10';
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.77.10' ) ) ) );

		$seen = null;

		$listener = static function ( $ip, $status ) use ( &$seen ) {
			$seen = $status;
			throw new WP_Ban_Denied_Exception( (string) $ip );
		};

		add_action( 'wp_ban_denied', $listener, 10, 2 );

		try {
			WP_Ban_Blocker::check();
		} catch ( WP_Ban_Denied_Exception $e ) {
			unset( $e );
		}

		$this->assertSame( 403, $seen );

		add_filter( 'wp_ban_status_code', static fn() => 200 );

		try {
			WP_Ban_Blocker::check();
		} catch ( WP_Ban_Denied_Exception $e ) {
			unset( $e );
		}

		remove_all_filters( 'wp_ban_status_code' );
		remove_action( 'wp_ban_denied', $listener, 10 );

		$this->assertSame( 200, $seen );
	}

	public function test_the_preview_substitutes_every_token() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.44';

		$this->set_options(
			array(
				'message' => '<div id="wp-ban-container">%SITE_NAME% %SITE_URL% %USER_IP% %USER_HOSTNAME% %USER_ATTEMPTS_COUNT% %TOTAL_ATTEMPTS_COUNT%</div>',
			)
		);

		$preview = WP_Ban_Blocker::preview();

		$this->assertStringContainsString( '203.0.113.44', $preview );

		foreach ( array( '%SITE_NAME%', '%SITE_URL%', '%USER_IP%', '%USER_HOSTNAME%', '%USER_ATTEMPTS_COUNT%', '%TOTAL_ATTEMPTS_COUNT%' ) as $token ) {
			$this->assertStringNotContainsString( $token, $preview, "{$token} was not substituted" );
		}
	}

	public function test_the_resolved_address_is_filterable() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
		$this->set_options( array( 'lists' => array( 'ips' => array( '192.168.77.10' ) ) ) );

		$this->assertFalse( $this->run_ban_check() );

		add_filter( 'wp_ban_ipaddress', static fn() => '192.168.77.10' );
		$denied = $this->run_ban_check();
		remove_all_filters( 'wp_ban_ipaddress' );

		$this->assertSame( '192.168.77.10', $denied );
	}
}
