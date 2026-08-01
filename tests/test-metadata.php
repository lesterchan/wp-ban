<?php
/**
 * The release invariants, asserted from the source and from the stored rows.
 *
 * These are the house rules every plugin in this family shares, and every one
 * of them has been broken by an ordinary edit at some point: a header field
 * that drifted out of the canonical order, a new directory shipped without its
 * silence guard, a version bumped in one file of three, a readme header line
 * that lost the two trailing spaces holding it apart from the next.
 *
 * They are the things a restructuring quietly breaks and nothing notices until
 * a release fails its pre-flight months later, so catching them here is far
 * cheaper than catching them there.
 *
 * @package WP-Ban
 */

/**
 * @coversNothing
 */
class WP_Ban_Metadata_Test extends WP_Ban_TestCase {

	const VERSION = '2.0.0';

	/**
	 * The main plugin file.
	 *
	 * @return string
	 */
	protected function plugin_file() {
		return wp_ban_test_read( 'wp-ban.php' );
	}

	/**
	 * The readme.
	 *
	 * @return string
	 */
	protected function readme() {
		return wp_ban_test_read( 'README.md' );
	}

	/**
	 * Every directory in the repo that holds at least one PHP file.
	 *
	 * @return string[] Absolute paths, plugin root included.
	 */
	protected function php_directories() {
		$root  = dirname( __DIR__ );
		$found = array();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();

			// vendor/ and node_modules/ are not ours and never ship, and
			// artifacts/ is whatever the last failing Playwright run left behind.
			if ( false !== strpos( $path, '/vendor/' )
				|| false !== strpos( $path, '/node_modules/' )
				|| false !== strpos( $path, '/artifacts/' ) ) {
				continue;
			}

			if ( 'php' === strtolower( $file->getExtension() ) ) {
				$found[ dirname( $path ) ] = true;
			}
		}

		return array_keys( $found );
	}

	/**
	 * Every option row the plugin owns, read straight from the table.
	 *
	 * @return string[]
	 */
	protected function stored_option_names() {
		global $wpdb;

		return (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'wp_ban_' ) . '%'
			)
		);
	}

	/**
	 * A field from the main plugin file's header docblock.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	protected function header_field( $field ) {
		$data = get_file_data( dirname( __DIR__ ) . '/wp-ban.php', array( $field => $field ) );

		return $data[ $field ];
	}

	/**
	 * A field from the readme's header block.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	protected function readme_field( $field ) {
		preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/m', $this->readme(), $matches );

		return isset( $matches[1] ) ? $matches[1] : '';
	}

	public function test_version_matches_everywhere() {
		$this->assertStringContainsString( ' * Version: ' . self::VERSION, $this->plugin_file() );
		$this->assertStringContainsString( "define( 'WP_BAN_VERSION', '" . self::VERSION . "' );", $this->plugin_file() );
		$this->assertStringContainsString( 'Stable tag: ' . self::VERSION, $this->readme() );
	}

	public function test_the_changelog_has_a_section_for_this_version() {
		$this->assertStringContainsString( '### ' . self::VERSION . "\n", $this->readme() );
	}

	/**
	 * The order is neither alphabetical nor intuitive -- Requires at least and
	 * Requires PHP sit before Author -- so it is copied, never composed.
	 */
	public function test_the_plugin_header_fields_are_in_the_canonical_order() {
		$expected = array(
			'Plugin Name',
			'Plugin URI',
			'Description',
			'Version',
			'Requires at least',
			'Requires PHP',
			'Author',
			'Author URI',
			'License',
			'License URI',
			'Text Domain',
			'Domain Path',
		);

		preg_match( '#^<\?php\s*/\*\*(.+?)\*/#s', $this->plugin_file(), $matches );
		$this->assertNotEmpty( $matches, 'The plugin file must open with a docblock header.' );

		preg_match_all( '/^\s*\*\s*([A-Z][A-Za-z ]*?):\s/m', $matches[1], $fields );

		$this->assertSame( $expected, $fields[1] );
	}

	/**
	 * The readme order differs from the PHP one on purpose: Requires PHP comes
	 * after Stable tag here. They are not to be harmonised.
	 */
	public function test_the_readme_header_fields_are_in_the_canonical_order() {
		$expected = array(
			'Contributors',
			'Donate link',
			'Tags',
			'Requires at least',
			'Tested up to',
			'Stable tag',
			'Requires PHP',
			'License',
			'License URI',
		);

		$header = substr( $this->readme(), 0, (int) strpos( $this->readme(), "\n\n" ) );

		preg_match_all( '/^([A-Z][A-Za-z ]*?):\s/m', $header, $fields );

		$this->assertSame( $expected, $fields[1] );
	}

	public function test_requires_headers_match_readme() {
		$this->assertStringContainsString( ' * Requires at least: 6.8', $this->plugin_file() );
		$this->assertStringContainsString( ' * Requires PHP: 8.2', $this->plugin_file() );
		$this->assertStringContainsString( 'Requires at least: 6.8', $this->readme() );
		$this->assertStringContainsString( 'Requires PHP: 8.2', $this->readme() );
	}

	/**
	 * Header lines need two trailing spaces to render as separate lines.
	 *
	 * Markdown joins consecutive lines into one paragraph unless each is ended
	 * with a hard line break, so a missing pair renders as
	 * "License: GPLv2 or later License URI: https://..." on GitHub -- which is
	 * exactly what this readme did until 2.0.0. It is invisible in the source
	 * and in a diff, which is why it wants a test. The last line needs none,
	 * having nothing after it to run into.
	 */
	public function test_every_readme_header_line_keeps_its_line_break() {
		$header = substr( $this->readme(), 0, (int) strpos( $this->readme(), "\n\n" ) );
		$lines  = explode( "\n", $header );

		// The first line is the "# WP-Ban" heading, not a header field.
		$fields = array_slice( $lines, 1 );
		$last   = array_pop( $fields );

		foreach ( $fields as $line ) {
			$this->assertStringEndsWith(
				'  ',
				$line,
				"Needs two trailing spaces or it merges with the line below: {$line}"
			);
		}

		$this->assertStringStartsWith( 'License URI:', $last );
	}

	public function test_the_readme_lists_at_most_five_tags() {
		preg_match( '/^Tags:\s*(.+?)\s*$/m', $this->readme(), $matches );

		$this->assertNotEmpty( $matches, 'The readme must carry a Tags line.' );
		$this->assertLessThanOrEqual( 5, count( explode( ',', $matches[1] ) ) );
	}

	/**
	 * Bare versions: "### 2.0.0", never "### Version 2.0.0".
	 */
	public function test_every_changelog_heading_is_a_bare_version() {
		$this->assertSame( 0, preg_match( '/^### Version /m', $this->readme() ) );
	}

	public function test_canonical_lesterchan_urls() {
		$this->assertSame(
			'https://lesterchan.net/portfolio/programming/php/',
			$this->header_field( 'Plugin URI' )
		);
		$this->assertSame( 'https://lesterchan.net', $this->header_field( 'Author URI' ) );
		$this->assertSame( 'https://lesterchan.net/site/donation/', $this->readme_field( 'Donate link' ) );
		$this->assertSame(
			'https://www.gnu.org/licenses/gpl-2.0.html',
			$this->header_field( 'License URI' )
		);
		$this->assertSame( 'https://www.gnu.org/licenses/gpl-2.0.html', $this->readme_field( 'License URI' ) );
	}

	/**
	 * One name, in every plugin. A second contributor has to be added on
	 * wordpress.org as well, so a name here that is not on the listing silently
	 * does nothing.
	 */
	public function test_contributors_is_gamerz_only() {
		$this->assertSame( 'GamerZ', $this->readme_field( 'Contributors' ) );
	}

	public function test_text_domain_is_the_plugin_slug() {
		$this->assertSame( 'wp-ban', $this->header_field( 'Text Domain' ) );
		$this->assertSame( '/languages', $this->header_field( 'Domain Path' ) );
		$this->assertSame( 'wp-ban', WP_BAN_SLUG );
	}

	/**
	 * The licence statement has to agree with itself.
	 *
	 * Five plugins in this family carried a version-2-only GPL block directly
	 * under a "GPLv2 or later" header and a GPL-2.0-or-later composer.json,
	 * which is a self-contradicting licence statement rather than a typo.
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

	public function test_the_gpl_licence_is_shipped() {
		$licence = wp_ban_test_read( 'LICENSE' );

		$this->assertStringContainsString( 'GNU GENERAL PUBLIC LICENSE', $licence );
		$this->assertStringContainsString( 'Version 2, June 1991', $licence );
	}

	/**
	 * The catalogue comes from translate.wordpress.org, and since WP 6.7 calling
	 * load_plugin_textdomain() this early trips _doing_it_wrong.
	 */
	public function test_the_plugin_does_not_load_its_own_textdomain() {
		$this->assertStringNotContainsString( 'load_plugin_textdomain', wp_ban_test_source_code() );
	}

	public function test_every_translation_call_uses_the_plugin_text_domain() {
		$code = wp_ban_test_source_code();

		preg_match_all( '/(?:__|_n)\((.*?)\);/s', $code, $calls );

		foreach ( $calls[1] as $arguments ) {
			$this->assertStringContainsString(
				"'wp-ban'",
				$arguments,
				"A translation call is missing the text domain: {$arguments}"
			);
		}
	}

	/**
	 * The second-level headings are a closed set in a fixed order.
	 *
	 * Third-level ones are not: Features, Donations, the usage subsections and
	 * every changelog version live below these.
	 */
	public function test_readme_sections_are_the_canonical_set() {
		preg_match_all( '/^## (.+?)\s*$/m', $this->readme(), $sections );

		$this->assertSame(
			array(
				'Description',
				'Usage',
				'Frequently Asked Questions',
				'Screenshots',
				'Changelog',
				'Upgrade Notice',
			),
			$sections[1]
		);
	}

	/**
	 * Donations is mandated, as the last h3 of Description, word for word.
	 */
	public function test_the_readme_carries_the_family_donations_paragraph() {
		$this->assertStringContainsString(
			"### Donations\nI spent most of my free time creating, updating, maintaining and supporting"
			. ' these plugins, if you really love my plugins and could spare me a couple of bucks,'
			. ' I will really appreciate it. If not feel free to use it without any obligations.',
			$this->readme()
		);
	}

	/**
	 * Five prefixes, and nothing else.
	 *
	 * The listing on wordpress.org renders the changelog verbatim, so a stray
	 * "IMPORTANT:" -- which this readme carried on three lines until 2.0.0 --
	 * is visible to every reader of it.
	 */
	public function test_changelog_prefixes_are_canonical() {
		$readme    = $this->readme();
		$changelog = substr( $readme, (int) strpos( $readme, '## Changelog' ) );
		$changelog = substr( $changelog, 0, (int) strpos( $changelog, "\n## Upgrade Notice" ) );

		preg_match_all( '/^\* (.+?):/m', $changelog, $bullets );

		$this->assertNotEmpty( $bullets[1], 'The changelog must carry bullets.' );

		foreach ( $bullets[1] as $prefix ) {
			$this->assertContains(
				$prefix . ':',
				array( 'BREAKING:', 'NEW:', 'CHANGED:', 'FIXED:', 'NOTE:' ),
				"'{$prefix}:' is not one of the five allowed changelog prefixes."
			);
		}
	}

	/**
	 * Every break a site owner updating from the released 1.69.2 would notice
	 * has to be findable under Upgrade Notice, not only in the changelog.
	 */
	public function test_the_upgrade_notice_covers_the_breaking_changes() {
		$readme = $this->readme();
		$notice = substr( $readme, (int) strpos( $readme, '## Upgrade Notice' ) );

		foreach ( array( '6.8', '8.2', '403', 'wp_ban_options', 'wp_ban_stats', 'wp_ban_version', 'banned()' ) as $subject ) {
			$this->assertStringContainsString(
				$subject,
				$notice,
				"The Upgrade Notice must tell a site owner about {$subject}."
			);
		}
	}

	public function test_every_directory_has_an_index_php() {
		foreach ( $this->php_directories() as $directory ) {
			$this->assertFileExists(
				$directory . '/index.php',
				"{$directory} ships PHP and so needs an index.php silence guard."
			);
		}
	}

	public function test_the_guards_use_the_docblock_form() {
		foreach ( $this->php_directories() as $directory ) {
			$guard = (string) file_get_contents( $directory . '/index.php' );

			// phpcbf cannot fix the one-line "// Silence is golden." form.
			$this->assertStringContainsString( '/**', $guard, "{$directory}/index.php must use the docblock form." );
			$this->assertStringContainsString( 'Silence is golden.', $guard );
		}
	}

	/**
	 * Nothing the plugin enqueues may pull the library in, and nothing it
	 * ships may reach for one at runtime.
	 */
	public function test_no_jquery_is_enqueued() {
		$this->assertStringNotContainsStringIgnoringCase( 'jquery', wp_ban_test_source_code() );
		$this->assertStringNotContainsStringIgnoringCase( 'jquery', wp_ban_test_script_code() );

		// The one script the plugin registers declares no dependencies at all,
		// which is the strongest form of the rule: there is no array for a
		// dependency to creep back into.
		$this->assertStringContainsString(
			"WP_BAN_URL . 'js/wp-ban-admin.js',\n\t\t\tarray(),",
			wp_ban_test_read( 'includes/class-wp-ban-settings.php' ),
			'The admin script must be enqueued with an empty dependency array.'
		);
	}

	/**
	 * No plugin in this family ships a second, mirrored stylesheet: the front
	 * end uses CSS logical properties instead, so one sheet serves both
	 * directions. This one ships no stylesheet at all.
	 */
	public function test_no_rtl_stylesheet_is_registered() {
		$root = dirname( __DIR__ );

		$this->assertSame( array(), (array) glob( $root . '/*-rtl.css' ) );
		$this->assertSame( array(), (array) glob( $root . '/css/*-rtl.css' ) );
		$this->assertStringNotContainsString(
			'wp_style_add_data',
			wp_ban_test_source_code(),
			"No plugin registers 'rtl' style data."
		);
	}

	/**
	 * The catalogue is built by translate.wordpress.org, and Travis has been
	 * dead for these repos for years.
	 */
	public function test_no_abandoned_build_or_translation_artefacts_ship() {
		$root = dirname( __DIR__ );

		$this->assertFileDoesNotExist( $root . '/.travis.yml' );
		$this->assertDirectoryDoesNotExist( $root . '/languages' );

		foreach ( array( 'pot', 'po', 'mo' ) as $extension ) {
			$this->assertSame(
				array(),
				(array) glob( $root . '/*.' . $extension ),
				"No .{$extension} files: translate.wordpress.org builds the catalogue."
			);
		}
	}

	/**
	 * The upgrade markers live in their own row, holding those two keys and no
	 * others. Anything else in here means a marker has drifted back into the
	 * settings array, which is the bug this shape exists to make impossible.
	 */
	public function test_version_row_holds_exactly_plugin_and_db() {
		WP_Ban_Options::maybe_upgrade();

		$markers = get_option( WP_Ban_Options::VERSION );

		$this->assertIsArray( $markers, 'wp_ban_version must be an array.' );

		$keys = array_keys( $markers );
		sort( $keys );

		$this->assertSame( array( 'db', 'plugin' ), $keys );
		$this->assertSame( WP_BAN_VERSION, $markers['plugin'] );
		$this->assertSame( WP_BAN_DB_VERSION, $markers['db'] );
	}

	/**
	 * The regression guard for the wp-useronline bug: it fails the moment
	 * someone moves an upgrade marker back into the settings array, where the
	 * sanitiser would have to rescue it by hand on every save.
	 */
	public function test_settings_sanitizer_never_stores_version_markers() {
		$clean = WP_Ban_Options::sanitize( WP_Ban_Options::get() );

		foreach ( array( 'version', 'db_version', 'versions', 'plugin', 'db' ) as $key ) {
			$this->assertArrayNotHasKey(
				$key,
				$clean,
				"The sanitiser stored a '{$key}' key; the upgrade markers belong in wp_ban_version."
			);
		}
	}

	/**
	 * Deleting the plugin leaves nothing behind.
	 *
	 * The assertion is deliberately a LIKE over wp_options rather than three
	 * delete_option() checks: a row added later and forgotten in uninstall.php
	 * is exactly the failure this is here to catch. The multisite config runs
	 * the same test through uninstall.php's get_sites() branch.
	 */
	public function test_uninstall_removes_every_option_row() {
		WP_Ban_Options::maybe_upgrade();
		WP_Ban_Stats::record( '203.0.113.1' );

		$this->assertNotEmpty(
			$this->stored_option_names(),
			'There should be rows to remove before uninstall runs.'
		);

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-ban/wp-ban.php' );
		}

		require_once dirname( __DIR__ ) . '/uninstall.php';

		wp_cache_flush();

		$this->assertSame(
			array(),
			$this->stored_option_names(),
			'uninstall.php must remove every wp_ban_* row.'
		);
	}
}
