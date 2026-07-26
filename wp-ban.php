<?php
/**
 * Plugin Name: WP-Ban
 * Plugin URI: https://lesterchan.net/portfolio/programming/php/
 * Description: Ban users by IP, IP range, host name, user agent and referrer URL from visiting your WordPress blog.
 * Version: 2.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
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
	Copyright 2026 Lester Chan  (email : lesterchan@gmail.com)

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

defined( 'ABSPATH' ) || exit;

/**
 * WP-Ban version.
 */
define( 'WP_BAN_VERSION', '2.0.0' );

/**
 * WP-Ban main file.
 */
define( 'WP_BAN_MAIN_FILE', __FILE__ );

/**
 * Absolute path to the plugin directory, with a trailing slash.
 */
define( 'WP_BAN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL of the plugin directory, with a trailing slash.
 *
 * Derived rather than hardcoded: before 2.0.0 the plugin built its admin URL
 * from the literal string 'wp-ban/ban-options.php', so installing under any
 * other directory name broke the settings screen outright.
 */
define( 'WP_BAN_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-ban-options.php';
require_once __DIR__ . '/includes/class-ban-ip.php';
require_once __DIR__ . '/includes/class-ban-stats.php';
require_once __DIR__ . '/includes/class-ban-blocker.php';
require_once __DIR__ . '/includes/class-ban-settings.php';
require_once __DIR__ . '/includes/class-ban.php';

Ban::get_instance();
