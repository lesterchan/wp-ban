<?php
/**
 * Option storage, normalisation and the sanitize callback.
 *
 * @package WP-Ban
 */

/**
 * @covers Ban_Options
 */
class Test_Ban_Options extends Ban_TestCase {

	public function test_a_missing_row_yields_the_defaults() {
		delete_option( Ban_Options::OPTION );
		Ban_Options::flush_cache();

		$this->assertSame( array(), Ban_Options::list_of( 'ips' ) );
		$this->assertFalse( Ban_Options::get()['reverse_proxy'] );
		$this->assertStringContainsString( 'wp-ban-container', Ban_Options::message() );
	}

	public function test_a_corrupt_row_falls_back_to_the_defaults() {
		update_option( Ban_Options::OPTION, 'not an array at all' );
		Ban_Options::flush_cache();

		$this->assertSame( array(), Ban_Options::list_of( 'ips' ) );
		$this->assertNotEmpty( Ban_Options::message() );
	}

	public function test_a_partially_shaped_row_is_normalised() {
		update_option( Ban_Options::OPTION, array( 'lists' => array( 'ips' => 'not a list' ) ) );
		Ban_Options::flush_cache();

		$this->assertSame( array(), Ban_Options::list_of( 'ips' ) );
		$this->assertSame( array(), Ban_Options::list_of( 'hosts' ) );
	}

	public function test_lines_to_list_trims_and_drops_blanks_and_duplicates() {
		$this->assertSame(
			array( '1.1.1.1', '2.2.2.2' ),
			Ban_Options::lines_to_list( "1.1.1.1\n\n  2.2.2.2  \n\n1.1.1.1\n" )
		);
	}

	public function test_lines_to_list_handles_every_line_ending() {
		$this->assertSame(
			array( 'a', 'b', 'c' ),
			Ban_Options::lines_to_list( "a\r\nb\rc" )
		);
	}

	/**
	 * The sanitize callback also runs for programmatic writes -- add_option()
	 * with the defaults on activation, or the common
	 * `$o = Ban_Options::get(); $o['x'] = ...; update_option( ... )`. Those pass
	 * lists that are already split, and casting them to a string would warn and
	 * throw every entry away.
	 */
	public function test_lines_to_list_accepts_an_already_split_list() {
		$this->assertSame(
			array( '1.1.1.1', '2.2.2.2' ),
			Ban_Options::lines_to_list( array( '1.1.1.1', '', '2.2.2.2' ) )
		);
	}

	public function test_writing_back_what_get_returns_keeps_the_lists() {
		Ban_Settings::register();

		$this->set_options( array( 'lists' => array( 'ips' => array( '1.1.1.1', '2.2.2.2' ) ) ) );

		$options              = Ban_Options::get();
		$options['ip_header'] = '';

		update_option( Ban_Options::OPTION, $options );
		Ban_Options::flush_cache();

		$this->assertSame( array( '1.1.1.1', '2.2.2.2' ), Ban_Options::list_of( 'ips' ) );
	}

	/**
	 * Entries are patterns matched against request data, not markup. Before
	 * 2.0.0 they were stored esc_html()'d, so a referrer pattern with a query
	 * string could never match, and re-saving compounded &amp; into &amp;amp;.
	 */
	public function test_an_ampersand_survives_the_sanitizer_unescaped() {
		$clean = Ban_Options::sanitize(
			array( 'lists' => array( 'referers' => 'http://*.spam.test/?a=1&b=2' ) )
		);

		$this->assertSame( array( 'http://*.spam.test/?a=1&b=2' ), $clean['lists']['referers'] );
	}

	public function test_sanitizing_is_idempotent() {
		$input = array(
			'reverse_proxy' => '1',
			'ip_header'     => 'HTTP_CF_CONNECTING_IP',
			'lists'         => array( 'referers' => 'http://*.spam.test/?a=1&b=2' ),
			'message'       => '<div id="wp-ban-container"><p>Nope &amp; goodbye</p></div>',
		);

		$once  = Ban_Options::sanitize( $input );
		$twice = Ban_Options::sanitize( $once );

		$this->assertSame( $once, $twice );
	}

	public function test_an_unchecked_checkbox_is_absent_and_means_false() {
		$clean = Ban_Options::sanitize( array() );

		$this->assertFalse( $clean['reverse_proxy'] );
	}

	public function test_a_header_name_is_restricted_to_the_shape_php_uses() {
		$this->assertSame( 'HTTP_CF_CONNECTING_IP', Ban_Options::sanitize( array( 'ip_header' => 'http_cf_connecting_ip' ) )['ip_header'] );
		$this->assertSame( '', Ban_Options::sanitize( array( 'ip_header' => 'not a header name' ) )['ip_header'] );
		$this->assertSame( '', Ban_Options::sanitize( array( 'ip_header' => 'HTTP_X; DROP' ) )['ip_header'] );
	}

	public function test_malformed_ranges_are_rejected_on_save() {
		$clean = Ban_Options::sanitize(
			array( 'lists' => array( 'ips_range' => "203.0.113.10-203.0.113.20\ngarbage-203.0.113.255\nnope" ) )
		);

		$this->assertSame( array( '203.0.113.10-203.0.113.20' ), $clean['lists']['ips_range'] );
	}

	public function test_an_empty_message_falls_back_to_the_default() {
		$clean = Ban_Options::sanitize( array( 'message' => '   ' ) );

		$this->assertSame( Ban_Options::default_message(), $clean['message'] );
	}

	public function test_the_message_is_run_through_kses() {
		$clean = Ban_Options::sanitize(
			array( 'message' => '<div id="wp-ban-container"><p>Nope</p><script>alert(1)</script></div>' )
		);

		$this->assertStringNotContainsString( '<script', $clean['message'] );
		$this->assertStringContainsString( 'wp-ban-container', $clean['message'] );
	}

	public function test_the_message_may_be_a_whole_html_document() {
		$clean = Ban_Options::sanitize( array( 'message' => Ban_Options::default_message() ) );

		foreach ( array( '<html>', '<head>', '<meta charset="utf-8">', '<title>', '<body>' ) as $tag ) {
			$this->assertStringContainsString( $tag, $clean['message'], "kses stripped {$tag}" );
		}
	}

	public function test_list_to_lines_round_trips() {
		$this->set_options( array( 'lists' => array( 'ips' => array( '1.1.1.1', '2.2.2.2' ) ) ) );

		$this->assertSame( "1.1.1.1\n2.2.2.2", Ban_Options::list_to_lines( 'ips' ) );
		$this->assertSame(
			array( '1.1.1.1', '2.2.2.2' ),
			Ban_Options::lines_to_list( Ban_Options::list_to_lines( 'ips' ) )
		);
	}

	/**
	 * Before 2.0.0 this only ran when the current user's login was literally
	 * "admin", so everyone else could lock themselves out of their own site.
	 */
	public function test_you_cannot_ban_your_own_address() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.44';

		$clean = Ban_Options::sanitize(
			array( 'lists' => array( 'ips' => "203.0.113.44\n8.8.8.8" ) )
		);

		$this->assertSame( array( '8.8.8.8' ), $clean['lists']['ips'] );
	}

	public function test_you_cannot_ban_a_wildcard_covering_your_own_address() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.44';

		$clean = Ban_Options::sanitize( array( 'lists' => array( 'ips' => '203.0.113.*' ) ) );

		$this->assertSame( array(), $clean['lists']['ips'] );
	}

	public function test_you_cannot_ban_a_range_containing_your_own_address() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.44';

		$clean = Ban_Options::sanitize(
			array( 'lists' => array( 'ips_range' => "203.0.113.1-203.0.113.100\n8.8.8.1-8.8.8.9" ) )
		);

		$this->assertSame( array( '8.8.8.1-8.8.8.9' ), $clean['lists']['ips_range'] );
	}

	public function test_you_cannot_ban_your_own_user_agent() {
		$_SERVER['HTTP_USER_AGENT'] = 'MyBrowser/1.0';

		$clean = Ban_Options::sanitize(
			array( 'lists' => array( 'user_agents' => "MyBrowser*\nEvilBot*" ) )
		);

		$this->assertSame( array( 'EvilBot*' ), $clean['lists']['user_agents'] );
	}

	public function test_you_cannot_ban_your_own_site_as_a_referrer() {
		$clean = Ban_Options::sanitize(
			array( 'lists' => array( 'referers' => get_option( 'home' ) . "\nhttp://*.spam.test" ) )
		);

		$this->assertSame( array( 'http://*.spam.test' ), $clean['lists']['referers'] );
	}

	public function test_self_ban_protection_can_be_switched_off() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.44';

		add_filter( 'wp_ban_protect_self', '__return_false' );
		$clean = Ban_Options::sanitize( array( 'lists' => array( 'ips' => '203.0.113.44' ) ) );
		remove_filter( 'wp_ban_protect_self', '__return_false' );

		$this->assertSame( array( '203.0.113.44' ), $clean['lists']['ips'] );
	}
}
