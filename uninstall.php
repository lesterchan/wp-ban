<?php
/**
 * Uninstall WP-Ban.
 *
 * Runs with the plugin inactive, so nothing here may depend on the plugin's
 * own classes, constants or functions being loaded. The row names are
 * therefore spelled out rather than read from WP_Ban_Options.
 *
 * @package WP-Ban
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete the plugin's options for the current site.
 *
 * The three wp_ban_* rows are what 2.0.0 stores. Every pre-2.0.0 name is
 * listed after them because a site that deletes the plugin without ever
 * loading wp-admin after the update never ran the upgrade, and so is still
 * carrying all ten.
 *
 * @return void
 */
function wp_ban_uninstall_site() {
	$option_names = array(
		'wp_ban_options',
		'wp_ban_stats',
		'wp_ban_version',
		// Pre-2.0.0 rows.
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
