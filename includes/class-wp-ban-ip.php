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
			$candidate = self::proxied_ip( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), false );

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

				$candidate = self::proxied_ip( sanitize_text_field( wp_unslash( $_SERVER[ $name ] ) ) );

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
	 * How many appending proxies sit in front of WordPress.
	 *
	 * One by default, which is the ordinary shape: nginx with
	 * `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for`, Apache's
	 * mod_proxy, or a CDN talking straight to the origin. Behind a CDN *and* a
	 * load balancer it is two, and so on -- each hop appends the address it
	 * received the request from, so the count is what says which entry in the
	 * chain the nearest trusted hop actually observed.
	 *
	 * @since 2.0.0
	 *
	 * @return int
	 */
	public static function trusted_proxy_hops() {
		/**
		 * Filters how many appending proxies sit in front of WordPress.
		 *
		 * @since 2.0.0
		 *
		 * @param int $hops Number of proxies. One is the common case.
		 */
		return max( 1, (int) apply_filters( 'wp_ban_trusted_proxy_hops', 1 ) );
	}

	/**
	 * The address a trusted proxy observed, out of a comma separated chain.
	 *
	 * Counted from the *right*, and that is the whole of this method.
	 * X-Forwarded-For is written left to right and every hop **appends**:
	 * nginx's `$proxy_add_x_forwarded_for` is literally
	 * "$http_x_forwarded_for, $remote_addr", so whatever the client sent stays
	 * on the left and the address the proxy actually saw is added on the right.
	 * Reading the leftmost entry therefore reads the part of the header the
	 * visitor wrote -- which meant a banned visitor could send any address they
	 * liked, and, because the exclude list is an exact compare against the same
	 * value, could send one from *that* and short-circuit every list at once,
	 * user agent and referrer bans included.
	 *
	 * Nothing here is safe on a site with no proxy in front of it. That is what
	 * the opt-in is for: this is reached only once a site has named a header or
	 * set the constant.
	 *
	 * @since 2.0.0
	 *
	 * @param string $value       Header value.
	 * @param bool   $public_only Whether to require a public address.
	 * @return string
	 */
	public static function proxied_ip( $value, $public_only = true ) {
		$flags = $public_only ? FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE : 0;
		$parts = array_map( 'trim', explode( ',', (string) $value ) );
		$index = count( $parts ) - self::trusted_proxy_hops();

		// A chain shorter than the configured hop count means the header did not
		// come through as many proxies as the site says it has. Refusing beats
		// guessing: the caller falls back to REMOTE_ADDR.
		if ( $index < 0 || ! isset( $parts[ $index ] ) ) {
			return '';
		}

		$candidate = filter_var( $parts[ $index ], FILTER_VALIDATE_IP, $flags );

		return false === $candidate ? '' : self::canonical_ip( $candidate );
	}

	/**
	 * The first usable address in a comma separated chain.
	 *
	 * Kept because it is public, and no longer used to decide who a visitor is
	 * -- see proxied_ip(), which reads the other end of the chain.
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
				return self::canonical_ip( $candidate );
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

		return false === $ip ? '' : self::canonical_ip( $ip );
	}

	/**
	 * Reduce an address to the one spelling everything else compares against.
	 *
	 * Validation with filter_var() hands the string back exactly as it arrived,
	 * so the same address had several spellings and the lists compare strings.
	 * `2001:0db8:0000:0000:0000:0000:0000:0001`, `2001:DB8::1` and `2001:db8::1`
	 * are one address and were three ban entries; and an IPv4-mapped
	 * `::ffff:203.0.113.5` matched neither an IPv4 entry nor an IPv4 range,
	 * because in_range() refuses to compare a 16-byte address against 4-byte
	 * bounds.
	 *
	 * That is a ban evasion wherever the visitor picks the spelling -- which is
	 * any site that has named a proxy header -- and a silent miss where they do
	 * not: a dual-stack server reporting REMOTE_ADDR as `::ffff:1.2.3.4` never
	 * matched the IPv4 entry its owner typed.
	 *
	 * inet_pton() then inet_ntop() is the round trip that settles it, and the
	 * mapped prefix is unwrapped so the address is stored and matched as the
	 * IPv4 one it is.
	 *
	 * @since 2.0.0
	 *
	 * @param string $ip A validated address.
	 * @return string
	 */
	public static function canonical_ip( $ip ) {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The caller has already validated the address; the silence is for a host built without IPv6 support, where this returns false and the address is used as it came.

		if ( false === $packed ) {
			return $ip;
		}

		// An IPv4-mapped IPv6 address is an IPv4 address. 10 zero bytes, two
		// 0xff bytes, then the four that matter.
		if ( 16 === strlen( $packed ) && 0 === strncmp( $packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff", 12 ) ) {
			$packed = substr( $packed, 12 );
		}

		$canonical = @inet_ntop( $packed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- As above.

		return false === $canonical ? $ip : $canonical;
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
	 * The subject is capped first, and that is not tidiness. A pattern with
	 * three or more stars compiles to `^.*a.*b.*c$`, which backtracks
	 * quadratically when the subject nearly matches -- and the subjects here are
	 * the user agent and the referrer, which the visitor writes and which a
	 * server will happily accept several kilobytes of. Measured: 6.8 KB against
	 * a four-star pattern exhausts pcre.backtrack_limit after ~0.16s of CPU, and
	 * matched_list() walks the referrer and user-agent lists on *every* request
	 * rather than only on banned ones, so it is per pattern per request.
	 *
	 * PCRE's own limit means this was bounded rather than unbounded, and a
	 * pattern needs several stars before it bites -- `*bot*` is `^.*bot.*$` and
	 * has no blowup at all. It is still an amplifier a visitor controls for
	 * free. Two kilobytes is far longer than any real user agent and shorter
	 * than the length at which the arithmetic starts to matter.
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

		/**
		 * Filters the longest subject a ban pattern is matched against.
		 *
		 * @since 2.0.0
		 *
		 * @param int $length Characters. Zero removes the cap.
		 */
		$max = (int) apply_filters( 'wp_ban_max_match_length', 2048 );

		if ( $max > 0 && strlen( $subject ) > $max ) {
			$subject = substr( $subject, 0, $max );
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
