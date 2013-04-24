<?php
/*
Plugin Name: WP-Ban
Plugin URI: http://lesterchan.net/portfolio/programming/php/
Description: Ban users by IP, IP Range, host name, user agent and referer url from visiting your WordPress's blog. It will display a custom ban message when the banned IP, IP range, host name, user agent or referer url tries to visit you blog. You can also exclude certain IPs from being banned. There will be statistics recordered on how many times they attemp to visit your blog. It allows wildcard matching too.
Version: 1.62
Author: Lester 'GaMerZ' Chan
Author URI: http://lesterchan.net
Text Domain: wp-ban
*/


/*
	Copyright 2013  Lester Chan  (email : lesterchan@gmail.com)

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


### Create Text Domain For Translation
add_action('init', 'ban_textdomain');
function ban_textdomain() {
	load_plugin_textdomain('wp-ban', false, 'wp-ban');
}


### Function: Ban Menu
add_action('admin_menu', 'ban_menu');
function ban_menu() {
	if (function_exists('add_management_page')) {
		add_options_page(__('Ban', 'wp-ban'), __('Ban', 'wp-ban'), 'manage_options', 'wp-ban/ban-options.php');
	}
}


### Function: Get IP Address
if(!function_exists('get_IP')) {
	function get_IP() {
		if(!empty($_SERVER['HTTP_CLIENT_IP'])) {
			$ip_address = $_SERVER['HTTP_CLIENT_IP'];
		} else if(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else if(!empty($_SERVER['REMOTE_ADDR'])) {
			$ip_address = $_SERVER['REMOTE_ADDR'];
		} else {
			$ip_address = '';
		}
		if(strpos($ip_address, ',') !== false) {
			$ip_address = explode(',', $ip_address);
			$ip_address = $ip_address[0];
		}
		return esc_attr($ip_address);
	}
}


### Function: Preview Banned Message
add_action('wp_ajax_ban-admin', 'preview_banned_message');
function preview_banned_message()
{
	$banned_stats = get_option('banned_stats');
	$banned_message = stripslashes(get_option('banned_message'));
	$banned_message = str_replace("%SITE_NAME%", get_option('blogname'), $banned_message);
	$banned_message = str_replace("%SITE_URL%",  get_option('siteurl'), $banned_message);
	$banned_message = str_replace("%USER_ATTEMPTS_COUNT%",  number_format_i18n($banned_stats['users'][get_IP()]), $banned_message);
	$banned_message = str_replace("%USER_IP%", get_IP(), $banned_message);
	$banned_message = str_replace("%USER_HOSTNAME%",  @gethostbyaddr(get_IP()), $banned_message);
	$banned_message = str_replace("%TOTAL_ATTEMPTS_COUNT%", number_format_i18n($banned_stats['count']), $banned_message);
	echo $banned_message;
	exit();
}


### Function: Print Out Banned Message
function print_banned_message() {
	// Credits To Joe (Ttech) - http://blog.fileville.net/
	$banned_stats = get_option('banned_stats');
	$banned_stats['count'] = intval($banned_stats['count']) + 1;
	$banned_stats['users'][get_IP()] = intval($banned_stats['users'][get_IP()]) + 1;
	update_option('banned_stats', $banned_stats);
	$banned_message = stripslashes(get_option('banned_message'));
	$banned_message = str_replace("%SITE_NAME%", get_option('blogname'), $banned_message);
	$banned_message = str_replace("%SITE_URL%",  get_option('siteurl'), $banned_message);
	$banned_message = str_replace("%USER_ATTEMPTS_COUNT%",  number_format_i18n($banned_stats['users'][get_IP()]), $banned_message);
	$banned_message = str_replace("%USER_IP%", get_IP(), $banned_message);
	$banned_message = str_replace("%USER_HOSTNAME%",  @gethostbyaddr(get_IP()), $banned_message);
	$banned_message = str_replace("%TOTAL_ATTEMPTS_COUNT%", number_format_i18n($banned_stats['count']), $banned_message);
	echo $banned_message;
	exit();
}


### Function: Process Banning
function process_ban($banarray, $against)  {
	if(!empty($banarray) && !empty($against)) {
		foreach($banarray as $cban) {
			if(preg_match_wildcard($cban, $against)) {
				print_banned_message();
			}
		}
	}
	return;
}


### Function: Process Banned IP Range
function process_ban_ip_range($banned_ips_range) {
	if(!empty($banned_ips_range)) {
		foreach($banned_ips_range as $banned_ip_range) {
			$range = explode('-', $banned_ip_range);
			$range_start = trim($range[0]);
			$range_end = trim($range[1]);
			if(check_ip_within_range(get_IP(), $range_start, $range_end)) {
				print_banned_message();
				break;
			}
		}
	}
}


### Function: Banned
add_action('init', 'banned');
function banned() {
	$ip = get_IP();
	if($ip == 'unknown') {
		return;
	}
	$banned_ips = get_option('banned_ips');
	if(is_array($banned_ips))
		$banned_ips = array_filter($banned_ips);

	$banned_ips_range = get_option('banned_ips_range');
	if(is_array($banned_ips_range))
		$banned_ips_range = array_filter($banned_ips_range);

	$banned_hosts = get_option('banned_hosts');
	if(is_array($banned_hosts))
		$banned_hosts = array_filter($banned_hosts);

	$banned_referers = get_option('banned_referers');
	if(is_array($banned_referers))
		$banned_referers = array_filter($banned_referers);

	$banned_user_agents = get_option('banned_user_agents');
	if(is_array($banned_user_agents))
		$banned_user_agents = array_filter($banned_user_agents);

	$banned_exclude_ips = get_option('banned_exclude_ips');
	if(is_array($banned_exclude_ips))
		$banned_exclude_ips = array_filter($banned_exclude_ips);

	$is_excluded = false;
	if(!empty($banned_exclude_ips)) {
		foreach($banned_exclude_ips as $banned_exclude_ip) {
			if($ip == $banned_exclude_ip) {
				$is_excluded = true;
				break;
			}
		}
	}

	if(!$is_excluded) {
		if(!empty($banned_ips))
			process_ban($banned_ips, $ip);
		if(!empty($banned_ips_range))
			process_ban_ip_range($banned_ips_range);
		if(!empty($banned_hosts))
			process_ban($banned_hosts, @gethostbyaddr($ip));
		if(!empty($banned_referers))
			process_ban($banned_referers, $_SERVER['HTTP_REFERER']);
		if(!empty($banned_user_agents))
			process_ban($banned_user_agents, $_SERVER['HTTP_USER_AGENT']);
	}
}


### Function: Check Whether Or Not The IP Address Belongs To Admin
function is_admin_ip($check) {
	return preg_match_wildcard($check, get_IP());
}


### Function: Check Whether IP Within A Given IP Range
function check_ip_within_range($ip, $range_start, $range_end) {
	$range_start = ip2long($range_start);
	$range_end = ip2long($range_end);
	$ip = ip2long($ip);
	if($ip !== false && $ip >= $range_start && $ip <= $range_end) {
		return true;
	}
	return false;
}


### Function: Check Whether Or Not The Hostname Belongs To Admin
function is_admin_hostname($check) {
	return preg_match_wildcard($check, @gethostbyaddr(get_IP()));
}


### Function: Check Whether Or Not The Referer Belongs To This Site
function is_admin_referer($check) {
	$url_patterns = array(get_option('siteurl'), get_option('home'), get_option('siteurl').'/', get_option('home').'/', get_option('siteurl').'/ ', get_option('home').'/ ', $_SERVER['HTTP_REFERER']);
	foreach($url_patterns as $url) {
		if(preg_match_wildcard($check, $url)) {
			return true;
		}
	}
	return false;
}


### Function: Check Whether Or Not The User Agent Is Used by Admin
function is_admin_user_agent($check) {
	return preg_match_wildcard($check, $_SERVER['HTTP_USER_AGENT']);
}


### Function: Returns page's language attributes depends on WordPress language
function get_language_attributes($doctype = 'html') {
	ob_start();
	language_attributes();
	$language_attributes = ob_get_contents();
	ob_end_clean();
	return $language_attributes;
}


### Function: Wildcard Check
function preg_match_wildcard($regex, $subject) {
	$regex = preg_quote($regex, '#');
	$regex = str_replace('\*', '.*', $regex);
	if(preg_match("#^$regex$#", $subject))
	{
		return true;
	}
	else
	{
		return false;
	}
}


### Function: Create Ban Options
add_action('activate_wp-ban/wp-ban.php', 'ban_init');
function ban_init() {
	global $wpdb;
	ban_textdomain();
	$banned_ips = array();
	$banned_ips_range = array();
	$banned_hosts = array();
	$banned_referers = array();
	$banned_exclude_ips = array();
	$banned_stats = array('users' => array(), 'count' => 0);
	add_option('banned_ips', $banned_ips, 'Banned IPs');
	add_option('banned_hosts', $banned_hosts, 'Banned Hosts');
	add_option('banned_stats', $banned_stats, 'WP-Ban Stats');
	add_option('banned_message', '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'."\n".
	'<html xmlns="http://www.w3.org/1999/xhtml" '.get_language_attributes().'>'."\n".
	'<head>'."\n".
	'<meta http-equiv="Content-Type" content="text/html; charset='.get_option('blog_charset').'" />'."\n".
	'<title>%SITE_NAME% - %SITE_URL%</title>'."\n".
	'</head>'."\n".
	'<body>'."\n".
	'<div id="wp-ban-container">'."\n".
	'<p style="text-align: center; font-weight: bold;">'.__('You Are Banned.', 'wp-ban').'</p>'."\n".
	'</div>'."\n".
	'</body>'."\n".
	'</html>', 'Banned Message');
	// Database Upgrade For WP-Ban 1.11
	add_option('banned_referers', $banned_referers, 'Banned Referers');
	add_option('banned_exclude_ips', $banned_exclude_ips, 'Banned Exclude IP');
	add_option('banned_ips_range', $banned_ips_range, 'Banned IP Range');
	// Database Upgrade For WP-Ban 1.30
	add_option('banned_user_agents', $banned_user_agents, 'Banned User Agents');
}
?>