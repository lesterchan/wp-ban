<?php
/**
 * Uninstall behaviour, and the multisite guards that cannot be exercised here.
 *
 * @package WP-Ban
 */

/**
 * @covers WP_Ban_Uninstall
 */
class Test_Ban_Uninstall extends WP_Ban_TestCase {

	/**
	 * The uninstaller's source.
	 *
	 * @return string
	 */
	private function source() {
		return (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
	}

	public function test_every_row_the_plugin_owns_is_named() {
		$source = $this->source();

		$owned = array(
			'banned_options',
			'banned_stats',
			'ban_db_version',
			// Pre-2.0.0 rows: a site that never loaded wp-admin after updating
			// can still be carrying them, so uninstall must clean them too.
			'banned_ips',
			'banned_ips_range',
			'banned_hosts',
			'banned_referers',
			'banned_user_agents',
			'banned_exclude_ips',
			'banned_message',
		);

		foreach ( $owned as $option ) {
			$this->assertStringContainsString( "'{$option}'", $source, "{$option} is not deleted on uninstall" );
		}
	}

	public function test_the_uninstaller_refuses_to_run_on_its_own() {
		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' )", $this->source() );
	}

	/**
	 * A single-site suite cannot build a 101-site network, so these are
	 * source-level guards. They exist because all three were live bugs, and
	 * each fails silently: uninstall reports success either way.
	 */
	public function test_the_multisite_loop_pages_through_every_site() {
		$source = $this->source();

		// WP_Site_Query defaults 'number' to 100, so a bare get_sites() leaves
		// the options behind on every site past the hundredth.
		$this->assertMatchesRegularExpression( "/'number'\s*=>\s*0/", $source );

		// The loop only needs the ID; hydrating WP_Site objects for a large
		// network is wasted work.
		$this->assertMatchesRegularExpression( "/'fields'\s*=>\s*'ids'/", $source );

		// switch_to_blog() pushes onto a stack, so restoring once after the
		// loop leaves it unwound by exactly one.
		$this->assertMatchesRegularExpression(
			'/switch_to_blog\(.*?\).*?restore_current_blog\(\s*\);\s*\}/s',
			$source
		);
	}

	public function test_wp_get_sites_is_not_called_anywhere() {
		// Removed in WordPress 5.1. Calling it fatals on multisite uninstall
		// and on network activation rather than merely skipping sites.
		foreach ( $this->plugin_php_files() as $path ) {
			$this->assertStringNotContainsString(
				'wp_get_sites(',
				$this->code_only( (string) file_get_contents( $path ) ),
				basename( $path ) . ' still calls wp_get_sites()'
			);
		}
	}

	public function test_load_plugin_textdomain_is_not_called_anywhere() {
		// Since WordPress 6.7 an early call trips _doing_it_wrong, and
		// WordPress.org-hosted plugins get their translations automatically.
		foreach ( $this->plugin_php_files() as $path ) {
			$this->assertStringNotContainsString(
				'load_plugin_textdomain(',
				$this->code_only( (string) file_get_contents( $path ) ),
				basename( $path ) . ' still calls load_plugin_textdomain()'
			);
		}
	}

	public function test_the_plugin_directory_name_is_not_hardcoded() {
		// Before 2.0.0 the admin URL was built from the literal string
		// 'wp-ban/ban-options.php', so installing under any other directory
		// name broke the settings screen outright.
		foreach ( $this->plugin_php_files() as $path ) {
			$this->assertDoesNotMatchRegularExpression(
				"#['\"]wp-ban/#",
				$this->code_only( (string) file_get_contents( $path ) ),
				basename( $path ) . ' hardcodes the plugin directory name'
			);
		}
	}

	/**
	 * Every PHP file the plugin ships.
	 *
	 * @return string[]
	 */
	private function plugin_php_files() {
		$root  = dirname( __DIR__ );
		$files = array();

		foreach ( array( $root, $root . '/includes' ) as $dir ) {
			foreach ( (array) glob( $dir . '/*.php' ) as $path ) {
				$files[] = $path;
			}
		}

		$this->assertNotEmpty( $files );

		return $files;
	}

	/**
	 * Source with comments stripped.
	 *
	 * Grepping raw source for a legacy symbol finds the comment explaining why
	 * the symbol is gone, and reports the fix as the bug.
	 *
	 * @param string $source PHP source.
	 * @return string
	 */
	private function code_only( $source ) {
		$out = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}

				$out .= $token[1];
				continue;
			}

			$out .= $token;
		}

		return $out;
	}
}
