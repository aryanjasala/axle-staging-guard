<?php
/**
 * The password gate on the staging front end.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Gate;

use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Service;
use AryanX\Axle\StagingGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Asks visitors who are not logged in for a password, or a share link.
 *
 * It only ever runs on a copy that is not production, and only when an
 * administrator turned it on and set a password. The front end, feeds, comment
 * posting and the REST API are covered. wp-login.php, WP-Cron and robots.txt always stay
 * reachable, and so do the IP addresses and paths the administrator allows.
 * admin-ajax.php and xmlrpc.php are not pages and are left alone.
 */
final class Gate implements Service {

	public const LINK_PARAM = 'axle-gate-link';
	public const NONCE      = 'axle_staging_guard_gate';
	public const FIELD      = 'axle_gate_password';

	private const FAILS = 'axle_sg_gate_fail_';

	/**
	 * Paths that are never gated.
	 */
	public const DEFAULT_PATHS = array( '/robots.txt', '/favicon.ico', '/wp-login.php', '/wp-cron.php' );

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Environment detection.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * Share links.
	 *
	 * @var Links
	 */
	private Links $links;

	/**
	 * Sets up the gate.
	 *
	 * @param Settings    $settings    Saved settings.
	 * @param Environment $environment Environment detection.
	 * @param Links       $links       Share links.
	 */
	public function __construct( Settings $settings, Environment $environment, Links $links ) {
		$this->settings    = $settings;
		$this->environment = $environment;
		$this->links       = $links;
	}

	/**
	 * Hooks the gate into the front end and the REST API.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'guard_front_end' ), 0 );
		// wp-comments-post.php never reaches template_redirect, so a comment cannot be posted past the gate.
		add_action( 'pre_comment_on_post', array( $this, 'guard_front_end' ), 0 );
		add_filter( 'rest_authentication_errors', array( $this, 'guard_rest' ), PHP_INT_MAX );
	}

	/**
	 * Share links.
	 *
	 * @return Links
	 */
	public function links(): Links {
		return $this->links;
	}

	/**
	 * Whether the gate is switched on and has what it needs.
	 *
	 * The live site is never gated, whatever a filter says.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		if ( ! $this->environment->is_guarded() ) {
			return false;
		}

		$on = (bool) $this->settings->get( 'gate_enabled' ) && $this->has_password();

		/**
		 * Filters whether the password gate is on.
		 *
		 * It cannot turn the gate on for the live site.
		 *
		 * @param bool $on Whether an administrator turned it on and a password is set.
		 */
		$on = (bool) apply_filters( 'axle_staging_guard_gate_enabled', $on );

		return $on;
	}

	/**
	 * Whether a password is set.
	 *
	 * @return bool
	 */
	public function has_password(): bool {
		return '' !== (string) $this->settings->get( 'gate_hash' );
	}

	/**
	 * The shortest password the gate accepts.
	 *
	 * @return int
	 */
	public function min_password_length(): int {
		/**
		 * Filters the shortest password the gate accepts.
		 *
		 * @param int $length Default 8. Never below 4.
		 */
		return max( 4, (int) apply_filters( 'axle_staging_guard_gate_min_password_length', 8 ) );
	}

	/**
	 * Sets the shared password.
	 *
	 * @param string $password The new password. Stored hashed.
	 * @return true|\WP_Error
	 */
	public function set_password( string $password ) {
		if ( strlen( $password ) < $this->min_password_length() ) {
			return new \WP_Error(
				'axle_staging_guard_gate_password',
				sprintf(
					/* translators: %d: smallest number of characters. */
					_n( 'Use a password with at least %d character.', 'Use a password with at least %d characters.', $this->min_password_length(), 'axle-staging-guard' ),
					$this->min_password_length()
				),
				array( 'status' => 400 )
			);
		}

		$this->settings->update(
			array(
				'gate_hash'   => wp_hash_password( $password ),
				'gate_secret' => $this->links->secret(),
			),
			false
		);

		/**
		 * Fires after the gate password changes. Everyone who entered the old one is signed out of the gate.
		 */
		do_action( 'axle_staging_guard_gate_password_changed' );

		return true;
	}

	/**
	 * Turns the gate on.
	 *
	 * @return true|\WP_Error An error when no password is set.
	 */
	public function enable() {
		if ( ! $this->has_password() ) {
			return new \WP_Error( 'axle_staging_guard_gate_no_password', __( 'Set a password first. The gate cannot open without one.', 'axle-staging-guard' ), array( 'status' => 400 ) );
		}

		if ( ! (bool) $this->settings->get( 'gate_enabled' ) ) {
			$this->settings->update( array( 'gate_enabled' => true ) );

			/**
			 * Fires after the password gate is turned on.
			 */
			do_action( 'axle_staging_guard_gate_turned_on' );
		}

		return true;
	}

	/**
	 * Turns the gate off.
	 */
	public function disable(): void {
		if ( (bool) $this->settings->get( 'gate_enabled' ) ) {
			$this->settings->update( array( 'gate_enabled' => false ) );

			/**
			 * Fires after the password gate is turned off.
			 */
			do_action( 'axle_staging_guard_gate_turned_off' );
		}
	}

	/**
	 * Paths that stay open: the built-in ones and the administrator's.
	 *
	 * @return string[]
	 */
	public function open_paths(): array {
		$paths = array_merge( self::DEFAULT_PATHS, (array) $this->settings->get( 'gate_paths' ) );

		/**
		 * Filters the request paths the gate lets through, for example health checks and webhooks.
		 *
		 * A `*` matches any characters. A REST route is matched as /wp-json/<route>.
		 *
		 * @param string[] $paths Built-in paths plus the ones saved on the Gate tab.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'axle_staging_guard_gate_bypass_paths', $paths ) ) ) );
	}

	/**
	 * IP addresses and ranges that skip the gate.
	 *
	 * @return string[]
	 */
	public function open_ips(): array {
		/**
		 * Filters the IP addresses and CIDR ranges that skip the gate, such as the office.
		 *
		 * @param string[] $ips Saved on the Gate tab.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'axle_staging_guard_gate_allowed_ips', (array) $this->settings->get( 'gate_ips' ) ) ) ) );
	}

	/**
	 * The visitor's IP address.
	 *
	 * Only REMOTE_ADDR is trusted. Behind a proxy or CDN, return the real
	 * address from the filter.
	 *
	 * @return string Empty when it is unknown.
	 */
	public function client_ip(): string {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- The gate is for staging copies, never cached. The address is validated with filter_var() before use and only decides who is locked out.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the visitor's IP address, for sites behind a proxy or CDN.
		 *
		 * @param string $ip Default REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'axle_staging_guard_gate_client_ip', $ip );

		return false === filter_var( $ip, FILTER_VALIDATE_IP ) ? '' : $ip;
	}

	/**
	 * Why a request may pass, or an empty string when it may not.
	 *
	 * @param string $path Request path from Access::request_path().
	 * @return string user, ip, path, session, link, filter or an empty string.
	 */
	public function allowed_by( string $path ): string {
		$reason = '';
		$ip     = $this->client_ip();

		if ( is_user_logged_in() && $this->user_allowed( wp_get_current_user() ) ) {
			$reason = 'user';
		} elseif ( '' !== $ip && Access::ip_matches( $ip, $this->open_ips() ) ) {
			$reason = 'ip';
		} elseif ( Access::path_matches( $path, $this->open_paths() ) ) {
			$reason = 'path';
		} else {
			$reason = $this->cookie_reason();
		}

		/**
		 * Filters whether a request passes the gate.
		 *
		 * @param bool   $allowed Whether the built-in rules let it through.
		 * @param string $reason  Why: user, ip, path, session or link. Empty when it is not allowed.
		 * @param string $path    The request path, relative to the site address.
		 */
		$allowed = (bool) apply_filters( 'axle_staging_guard_gate_allow_request', '' !== $reason, $reason, $path );

		if ( $allowed ) {
			return '' === $reason ? 'filter' : $reason;
		}

		return '';
	}

	/**
	 * Whether a logged-in user skips the gate.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	private function user_allowed( \WP_User $user ): bool {
		/**
		 * Filters whether a logged-in user skips the gate.
		 *
		 * @param bool     $allowed Default true for every logged-in user.
		 * @param \WP_User $user    The user.
		 */
		return (bool) apply_filters( 'axle_staging_guard_gate_user_allowed', true, $user );
	}

	/**
	 * Checks the gate cookie.
	 *
	 * @return string session, link or an empty string.
	 */
	private function cookie_reason(): string {
		$name = $this->cookie_name();
		if ( ! isset( $_COOKIE[ $name ] ) ) {
			return '';
		}

		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Staging copies only, and the gate screen is never cached. The token is signed and checked before it is trusted.
		$token  = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
		$parsed = Access::parse_token( $token );
		if ( null === $parsed ) {
			return '';
		}

		if ( 'session' === $parsed['kind'] ) {
			$expected = Access::session_token( $parsed['expires'], (string) $this->settings->get( 'gate_hash' ), (string) $this->settings->get( 'gate_secret' ) );

			return $parsed['expires'] > time() && '' !== (string) $this->settings->get( 'gate_secret' ) && hash_equals( $expected, $token ) ? 'session' : '';
		}

		$link = $this->links->from_token( $token );
		if ( null === $link ) {
			return '';
		}

		$this->links->touch( $link['id'], false );

		return 'link';
	}

	/**
	 * The gate cookie's name.
	 *
	 * @return string
	 */
	public function cookie_name(): string {
		$name = 'axle_staging_guard_gate_' . substr( md5( home_url() ), 0, 8 );

		/**
		 * Filters the name of the cookie that remembers a visitor at the gate.
		 *
		 * @param string $name Default axle_staging_guard_gate_ and eight characters from the site address.
		 */
		$name = (string) apply_filters( 'axle_staging_guard_gate_cookie_name', $name );

		return (string) preg_replace( '/[^A-Za-z0-9_]/', '', $name );
	}

	/**
	 * Hours a correct password keeps a visitor in.
	 *
	 * @return int
	 */
	public function session_hours(): int {
		/**
		 * Filters how many hours a correct password keeps a visitor in.
		 *
		 * @param int $hours Default 12. Between 1 and 720.
		 */
		return max( 1, min( 720, (int) apply_filters( 'axle_staging_guard_gate_session_hours', 12 ) ) );
	}

	/**
	 * Wrong passwords allowed before a lockout.
	 *
	 * @return int
	 */
	public function max_attempts(): int {
		/**
		 * Filters how many wrong passwords one IP address can try before it is locked out.
		 *
		 * @param int $attempts Default 5.
		 */
		return max( 1, (int) apply_filters( 'axle_staging_guard_gate_max_attempts', 5 ) );
	}

	/**
	 * Minutes a lockout lasts.
	 *
	 * @return int
	 */
	public function lockout_minutes(): int {
		/**
		 * Filters how many minutes an IP address stays locked out after too many wrong passwords.
		 *
		 * @param int $minutes Default 15.
		 */
		return max( 1, (int) apply_filters( 'axle_staging_guard_gate_lockout_minutes', 15 ) );
	}

	/**
	 * Seconds left on a lockout.
	 *
	 * @param string $ip The visitor's IP address.
	 * @return int Zero when the address is not locked out.
	 */
	public function locked_for( string $ip ): int {
		$state = get_transient( $this->fail_key( $ip ) );
		if ( ! is_array( $state ) || (int) ( $state['count'] ?? 0 ) < $this->max_attempts() ) {
			return 0;
		}

		return max( 0, (int) ( $state['until'] ?? 0 ) - time() );
	}

	/**
	 * Counts a wrong password.
	 *
	 * @param string $ip The visitor's IP address.
	 * @return int Wrong passwords counted so far.
	 */
	public function record_failure( string $ip ): int {
		$key   = $this->fail_key( $ip );
		$state = get_transient( $key );
		$count = is_array( $state ) ? (int) ( $state['count'] ?? 0 ) + 1 : 1;
		$ttl   = $this->lockout_minutes() * MINUTE_IN_SECONDS;

		set_transient(
			$key,
			array(
				'count' => $count,
				'until' => time() + $ttl,
			),
			$ttl
		);

		if ( $count === $this->max_attempts() ) {
			/**
			 * Fires when an IP address is locked out of the gate for too many wrong passwords.
			 *
			 * @param string $ip      The visitor's IP address.
			 * @param int    $minutes How long the lockout lasts.
			 */
			do_action( 'axle_staging_guard_gate_locked_out', $ip, $this->lockout_minutes() );
		}

		return $count;
	}

	/**
	 * Transient name for an address. The address itself is never stored.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	private function fail_key( string $ip ): string {
		return self::FAILS . substr( hash_hmac( 'sha256', $ip, (string) $this->settings->get( 'gate_secret' ) . wp_salt() ), 0, 32 );
	}

	/**
	 * Gates the front end. Runs first on `template_redirect`.
	 */
	public function guard_front_end(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only used to build a path that is cleaned and compared.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = Access::request_path( (string) $uri, (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$by   = $this->allowed_by( $path );

		if ( '' !== $by ) {
			return;
		}

		$error = '';
		$ip    = $this->client_ip();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- The visitor has no account; the link is signed and the password form has its own nonce, checked below.
		if ( isset( $_POST[ self::FIELD ] ) && 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			$error = $this->attempt( $ip );
		} elseif ( isset( $_GET[ self::LINK_PARAM ] ) ) {
			$link = $this->links->from_token( sanitize_text_field( wp_unslash( $_GET[ self::LINK_PARAM ] ) ) );
			if ( null !== $link ) {
				$this->links->touch( $link['id'], true );
				$this->set_cookie( $this->links->token( $link ), (int) $link['expires'] );

				/**
				 * Fires when someone gets in with a share link.
				 *
				 * @param array $link The link that was used.
				 */
				do_action( 'axle_staging_guard_gate_link_used', $link );

				$this->redirect_clean();
			}

			$error = 'link';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

		$locked = '' !== $ip ? $this->locked_for( $ip ) : 0;
		if ( $locked > 0 ) {
			$error = 'locked';
		}

		$context = array(
			'path'   => $path,
			'method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET',
			'error'  => $error,
		);

		/**
		 * Fires when the gate stops a front-end request.
		 *
		 * @param array $context path (relative to the site address, no query string), method and error (empty, password, expired, link or locked).
		 */
		do_action( 'axle_staging_guard_gate_denied', $context );

		$this->deny( $error, $locked );
	}

	/**
	 * Checks a submitted password.
	 *
	 * @param string $ip The visitor's IP address.
	 * @return string An error code, or an empty string after a redirect.
	 */
	private function attempt( string $ip ): string {
		if ( '' !== $ip && $this->locked_for( $ip ) > 0 ) {
			return 'locked';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked on the next line; a password must reach wp_check_password() exactly as typed.
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return 'expired';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password must reach wp_check_password() exactly as typed.
		$password = isset( $_POST[ self::FIELD ] ) ? (string) wp_unslash( $_POST[ self::FIELD ] ) : '';

		if ( '' !== $password && wp_check_password( $password, (string) $this->settings->get( 'gate_hash' ) ) ) {
			if ( '' !== $ip ) {
				delete_transient( $this->fail_key( $ip ) );
			}

			$expires = time() + $this->session_hours() * HOUR_IN_SECONDS;
			$this->set_cookie( Access::session_token( $expires, (string) $this->settings->get( 'gate_hash' ), $this->links->secret() ), $expires );

			/**
			 * Fires when a visitor gets in with the gate password.
			 *
			 * @param string $ip The visitor's IP address.
			 */
			do_action( 'axle_staging_guard_gate_unlocked', $ip );

			$this->redirect_clean();
		}

		if ( '' !== $ip ) {
			$this->record_failure( $ip );
		}

		return 'password';
	}

	/**
	 * Gates the REST API: a request needs a logged-in user (cookie with nonce, or an application password).
	 *
	 * @param mixed $result Earlier result of the filter.
	 * @return mixed
	 */
	public function guard_rest( $result ) {
		if ( ! $this->is_active() || is_wp_error( $result ) ) {
			return $result;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- Only used to build a path that is cleaned and compared.
		$route = isset( $_GET['rest_route'] ) ? '/' . rest_get_url_prefix() . '/' . ltrim( (string) wp_unslash( $_GET['rest_route'] ), '/' ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only used to build a path that is cleaned and compared.
		$uri  = '' !== $route ? $route : ( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' );
		$path = Access::request_path( $uri, '' !== $route ? '' : (string) wp_parse_url( home_url(), PHP_URL_PATH ) );

		if ( '' !== $this->allowed_by( $path ) ) {
			return $result;
		}

		do_action(
			'axle_staging_guard_gate_denied',
			array(
				'path'   => $path,
				'method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET',
				'error'  => 'rest',
			)
		);

		return new \WP_Error(
			'axle_staging_guard_gate',
			__( 'This is a staging site. Log in, or use a share link, to see it.', 'axle-staging-guard' ),
			array( 'status' => $this->status() )
		);
	}

	/**
	 * The status code of the gate screen.
	 *
	 * @return int
	 */
	public function status(): int {
		/**
		 * Filters the HTTP status code of the gate screen.
		 *
		 * @param int $status Default 401. Between 400 and 599.
		 */
		return max( 400, min( 599, (int) apply_filters( 'axle_staging_guard_gate_status', 401 ) ) );
	}

	/**
	 * Sends the gate screen and stops.
	 *
	 * @param string $error   Error code for the message, or an empty string.
	 * @param int    $seconds Seconds left on a lockout.
	 */
	private function deny( string $error, int $seconds ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The constant caching plugins read.
		}

		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		if ( 'locked' === $error ) {
			header( 'Retry-After: ' . max( 1, $seconds ) );
		}
		status_header( 'locked' === $error ? 429 : $this->status() );

		Screen::render( $error, $seconds, wp_create_nonce( self::NONCE ) );
		exit;
	}

	/**
	 * Sets the gate cookie.
	 *
	 * @param string $value   Token.
	 * @param int    $expires Unix time it expires.
	 */
	private function set_cookie( string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}

		setcookie( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- Not available on VIP: the gate is for staging copies.
			$this->cookie_name(),
			$value,
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Sends the visitor to the page they asked for, without the link token, and stops.
	 */
	private function redirect_clean(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only used to build a path that is cleaned and compared.
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path  = Access::request_path( $uri, (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );

		// Keep a trailing slash, which request_path() drops, so pretty permalinks do not take a second redirect.
		if ( '/' !== $path && '/' === substr( (string) strtok( $uri, '?#' ), -1 ) ) {
			$path .= '/';
		}
		parse_str( $query, $args );
		unset( $args[ self::LINK_PARAM ] );

		$target = add_query_arg( array_map( 'strval', array_filter( $args, 'is_scalar' ) ), home_url( $path ) );

		nocache_headers();
		wp_safe_redirect( $target, 303 );
		exit;
	}
}
