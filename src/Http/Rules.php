<?php
/**
 * The host rules for outgoing requests.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Pure decision logic, with no settings and no WordPress state, so it is easy to test.
 *
 * Patterns are host names. `api.example.com` matches that host only.
 * `*.example.com` matches any subdomain, at any depth, but not `example.com`
 * itself. Ports, schemes and paths never take part in a match.
 */
final class Rules {

	/**
	 * What a request can be given.
	 */
	public const ACTIONS = array( 'allow', 'log', 'block' );

	/**
	 * The host of a URL: lowercase, with no port, brackets or trailing dot.
	 *
	 * @param string $url Full URL.
	 * @return string Empty when the URL has no host.
	 */
	public static function host( string $url ): string {
		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

		return rtrim( trim( $host, '[]' ), '.' );
	}

	/**
	 * Whether a string is an http or https URL with a host.
	 *
	 * @param string $url Address as typed.
	 * @return bool
	 */
	public static function is_url( string $url ): bool {
		return false !== filter_var( $url, FILTER_VALIDATE_URL )
			&& in_array( strtolower( (string) strtok( $url, ':' ) ), array( 'http', 'https' ), true )
			&& '' !== self::host( $url );
	}

	/**
	 * Cleans submitted host patterns: one per line, space or comma.
	 *
	 * Pasting a whole URL works: the scheme, port and path are dropped. Only
	 * a leading `*.` is a wildcard, and a bare `*` is refused because it would
	 * switch the guard off.
	 *
	 * @param string $raw Submitted text.
	 * @return string[]
	 */
	public static function parse_patterns( string $raw ): array {
		$patterns = array();

		foreach ( preg_split( '/[\s,]+/', strtolower( $raw ) ) as $entry ) {
			$pattern = self::pattern( $entry );
			if ( '' !== $pattern ) {
				$patterns[] = $pattern;
			}
		}

		return array_values( array_unique( $patterns ) );
	}

	/**
	 * One cleaned pattern, or an empty string when it is not usable.
	 *
	 * @param string $entry Pattern as typed.
	 * @return string
	 */
	public static function pattern( string $entry ): string {
		$entry = trim( strtolower( $entry ) );
		$entry = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $entry );
		$entry = (string) preg_replace( '#[/?\#].*$#', '', $entry );
		$entry = (string) preg_replace( '#^[^@]*@#', '', $entry );
		$entry = (string) preg_replace( '#:\d+$#', '', $entry );
		$entry = rtrim( $entry, '.' );

		$wildcard = 0 === strpos( $entry, '*.' );
		$name     = $wildcard ? substr( $entry, 2 ) : $entry;

		if ( '' === $name || ! preg_match( '/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*$/', $name ) ) {
			return '';
		}

		if ( $wildcard && false === strpos( $name, '.' ) ) {
			// "*.com" would cover a whole top-level domain.
			return '';
		}

		return $wildcard ? '*.' . $name : $name;
	}

	/**
	 * Whether a host matches one pattern.
	 *
	 * @param string $host    Lowercase host.
	 * @param string $pattern Cleaned pattern.
	 * @return bool
	 */
	public static function matches( string $host, string $pattern ): bool {
		if ( '' === $host || '' === $pattern ) {
			return false;
		}

		if ( 0 === strpos( $pattern, '*.' ) ) {
			$suffix = substr( $pattern, 1 );

			return strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix;
		}

		return $host === $pattern;
	}

	/**
	 * Whether a host matches any of the patterns.
	 *
	 * @param string   $host     Lowercase host.
	 * @param string[] $patterns Cleaned patterns.
	 * @return bool
	 */
	public static function matches_any( string $host, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( self::matches( $host, (string) $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a host is this machine: localhost, 127.0.0.0/8 or ::1.
	 *
	 * @param string $host Lowercase host.
	 * @return bool
	 */
	public static function is_loopback( string $host ): bool {
		return 'localhost' === $host
			|| '::1' === $host
			|| '0:0:0:0:0:0:0:1' === $host
			|| ( strlen( $host ) > 10 && '.localhost' === substr( $host, -10 ) )
			|| (bool) preg_match( '/^127(\.\d{1,3}){3}$/', $host );
	}

	/**
	 * What happens to a request to a host.
	 *
	 * Order: the blocklist first (so it also wins over the allowlist), then
	 * hosts that are always allowed, then the allowlist, then the mode.
	 *
	 * @param string   $host    Lowercase host of the request.
	 * @param string   $mode    allow, log or block.
	 * @param string[] $allowed Hosts the site added to the allowlist.
	 * @param string[] $blocked Hosts that are always blocked.
	 * @param string[] $always  Hosts that are always allowed: the built-in list and this site's own address.
	 * @return array{action: string, reason: string}
	 */
	public static function decide( string $host, string $mode, array $allowed, array $blocked, array $always ): array {
		if ( '' === $host ) {
			return array(
				'action' => 'allow',
				'reason' => 'no_host',
			);
		}

		if ( self::matches_any( $host, $blocked ) ) {
			return array(
				'action' => 'block',
				'reason' => 'blocklisted',
			);
		}

		if ( self::is_loopback( $host ) || self::matches_any( $host, $always ) ) {
			return array(
				'action' => 'allow',
				'reason' => 'always_allowed',
			);
		}

		if ( self::matches_any( $host, $allowed ) ) {
			return array(
				'action' => 'allow',
				'reason' => 'allowlisted',
			);
		}

		switch ( $mode ) {
			case 'block':
				return array(
					'action' => 'block',
					'reason' => 'mode_block',
				);

			case 'log':
				return array(
					'action' => 'log',
					'reason' => 'mode_log',
				);

			default:
				return array(
					'action' => 'allow',
					'reason' => 'mode_allow',
				);
		}
	}

	/**
	 * The path of a URL as it may be stored: no query, no fragment, no secrets.
	 *
	 * Webhook and API URLs often carry a secret or an identifier in the path
	 * (Slack and Discord webhooks, Telegram bot tokens, Mailchimp's hash of
	 * an email address). Those segments are replaced, so the log shows the
	 * shape of the call and nothing that could be misused or identifies a person.
	 *
	 * @param string $url Full URL.
	 * @return string Starts with a slash.
	 */
	public static function clean_path( string $url ): string {
		$parts = wp_parse_url( $url );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? rawurldecode( (string) $parts['path'] ) : '/';
		$out   = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			if ( false !== strpos( $segment, '@' ) ) {
				$out[] = '{email}';
			} elseif ( preg_match( '/^[0-9a-f]{16,}$/i', $segment ) ) {
				$out[] = '{hash}';
			} elseif ( preg_match( '/\d+:[A-Za-z0-9_\-]{10,}/', $segment ) || ( strlen( $segment ) >= 20 && preg_match( '/\d/', $segment ) && preg_match( '/[a-z]/i', $segment ) ) ) {
				$out[] = '{token}';
			} else {
				$out[] = preg_replace( '/[^\x20-\x7E]/', '?', $segment );
			}
		}

		return substr( '/' . implode( '/', $out ), 0, 200 );
	}
}
