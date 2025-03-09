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
);

if ( is_multisite() ) {
	$ms_sites = wp_get_sites();

	if( 0 < sizeof( $ms_sites ) ) {
		foreach ( $ms_sites as $ms_site ) {
			switch_to_blog( $ms_site['blog_id'] );
			if( sizeof( $option_names ) > 0 ) {
				foreach( $option_names as $option_name ) {
					delete_option( $option_name );
					plugin_uninstalled();
				}
			}
		}
	}

	restore_current_blog();
} else {
	if( sizeof( $option_names ) > 0 ) {
		foreach( $option_names as $option_name ) {
			delete_option( $option_name );
			plugin_uninstalled();
		}
	}
}

/**
 * Delete plugin table when uninstalled
 *
 * @access public
 * @return void
 */
function plugin_uninstalled() {
	global $wpdb;
}