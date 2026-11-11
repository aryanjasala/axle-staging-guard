<?php
/**
 * Stops or records outgoing HTTP requests.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Http;

use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Service;
use AryanX\Axle\StagingGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what happens to a request made through the WordPress HTTP API.
 *
 * That covers wp_remote_get() and friends, which is what WordPress, most
 * plugins and the payment, CRM and webhook integrations use. Code that opens
 * its own cURL handle or sockets does not pass through it.
 *
 * It runs on `pre_http_request`, before anything leaves the server. A blocked
 * request returns a WP_Error, never a fake success, so the calling plugin
 * sees a failed request and handles it like any network error.
 */
final class Guard implements Service {

	public const ERROR_CODE = 'axle_staging_guard_http_blocked';

	/**
	 * Hosts WordPress needs on any site, so an update or plugin search never breaks.
	 *
	 * WordPress.org and its subdomains cover api.wordpress.org (core, plugin and
	 * theme updates, translations, the plugin directory), downloads.wordpress.org
	 * (the update packages), and ps.w.org, s.w.org and the like (plugin assets).
	 * They are read-only services that never act on your customers' behalf.
	 */
	public const DEFAULT_HOSTS = array(
		'wordpress.org',
		'*.wordpress.org',
		'w.org',
		'*.w.org',
	);

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
	 * The request list.
	 *
	 * @var Log
	 */
	private Log $log;

	/**
	 * Sets up the guard.
	 *
	 * @param Settings    $settings    Saved settings.
	 * @param Environment $environment Environment detection.
	 * @param Log         $log         The request list.
	 */
	public function __construct( Settings $settings, Environment $environment, Log $log ) {
		$this->settings    = $settings;
		$this->environment = $environment;
		$this->log         = $log;
	}

	/**
	 * Hooks into the HTTP API. Runs first, so no other plugin's short circuit sees a request we stop.
	 */
	public function register(): void {
		add_filter( 'pre_http_request', array( $this, 'pre_request' ), PHP_INT_MIN, 3 );
	}

	/**
	 * The request list.
	 *
	 * @return Log
	 */
	public function log(): Log {
		return $this->log;
	}

	/**
	 * Outgoing request handling in effect, after wp-config.php constants and filters.
	 *
	 * An unknown mode falls back to blocking, so a typo in wp-config.php never
	 * lets calls through.
	 *
	 * @return array{mode: string, allowlist: string[], blocklist: string[], defaults: string[], locked: bool}
	 */
	public function policy(): array {
		$mode      = defined( 'AXLE_STAGING_GUARD_HTTP_MODE' ) ? strtolower( (string) AXLE_STAGING_GUARD_HTTP_MODE ) : (string) $this->settings->get( 'http_mode' );
		$allowlist = defined( 'AXLE_STAGING_GUARD_HTTP_ALLOWED_HOSTS' ) ? Rules::parse_patterns( (string) AXLE_STAGING_GUARD_HTTP_ALLOWED_HOSTS ) : (array) $this->settings->get( 'http_allowlist' );
		$blocklist = defined( 'AXLE_STAGING_GUARD_HTTP_BLOCKED_HOSTS' ) ? Rules::parse_patterns( (string) AXLE_STAGING_GUARD_HTTP_BLOCKED_HOSTS ) : (array) $this->settings->get( 'http_blocklist' );

		/**
		 * Filters how outgoing requests are handled on a staging copy.
		 *
		 * @param string $mode        allow, log or block.
		 * @param string $environment Environment type: staging, development or local.
		 */
		$mode = (string) apply_filters( 'axle_staging_guard_http_mode', $mode, $this->environment->type() );

		/**
		 * Filters the hosts that always get requests through, on top of the built-in ones.
		 *
		 * @param string[] $hosts Host names, or `*.example.com` for every subdomain.
		 */
		$allowlist = $this->clean( (array) apply_filters( 'axle_staging_guard_http_allowed_hosts', $allowlist ) );

		/**
		 * Filters the hosts that are always blocked, in every mode and even when they are also allowed.
		 *
		 * @param string[] $hosts Host names, or `*.example.com` for every subdomain.
		 */
		$blocklist = $this->clean( (array) apply_filters( 'axle_staging_guard_http_blocked_hosts', $blocklist ) );

		return array(
			'mode'      => in_array( $mode, Settings::HTTP_MODES, true ) ? $mode : 'block',
			'allowlist' => $allowlist,
			'blocklist' => $blocklist,
			'defaults'  => $this->default_hosts(),
			'locked'    => in_array( true, self::fixed(), true ),
		);
	}

	/**
	 * Which settings a wp-config.php constant fixes.
	 *
	 * @return array{mode: bool, allowlist: bool, blocklist: bool}
	 */
	public static function fixed(): array {
		return array(
			'mode'      => defined( 'AXLE_STAGING_GUARD_HTTP_MODE' ),
			'allowlist' => defined( 'AXLE_STAGING_GUARD_HTTP_ALLOWED_HOSTS' ),
			'blocklist' => defined( 'AXLE_STAGING_GUARD_HTTP_BLOCKED_HOSTS' ),
		);
	}

	/**
	 * Hosts that are always allowed: WordPress.org, and this site's own address.
	 *
	 * Loopback (localhost, 127.0.0.0/8 and ::1) is always allowed too, and is not listed here.
	 *
	 * @return string[]
	 */
	public function default_hosts(): array {
		$hosts = self::DEFAULT_HOSTS;

		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = Rules::host( (string) $url );
			if ( '' !== $host ) {
				$hosts = array_merge( $hosts, $this->environment->domain_variants( $host ) );
			}
		}

		/**
		 * Filters the hosts that are always allowed, whatever the mode.
		 *
		 * Removing the built-in WordPress.org hosts stops core updates on this copy.
		 *
		 * @param string[] $hosts WordPress.org and this site's own host, with and without www.
		 */
		return $this->clean( (array) apply_filters( 'axle_staging_guard_http_default_hosts', $hosts ) );
	}

	/**
	 * What happens to the outgoing requests, in a few words.
	 *
	 * @return string
	 */
	public function describe(): string {
		switch ( $this->policy()['mode'] ) {
			case 'block':
				return __( 'Blocked except allowed hosts', 'axle-staging-guard' );

			case 'log':
				return __( 'Logged, not blocked', 'axle-staging-guard' );

			default:
				return __( 'Allowed', 'axle-staging-guard' );
		}
	}

	/**
	 * What would happen to a request to a URL.
	 *
	 * Nothing is sent and nothing is logged.
	 *
	 * @param string $url    Full URL.
	 * @param string $method HTTP method.
	 * @param array  $args   Request arguments, for the decision filter.
	 * @return array{action: string, reason: string, host: string, path: string, method: string, guarded: bool}
	 */
	public function evaluate( string $url, string $method = 'GET', array $args = array() ): array {
		$host     = Rules::host( $url );
		$decision = array(
			'action' => 'allow',
			'reason' => 'production',
		);

		if ( $this->environment->is_guarded() ) {
			$policy   = $this->policy();
			$decision = Rules::decide( $host, $policy['mode'], $policy['allowlist'], $policy['blocklist'], $policy['defaults'] );
		}

		$decision += array(
			'host'    => $host,
			'path'    => '' === $host ? '' : Rules::clean_path( $url ),
			'method'  => strtoupper( $method ),
			'guarded' => $this->environment->is_guarded(),
		);

		if ( $decision['guarded'] ) {
			/**
			 * Filters what happens to one outgoing request.
			 *
			 * For example, let one plugin's calls through whatever the host rules say.
			 * Return the array with `action` set to allow, log or block. Other
			 * values are ignored. It never runs on the live site.
			 *
			 * @param array  $decision {
			 *     @type string $action  allow, log or block.
			 *     @type string $reason  Why: blocklisted, always_allowed, allowlisted, mode_allow, mode_log, mode_block or no_host.
			 *     @type string $host    Host of the request.
			 *     @type string $path    Path, with secrets masked and no query string.
			 *     @type string $method  HTTP method.
			 * }
			 * @param string $url      The full URL, including any query string. Do not store it.
			 * @param array  $args     Request arguments passed to wp_remote_request().
			 */
			$filtered = (array) apply_filters( 'axle_staging_guard_http_decision', $decision, $url, $args );

			if ( in_array( $filtered['action'] ?? '', Rules::ACTIONS, true ) ) {
				$decision = array_merge( $decision, $filtered );
			}
		}

		return $decision;
	}

	/**
	 * Explains a decision in a sentence.
	 *
	 * @param array $decision A result of evaluate().
	 * @return string
	 */
	public function explain( array $decision ): string {
		$host = (string) ( $decision['host'] ?? '' );

		switch ( $decision['reason'] ?? '' ) {
			case 'production':
				return __( 'Nothing happens: this is the live site, so outgoing requests are never stopped or recorded.', 'axle-staging-guard' );

			case 'no_host':
				return __( 'Nothing happens: this address has no host name, so it is not checked.', 'axle-staging-guard' );

			case 'blocklisted':
				/* translators: %s: host name. */
				return sprintf( __( 'Blocked: %s is on the blocklist.', 'axle-staging-guard' ), $host );

			case 'always_allowed':
				/* translators: %s: host name. */
				return sprintf( __( 'Goes through: %s is always allowed (WordPress.org, this site and this machine).', 'axle-staging-guard' ), $host );

			case 'allowlisted':
				/* translators: %s: host name. */
				return sprintf( __( 'Goes through: %s is on the allowlist.', 'axle-staging-guard' ), $host );

			case 'mode_log':
				return __( 'Goes through and is recorded: outgoing requests are set to log only.', 'axle-staging-guard' );

			case 'mode_block':
				/* translators: %s: host name. */
				return sprintf( __( 'Blocked: %s is not on the allowlist.', 'axle-staging-guard' ), $host );

			case 'mode_allow':
				return __( 'Goes through: outgoing requests are set to allow all.', 'axle-staging-guard' );

			default:
				return 'block' === ( $decision['action'] ?? '' )
					? __( 'Blocked by a custom rule.', 'axle-staging-guard' )
					: __( 'Goes through.', 'axle-staging-guard' );
		}
	}

	/**
	 * Stops, records or ignores a request on `pre_http_request`.
	 *
	 * @param false|array|\WP_Error $preempt Short-circuit value from an earlier filter.
	 * @param mixed                 $args    Request arguments.
	 * @param mixed                 $url     Request URL.
	 * @return false|array|\WP_Error
	 */
	public function pre_request( $preempt, $args, $url ) {
		if ( false !== $preempt || ! is_string( $url ) ) {
			return $preempt;
		}

		$args = is_array( $args ) ? $args : array();

		// Most requests on a site in "allow" mode need no more work than this.
		$policy = $this->policy();
		if ( 'allow' === $policy['mode'] && ! $policy['blocklist'] && ! has_filter( 'axle_staging_guard_http_decision' ) ) {
			return $preempt;
		}

		$decision = $this->evaluate( $url, (string) ( $args['method'] ?? 'GET' ), $args );
		if ( 'allow' === $decision['action'] ) {
			return $preempt;
		}

		$entry       = array(
			'method' => $decision['method'],
			'host'   => $decision['host'],
			'path'   => $decision['path'],
			'action' => 'block' === $decision['action'] ? 'blocked' : 'logged',
			'reason' => $decision['reason'],
			'caller' => $this->caller(),
		);
		$entry['id'] = $this->log->add( $entry );

		if ( 'logged' === $entry['action'] ) {
			/**
			 * Fires when an outgoing request goes through in log-only mode and is recorded.
			 *
			 * @param array $entry id (list entry), method, host, path (no query string), action, reason and caller (such as plugin:woocommerce).
			 */
			do_action( 'axle_staging_guard_http_logged', $entry );

			return $preempt;
		}

		/**
		 * Fires when Axle Staging Guard blocks an outgoing request.
		 *
		 * @param array $entry id (list entry), method, host, path (no query string), action, reason and caller (such as plugin:woocommerce).
		 */
		do_action( 'axle_staging_guard_http_blocked', $entry );

		$message = sprintf(
			/* translators: 1: host name, 2: environment name, e.g. "staging". */
			__( 'Axle Staging Guard stopped this request to %1$s because this is a %2$s copy of the site. To let it through, add the host to the allowed hosts under Tools > Staging Guard.', 'axle-staging-guard' ),
			$entry['host'],
			strtolower( $this->environment->label() )
		);

		/**
		 * Filters the message of the error a blocked request returns.
		 *
		 * The error's code is axle_staging_guard_http_blocked.
		 *
		 * @param string $message The message.
		 * @param array  $entry   id, method, host, path, action, reason and caller.
		 */
		$message = (string) apply_filters( 'axle_staging_guard_http_error_message', $message, $entry );

		return new \WP_Error(
			self::ERROR_CODE,
			$message,
			array(
				'host'   => $entry['host'],
				'method' => $entry['method'],
				'path'   => $entry['path'],
				'reason' => $entry['reason'],
			)
		);
	}

	/**
	 * Which plugin or theme made the call, from the backtrace, as `plugin:slug`, `mu-plugin:slug` or `theme:slug`.
	 *
	 * Only runs for requests that are stopped or recorded. The frame closest to
	 * the call that lives in a plugin or theme folder wins, which is the code
	 * that asked for the request, not the library it used. Empty when nothing
	 * matches, such as core's own cron.
	 *
	 * @return string
	 */
	public function caller(): string {
		$roots = array(
			'plugin'    => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
			'mu-plugin' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
			'theme'     => function_exists( 'get_theme_root' ) ? get_theme_root() : '',
		);

		// A short list of frames is enough: the call is made close to the request.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Needed to name the plugin that made the call, and only runs for requests that are stopped or recorded.
		return self::caller_from_frames( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ), $roots, dirname( __DIR__, 2 ) );
	}

	/**
	 * Names the plugin or theme in a list of backtrace frames.
	 *
	 * @param array[]  $frames Backtrace frames, the nearest first. Only `file` is read.
	 * @param string[] $roots  Folder of each kind: plugin, mu-plugin and theme.
	 * @param string   $own    This plugin's folder, whose frames are skipped.
	 * @return string `plugin:slug`, `mu-plugin:slug`, `theme:slug` or an empty string.
	 */
	public static function caller_from_frames( array $frames, array $roots, string $own ): string {
		$own = trailingslashit( wp_normalize_path( $own ) );

		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}

			$file = wp_normalize_path( $frame['file'] );
			if ( 0 === strpos( $file, $own ) ) {
				continue;
			}

			foreach ( $roots as $type => $root ) {
				$root = '' === $root ? '' : trailingslashit( wp_normalize_path( $root ) );
				if ( '' === $root || 0 !== strpos( $file, $root ) ) {
					continue;
				}

				$slug = explode( '/', substr( $file, strlen( $root ) ) )[0];
				$slug = (string) preg_replace( '/\.php$/', '', $slug );
				if ( '' !== $slug ) {
					return $type . ':' . $slug;
				}
			}
		}

		return '';
	}

	/**
	 * Cleans and de-duplicates a list of host patterns.
	 *
	 * @param array $hosts Patterns as given by settings, constants or filters.
	 * @return string[]
	 */
	private function clean( array $hosts ): array {
		$clean = array();

		foreach ( $hosts as $host ) {
			$pattern = Rules::pattern( (string) $host );
			if ( '' !== $pattern ) {
				$clean[] = $pattern;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
