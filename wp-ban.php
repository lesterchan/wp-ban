<?php
/**
 * Plugin Name: WP-Ban
 * Plugin URI: https://lesterchan.net/portfolio/programming/php/
 * Description: Ban users by IP, IP range, host name, user agent and referrer URL from visiting your WordPress blog.
 * Version: 2.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: Lester 'GaMerZ' Chan
 * Author URI: https://lesterchan.net
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ban
 * Domain Path: /languages
 *
 * @package WP-Ban
 */

/*
	Copyright 2026  Lester Chan  (email : lesterchan@gmail.com)

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
	Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
*/

defined( 'ABSPATH' ) || exit;

/**
 * WP-Ban version. The last-run value is kept in the wp_ban_version row.
 */
define( 'WP_BAN_VERSION', '2.0.0' );

/**
 * Schema counter. Bumped only when the stored rows need reshaping.
 */
define( 'WP_BAN_DB_VERSION', '2' );

/**
 * WP-Ban slug, which is also the text domain.
 */
define( 'WP_BAN_SLUG', 'wp-ban' );

/**
 * WP-Ban main file.
 */
define( 'WP_BAN_MAIN_FILE', __FILE__ );

/**
 * WP-Ban directory, with a trailing slash.
 */
define( 'WP_BAN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * WP-Ban URL, with a trailing slash.
 *
 * Derived rather than hardcoded: before 2.0.0 the plugin built its admin URL
 * from the literal string 'wp-ban/ban-options.php', so installing under any
 * other directory name broke the settings screen outright.
 */
define( 'WP_BAN_URL', plugin_dir_url( __FILE__ ) );

require_once WP_BAN_DIR . 'includes/class-wp-ban-options.php';
require_once WP_BAN_DIR . 'includes/class-wp-ban-ip.php';
require_once WP_BAN_DIR . 'includes/class-wp-ban-stats.php';
require_once WP_BAN_DIR . 'includes/class-wp-ban-verdict.php';
require_once WP_BAN_DIR . 'includes/class-wp-ban-blocker.php';
require_once WP_BAN_DIR . 'includes/class-wp-ban-settings.php';
require_once WP_BAN_DIR . 'includes/class-wp-ban.php';

WP_Ban::get_instance();
