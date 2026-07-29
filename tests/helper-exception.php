<?php
/**
 * The seam that lets the ban decision be asserted.
 *
 * @package WP-Ban
 */

/**
 * Thrown by the test listener on wp_ban_denied.
 *
 * WP_Ban_Blocker::deny() ends in exit(); wp_ban_denied fires immediately before
 * that, so throwing from it stops the runner being taken down while still
 * exercising the real entry point.
 */
class WP_Ban_Denied_Exception extends Exception {
}
