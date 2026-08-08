<?php
/**
 * Which ban list a request matches, if any.
 *
 * Split out of WP_Ban_Blocker so the question can be asked without answering
 * it. WP_Ban_Blocker::check() used to interleave the decision with the
 * consequence -- it worked its way down the six lists and called deny() from
 * the middle of that walk, and deny() writes a statistic, prints a whole HTML
 * document and exits. A second caller wanting only the decision therefore had
 * no way in, and the two callers there are now both want exactly that: the
 * blocker, which turns a verdict into a ban page, and `wp ban check`, which
 * turns the same verdict into a line of output.
 *
 * @package WP-Ban
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a request matches a ban list, and which one.
 */
class WP_Ban_Verdict {

	/**
	 * The ban list a request matches, or an empty string for none.
	 *
	 * The order is the order the lists are checked in, and it is not
	 * arbitrary: the exclude list wins over everything, the two address lists
	 * are free to test, and the host name list costs a reverse DNS lookup --
	 * so it is only reached when there is a host name list to match against,
	 * and hoisting that lookup out of the condition would put a blocking DNS
	 * call on every request to sites that do not use the list at all.
	 *
	 * Only the first match is reported. Somebody on two lists is banned once,
	 * and the name this returns is the reason to show them.
	 *
	 * @param string $ip         Address to test. May be empty when it could not be resolved.
	 * @param string $referer    Referrer to test against the referrer list.
	 * @param string $user_agent User agent to test against the user agent list.
	 * @return string One of the list keys, or '' when nothing matched.
	 */
	public static function matched_list( $ip, $referer = '', $user_agent = '' ) {
		if ( self::is_excluded( $ip ) ) {
			return '';
		}

		if ( '' !== $ip ) {
			if ( WP_Ban_IP::matches_any( WP_Ban_Options::list_of( 'ips' ), $ip ) ) {
				return 'ips';
			}

			if ( WP_Ban_IP::in_any_range( WP_Ban_Options::list_of( 'ips_range' ), $ip ) ) {
				return 'ips_range';
			}
		}

		$hosts = WP_Ban_Options::list_of( 'hosts' );

		// gethostbyaddr() is a blocking DNS lookup, so it only happens when
		// there is a host name list to match against. Do not hoist it.
		if ( ! empty( $hosts ) && WP_Ban_IP::matches_any( $hosts, WP_Ban_IP::hostname( $ip ) ) ) {
			return 'hosts';
		}

		if ( WP_Ban_IP::matches_any( WP_Ban_Options::list_of( 'referers' ), $referer ) ) {
			return 'referers';
		}

		if ( WP_Ban_IP::matches_any( WP_Ban_Options::list_of( 'user_agents' ), $user_agent ) ) {
			return 'user_agents';
		}

		return '';
	}

	/**
	 * Whether this address is on the exclude list.
	 *
	 * Compared exactly rather than as a wildcard, which is what the field's own
	 * description promises: an exclusion is an address somebody typed out in
	 * full, and a pattern here would be a way to accidentally exclude a whole
	 * block from every ban the site has.
	 *
	 * @param string $ip Visitor address.
	 * @return bool
	 */
	public static function is_excluded( $ip ) {
		if ( '' === (string) $ip ) {
			return false;
		}

		foreach ( WP_Ban_Options::list_of( 'exclude_ips' ) as $excluded ) {
			if ( $ip === $excluded ) {
				return true;
			}
		}

		return false;
	}
}
