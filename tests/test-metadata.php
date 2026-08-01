<?php
/**
 * What is true of WP-Ban and of no other plugin.
 *
 * The twenty-three assertions §7.2 asks of all nineteen live in
 * Plugin_Metadata_TestCase, a byte-identical copy of
 * _standards/templates/helper-metadata-testcase.php. What is left here is the
 * three declarations that class cannot derive, the hooks it reaches back
 * through, and the few checks that are genuinely this plugin's - most of them
 * because WP-Ban is the plugin that broke the rule they now guard.
 *
 * @package WP-Ban
 */

/**
 * WP-Ban against §7.2.
 *
 * @coversNothing
 */
class WP_Ban_Metadata_Test extends Plugin_Metadata_TestCase {

	/**
	 * The version this release ships.
	 *
	 * @return string
	 */
	protected function expected_version() {
		return '2.0.0';
	}

	/**
	 * The prefix every class the plugin declares carries.
	 *
	 * @return string
	 */
	protected function class_prefix() {
		return 'WP_Ban';
	}

	/**
	 * Every break a site owner updating from the released 1.69.2 would notice.
	 *
	 * The screen became three tabs, proxy headers stopped being trusted by
	 * default, the ban page became a 403, ten option rows were folded into
	 * three, and six unprefixed global functions were deleted outright - the
	 * last of which fatals rather than degrading, so the replacements are named
	 * beside them.
	 *
	 * @return string[]
	 */
	protected function upgrade_notice_subjects() {
		return array(
			'6.8',
			'8.2',
			// The screen, and how to reach a tab directly.
			'&tab=settings',
			'&tab=templates',
			// The security change, and the header it stopped trusting blindly.
			'HTTP_X_FORWARDED_FOR',
			// The status code, and the filter that restores the old one.
			'403',
			'wp_ban_status_code',
			// The rows this release stores.
			'wp_ban_options',
			'wp_ban_stats',
			'wp_ban_version',
			// And every pre-2.0.0 row folded into them.
			'banned_options',
			'banned_stats',
			'ban_db_version',
			'banned_ips',
			'banned_ips_range',
			'banned_hosts',
			'banned_referers',
			'banned_user_agents',
			'banned_exclude_ips',
			'banned_message',
			// The global functions that are gone, and what replaced them.
			'banned()',
			'ban_get_ip()',
			'print_banned_message()',
			'process_ban()',
			'is_admin_ip()',
			'preg_match_wildcard()',
			'WP_Ban_IP',
			'WP_Ban_Options',
			// The filters and the action that took their place.
			'wp_ban_capability',
			'wp_ban_denied',
			'wp_ban_enabled',
			'wp_ban_ipaddress',
			'wp_ban_protect_self',
			'wp_ban_trust_proxy',
		);
	}

	/**
	 * The settings row, the stats row and the marker row.
	 *
	 * @return void
	 */
	protected function seed_option_rows() {
		WP_Ban_Options::maybe_upgrade();
		WP_Ban_Stats::record( '203.0.113.1' );
	}

	/**
	 * Write the marker row through the plugin's own upgrade routine.
	 *
	 * @return void
	 */
	protected function write_version_row() {
		WP_Ban_Options::maybe_upgrade();
	}

	/**
	 * Round-trip the settings sanitiser.
	 *
	 * @param array $input What the settings form is pretending to have posted.
	 * @return array
	 */
	protected function sanitize_settings( array $input ) {
		return (array) WP_Ban_Options::sanitize( $input );
	}

	/**
	 * The plugin's own settings, so the sanitiser has real work to do.
	 *
	 * @return array
	 */
	protected function settings_fixture() {
		return WP_Ban_Options::get();
	}

	/**
	 * Register the one script the plugin registers.
	 *
	 * It is enqueued only on its own screen, so the submenu page has to be
	 * registered first - and that only happens for a user who may reach it.
	 *
	 * @return void
	 */
	protected function register_plugin_assets() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}

		WP_Ban_Settings::add_page();
		WP_Ban_Settings::enqueue( 'settings_page_' . WP_Ban_Settings::PAGE );
	}

	/**
	 * At most five tags, which is what the listing shows.
	 */
	public function test_the_readme_lists_at_most_five_tags() {
		preg_match( '/^Tags:\s*(.+?)\s*$/m', $this->readme(), $matches );

		$this->assertNotEmpty( $matches, 'The readme must carry a Tags line.' );
		$this->assertLessThanOrEqual( 5, count( explode( ',', $matches[1] ) ) );
	}

	/**
	 * The copyright block agrees with the header two lines above it.
	 *
	 * Five plugins in this collection carried a version-2-only GPL block
	 * directly under a "GPLv2 or later" header and a GPL-2.0-or-later
	 * composer.json, which is a self-contradicting licence statement rather
	 * than a typo.
	 */
	public function test_the_gpl_block_is_the_or_later_variant() {
		$this->assertSame( 'GPLv2 or later', $this->header_field( 'License' ) );
		$this->assertStringContainsString(
			'either version 2 of the License, or',
			$this->plugin_file(),
			'The GPL comment block must be the "or later" variant.'
		);
		$this->assertStringContainsString( '(at your option) any later version.', $this->plugin_file() );
		$this->assertStringContainsString( '"license": "GPL-2.0-or-later"', wp_ban_test_read( 'composer.json' ) );
	}

	/**
	 * Every translation call names the plugin's text domain.
	 *
	 * A missing domain is not a failure anything reports: the string is simply
	 * looked up in the default domain, found nowhere, and rendered in English
	 * forever.
	 */
	public function test_every_translation_call_uses_the_plugin_text_domain() {
		preg_match_all( '/(?:__|_n)\((.*?)\);/s', wp_ban_test_source_code(), $calls );

		$this->assertNotEmpty( $calls[1], 'The plugin translates at least one string.' );

		foreach ( $calls[1] as $arguments ) {
			$this->assertStringContainsString(
				"'wp-ban'",
				$arguments,
				"A translation call is missing the text domain: {$arguments}"
			);
		}
	}

	/**
	 * Donations is the last h3 of Description, word for word.
	 */
	public function test_the_readme_carries_the_family_donations_paragraph() {
		$this->assertStringContainsString(
			"### Donations\nI spent most of my free time creating, updating, maintaining and supporting"
			. ' these plugins, if you really love my plugins and could spare me a couple of bucks,'
			. ' I will really appreciate it. If not feel free to use it without any obligations.',
			$this->readme()
		);
	}
}
