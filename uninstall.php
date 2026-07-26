<?php
/*
 * Uninstall plugin
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$option_names = array(
	'banned_ips'
	, 'banned_hosts'
	, 'banned_stats'
	, 'banned_message'
	, 'banned_referers'
	, 'banned_exclude_ips'
	, 'banned_ips_range'
	, 'banned_user_agents'
	// Added in 1.64 and never added here, so it outlived every uninstall.
	, 'banned_options'
);

/**
 * Delete the plugin's options for the current site.
 *
 * @param string[] $option_names Option names to delete.
 * @return void
 */
function ban_delete_options( $option_names ) {
	foreach ( $option_names as $option_name ) {
		delete_option( $option_name );
	}
}

if ( is_multisite() ) {
	/*
	 * wp_get_sites() was removed in WordPress 5.1, so this fatalled instead of
	 * uninstalling. 'number' => 0 lifts WP_Site_Query's default cap of 100,
	 * which would otherwise leave the options behind on every site past the
	 * hundredth while still reporting a successful uninstall. 'fields' => 'ids'
	 * avoids hydrating WP_Site objects the loop never looks at.
	 */
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );

	foreach ( $site_ids as $site_id ) {
		// switch_to_blog() pushes onto a stack, so the restore belongs inside
		// the loop -- restoring once at the end leaves it unwound by one.
		switch_to_blog( (int) $site_id );

		ban_delete_options( $option_names );

		restore_current_blog();
	}
} else {
	ban_delete_options( $option_names );
}
