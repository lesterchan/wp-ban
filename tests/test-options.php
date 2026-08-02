<?php
/**
 * Option storage, normalisation and the sanitize callback.
 *
 * @package WP-Ban
 */

/**
 * @covers WP_Ban_Options
 */
class WP_Ban_Options_Test extends WP_Ban_TestCase {

	public function test_a_missing_row_yields_the_defaults() {
		delete_option( WP_Ban_Options::OPTION );
		WP_Ban_Options::flush_cache();

		$this->assertSame( array(), WP_Ban_Options::list_of( 'ips' ) );
		// Blank, which is what says "no proxy" now that the checkbox is gone.
		$this->assertSame( '', WP_Ban_Options::get()['ip_header'] );
		$this->assertStringContainsString( 'wp-ban-container', WP_Ban_Options::message() );
	}

	public function test_a_corrupt_row_falls_back_to_the_defaults() {
		update_option( WP_Ban_Options::OPTION, 'not an array at all' );
		WP_Ban_Options::flush_cache();

		$this->assertSame( array(), WP_Ban_Options::list_of( 'ips' ) );
		$this->assertNotEmpty( WP_Ban_Options::message() );
	}

	public function test_a_partially_shaped_row_is_normalised() {
		update_option( WP_Ban_Options::OPTION, array( 'lists' => array( 'ips' => 'not a list' ) ) );
		WP_Ban_Options::flush_cache();

		$this->assertSame( array(), WP_Ban_Options::list_of( 'ips' ) );
		$this->assertSame( array(), WP_Ban_Options::list_of( 'hosts' ) );
	}

	public function test_lines_to_list_trims_and_drops_blanks_and_duplicates() {
		$this->assertSame(
			array( '1.1.1.1', '2.2.2.2' ),
			WP_Ban_Options::lines_to_list( "1.1.1.1\n\n  2.2.2.2  \n\n1.1.1.1\n" )
		);
	}

	public function test_lines_to_list_handles_every_line_ending() {
		$this->assertSame(
			array( 'a', 'b', 'c' ),
			WP_Ban_Options::lines_to_list( "a\r\nb\rc" )
		);
	}

	/**
	 * The sanitize callback also runs for programmatic writes -- add_option()
	 * with the defaults on activation, or the common
	 * `$o = WP_Ban_Options::get(); $o['x'] = ...; update_option( ... )`. Those pass
	 * lists that are already split, and casting them to a string would warn and
	 * throw every entry away.
	 */
	public function test_lines_to_list_accepts_an_already_split_list() {
		$this->assertSame(
			array( '1.1.1.1', '2.2.2.2' ),
			WP_Ban_Options::lines_to_list( array( '1.1.1.1', '', '2.2.2.2' ) )
		);
	}

	public function test_writing_back_what_get_returns_keeps_the_lists() {
		WP_Ban_Settings::register();

		$this->set_options( array( 'lists' => array( 'ips' => array( '1.1.1.1', '2.2.2.2' ) ) ) );

		$options              = WP_Ban_Options::get();
		$options['ip_header'] = '';

		update_option( WP_Ban_Options::OPTION, $options );
		WP_Ban_Options::flush_cache();

		$this->assertSame( array( '1.1.1.1', '2.2.2.2' ), WP_Ban_Options::list_of( 'ips' ) );
	}

	/**
	 * Entries are patterns matched against request data, not markup. Before
	 * 2.0.0 they were stored esc_html()'d, so a referrer pattern with a query
	 * string could never match, and re-saving compounded &amp; into &amp;amp;.
	 */
	public function test_an_ampersand_survives_the_sanitizer_unescaped() {
		$clean = WP_Ban_Options::sanitize(
			array( 'lists' => array( 'referers' => 'http://*.spam.test/?a=1&b=2' ) )
		);

		$this->assertSame( array( 'http://*.spam.test/?a=1&b=2' ), $clean['lists']['referers'] );
	}

	public function test_sanitizing_is_idempotent() {
		$input = array(
			'ip_header' => 'HTTP_CF_CONNECTING_IP',
			'lists'     => array( 'referers' => 'http://*.spam.test/?a=1&b=2' ),
			'message'   => '<div id="wp-ban-container"><p>Nope &amp; goodbye</p></div>',
		);

		$once  = WP_Ban_Options::sanitize( $input );
		$twice = WP_Ban_Options::sanitize( $once );

		$this->assertSame( $once, $twice );
	}

	/**
	 * The retired setting is not merely unread: it is not stored either.
	 *
	 * The sanitizer starts from what is stored, so a key it did not explicitly
	 * drop would be carried forward by every save for the life of the install.
	 */
	public function test_the_sanitizer_never_stores_a_reverse_proxy_key() {
		$clean = WP_Ban_Options::sanitize( array( 'reverse_proxy' => '1' ) );

		$this->assertArrayNotHasKey( 'reverse_proxy', $clean );
	}

	/**
	 * A field the submission never mentioned keeps whatever is stored.
	 *
	 * The screen is three tabs posting disjoint sets of fields into one option
	 * row, and a sanitizer that returned only what it was given would blank
	 * every field the submitting tab does not own.
	 */
	public function test_a_field_the_submission_omitted_keeps_its_stored_value() {
		$this->set_options(
			array(
				'ip_header' => 'HTTP_CF_CONNECTING_IP',
				'lists'     => array( 'ips' => array( '203.0.113.5' ) ),
				'message'   => '<div id="wp-ban-container"><p>Mine</p></div>',
			)
		);

		$clean = WP_Ban_Options::sanitize( array( 'message' => '<div id="wp-ban-container"><p>Yours</p></div>' ) );

		$this->assertSame( 'HTTP_CF_CONNECTING_IP', $clean['ip_header'] );
		$this->assertSame( array( '203.0.113.5' ), $clean['lists']['ips'] );
		$this->assertStringContainsString( 'Yours', $clean['message'] );
	}

	/**
	 * A list the submission did mention is replaced, emptied included.
	 *
	 * The other half of the merge: "keep what was not posted" must not turn
	 * into "a list can never be cleared".
	 */
	public function test_a_list_the_submission_emptied_is_actually_emptied() {
		$this->set_options( array( 'lists' => array( 'ips' => array( '203.0.113.5' ) ) ) );

		$clean = WP_Ban_Options::sanitize( array( 'lists' => array( 'ips' => '' ) ) );

		$this->assertSame( array(), $clean['lists']['ips'] );
	}

	/**
	 * Self-ban protection runs over the lists that were posted and no others.
	 *
	 * Re-running it over the stored ones would let a save from the Templates
	 * tab quietly delete an entry an owner added from a different address, and
	 * complain about it on a screen that never showed the list.
	 */
	public function test_a_save_from_another_tab_does_not_re_examine_the_stored_lists() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.44';

		// add_settings_error()'s queue is a plain global that no transaction
		// rolls back, so an earlier test's warnings would answer for this one.
		$GLOBALS['wp_settings_errors'] = array();

		// Stored while the owner was somewhere else, so it matches them now.
		$this->set_options( array( 'lists' => array( 'ips' => array( '203.0.113.44' ) ) ) );

		$clean = WP_Ban_Options::sanitize( array( 'message' => '<div id="wp-ban-container"><p>Nope</p></div>' ) );

		$this->assertSame( array( '203.0.113.44' ), $clean['lists']['ips'] );
		$this->assertSame( array(), get_settings_errors( WP_Ban_Options::OPTION ) );
	}

	public function test_a_header_name_is_restricted_to_the_shape_php_uses() {
		$this->assertSame( 'HTTP_CF_CONNECTING_IP', WP_Ban_Options::sanitize( array( 'ip_header' => 'http_cf_connecting_ip' ) )['ip_header'] );
		$this->assertSame( '', WP_Ban_Options::sanitize( array( 'ip_header' => 'not a header name' ) )['ip_header'] );
		$this->assertSame( '', WP_Ban_Options::sanitize( array( 'ip_header' => 'HTTP_X; DROP' ) )['ip_header'] );
	}

	public function test_malformed_ranges_are_rejected_on_save() {
		$clean = WP_Ban_Options::sanitize(
			array( 'lists' => array( 'ips_range' => "203.0.113.10-203.0.113.20\ngarbage-203.0.113.255\nnope" ) )
		);

		$this->assertSame( array( '203.0.113.10-203.0.113.20' ), $clean['lists']['ips_range'] );
	}

	public function test_an_empty_message_falls_back_to_the_default() {
		$clean = WP_Ban_Options::sanitize( array( 'message' => '   ' ) );

		$this->assertSame( WP_Ban_Options::default_message(), $clean['message'] );
	}

	public function test_the_message_is_run_through_kses() {
		$clean = WP_Ban_Options::sanitize(
			array( 'message' => '<div id="wp-ban-container"><p>Nope</p><script>alert(1)</script></div>' )
		);

		$this->assertStringNotContainsString( '<script', $clean['message'] );
		$this->assertStringContainsString( 'wp-ban-container', $clean['message'] );
	}

	public function test_the_message_may_be_a_whole_html_document() {
		$clean = WP_Ban_Options::sanitize( array( 'message' => WP_Ban_Options::default_message() ) );

		foreach ( array( '<html>', '<head>', '<meta charset="utf-8">', '<title>', '<style>', '<body>' ) as $tag ) {
			$this->assertStringContainsString( $tag, $clean['message'], "kses stripped {$tag}" );
		}

		// The one rule the default template carries. kses passes a <style>
		// element's contents through as text, so it must arrive intact.
		$this->assertStringContainsString(
			'#wp-ban-container { text-align: center; font-weight: bold; }',
			$clean['message'],
			'kses mangled the default stylesheet'
		);
	}

	public function test_the_shipped_template_carries_no_inline_style_attribute() {
		$this->assertStringNotContainsString( 'style="', WP_Ban_Options::default_message() );
	}

	public function test_list_to_lines_round_trips() {
		$this->set_options( array( 'lists' => array( 'ips' => array( '1.1.1.1', '2.2.2.2' ) ) ) );

		$this->assertSame( "1.1.1.1\n2.2.2.2", WP_Ban_Options::list_to_lines( 'ips' ) );
		$this->assertSame(
			array( '1.1.1.1', '2.2.2.2' ),
			WP_Ban_Options::lines_to_list( WP_Ban_Options::list_to_lines( 'ips' ) )
		);
	}

	/**
	 * Before 2.0.0 this only ran when the current user's login was literally
	 * "admin", so everyone else could lock themselves out of their own site.
	 */
	public function test_you_cannot_ban_your_own_address() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.44';

		$clean = WP_Ban_Options::sanitize(
			array( 'lists' => array( 'ips' => "203.0.113.44\n8.8.8.8" ) )
		);

		$this->assertSame( array( '8.8.8.8' ), $clean['lists']['ips'] );
	}

	public function test_you_cannot_ban_a_wildcard_covering_your_own_address() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.44';

		$clean = WP_Ban_Options::sanitize( array( 'lists' => array( 'ips' => '203.0.113.*' ) ) );

		$this->assertSame( array(), $clean['lists']['ips'] );
	}

	public function test_you_cannot_ban_a_range_containing_your_own_address() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.44';

		$clean = WP_Ban_Options::sanitize(
			array( 'lists' => array( 'ips_range' => "203.0.113.1-203.0.113.100\n8.8.8.1-8.8.8.9" ) )
		);

		$this->assertSame( array( '8.8.8.1-8.8.8.9' ), $clean['lists']['ips_range'] );
	}

	public function test_you_cannot_ban_your_own_user_agent() {
		$_SERVER['HTTP_USER_AGENT'] = 'MyBrowser/1.0';

		$clean = WP_Ban_Options::sanitize(
			array( 'lists' => array( 'user_agents' => "MyBrowser*\nEvilBot*" ) )
		);

		$this->assertSame( array( 'EvilBot*' ), $clean['lists']['user_agents'] );
	}

	public function test_you_cannot_ban_your_own_site_as_a_referrer() {
		$clean = WP_Ban_Options::sanitize(
			array( 'lists' => array( 'referers' => get_option( 'home' ) . "\nhttp://*.spam.test" ) )
		);

		$this->assertSame( array( 'http://*.spam.test' ), $clean['lists']['referers'] );
	}

	public function test_self_ban_protection_can_be_switched_off() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.44';

		add_filter( 'wp_ban_protect_self', '__return_false' );
		$clean = WP_Ban_Options::sanitize( array( 'lists' => array( 'ips' => '203.0.113.44' ) ) );
		remove_filter( 'wp_ban_protect_self', '__return_false' );

		$this->assertSame( array( '203.0.113.44' ), $clean['lists']['ips'] );
	}
}
