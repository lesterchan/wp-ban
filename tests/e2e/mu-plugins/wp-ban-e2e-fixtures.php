<?php
/**
 * Plugin Name: WP-Ban E2E fixtures
 * Description: Attaches the plugin's six public hooks, so the browser suite can reach the decisions a site plugin owns. Loaded only in the wp-env tests environment.
 *
 * Every hook here is documented as public API and every one of them decides
 * something that only exists in a live request: whether the check runs at all,
 * what address the request is attributed to, what status a banned visitor gets,
 * who may reach the admin surface, and whether the administrator saving a form
 * is protected from banning themselves. None has a caller in the plugin, so
 * without a file playing the part of the site plugin that would attach them,
 * all six would ship untested.
 *
 * Each reads its answer out of an option and passes the value through untouched
 * when that option is absent. A fixture that always answered would decide the
 * outcome of every other test in the suite -- and in this plugin, "always
 * answered" could mean every request after it is served a 403.
 *
 * It is a fixture, not a shipped file: it lives under tests/ and is mapped into
 * wp-content/mu-plugins for the tests environment only, by .wp-env.json.
 *
 * @package WP-Ban
 */

defined( 'ABSPATH' ) || exit;

/*
 * Web requests only.
 *
 * wp-env maps this directory into the tests environment, and PHPUnit runs in
 * that same environment -- so without this guard the unit suite would load a
 * fixture that has no business being visible to a test that is not driving a
 * browser.
 */
if ( 'cli' === PHP_SAPI ) {
	return;
}

/**
 * Read one of the fixture's answers.
 *
 * null means "this test has not asked for anything", which is a different thing
 * from an answer of false -- and telling the two apart is the whole reason the
 * filters below are inert by default.
 *
 * The answers travel as JSON text rather than as their own types, which is not
 * fussiness. update_option( $name, false ) on a row that does not exist writes
 * nothing at all: update_option() reads the old value first, gets false because
 * the row is absent, sees that it equals the value it was given, and returns
 * without touching the database. Every "switch this filter off" answer would
 * therefore have been silently invisible, and the filter would have gone on
 * saying yes while the test believed it had said no.
 *
 * @param string $name Option suffix.
 * @return mixed|null The stored answer, or null when there is none.
 */
function wp_ban_e2e_answer( $name ) {
	$value = get_option( 'wp_ban_e2e_' . $name, null );

	if ( null === $value || '' === $value ) {
		return null;
	}

	return json_decode( (string) $value, true );
}

/**
 * Switch the ban check off entirely.
 *
 * The filter is the escape hatch for a site that wants bans on the front end
 * and not in wp-admin, or off during a maintenance window.
 *
 * @param bool $enabled Whether the plugin decided to run the check.
 * @return bool
 */
function wp_ban_e2e_enabled( $enabled ) {
	$answer = wp_ban_e2e_answer( 'enabled' );

	return null === $answer ? $enabled : (bool) $answer;
}

add_filter( 'wp_ban_enabled', 'wp_ban_e2e_enabled' );

/**
 * Rewrite the address the plugin attributes the request to.
 *
 * A site behind something the plugin has never heard of resolves the address
 * itself and hands the answer over here, so this is the last word on identity.
 *
 * @param string $ip The address the plugin resolved.
 * @return string
 */
function wp_ban_e2e_ipaddress( $ip ) {
	$answer = wp_ban_e2e_answer( 'ipaddress' );

	return null === $answer ? $ip : (string) $answer;
}

add_filter( 'wp_ban_ipaddress', 'wp_ban_e2e_ipaddress' );

/**
 * Trust the usual proxy headers without the checkbox or the constant.
 *
 * @param bool $trust Whether the constant is defined and true.
 * @return bool
 */
function wp_ban_e2e_trust_proxy( $trust ) {
	$answer = wp_ban_e2e_answer( 'trust_proxy' );

	return null === $answer ? $trust : (bool) $answer;
}

add_filter( 'wp_ban_trust_proxy', 'wp_ban_e2e_trust_proxy' );

/**
 * Serve the ban page with a different status.
 *
 * Documented as the way back to the 200 the plugin sent until 2.0.0, for a site
 * that would rather its ban page were cached and indexed.
 *
 * @param int $status The status the plugin chose.
 * @return int
 */
function wp_ban_e2e_status_code( $status ) {
	$answer = wp_ban_e2e_answer( 'status_code' );

	return null === $answer ? $status : (int) $answer;
}

add_filter( 'wp_ban_status_code', 'wp_ban_e2e_status_code' );

/**
 * Stop protecting the administrator from banning themselves.
 *
 * @param bool $protect Whether the plugin would drop matching entries.
 * @return bool
 */
function wp_ban_e2e_protect_self( $protect ) {
	$answer = wp_ban_e2e_answer( 'protect_self' );

	return null === $answer ? $protect : (bool) $answer;
}

add_filter( 'wp_ban_protect_self', 'wp_ban_e2e_protect_self' );

/**
 * Hand the admin surface to a lesser capability.
 *
 * `read` is the capability every logged-in user has, so a subscriber reaching
 * the screen is unambiguous evidence the filter was honoured rather than that
 * some other gate happened to let them by.
 *
 * @param string $capability The capability the plugin requires.
 * @return string
 */
function wp_ban_e2e_capability( $capability ) {
	$answer = wp_ban_e2e_answer( 'capability' );

	return null === $answer ? $capability : (string) $answer;
}

add_filter( 'wp_ban_capability', 'wp_ban_e2e_capability' );

/**
 * Write down that a visitor was turned away, and with what.
 *
 * `wp_ban_denied` is the hook a site logs bans from or feeds to fail2ban, and
 * it fires on a request that ends in exit() -- so the only way to observe it is
 * to leave something behind for the next request to read. The row is not
 * autoloaded: it is written on a request that is already being denied, and
 * nothing but a test ever reads it.
 *
 * @param string $ip     The banned visitor's address.
 * @param int    $status The status being sent.
 * @return void
 */
function wp_ban_e2e_record_denial( $ip, $status ) {
	update_option(
		'wp_ban_e2e_last_denial',
		array(
			'ip'     => (string) $ip,
			'status' => (int) $status,
		),
		false
	);
}

add_action( 'wp_ban_denied', 'wp_ban_e2e_record_denial', 10, 2 );
