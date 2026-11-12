<?php
/**
 * Pure helpers for the password gate: tokens, IP ranges and path patterns.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Gate;

defined( 'ABSPATH' ) || exit;

/**
 * Small, side-effect-free functions the gate builds on, so they can be tested alone.
 */
final class Access {

	/**
	 * Signs a message with the gate's secret.
	 *
	 * @param string $message Text to sign.
	 * @param string $secret  The gate's signing secret.
	 * @return string 40 hex characters.
	 */
	public static function sign( string $message, string $secret ): string {
		return substr( hash_hmac( 'sha256', $message, $secret ), 0, 40 );
	}

	/**
	 * Whether a signature is right. Always false without a secret.
	 *
	 * @param string $message   Text that was signed.
	 * @param string $signature The signature to check.
	 * @param string $secret    The gate's signing secret.
	 * @return bool
	 */
	public static function verify( string $message, string $signature, string $secret ): bool {
		return '' !== $secret && hash_equals( self::sign( $message, $secret ), $signature );
	}

	/**
	 * The token a share link carries, and the cookie it sets.
	 *
	 * @param string $id      Link ID.
	 * @param int    $expires Unix time the link stops working.
	 * @param string $secret  The gate's signing secret.
	 * @return string `<id>.<signature>`.
	 */
	public static function link_token( string $id, int $expires, string $secret ): string {
		return $id . '.' . self::sign( 'link|' . $id . '|' . $expires, $secret );
	}

	/**
	 * The cookie value that remembers a correct password.
	 *
	 * The password hash is part of the signed text, so changing the password signs everyone out.
	 *
	 * @param int    $expires Unix time the cookie stops working.
	 * @param string $hash    The saved password hash.
	 * @param string $secret  The gate's signing secret.
	 * @return string `pw.<expires>.<signature>`.
	 */
	public static function session_token( int $expires, string $hash, string $secret ): string {
		return 'pw.' . $expires . '.' . self::sign( 'pw|' . $expires . '|' . hash( 'sha256', $hash ), $secret );
	}

	/**
	 * Splits a cookie or link value into its kind and parts.
	 *
	 * @param string $token Raw value.
	 * @return array{kind: string, id: string, expires: int, signature: string}|null Null when it is not shaped like a token.
	 */
	public static function parse_token( string $token ): ?array {
		if ( strlen( $token ) > 120 || ! preg_match( '/^[A-Za-z0-9._-]+$/', $token ) ) {
			return null;
		}

		$parts = explode( '.', $token );

		if ( 3 === count( $parts ) && 'pw' === $parts[0] && ctype_digit( $parts[1] ) ) {
			return array(
				'kind'      => 'session',
				'id'        => '',
				'expires'   => (int) $parts[1],
				'signature' => $parts[2],
			);
		}

		if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
			return array(
				'kind'      => 'link',
				'id'        => $parts[0],
				'expires'   => 0,
				'signature' => $parts[1],
			);
		}

		return null;
	}

	/**
	 * Whether an IP address is in a list of addresses and CIDR ranges.
	 *
	 * @param string   $ip      IPv4 or IPv6 address.
	 * @param string[] $entries Addresses such as 203.0.113.7, or ranges such as 10.0.0.0/8 or 2001:db8::/32.
	 * @return bool
	 */
	public static function ip_matches( string $ip, array $entries ): bool {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An invalid address returns false, which is handled below.
		if ( false === $packed ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' === $entry ) {
				continue;
			}

			$bits = null;
			if ( false !== strpos( $entry, '/' ) ) {
				list( $entry, $length ) = explode( '/', $entry, 2 );
				if ( ! ctype_digit( $length ) ) {
					continue;
				}
				$bits = (int) $length;
			}

			$base = @inet_pton( $entry ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An invalid entry is skipped.
			if ( false === $base || strlen( $base ) !== strlen( $packed ) ) {
				continue;
			}

			$max  = strlen( $base ) * 8;
			$bits = null === $bits ? $max : min( $bits, $max );

			if ( self::same_prefix( $packed, $base, $bits ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether two packed addresses share their first bits.
	 *
	 * @param string $a    Packed address.
	 * @param string $b    Packed address of the same length.
	 * @param int    $bits How many leading bits must match.
	 * @return bool
	 */
	private static function same_prefix( string $a, string $b, int $bits ): bool {
		$bytes = intdiv( $bits, 8 );
		if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}

		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;

		return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
	}

	/**
	 * Cleans a submitted list of IP addresses and CIDR ranges, one per line or comma.
	 *
	 * @param string $raw Submitted text.
	 * @return string[] Valid entries only.
	 */
	public static function parse_ips( string $raw ): array {
		$entries = array();

		foreach ( (array) preg_split( '/[\s,]+/', trim( $raw ) ) as $entry ) {
			$entry = trim( (string) $entry );
			$bits  = null;
			$ip    = $entry;

			if ( false !== strpos( $entry, '/' ) ) {
				list( $ip, $length ) = explode( '/', $entry, 2 );
				if ( ! ctype_digit( $length ) ) {
					continue;
				}
				$bits = (int) $length;
			}

			if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				continue;
			}

			if ( null !== $bits && $bits > ( false !== strpos( $ip, ':' ) ? 128 : 32 ) ) {
				continue;
			}

			$entries[] = $entry;
		}

		return array_values( array_unique( $entries ) );
	}

	/**
	 * Cleans a submitted list of request paths, one per line or comma.
	 *
	 * @param string $raw Submitted text.
	 * @return string[] Paths that start with a slash. A `*` at any place matches any characters.
	 */
	public static function parse_paths( string $raw ): array {
		$paths = array();

		foreach ( (array) preg_split( '/[\s,]+/', trim( $raw ) ) as $path ) {
			$path = trim( (string) $path );
			if ( '' === $path ) {
				continue;
			}

			// Keep only characters a path can hold, so a pasted URL or query string cannot widen a rule.
			$path = (string) preg_replace( '/[^A-Za-z0-9\/._~%!$&\'()*+,;=:@-]/', '', (string) strtok( $path, '?#' ) );
			if ( '' === $path ) {
				continue;
			}

			$paths[] = '/' === $path[0] ? $path : '/' . $path;
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * The path of a request, relative to the site's own path.
	 *
	 * @param string $request_uri Value of REQUEST_URI.
	 * @param string $home_path   Path of the Site Address, such as /blog or an empty string.
	 * @return string Always starts with a slash.
	 */
	public static function request_path( string $request_uri, string $home_path = '' ): string {
		$path = (string) strtok( $request_uri, '?#' );
		$path = rawurldecode( $path );
		$home = rtrim( $home_path, '/' );

		if ( '' !== $home && ( $path === $home || 0 === strpos( $path, $home . '/' ) ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		// Collapse repeated slashes and dot segments, so /health/../x cannot pose as /health.
		$parts = array();
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $parts );
				continue;
			}
			$parts[] = $part;
		}

		return '/' . implode( '/', $parts );
	}

	/**
	 * Whether a path is covered by a list of patterns.
	 *
	 * A pattern without `*` matches that path, with or without a trailing slash.
	 * A `*` matches any characters, so `/webhooks/*` covers everything below it.
	 *
	 * @param string   $path     Normalised path from request_path().
	 * @param string[] $patterns Patterns from parse_paths().
	 * @return bool
	 */
	public static function path_matches( string $path, array $patterns ): bool {
		$path = '/' === $path ? '/' : rtrim( $path, '/' );

		foreach ( $patterns as $pattern ) {
			$pattern = (string) $pattern;
			$pattern = '/' === $pattern ? '/' : rtrim( $pattern, '/' );
			if ( '' === $pattern ) {
				continue;
			}

			$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#';
			if ( 1 === preg_match( $regex, $path ) ) {
				return true;
			}
		}

		return false;
	}
}
