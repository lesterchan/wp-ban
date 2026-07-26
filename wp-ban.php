<?php
/*
Plugin Name: WP-Ban
Plugin URI: https://lesterchan.net/portfolio/programming/php/
Description: Ban users by IP, IP Range, host name, user agent and referer url from visiting your WordPress's blog. It will display a custom ban message when the banned IP, IP range, host name, user agent or referer url tries to visit you blog. You can also exclude certain IPs from being banned. There will be statistics recordered on how many times they attemp to visit your blog. It allows wildcard matching too.
Version: 1.69.2
Author: Lester 'GaMerZ' Chan
Author URI: https://lesterchan.net
Text Domain: wp-ban
*/


/*
	Copyright 2025  Lester Chan  (email : lesterchan@gmail.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/


// Create Text Domain For Translation
add_action( 'plugins_loaded', 'ban_textdomain' );
function ban_textdomain() {
	load_plugin_textdomain( 'wp-ban' );
}


// Function: Ban Menu
add_action( 'admin_menu', 'ban_menu' );
function ban_menu() {
	add_options_page( __( 'Ban', 'wp-ban' ), __( 'Ban', 'wp-ban' ), 'manage_options', 'wp-ban/ban-options.php' );
}


// Function: Get IP Address (http://stackoverflow.com/a/2031935)
function ban_get_ip() {
	$banned_options = get_option( 'banned_options' );

	// The row predates 1.64 on old installs, and an upgrade does not fire the
	// activation hook that would create it.
	$reverse_proxy = is_array( $banned_options ) && isset( $banned_options['reverse_proxy'] )
		&& intval( $banned_options['reverse_proxy'] ) === 1;

	// REMOTE_ADDR is the only address the visitor cannot choose, so it is the
	// baseline. esc_attr() used to stand in for validation here; it is an
	// output escaper, not a sanitiser, and it let an unvalidated value through.
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? ban_valid_ip( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	if ( $reverse_proxy ) {
		foreach ( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			// X-Forwarded-For is a chain -- "client, proxy1, proxy2" -- and
			// private/reserved entries are the hops, not the visitor.
			foreach ( explode( ',', wp_unslash( $_SERVER[ $key ] ) ) as $candidate ) {
				$candidate = trim( $candidate );

				if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) !== false ) {
					return $candidate;
				}
			}
		}
	}

	// Falling through to REMOTE_ADDR matters: with the box ticked and no proxy
	// header present this used to return '', and every ban stopped applying.
	return $ip;
}


// Function: Validate An IP Address, Or Return An Empty String
function ban_valid_ip( $ip ) {
	$ip = filter_var( trim( (string) $ip ), FILTER_VALIDATE_IP );

	return ( $ip === false ) ? '' : $ip;
}


// Function: Preview Banned Message
add_action( 'wp_ajax_ban-admin', 'preview_banned_message' );
function preview_banned_message() {
	// wp_ajax_* fires for every authenticated role, subscribers included, so
	// the hook alone is not an authorisation check.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( -1, 403 );
	}

	$banned_stats   = get_option( 'banned_stats' );
	$banned_ip      = ban_get_ip();
	$user_attempts  = isset( $banned_stats['users'][ $banned_ip ] ) ? intval( $banned_stats['users'][ $banned_ip ] ) : 0;
	$total_attempts = isset( $banned_stats['count'] ) ? intval( $banned_stats['count'] ) : 0;
	$banned_message = stripslashes( get_option( 'banned_message' ) );
	$banned_message = str_replace( '%SITE_NAME%', get_option( 'blogname' ), $banned_message );
	$banned_message = str_replace( '%SITE_URL%', get_option( 'siteurl' ), $banned_message );
	$banned_message = str_replace( '%USER_ATTEMPTS_COUNT%', number_format_i18n( $user_attempts ), $banned_message );
	$banned_message = str_replace( '%USER_IP%', $banned_ip, $banned_message );
	$banned_message = str_replace( '%USER_HOSTNAME%', ban_gethostbyaddr( $banned_ip ), $banned_message );
	$banned_message = str_replace( '%TOTAL_ATTEMPTS_COUNT%', number_format_i18n( $total_attempts ), $banned_message );
	echo $banned_message;
	exit();
}


// Function: Reverse DNS Lookup, Without Warning On An Empty Address
function ban_gethostbyaddr( $ip ) {
	if ( ban_valid_ip( $ip ) === '' ) {
		return '';
	}

	return gethostbyaddr( $ip );
}


// Function: Print Out Banned Message
function print_banned_message() {
	$banned_ip    = ban_get_ip();
	$banned_stats = get_option( 'banned_stats' );
	if ( isset( $banned_stats['count'] ) ) {
		$banned_stats['count'] += 1;
	} else {
		$banned_stats['count'] = 1;
	}
	if ( isset( $banned_stats['users'][ $banned_ip ] ) ) {
		$banned_stats['users'][ $banned_ip ] += 1;
	} else {
		$banned_stats['users'][ $banned_ip ] = 1;
	}
	update_option( 'banned_stats', $banned_stats );
	$banned_message = str_replace(
		array(
			'%SITE_NAME%',
			'%SITE_URL%',
			'%USER_ATTEMPTS_COUNT%',
			'%USER_IP%',
			'%USER_HOSTNAME%',
			'%TOTAL_ATTEMPTS_COUNT%',
		),
		array(
			get_option( 'blogname' ),
			get_option( 'siteurl' ),
			number_format_i18n( $banned_stats['users'][ $banned_ip ] ),
			$banned_ip,
			ban_gethostbyaddr( $banned_ip ),
			number_format_i18n( $banned_stats['count'] ),
		),
		stripslashes( get_option( 'banned_message' ) )
	);

	/**
	 * Filters the HTTP status the ban page is served with.
	 *
	 * Until 2.0.0 this was 200 OK, so search engines and caches treated the ban
	 * page as the site's real content. Return 200 to restore that.
	 *
	 * @param int $status HTTP status code.
	 */
	$status = (int) apply_filters( 'wp_ban_status_code', 403 );

	if ( ! headers_sent() ) {
		status_header( $status );
		nocache_headers();
	}

	echo '<!DOCTYPE html>' . "\n";
	echo $banned_message;
	exit();
}


// Function: Process Banning
function process_ban( $banarray, $against ) {
	if ( ! empty( $banarray ) && ! empty( $against ) ) {
		foreach ( $banarray as $cban ) {
			if ( preg_match_wildcard( $cban, $against ) ) {
				print_banned_message();
			}
		}
	}
	return;
}


// Function: Process Banned IP Range
function process_ban_ip_range( $banned_ips_range ) {
	if ( ! empty( $banned_ips_range ) ) {
		foreach ( $banned_ips_range as $banned_ip_range ) {
			$range = explode( '-', $banned_ip_range );
			// A stored entry need not have a separator; the save path has
			// required one since 1.11 but nothing ever cleaned older rows.
			if ( count( $range ) !== 2 ) {
				continue;
			}
			$range_start = trim( $range[0] );
			$range_end   = trim( $range[1] );
			if ( check_ip_within_range( ban_get_ip(), $range_start, $range_end ) ) {
				print_banned_message();
				break;
			}
		}
	}
}


// Function: Banned
add_action( 'init', 'banned' );
function banned() {
	$ip = ban_get_ip();
	if ( $ip === 'unknown' ) {
		return;
	}
	$banned_ips = get_option( 'banned_ips' );
	if ( is_array( $banned_ips ) ) {
		$banned_ips = array_filter( $banned_ips );
	}

	$banned_ips_range = get_option( 'banned_ips_range' );
	if ( is_array( $banned_ips_range ) ) {
		$banned_ips_range = array_filter( $banned_ips_range );
	}

	$banned_hosts = get_option( 'banned_hosts' );
	if ( is_array( $banned_hosts ) ) {
		$banned_hosts = array_filter( $banned_hosts );
	}

	$banned_referers = get_option( 'banned_referers' );
	if ( is_array( $banned_referers ) ) {
		$banned_referers = array_filter( $banned_referers );
	}

	$banned_user_agents = get_option( 'banned_user_agents' );
	if ( is_array( $banned_user_agents ) ) {
		$banned_user_agents = array_filter( $banned_user_agents );
	}

	$banned_exclude_ips = get_option( 'banned_exclude_ips' );
	if ( is_array( $banned_exclude_ips ) ) {
		$banned_exclude_ips = array_filter( $banned_exclude_ips );
	}

	$is_excluded = false;
	if ( ! empty( $banned_exclude_ips ) ) {
		foreach ( $banned_exclude_ips as $banned_exclude_ip ) {
			if ( $ip === $banned_exclude_ip ) {
				$is_excluded = true;
				break;
			}
		}
	}

	if ( ! $is_excluded ) {
		if ( ! empty( $banned_ips ) ) {
			process_ban( $banned_ips, $ip );
		}
		if ( ! empty( $banned_ips_range ) ) {
			process_ban_ip_range( $banned_ips_range );
		}
		if ( ! empty( $banned_hosts ) ) {
			process_ban( $banned_hosts, ban_gethostbyaddr( $ip ) );
		}
		if ( ! empty( $banned_referers ) && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			process_ban( $banned_referers, wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}
		if ( ! empty( $banned_user_agents ) && ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			process_ban( $banned_user_agents, wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}
	}
}


// Function: Check Whether Or Not The IP Address Belongs To Admin
function is_admin_ip( $check ) {
	return preg_match_wildcard( $check, ban_get_ip() );
}


// Function: Check Whether IP Within A Given IP Range
function check_ip_within_range( $ip, $range_start, $range_end ) {
	/*
	 * ip2long() returned false for an unparseable bound, and PHP compares an
	 * int against a bool by casting the int to true -- so `$ip >= false` was
	 * always true and a range with a junk lower bound banned every address
	 * below its upper bound. Validate first, and bail on anything malformed.
	 *
	 * inet_pton() also gets IPv6 right, which ip2long() could not see at all:
	 * an IPv6 range silently matched nobody.
	 */
	$ip          = ban_valid_ip( $ip );
	$range_start = ban_valid_ip( $range_start );
	$range_end   = ban_valid_ip( $range_end );

	if ( $ip === '' || $range_start === '' || $range_end === '' ) {
		return false;
	}

	$ip          = inet_pton( $ip );
	$range_start = inet_pton( $range_start );
	$range_end   = inet_pton( $range_end );

	// Packed addresses are 4 bytes for IPv4 and 16 for IPv6; comparing across
	// families is meaningless, so a mixed range matches nothing.
	if ( strlen( $ip ) !== strlen( $range_start ) || strlen( $ip ) !== strlen( $range_end ) ) {
		return false;
	}

	// inet_pton() packs big-endian, so a byte-wise compare is an unsigned
	// numeric compare -- and unlike ip2long() it is correct above 127.x on
	// 32-bit builds too.
	return ( strcmp( $ip, $range_start ) >= 0 && strcmp( $ip, $range_end ) <= 0 );
}


// Function: Check Whether Or Not The Hostname Belongs To Admin
function is_admin_hostname( $check ) {
	return preg_match_wildcard( $check, ban_gethostbyaddr( ban_get_ip() ) );
}


// Function: Check Whether Or Not The Referer Belongs To This Site
function is_admin_referer( $check ) {
	// A request need not carry a Referer, and passing null to preg_match() is
	// deprecated as of PHP 8.1.
	$referer      = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
	$url_patterns = array( get_option( 'siteurl' ), get_option( 'home' ), get_option( 'siteurl' ) . '/', get_option( 'home' ) . '/', get_option( 'siteurl' ) . '/ ', get_option( 'home' ) . '/ ', $referer );
	foreach ( $url_patterns as $url ) {
		if ( preg_match_wildcard( $check, $url ) ) {
			return true;
		}
	}
	return false;
}


// Function: Check Whether Or Not The User Agent Is Used by Admin
function is_admin_user_agent( $check ) {
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
	return preg_match_wildcard( $check, $user_agent );
}


// Function: Wildcard Check
function preg_match_wildcard( $regex, $subject ) {
	$regex = preg_quote( $regex, '#' );
	$regex = str_replace( '\*', '.*', $regex );
	if ( preg_match( "#^$regex$#", (string) $subject ) ) {
		return true;
	} else {
		return false;
	}
}


// Function: Activate Plugin
register_activation_hook( __FILE__, 'ban_activation' );
function ban_activation( $network_wide ) {
	if ( is_multisite() && $network_wide ) {
		/*
		 * wp_get_sites() was removed in WordPress 5.1, so network activation
		 * fatalled rather than merely skipping sites. 'number' => 0 lifts
		 * WP_Site_Query's default cap of 100, which would otherwise leave every
		 * site past the hundredth unactivated while reporting success.
		 */
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			// switch_to_blog() pushes onto a stack, so the restore belongs
			// inside the loop -- restoring once at the end unwinds it by one.
			switch_to_blog( (int) $site_id );
			ban_activate();
			restore_current_blog();
		}
	} else {
		ban_activate();
	}
}

function ban_activate() {
	add_option( 'banned_ips', array() );
	add_option( 'banned_hosts', array() );
	add_option(
		'banned_stats',
		array(
			'users' => array(),
			'count' => 0,
		)
	);
	add_option(
		'banned_message',
		'<html>' . "\n" .
		'<head>' . "\n" .
		'<meta charset="utf-8">' . "\n" .
		'<title>%SITE_NAME% - %SITE_URL%</title>' . "\n" .
		'</head>' . "\n" .
		'<body>' . "\n" .
		'<div id="wp-ban-container">' . "\n" .
		'<p style="text-align: center; font-weight: bold;">' . __( 'You Are Banned.', 'wp-ban' ) . '</p>' . "\n" .
		'</div>' . "\n" .
		'</body>' . "\n" .
		'</html>',
		'Banned Message'
	);
	// Database Upgrade For WP-Ban 1.11
	add_option( 'banned_referers', array() );
	add_option( 'banned_exclude_ips', array() );
	add_option( 'banned_ips_range', array() );
	// Database Upgrade For WP-Ban 1.30
	add_option( 'banned_user_agents', array() );
	// Database Upgrade For WP-Ban 1.64
	add_option( 'banned_options', array( 'reverse_proxy' => 0 ) );
}
