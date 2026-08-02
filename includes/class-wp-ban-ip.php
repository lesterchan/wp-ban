<?php
/**
 * Address resolution and pattern matching for WP-Ban.
 *
 * @package WP-Ban
 */

defined( 'ABSPATH' ) || exit;

/**
 * Works out who the visitor is, and whether they match a ban entry.
 */
class WP_Ban_IP {

	/**
	 * Proxy headers trusted once the site has opted in.
	 *
	 * @var string[]
	 */
	const PROXY_HEADERS = array(
		'HTTP_CF_CONNECTING_IP',
		'HTTP_CLIENT_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_FORWARDED',
		'HTTP_X_CLUSTER_CLIENT_IP',
		'HTTP_FORWARDED_FOR',
		'HTTP_FORWARDED',
	);

	/**
	 * The visitor's IP address.
	 *
	 * REMOTE_ADDR is the only address the visitor cannot choose, so it is the
	 * default and everything else is opt-in. Every proxy header is set by the
	 * client, so honouring them unconditionally lets anyone walk past a ban by
	 * sending a different value on each request.
	 *
	 * Sites genuinely behind a proxy opt in two ways, narrowest first: by naming
	 * the exact header on the settings screen, or by defining WP_BAN_TRUST_PROXY
	 * / returning true from the wp_ban_trust_proxy filter. Naming the header is
	 * the one to reach for; the constant is the escape hatch, and it trusts the
	 * whole of PROXY_HEADERS because it cannot know which one to prefer.
	 *
	 * @return string
	 */
	public static function address() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? self::valid_ip( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) )
			: '';

		$options = WP_Ban_Options::get();
		$header  = (string) $options['ip_header'];

		if ( '' !== $header && ! empty( $_SERVER[ $header ] ) ) {
			$candidate = self::first_valid_ip( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), false );

			if ( '' !== $candidate ) {
				return self::filter_address( $candidate );
			}
		}

		/**
		 * Filters whether the usual proxy headers may be trusted.
		 *
		 * Lets the decision be made per request -- trusting the header only when
		 * the request actually arrives from a known load balancer, say -- rather
		 * than once in wp-config.php. Defaults to the constant, so defining the
		 * constant keeps working and the filter gets the last word.
		 *
		 * @since 2.0.0
		 *
		 * @param bool $trust Defaults to the WP_BAN_TRUST_PROXY constant.
		 */
		$trust_proxy = (bool) apply_filters(
			'wp_ban_trust_proxy',
			defined( 'WP_BAN_TRUST_PROXY' ) && WP_BAN_TRUST_PROXY
		);

		if ( $trust_proxy ) {
			foreach ( self::PROXY_HEADERS as $name ) {
				if ( empty( $_SERVER[ $name ] ) ) {
					continue;
				}

				$candidate = self::first_valid_ip( sanitize_text_field( wp_unslash( $_SERVER[ $name ] ) ) );

				if ( '' !== $candidate ) {
					return self::filter_address( $candidate );
				}
			}
		}

		return self::filter_address( $ip );
	}

	/**
	 * Apply the public filter to a resolved address.
	 *
	 * @param string $ip Resolved address.
	 * @return string
	 */
	private static function filter_address( $ip ) {
		/**
		 * Filters the IP address the plugin attributes a request to.
		 *
		 * @since 2.0.0
		 *
		 * @param string $ip IP address.
		 */
		return (string) apply_filters( 'wp_ban_ipaddress', $ip );
	}

	/**
	 * The first usable address in a comma separated chain.
	 *
	 * X-Forwarded-For is a chain -- "client, proxy1, proxy2" -- and the visitor
	 * controls the left of it. Using the whole string as an identity means
	 * appending one more hop yields a different value, so the ban is defeated
	 * again even with the opt-in working as designed.
	 *
	 * @param string $value       Header value.
	 * @param bool   $public_only Whether to step over private and reserved hops.
	 * @return string
	 */
	public static function first_valid_ip( $value, $public_only = true ) {
		$flags = $public_only ? FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE : 0;

		foreach ( explode( ',', (string) $value ) as $candidate ) {
			$candidate = filter_var( trim( $candidate ), FILTER_VALIDATE_IP, $flags );

			if ( false !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * An IP address, or an empty string.
	 *
	 * @param string $ip Candidate.
	 * @return string
	 */
	public static function valid_ip( $ip ) {
		$ip = filter_var( trim( (string) $ip ), FILTER_VALIDATE_IP );

		return false === $ip ? '' : $ip;
	}

	/**
	 * Reverse DNS for an address.
	 *
	 * @param string $ip IP address. Defaults to the current visitor.
	 * @return string
	 */
	public static function hostname( $ip = null ) {
		$ip = null === $ip ? self::address() : self::valid_ip( $ip );

		if ( '' === $ip ) {
			return '';
		}

		$hostname = gethostbyaddr( $ip );

		return false === $hostname ? '' : $hostname;
	}

	/**
	 * The request's user agent.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
	}

	/**
	 * The request's referrer.
	 *
	 * @return string
	 */
	public static function referer() {
		return isset( $_SERVER['HTTP_REFERER'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) )
			: '';
	}

	/**
	 * Whether a subject matches a pattern in which * is a wildcard.
	 *
	 * @param string $pattern Ban pattern.
	 * @param string $subject Value to test.
	 * @return bool
	 */
	public static function matches_wildcard( $pattern, $subject ) {
		$pattern = (string) $pattern;
		$subject = (string) $subject;

		if ( '' === $pattern || '' === $subject ) {
			return false;
		}

		// preg_quote() first, so a pattern containing regex metacharacters is
		// matched literally; only the escaped star is turned back into a wildcard.
		$regex = str_replace( '\*', '.*', preg_quote( $pattern, '#' ) );

		return 1 === preg_match( '#^' . $regex . '$#', $subject );
	}

	/**
	 * Whether any pattern in a list matches the subject.
	 *
	 * @param string[] $patterns Ban patterns.
	 * @param string   $subject  Value to test.
	 * @return bool
	 */
	public static function matches_any( $patterns, $subject ) {
		if ( '' === (string) $subject ) {
			return false;
		}

		foreach ( (array) $patterns as $pattern ) {
			if ( self::matches_wildcard( $pattern, $subject ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Split "start-end" into two validated addresses of the same family.
	 *
	 * @param string $range Range entry.
	 * @return array{0:string,1:string}|false
	 */
	public static function parse_range( $range ) {
		$parts = explode( '-', (string) $range );

		if ( 2 !== count( $parts ) ) {
			return false;
		}

		$start = self::valid_ip( $parts[0] );
		$end   = self::valid_ip( $parts[1] );

		if ( '' === $start || '' === $end ) {
			return false;
		}

		// Packed addresses are 4 bytes for IPv4 and 16 for IPv6; a range that
		// mixes the two is meaningless.
		if ( strlen( inet_pton( $start ) ) !== strlen( inet_pton( $end ) ) ) {
			return false;
		}

		return array( $start, $end );
	}

	/**
	 * Whether an address falls inside an inclusive range.
	 *
	 * Before 2.0.0 this used ip2long(), which returns false for anything it
	 * cannot parse. PHP compares an int against a bool by casting the int to
	 * true, so `$ip >= false` was always true and a range with a junk lower
	 * bound banned every visitor at or below its upper bound. ip2long() also
	 * could not see IPv6 at all, so IPv6 ranges matched nobody.
	 *
	 * @param string $ip    Address to test.
	 * @param string $start Range start.
	 * @param string $end   Range end.
	 * @return bool
	 */
	public static function in_range( $ip, $start, $end ) {
		$ip    = self::valid_ip( $ip );
		$start = self::valid_ip( $start );
		$end   = self::valid_ip( $end );

		if ( '' === $ip || '' === $start || '' === $end ) {
			return false;
		}

		$ip    = inet_pton( $ip );
		$start = inet_pton( $start );
		$end   = inet_pton( $end );

		if ( strlen( $ip ) !== strlen( $start ) || strlen( $ip ) !== strlen( $end ) ) {
			return false;
		}

		// inet_pton() packs big-endian, so a byte-wise compare is an unsigned
		// numeric compare -- correct above 127.x on 32-bit builds too.
		return strcmp( $ip, $start ) >= 0 && strcmp( $ip, $end ) <= 0;
	}

	/**
	 * Whether an address falls inside any range in a list.
	 *
	 * @param string[] $ranges Range entries.
	 * @param string   $ip     Address to test.
	 * @return bool
	 */
	public static function in_any_range( $ranges, $ip ) {
		if ( '' === (string) $ip ) {
			return false;
		}

		foreach ( (array) $ranges as $range ) {
			$bounds = self::parse_range( $range );

			if ( $bounds && self::in_range( $ip, $bounds[0], $bounds[1] ) ) {
				return true;
			}
		}

		return false;
	}
}
