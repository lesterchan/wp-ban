<?php
/**
 * Source-inspection helpers shared by the test cases.
 *
 * Kept apart from helper-testcase.php because a file may declare either
 * functions or a class, not both.
 *
 * @package WP-Ban
 */

/**
 * Every shipped PHP source file, root and includes/.
 *
 * Built from two glob() calls rather than one GLOB_BRACE pattern: GLOB_BRACE is
 * a GNU extension and is not defined in every PHP build, including the one in
 * the wp-env container.
 *
 * @return string[] Absolute paths.
 */
function wp_ban_test_source_files() {
	$root = dirname( __DIR__ );

	return array_merge(
		(array) glob( $root . '/*.php' ),
		(array) glob( $root . '/includes/*.php' )
	);
}

/**
 * Every shipped PHP source file concatenated, with all comments removed.
 *
 * Comments must not be searchable: these files document the APIs they no longer
 * use, so "does the plugin still call load_plugin_textdomain()?" would match the
 * comment explaining that it does not, and a test asserting on the raw text
 * fails for the wrong reason -- or worse, passes for one.
 *
 * @param string[] $skip Basenames to leave out.
 * @return string
 */
function wp_ban_test_source_code( array $skip = array() ) {
	$code = '';

	foreach ( wp_ban_test_source_files() as $file ) {
		if ( in_array( basename( $file ), $skip, true ) ) {
			continue;
		}

		$code .= php_strip_whitespace( $file );
	}

	return $code;
}

/**
 * Every shipped JavaScript file concatenated.
 *
 * @return string
 */
function wp_ban_test_script_code() {
	$code = '';

	foreach ( (array) glob( dirname( __DIR__ ) . '/js/*.js' ) as $file ) {
		$code .= (string) file_get_contents( $file );
	}

	return $code;
}

/**
 * Read a file from the plugin root.
 *
 * @param string $relative Path relative to the plugin root.
 * @return string
 */
function wp_ban_test_read( $relative ) {
	return (string) file_get_contents( dirname( __DIR__ ) . '/' . $relative );
}
