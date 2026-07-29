<?php
/**
 * Uninstall WP-Ban.
 *
 * Runs with the plugin inactive, so nothing here may depend on the plugin's
 * own classes or functions being loaded.
 *
 * @package WP-Ban
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete the plugin's options for the current site.
 *
 * The six list rows and banned_message were folded into banned_options by the
 * 2.0.0 migration, but a site that never loaded wp-admin after updating can
 * still be carrying them, so they are cleaned up here too.
 *
 * @return void
 */
function wp_ban_uninstall_site() {
	$option_names = array(
		'banned_options',
		'banned_stats',
		'ban_db_version',
		// Pre-2.0.0 rows.
		'banned_ips',
		'banned_ips_range',
		'banned_hosts',
		'banned_referers',
		'banned_user_agents',
		'banned_exclude_ips',
		'banned_message',
	);

	foreach ( $option_names as $option_name ) {
		delete_option( $option_name );
	}
}

if ( is_multisite() ) {
	/*
	 * 'number' => 0 lifts WP_Site_Query's default cap of 100, which would
	 * otherwise leave the options behind on every site past the hundredth while
	 * still reporting a successful uninstall. 'fields' => 'ids' avoids
	 * hydrating WP_Site objects the loop never looks at.
	 */
	$wp_ban_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $wp_ban_site_ids as $wp_ban_site_id ) {
		// switch_to_blog() pushes onto a stack, so the restore belongs inside
		// the loop -- restoring once at the end leaves it unwound by one.
		switch_to_blog( (int) $wp_ban_site_id );

		wp_ban_uninstall_site();

		restore_current_blog();
	}
} else {
	wp_ban_uninstall_site();
}
