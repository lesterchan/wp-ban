<?php
/**
 * The settings screen: registration, markup and the save round-trip.
 *
 * @package WP-Ban
 */

/**
 * @covers WP_Ban_Settings
 */
class Test_Ban_Settings extends WP_Ban_TestCase {

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		if ( ! function_exists( 'get_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}

		/*
		 * WP_List_Table::__construct() reaches WP_Screen::get(), which reads
		 * $GLOBALS['hook_suffix'] unguarded on WordPress 6.0 -- the notice was
		 * only fixed in a later release. wp-admin always sets it before a page
		 * callback runs, so this is the test bootstrap standing in for
		 * admin.php rather than a plugin-side problem.
		 */
		$GLOBALS['hook_suffix'] = 'settings_page_wp-ban';

		set_current_screen( 'settings_page_wp-ban' );

		/*
		 * WP_Ban::__construct() only calls init() when is_admin() is true, which
		 * it is not under the test bootstrap, so the admin hooks have to be
		 * registered explicitly here.
		 */
		WP_Ban_Settings::init();
		WP_Ban_Settings::register();

		/*
		 * check_ajax_referer() calls a bare die( '-1' ) -- not wp_die() -- when
		 * wp_doing_ajax() is false, which no die handler can intercept and which
		 * takes the whole runner down. The endpoint really is an AJAX request in
		 * production, so saying so here is accurate, not a workaround.
		 */
		add_filter( 'wp_doing_ajax', '__return_true' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'wp_doing_ajax', '__return_true' );

		parent::tear_down();
	}

	/**
	 * Render the screen.
	 *
	 * These pages are the least covered part of any of these plugins and the
	 * likeliest place for a rendering bug, so diagnostics are collected too:
	 * asserting the page is merely non-empty proves very little.
	 *
	 * @return string
	 */
	private function render() {
		$notices = array();

		set_error_handler(
			static function ( $errno, $errstr, $errfile, $errline ) use ( &$notices ) {
				$notices[] = $errstr . ' in ' . basename( $errfile ) . ':' . $errline;
				return true;
			}
		);

		try {
			ob_start();
			WP_Ban_Settings::render();
			$html = (string) ob_get_clean();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $notices, 'the settings screen raised PHP diagnostics' );

		return $html;
	}

	public function test_the_screen_renders_without_diagnostics() {
		$html = $this->render();

		$this->assertGreaterThan( 2000, strlen( $html ) );
		$this->assertStringNotContainsString( '<?php', $html );
		$this->assertStringNotContainsString( 'Fatal error', $html );
	}

	/**
	 * A /* translators: *​/ comment that drifts into HTML context is printed to
	 * the user, and a value escaped twice renders as a visible &amp;amp;.
	 * Neither is caught by lint or by a smoke test.
	 */
	public function test_the_screen_is_free_of_the_usual_rendering_damage() {
		$html = $this->render();

		$this->assertStringNotContainsString( 'translators:', $html );
		$this->assertStringNotContainsString( '&amp;amp;', $html );
		$this->assertStringNotContainsString( '&amp;quot;', $html );
	}

	public function test_the_form_posts_to_options_php_with_the_settings_group() {
		$html = $this->render();

		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertStringContainsString( WP_Ban_Settings::GROUP, $html );
	}

	/**
	 * Nested field names are what let one sanitize_callback receive the lot.
	 *
	 * @dataProvider data_list_keys
	 *
	 * @param string $key List key.
	 */
	public function test_every_list_has_a_textarea( $key ) {
		$this->assertStringContainsString(
			WP_Ban_Options::OPTION . '[lists][' . $key . ']',
			$this->render()
		);
	}

	public function data_list_keys() {
		return array(
			array( 'ips' ),
			array( 'ips_range' ),
			array( 'hosts' ),
			array( 'referers' ),
			array( 'user_agents' ),
			array( 'exclude_ips' ),
		);
	}

	public function test_the_proxy_and_message_fields_are_present() {
		$html = $this->render();

		$this->assertStringContainsString( WP_Ban_Options::OPTION . '[reverse_proxy]', $html );
		$this->assertStringContainsString( WP_Ban_Options::OPTION . '[ip_header]', $html );
		$this->assertStringContainsString( WP_Ban_Options::OPTION . '[message]', $html );
	}

	/**
	 * Message tokens must survive phpcbf.
	 *
	 * A % inside a translatable string is read by phpcbf as a printf placeholder
	 * and renumbered, which would put %1$SITE_NAME% on the screen. The token list
	 * is emitted as code spans outside the translated strings to prevent that.
	 */
	public function test_the_message_tokens_render_literally() {
		$html = $this->render();

		foreach ( array( '%SITE_NAME%', '%SITE_URL%', '%USER_IP%', '%USER_HOSTNAME%', '%USER_ATTEMPTS_COUNT%', '%TOTAL_ATTEMPTS_COUNT%' ) as $token ) {
			$this->assertStringContainsString( $token, $html, "{$token} is not offered on the screen" );
		}

		$this->assertStringNotContainsString( '%1$', $html );
	}

	public function test_stored_entries_appear_in_their_textarea_unescaped() {
		$this->set_options(
			array( 'lists' => array( 'referers' => array( 'http://*.spam.test/?a=1&b=2' ) ) )
		);

		$html = $this->render();

		// esc_textarea() is the escaping at the sink, so the raw & appears as
		// a single &amp; and nothing more.
		$this->assertStringContainsString( 'http://*.spam.test/?a=1&amp;b=2', $html );
		$this->assertStringNotContainsString( '&amp;amp;', $html );
	}

	public function test_the_stats_table_lists_recorded_addresses() {
		WP_Ban_Stats::record( '203.0.113.99' );

		$html = $this->render();

		$this->assertStringContainsString( '203.0.113.99', $html );
		$this->assertStringContainsString( '_wpnonce', $html );
	}

	public function test_the_stats_table_is_paginated() {
		for ( $i = 1; $i <= 60; $i++ ) {
			WP_Ban_Stats::record( '203.0.113.' . $i );
		}

		$table = new WP_Ban_Stats_Table();
		$table->prepare_items();

		// The pre-2.0.0 screen rendered every recorded address on one page.
		$this->assertCount( 50, $table->items );
	}

	public function test_the_stats_table_sorts_by_attempts_by_default() {
		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '203.0.113.2' );
		WP_Ban_Stats::record( '203.0.113.2' );

		$table = new WP_Ban_Stats_Table();
		$table->prepare_items();

		$this->assertSame( '203.0.113.2', $table->items[0]['ip'] );
	}

	/**
	 * The real save path a form post takes.
	 *
	 * Registering the setting installs the sanitize_option_* filter, so writing
	 * the option is exactly what submitting the form does.
	 */
	public function test_a_form_post_round_trips_through_the_registered_sanitizer() {
		$referer = 'http://*.spam.test/path?a=1&b=2';

		update_option(
			WP_Ban_Options::OPTION,
			array(
				'reverse_proxy' => '1',
				'ip_header'     => 'HTTP_CF_CONNECTING_IP',
				'lists'         => array(
					'ips'      => "192.168.77.10\n10.1.*.*",
					'referers' => $referer,
				),
				'message'       => '<div id="wp-ban-container"><p>Nope</p></div>',
			)
		);

		WP_Ban_Options::flush_cache();

		$this->assertSame( array( $referer ), WP_Ban_Options::list_of( 'referers' ) );
		$this->assertSame( array( '192.168.77.10', '10.1.*.*' ), WP_Ban_Options::list_of( 'ips' ) );
		$this->assertTrue( WP_Ban_Options::get()['reverse_proxy'] );
		$this->assertSame( 'HTTP_CF_CONNECTING_IP', WP_Ban_Options::get()['ip_header'] );
	}

	/**
	 * Re-submitting exactly what the screen shows must be a no-op. Before
	 * 2.0.0 pre-escaping compounded on every save.
	 */
	public function test_saving_twice_changes_nothing() {
		$this->test_a_form_post_round_trips_through_the_registered_sanitizer();

		$before = WP_Ban_Options::get();

		update_option(
			WP_Ban_Options::OPTION,
			array(
				'reverse_proxy' => $before['reverse_proxy'] ? '1' : '',
				'ip_header'     => $before['ip_header'],
				'lists'         => array(
					'ips'         => WP_Ban_Options::list_to_lines( 'ips' ),
					'ips_range'   => WP_Ban_Options::list_to_lines( 'ips_range' ),
					'hosts'       => WP_Ban_Options::list_to_lines( 'hosts' ),
					'referers'    => WP_Ban_Options::list_to_lines( 'referers' ),
					'user_agents' => WP_Ban_Options::list_to_lines( 'user_agents' ),
					'exclude_ips' => WP_Ban_Options::list_to_lines( 'exclude_ips' ),
				),
				'message'       => WP_Ban_Options::message(),
			)
		);

		WP_Ban_Options::flush_cache();

		$this->assertSame( $before, WP_Ban_Options::get() );
	}

	/**
	 * The point of the consolidation: three prefixed rows where there were ten
	 * unprefixed ones.
	 */
	public function test_the_plugin_owns_exactly_three_option_rows() {
		global $wpdb;

		WP_Ban_Options::maybe_upgrade();
		$this->test_a_form_post_round_trips_through_the_registered_sanitizer();
		WP_Ban_Stats::record( '203.0.113.1' );

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
				$wpdb->esc_like( 'wp_ban_' ) . '%'
			)
		);

		$this->assertSame( array( 'wp_ban_options', 'wp_ban_stats', 'wp_ban_version' ), $rows );

		$legacy = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE option_name IN (
				'banned_options','banned_stats','ban_db_version','banned_ips','banned_ips_range',
				'banned_hosts','banned_referers','banned_user_agents','banned_exclude_ips','banned_message'
			 )"
		);

		$this->assertSame( array(), $legacy, 'an unprefixed row was left behind' );
	}

	public function test_the_preview_endpoint_is_registered_without_a_public_twin() {
		$this->assertNotFalse( has_action( 'wp_ajax_ban-admin', array( 'WP_Ban_Settings', 'ajax_preview' ) ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_ban-admin' ) );
	}

	/**
	 * Subscribers must not reach the preview.
	 *
	 * Every authenticated role can reach a wp_ajax_* hook, so the hook is not an
	 * authorisation check. Before 2.0.0 there was none.
	 */
	public function test_a_subscriber_cannot_read_the_preview() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_REQUEST['_ajax_nonce'] = wp_create_nonce( 'wp-ban_preview' );

		$this->expectException( WPDieException::class );

		try {
			WP_Ban_Settings::ajax_preview();
		} finally {
			unset( $_REQUEST['_ajax_nonce'] );
		}
	}

	public function test_a_request_without_a_nonce_is_refused() {
		unset( $_REQUEST['_ajax_nonce'], $_GET['_ajax_nonce'], $_POST['_ajax_nonce'] );

		$this->expectException( WPDieException::class );

		WP_Ban_Settings::ajax_preview();
	}

	public function test_an_administrator_with_a_nonce_gets_the_preview() {
		$this->set_options(
			array( 'message' => '<div id="wp-ban-container"><p>PREVIEW-CANARY %USER_IP%</p></div>' )
		);

		$_REQUEST['_ajax_nonce'] = wp_create_nonce( 'wp-ban_preview' );

		ob_start();

		try {
			WP_Ban_Settings::ajax_preview();
		} catch ( WPDieException $e ) {
			unset( $e );
		}

		$html = (string) ob_get_clean();

		unset( $_REQUEST['_ajax_nonce'] );

		$this->assertStringContainsString( 'PREVIEW-CANARY', $html );
		$this->assertStringNotContainsString( '%USER_IP%', $html );
	}

	/**
	 * Each validation warning must appear once.
	 *
	 * WordPress already prints the errors queued by the sanitize callback for
	 * pages registered under Settings, and common.js relocates the notices into
	 * .wrap. A manual settings_errors() call in render() therefore rendered
	 * every warning twice, inside what looked like the plugin's own markup.
	 */
	public function test_validation_notices_are_not_printed_twice() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.44';

		// Queue one of each: a rejected range and a self-ban.
		WP_Ban_Options::sanitize(
			array(
				'lists' => array(
					'ips'       => '203.0.113.44',
					'ips_range' => 'garbage-9.9.9.9',
				),
			)
		);

		$this->assertNotEmpty( get_settings_errors( WP_Ban_Options::OPTION ) );

		ob_start();
		WP_Ban_Settings::render();
		$html = (string) ob_get_clean();

		$this->assertSame( 0, substr_count( $html, 'setting-error-ban_bad_range' ) );
		$this->assertSame( 0, substr_count( $html, 'setting-error-ban_self' ) );
	}

	/**
	 * The bulk form must be checked against the nonce the table emits.
	 *
	 * WP_List_Table::display_tablenav() outputs its own _wpnonce for
	 * "bulk-{$plural}". A second wp_nonce_field() in the same form is
	 * overridden by it -- both inputs are named _wpnonce -- so every bulk
	 * action failed its referer check with "An error occurred."
	 */
	public function test_the_stats_form_uses_the_list_table_nonce() {
		$this->assertSame( 'bulk-' . WP_Ban_Settings::STATS_PLURAL, WP_Ban_Settings::STATS_NONCE );

		WP_Ban_Stats::record( '203.0.113.99' );

		$html = $this->render();

		// Exactly one _wpnonce in the stats form: the table's own. Slice from
		// the Ban Stats heading, since the settings form above has one too.
		$stats_form = substr( $html, (int) strpos( $html, 'Ban Stats' ) );

		$this->assertSame( 1, substr_count( $stats_form, 'name="_wpnonce"' ) );

		// And it must be the nonce handle_stats_actions() checks against.
		preg_match( '/name="_wpnonce" value="([^"]+)"/', $stats_form, $m );

		$this->assertNotEmpty( $m, 'the stats form has no nonce at all' );
		$this->assertSame( 1, wp_verify_nonce( $m[1], WP_Ban_Settings::STATS_NONCE ) );
	}

	public function test_the_settings_link_points_at_the_screen() {
		$links = WP_Ban_Settings::action_links( array() );

		$this->assertStringContainsString( 'page=' . WP_Ban_Settings::PAGE, $links[0] );
	}
}
