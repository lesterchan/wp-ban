<?php
/**
 * The settings screen: registration, markup and the save round-trip.
 *
 * @package WP-Ban
 */

/**
 * @covers WP_Ban_Settings
 */
class WP_Ban_Settings_Test extends WP_Ban_TestCase {

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
		 * $GLOBALS['hook_suffix']. wp-admin always sets it before a page
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

		unset( $_GET['tab'] );

		parent::tear_down();
	}

	/**
	 * Render the screen.
	 *
	 * These pages are the least covered part of any of these plugins and the
	 * likeliest place for a rendering bug, so diagnostics are collected too:
	 * asserting the page is merely non-empty proves very little.
	 *
	 * @param string $tab Tab to draw. The screen's default when omitted.
	 * @return string
	 */
	private function render( $tab = '' ) {
		if ( '' === $tab ) {
			unset( $_GET['tab'] );
		} else {
			$_GET['tab'] = $tab;
		}

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

	/**
	 * @dataProvider data_tabs
	 *
	 * @param string $tab Tab slug.
	 */
	public function test_every_tab_renders_without_diagnostics( $tab ) {
		$html = $this->render( $tab );

		$this->assertGreaterThan( 1000, strlen( $html ), "the {$tab} tab rendered almost nothing" );
		$this->assertStringNotContainsString( '<?php', $html, 'No PHP tag reached the page, which would mean a template was echoed unparsed.' );
		$this->assertStringNotContainsString( 'Fatal error', $html, 'And no PHP diagnostic.' );
	}

	/**
	 * A /* translators: *​/ comment that drifts into HTML context is printed to
	 * the user, and a value escaped twice renders as a visible &amp;amp;.
	 * Neither is caught by lint or by a smoke test.
	 *
	 * @dataProvider data_tabs
	 *
	 * @param string $tab Tab slug.
	 */
	public function test_no_tab_carries_the_usual_rendering_damage( $tab ) {
		$html = $this->render( $tab );

		$this->assertStringNotContainsString( 'translators:', $html, 'No translator comment leaked into the markup.' );
		$this->assertStringNotContainsString( '&amp;amp;', $html, 'No ampersand is encoded twice.' );
		$this->assertStringNotContainsString( '&amp;quot;', $html, 'And no quote, which is what a second escaping pass would leave.' );
	}

	public function data_tabs() {
		return array(
			'stats'     => array( 'stats' ),
			'settings'  => array( 'settings' ),
			'templates' => array( 'templates' ),
		);
	}

	/**
	 * The three tabs, named exactly as the standard names them.
	 *
	 * "Ban Settings" and "Ban Stats" would repeat the heading above them, which
	 * is the drift §4.2.1 exists to stop.
	 */
	public function test_the_tabs_are_stats_settings_and_templates_in_that_order() {
		$this->assertSame(
			array( 'stats', 'settings', 'templates' ),
			array_keys( WP_Ban_Settings::tabs() ),
			'The tabs are in the documented order, stats first.'
		);

		$this->assertSame(
			array( 'Stats', 'Settings', 'Templates' ),
			array_values( WP_Ban_Settings::tabs() ),
			'With the labels that go with them.'
		);
	}

	/**
	 * The counters are what somebody opens this screen to look at.
	 */
	public function test_the_screen_opens_on_the_stats_tab() {
		unset( $_GET['tab'] );

		$this->assertSame( 'stats', WP_Ban_Settings::current_tab(), 'With no tab asked for, the screen opens on stats.' );

		$_GET['tab'] = 'not-a-tab';

		$this->assertSame( 'stats', WP_Ban_Settings::current_tab(), 'an unknown tab must fall back, not render nothing' );

		$_GET['tab'] = 'templates';

		$this->assertSame( 'templates', WP_Ban_Settings::current_tab(), 'And a tab asked for by name is the one that opens.' );
	}

	/**
	 * @dataProvider data_tabs
	 *
	 * @param string $tab Tab slug.
	 */
	public function test_every_tab_links_to_the_other_two_and_marks_itself_active( $tab ) {
		$html = $this->render( $tab );

		$this->assertStringContainsString( 'nav-tab-wrapper', $html, 'The screen carries the core tab nav.' );

		foreach ( array_keys( WP_Ban_Settings::tabs() ) as $slug ) {
			$this->assertStringContainsString(
				'tab=' . $slug,
				$html,
				"the {$tab} tab does not link to {$slug}"
			);
		}

		// One active tab, and it is this one.
		$this->assertSame( 1, substr_count( $html, 'nav-tab-active' ), 'With exactly one tab marked active, not two.' );

		preg_match( '/href="([^"]*)" class="nav-tab nav-tab-active"/', $html, $matches );

		$this->assertNotEmpty( $matches, 'no tab is marked active' );
		$this->assertStringContainsString( 'tab=' . $tab, html_entity_decode( $matches[1] ), 'The ' . $tab . ' tab is not linked from this one.' );
	}

	public function test_the_stats_tab_is_a_table_rather_than_a_settings_form() {
		$html = $this->render( 'stats' );

		$this->assertStringNotContainsString( 'action="options.php"', $html, 'The stats tab is not a settings form; there is nothing on it to save.' );
		$this->assertStringContainsString( 'wp-list-table', $html, 'It is a list table.' );
	}

	/**
	 * @dataProvider data_form_tabs
	 *
	 * @param string $tab Tab slug.
	 */
	public function test_a_form_tab_posts_to_options_php_with_the_one_settings_group( $tab ) {
		$html = $this->render( $tab );

		$this->assertStringContainsString( 'action="options.php"', $html, 'A form tab posts to options.php.' );
		$this->assertStringContainsString( WP_Ban_Settings::GROUP, $html, 'Under the one settings group the whole screen shares.' );

		// One group across all three tabs, so one option row behind them.
		$this->assertSame( 1, substr_count( $html, 'option_page' ), 'And declares it once, so a save is not registered twice.' );
	}

	/**
	 * The tab has to survive the round trip through options.php, or a save from
	 * Templates lands back on Stats with a notice about fields nobody can see.
	 *
	 * @dataProvider data_form_tabs
	 *
	 * @param string $tab Tab slug.
	 */
	public function test_a_form_tab_carries_itself_through_the_save( $tab ) {
		$html = $this->render( $tab );

		preg_match_all( '/name="_wp_http_referer" value="([^"]*)"/', $html, $matches );

		$this->assertNotEmpty( $matches[1], 'the form posts no referer at all' );

		// PHP keeps the last of a repeated name, and settings_fields() emitted
		// one of its own first, so the last is the one that decides.
		$this->assertStringContainsString(
			'tab=' . $tab,
			html_entity_decode( end( $matches[1] ) ),
			'the save would come back to the wrong tab'
		);
	}

	public function data_form_tabs() {
		return array(
			'settings'  => array( 'settings' ),
			'templates' => array( 'templates' ),
		);
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
			$this->render( 'settings' ),
			'The ' . $key . ' list has no textarea to edit it in.'
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

	/**
	 * Each field is on exactly one tab, and it is the tab it belongs to.
	 *
	 * The half that matters is the absence: a section left registered against
	 * the old page slug would draw on every tab, which is the failure this
	 * split is meant to remove.
	 */
	public function test_each_field_is_on_its_own_tab_and_not_on_the_others() {
		$settings  = $this->render( 'settings' );
		$templates = $this->render( 'templates' );
		$stats     = $this->render( 'stats' );

		$header  = WP_Ban_Options::OPTION . '[ip_header]';
		$lists   = WP_Ban_Options::OPTION . '[lists]';
		$message = WP_Ban_Options::OPTION . '[message]';

		$this->assertStringContainsString( $header, $settings, 'The header field is on the settings tab.' );
		$this->assertStringContainsString( $lists, $settings, 'And the lists.' );
		$this->assertStringNotContainsString( $message, $settings, 'the message template leaked onto the Settings tab' );

		$this->assertStringContainsString( $message, $templates, 'While the message is on the templates tab, so neither tab carries the other fields.' );
		$this->assertStringNotContainsString( $header, $templates, 'the proxy field leaked onto the Templates tab' );
		$this->assertStringNotContainsString( $lists, $templates, 'the ban lists leaked onto the Templates tab' );

		foreach ( array( $header, $lists, $message ) as $field ) {
			$this->assertStringNotContainsString( $field, $stats, 'a settings field leaked onto the Stats tab' );
		}
	}

	/**
	 * The removed control is removed from the screen, not merely from the docs.
	 *
	 * 2.0.0 drops "This site is behind a reverse proxy.": a blank header field
	 * already says "no proxy", so the box's only distinct meaning was "trust
	 * whichever of the seven forwarding headers turns up" -- the thing the field
	 * beneath it warns owners off. A control left rendering after the setting
	 * behind it has gone is a box an owner ticks and a save that ignores them.
	 */
	public function test_the_settings_tab_no_longer_offers_a_reverse_proxy_checkbox() {
		$html = $this->render( 'settings' );

		$this->assertStringNotContainsString( WP_Ban_Options::OPTION . '[reverse_proxy]', $html, 'The withdrawn reverse proxy checkbox is gone from the form.' );
		$this->assertStringNotContainsString( 'This site is behind a reverse proxy', $html, 'And its label with it, rather than standing over nothing.' );
	}

	/**
	 * The field's placeholder names the header its own Example sentence names.
	 *
	 * All five proxy-aware plugins offer the same one. It used to read
	 * HTTP_CF_CONNECTING_IP here, which suggested Cloudflare to a site that had
	 * never heard of it while the line below suggested something else.
	 */
	public function test_the_header_field_and_its_example_name_the_same_header() {
		$html = $this->render( 'settings' );

		$this->assertStringContainsString( 'placeholder="HTTP_X_FORWARDED_FOR"', $html, 'The field suggests a header name.' );
		$this->assertStringContainsString( 'Example: <code>HTTP_X_FORWARDED_FOR</code>', $html, 'And the example beside it names the same one, so the two cannot disagree.' );

		// The sentence the other four plugins carry, byte for byte. It used to
		// end "..., unless the checkbox above is ticked", and there is no
		// checkbox above.
		$this->assertStringContainsString(
			'Leave this blank unless the site is behind a reverse proxy or CDN. Blank means the address the web server saw is used.',
			$html,
			'With the description that says when to fill it in and what blank means.'
		);
	}

	/**
	 * Message tokens must survive phpcbf.
	 *
	 * A % inside a translatable string is read by phpcbf as a printf placeholder
	 * and renumbered, which would put %1$SITE_NAME% on the screen. The token list
	 * is emitted as code spans outside the translated strings to prevent that.
	 */
	public function test_the_message_tokens_render_literally() {
		$html = $this->render( 'templates' );

		foreach ( array( '%SITE_NAME%', '%SITE_URL%', '%USER_IP%', '%USER_HOSTNAME%', '%USER_ATTEMPTS_COUNT%', '%TOTAL_ATTEMPTS_COUNT%' ) as $token ) {
			$this->assertStringContainsString( $token, $html, "{$token} is not offered on the screen" );
		}

		$this->assertStringNotContainsString( '%1$', $html, 'The message tokens render as themselves rather than being consumed as format specifiers.' );
	}

	public function test_stored_entries_appear_in_their_textarea_unescaped() {
		$this->set_options(
			array( 'lists' => array( 'referers' => array( 'http://*.spam.test/?a=1&b=2' ) ) )
		);

		$html = $this->render( 'settings' );

		// esc_textarea() is the escaping at the sink, so the raw & appears as
		// a single &amp; and nothing more.
		$this->assertStringContainsString( 'http://*.spam.test/?a=1&amp;b=2', $html, 'A stored entry appears in its textarea escaped exactly once.' );
		$this->assertStringNotContainsString( '&amp;amp;', $html, 'With no double encoding, which would change the entry on the next save.' );
	}

	public function test_the_stats_table_lists_recorded_addresses() {
		WP_Ban_Stats::record( '203.0.113.99' );

		$html = $this->render( 'stats' );

		$this->assertStringContainsString( '203.0.113.99', $html, 'The stats table lists the addresses that were recorded.' );
		$this->assertStringContainsString( '_wpnonce', $html, 'And carries a nonce, so the bulk action is not forgeable.' );
	}

	public function test_the_stats_table_is_paginated() {
		for ( $i = 1; $i <= 30; $i++ ) {
			WP_Ban_Stats::record( '203.0.113.' . $i );
		}

		$table = new WP_Ban_Stats_Table();
		$table->prepare_items();

		// The pre-2.0.0 screen rendered every recorded address on one page.
		$this->assertCount( 20, $table->items, 'The stats table shows one page of twenty rather than every row.' );
	}

	public function test_the_stats_table_sorts_by_attempts_by_default() {
		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '203.0.113.2' );
		WP_Ban_Stats::record( '203.0.113.2' );

		$table = new WP_Ban_Stats_Table();
		$table->prepare_items();

		$this->assertSame( '203.0.113.2', $table->items[0]['ip'], 'the busiest address belongs at the top' );
	}

	/**
	 * The sort a column header link asks for is honoured.
	 *
	 * Read straight from the query string, because that is what core's own
	 * sortable headers put there -- they swap orderby and order on the current
	 * URL and carry no nonce.
	 */
	public function test_the_stats_table_honours_the_sort_the_column_headers_ask_for() {
		WP_Ban_Stats::record( '203.0.113.9' );
		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '203.0.113.1' );

		$_GET['orderby'] = 'ip';
		$_GET['order']   = 'asc';

		$table = new WP_Ban_Stats_Table();
		$table->prepare_items();

		$this->assertSame( '203.0.113.1', $table->items[0]['ip'], 'ascending sort by address was ignored' );

		$_GET['order'] = 'desc';

		$table = new WP_Ban_Stats_Table();
		$table->prepare_items();

		$this->assertSame( '203.0.113.9', $table->items[0]['ip'], 'descending sort by address was ignored' );
	}

	/**
	 * Anything that is not one of the two sortable columns is discarded.
	 */
	public function test_an_unknown_sort_column_falls_back_to_attempts() {
		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '203.0.113.2' );
		WP_Ban_Stats::record( '203.0.113.2' );

		$_GET['orderby'] = 'DROP TABLE';
		$_GET['order']   = 'sideways';

		$table = new WP_Ban_Stats_Table();
		$table->prepare_items();

		$this->assertSame( '203.0.113.2', $table->items[0]['ip'], 'an unknown orderby must fall back to attempts, descending' );
	}

	/**
	 * The notice after a reset survives the redirect without a query argument.
	 *
	 * It used to travel as ?wp-ban-reset=all, which meant reading a marker back
	 * out of $_GET with no nonce to check it against -- and which claimed a
	 * reset had happened every time that URL was opened again from a bookmark.
	 */
	public function test_the_reset_notice_is_shown_once_and_then_forgotten() {
		set_transient( 'wp_ban_notice_' . get_current_user_id(), 'all', MINUTE_IN_SECONDS );

		$this->assertStringContainsString( 'All ban stats were reset.', $this->render(), 'The reset notice is shown on the render that follows the reset.' );

		$this->assertFalse(
			get_transient( 'wp_ban_notice_' . get_current_user_id() ),
			'the notice must be cleared once it has been shown'
		);

		$this->assertStringNotContainsString(
			'All ban stats were reset.',
			$this->render(),
			'a refresh must not repeat the notice'
		);
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
				'ip_header' => 'HTTP_CF_CONNECTING_IP',
				'lists'     => array(
					'ips'      => "192.168.77.10\n10.1.*.*",
					'referers' => $referer,
				),
				'message'   => '<div id="wp-ban-container"><p>Nope</p></div>',
			)
		);

		WP_Ban_Options::flush_cache();

		$this->assertSame( array( $referer ), WP_Ban_Options::list_of( 'referers' ), 'A real form post reaches the registered sanitiser, referrers and all.' );
		$this->assertSame( array( '192.168.77.10', '10.1.*.*' ), WP_Ban_Options::list_of( 'ips' ), 'And the IP list.' );
		$this->assertSame( 'HTTP_CF_CONNECTING_IP', WP_Ban_Options::get()['ip_header'], 'And the header field.' );
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
				'ip_header' => $before['ip_header'],
				'lists'     => array(
					'ips'         => WP_Ban_Options::list_to_lines( 'ips' ),
					'ips_range'   => WP_Ban_Options::list_to_lines( 'ips_range' ),
					'hosts'       => WP_Ban_Options::list_to_lines( 'hosts' ),
					'referers'    => WP_Ban_Options::list_to_lines( 'referers' ),
					'user_agents' => WP_Ban_Options::list_to_lines( 'user_agents' ),
					'exclude_ips' => WP_Ban_Options::list_to_lines( 'exclude_ips' ),
				),
				'message'   => WP_Ban_Options::message(),
			)
		);

		WP_Ban_Options::flush_cache();

		$this->assertSame( $before, WP_Ban_Options::get(), 'Saving the same form twice changes nothing.' );
	}

	/**
	 * Saving one tab must leave the other two exactly as they were.
	 *
	 * This is the regression the split invites and it is silent.
	 * register_setting()'s sanitize_callback is handed only the fields the
	 * submitting form posted, so a sanitizer that returned just what it was
	 * given would blank the banned message the moment somebody edited a ban
	 * list -- with "Settings saved." on the screen and nothing to say the
	 * template had gone. Both directions, because either tab can be the one
	 * that does the damage.
	 */
	public function test_saving_one_tab_leaves_the_other_tabs_values_alone() {
		$message = '<div id="wp-ban-container"><p>A carefully written message</p></div>';

		$this->set_options(
			array(
				'ip_header' => 'HTTP_CF_CONNECTING_IP',
				'lists'     => array(
					'ips'         => array( '192.168.77.10' ),
					'user_agents' => array( 'EvilBot*' ),
				),
				'message'   => $message,
			)
		);

		// The Settings tab, posting exactly what it owns and nothing else.
		update_option(
			WP_Ban_Options::OPTION,
			array(
				'ip_header' => 'HTTP_X_REAL_IP',
				'lists'     => array(
					'ips'         => '203.0.113.5',
					'ips_range'   => '',
					'hosts'       => '',
					'referers'    => '',
					'user_agents' => 'EvilBot*',
					'exclude_ips' => '',
				),
			)
		);

		WP_Ban_Options::flush_cache();

		$this->assertSame( $message, WP_Ban_Options::message(), 'saving the Settings tab destroyed the message template' );
		$this->assertSame( array( '203.0.113.5' ), WP_Ban_Options::list_of( 'ips' ), 'Saving the templates tab leaves the settings tab lists alone.' );
		$this->assertSame( 'HTTP_X_REAL_IP', WP_Ban_Options::get()['ip_header'], 'And its header field.' );

		// And the Templates tab, which posts one field.
		update_option(
			WP_Ban_Options::OPTION,
			array( 'message' => '<div id="wp-ban-container"><p>Rewritten</p></div>' )
		);

		WP_Ban_Options::flush_cache();

		$this->assertStringContainsString( 'Rewritten', WP_Ban_Options::message(), 'While the message it did post is actually written.' );
		$this->assertSame( array( '203.0.113.5' ), WP_Ban_Options::list_of( 'ips' ), 'saving the Templates tab emptied a ban list' );
		$this->assertSame( array( 'EvilBot*' ), WP_Ban_Options::list_of( 'user_agents' ), 'saving the Templates tab emptied a ban list' );
		$this->assertSame( 'HTTP_X_REAL_IP', WP_Ban_Options::get()['ip_header'], 'saving the Templates tab reset the trusted header' );
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

		$this->assertSame( array( 'wp_ban_options', 'wp_ban_stats', 'wp_ban_version' ), $rows, 'The plugin owns exactly these three rows, so uninstall has a complete list.' );

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
		$this->assertNotFalse( has_action( 'wp_ajax_wp_ban_preview', array( 'WP_Ban_Settings', 'ajax_preview' ) ), 'The preview endpoint is registered for logged-in callers.' );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_wp_ban_preview' ), 'The preview endpoint has no nopriv twin, so a logged out caller cannot reach it.' );
	}

	/**
	 * Subscribers must not reach the preview.
	 *
	 * Every authenticated role can reach a wp_ajax_* hook, so the hook is not an
	 * authorisation check. Before 2.0.0 there was none.
	 */
	public function test_a_subscriber_cannot_read_the_preview() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_REQUEST['_ajax_nonce'] = wp_create_nonce( 'wp_ban_preview' );

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

		$_REQUEST['_ajax_nonce'] = wp_create_nonce( 'wp_ban_preview' );

		ob_start();

		try {
			WP_Ban_Settings::ajax_preview();
		} catch ( WPDieException $e ) {
			unset( $e );
		}

		$html = (string) ob_get_clean();

		unset( $_REQUEST['_ajax_nonce'] );

		$this->assertStringContainsString( 'PREVIEW-CANARY', $html, 'The preview renders the stored message.' );
		$this->assertStringNotContainsString( '%USER_IP%', $html, 'With its tokens substituted, so it shows what a visitor would see.' );
	}

	/**
	 * Each validation warning must appear once.
	 *
	 * WordPress already prints the errors queued by the sanitize callback for
	 * pages registered under Settings, and common.js relocates the notices into
	 * .wrap. A manual settings_errors() call in render() therefore rendered
	 * every warning twice, inside what looked like the plugin's own markup.
	 */
	public function test_validation_notices_are_not_printed_twice_on_any_tab() {
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

		$this->assertNotEmpty( get_settings_errors( WP_Ban_Options::OPTION ), 'The tab registered its validation notice, or the count below proves nothing.' );

		foreach ( array_keys( WP_Ban_Settings::tabs() ) as $tab ) {
			$_GET['tab'] = $tab;

			ob_start();
			WP_Ban_Settings::render();
			$html = (string) ob_get_clean();

			$this->assertSame( 0, substr_count( $html, 'setting-error-wp_ban_bad_range' ), "the {$tab} tab printed the notice a second time" );
			$this->assertSame( 0, substr_count( $html, 'setting-error-wp_ban_self' ), "the {$tab} tab printed the notice a second time" );
		}
	}

	/**
	 * The bulk form must be checked against the nonce the table emits.
	 *
	 * WP_List_Table::display_tablenav() outputs its own _wpnonce for
	 * "bulk-{$plural}". A second wp_nonce_field() in the same form is
	 * overridden by it -- both inputs are named _wpnonce -- so every bulk
	 * action failed its referer check with "An error occurred."
	 *
	 * Moving the table onto a tab of its own must not disturb that, which is
	 * why this asserts the count as well as the value.
	 */
	public function test_the_stats_form_uses_the_list_table_nonce() {
		$this->assertSame( 'bulk-' . WP_Ban_Settings::STATS_PLURAL, WP_Ban_Settings::STATS_NONCE, 'The stats nonce is the one the core list table generates for this plural.' );

		WP_Ban_Stats::record( '203.0.113.99' );

		$stats_form = $this->render( 'stats' );

		// Exactly one _wpnonce on the tab: the table's own. There is no settings
		// form here to contribute a second.
		$this->assertSame( 1, substr_count( $stats_form, 'name="_wpnonce"' ), 'The form carries exactly one nonce field.' );

		// And it must be the nonce handle_stats_actions() checks against.
		preg_match( '/name="_wpnonce" value="([^"]+)"/', $stats_form, $m );

		$this->assertNotEmpty( $m, 'the stats form has no nonce at all' );
		$this->assertSame( 1, wp_verify_nonce( $m[1], WP_Ban_Settings::STATS_NONCE ), 'And it verifies against the action the handler checks.' );

		// And the form must post back to the tab the table is on, so the
		// redirect after a bulk action returns to it.
		$this->assertStringContainsString( 'tab=stats', html_entity_decode( $stats_form ), 'The form returns to the stats tab rather than the default one.' );
	}

	/**
	 * A bulk action still works now that the table is on a tab.
	 *
	 * The whole round trip: the table's own nonce, the selected rows, the
	 * redirect that stops a refresh replaying the reset, and the tab it comes
	 * back to.
	 */
	public function test_a_bulk_reset_still_works_from_the_stats_tab() {
		WP_Ban_Stats::record( '203.0.113.1' );
		WP_Ban_Stats::record( '203.0.113.2' );

		$_REQUEST['action']   = 'reset';
		$_REQUEST['ips']      = array( '203.0.113.1' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( WP_Ban_Settings::STATS_NONCE );
		$_GET['tab']          = 'stats';

		$redirect = '';

		$listener = static function ( $location ) use ( &$redirect ) {
			$redirect = $location;

			throw new WPDieException( 'redirected' );
		};

		add_filter( 'wp_redirect', $listener );

		try {
			WP_Ban_Settings::handle_stats_actions();
		} catch ( WPDieException $e ) {
			unset( $e );
		} finally {
			remove_filter( 'wp_redirect', $listener );
			unset( $_REQUEST['action'], $_REQUEST['ips'], $_REQUEST['_wpnonce'] );
		}

		$this->assertArrayNotHasKey( '203.0.113.1', WP_Ban_Stats::get()['users'], 'the selected row was not reset' );
		$this->assertArrayHasKey( '203.0.113.2', WP_Ban_Stats::get()['users'], 'an unselected row was reset too' );
		$this->assertStringContainsString( 'tab=stats', $redirect, 'the reset came back to the wrong tab' );
	}

	public function test_the_settings_link_points_at_the_settings_tab() {
		$links = WP_Ban_Settings::action_links( array() );

		$this->assertStringContainsString( 'page=' . WP_Ban_Settings::PAGE, $links[0], 'The Settings link points at this plugin screen.' );

		// Somebody who clicked "Settings" came to change one, not to read
		// counters, so the link skips the screen's default tab.
		$this->assertStringContainsString( 'tab=settings', html_entity_decode( $links[0] ), 'And at the settings tab specifically, not the stats one the screen opens on.' );
	}
}
