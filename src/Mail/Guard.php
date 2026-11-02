<?php
/**
 * Stops or redirects outgoing email.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Mail;

use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Service;
use AryanX\Axle\StagingGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Decides who an email may reach on a non-production copy.
 *
 * Recipients on the allowlist always get the email. Everyone else is either
 * dropped (block mode) or replaced by one test address (redirect mode).
 * Both happen on the `wp_mail` filter, which runs before `pre_wp_mail`, so
 * the decision sees the final recipient list.
 */
final class Guard implements Service {

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
	 * Stopped email record.
	 *
	 * @var Log
	 */
	private Log $log;

	/**
	 * Whether the email being sent right now should not go out at all.
	 *
	 * @var bool
	 */
	private bool $block_next = false;

	/**
	 * Sets up the guard.
	 *
	 * @param Settings    $settings    Saved settings.
	 * @param Environment $environment Environment detection.
	 * @param Log         $log         Stopped email record.
	 */
	public function __construct( Settings $settings, Environment $environment, Log $log ) {
		$this->settings    = $settings;
		$this->environment = $environment;
		$this->log         = $log;
	}

	/**
	 * Hooks into wp_mail().
	 */
	public function register(): void {
		add_filter( 'wp_mail', array( $this, 'filter_mail' ), PHP_INT_MAX );
		add_filter( 'pre_wp_mail', array( $this, 'short_circuit' ), PHP_INT_MIN, 2 );
	}

	/**
	 * The stopped email record.
	 *
	 * @return Log
	 */
	public function log(): Log {
		return $this->log;
	}

	/**
	 * Mail handling in effect, after wp-config.php overrides.
	 *
	 * Falls back to blocking when redirect is chosen without a valid address,
	 * so a typo never lets mail through to real recipients.
	 *
	 * @return array{mode: string, redirect_to: string, allowlist: string[], locked: bool}
	 */
	public function policy(): array {
		$mode      = defined( 'AXLE_STAGING_GUARD_MAIL_MODE' ) ? (string) AXLE_STAGING_GUARD_MAIL_MODE : (string) $this->settings->get( 'mail_mode' );
		$to        = defined( 'AXLE_STAGING_GUARD_REDIRECT_TO' ) ? (string) AXLE_STAGING_GUARD_REDIRECT_TO : (string) $this->settings->get( 'redirect_to' );
		$allowlist = defined( 'AXLE_STAGING_GUARD_ALLOWLIST' ) ? Settings::parse_allowlist( (string) AXLE_STAGING_GUARD_ALLOWLIST ) : (array) $this->settings->get( 'allowlist' );

		/**
		 * Filters the addresses and @domains that still get email on a staging copy.
		 *
		 * @param string[] $allowlist Lowercase addresses and @domains.
		 */
		$allowlist = array_values( array_filter( array_map( 'strtolower', array_map( 'strval', (array) apply_filters( 'axle_staging_guard_allowlist', $allowlist ) ) ) ) );

		if ( ! in_array( $mode, Settings::MAIL_MODES, true ) || ( 'redirect' === $mode && ! is_email( $to ) ) ) {
			$mode = 'block';
		}

		$policy = array(
			'mode'        => $mode,
			'redirect_to' => $to,
			'allowlist'   => $allowlist,
			'locked'      => defined( 'AXLE_STAGING_GUARD_MAIL_MODE' ) || defined( 'AXLE_STAGING_GUARD_REDIRECT_TO' ) || defined( 'AXLE_STAGING_GUARD_ALLOWLIST' ),
		);

		/**
		 * Filters how email is handled on this copy, after settings and constants.
		 *
		 * For example, redirect on staging but block on development. A redirect
		 * without a valid address still falls back to blocking.
		 *
		 * @param array $policy {
		 *     @type string   $mode        block, redirect or allow.
		 *     @type string   $redirect_to Test address for redirect mode.
		 *     @type string[] $allowlist   Addresses and @domains that still get email.
		 *     @type bool     $locked      Whether wp-config.php constants fix the settings.
		 * }
		 * @param string $environment Environment type: staging, development or local.
		 */
		$policy = (array) apply_filters( 'axle_staging_guard_mail_policy', $policy, $this->environment->type() );

		if ( ! in_array( $policy['mode'] ?? '', Settings::MAIL_MODES, true ) || ( 'redirect' === $policy['mode'] && ! is_email( $policy['redirect_to'] ?? '' ) ) ) {
			$policy['mode'] = 'block';
		}

		return $policy;
	}

	/**
	 * What happens to email, in a few words.
	 *
	 * @return string
	 */
	public function describe(): string {
		$policy = $this->policy();

		switch ( $policy['mode'] ) {
			case 'redirect':
				/* translators: %s: email address. */
				return sprintf( __( 'Redirected to %s', 'axle-staging-guard' ), $policy['redirect_to'] );

			case 'allow':
				return __( 'Sent normally', 'axle-staging-guard' );

			default:
				return __( 'Blocked', 'axle-staging-guard' );
		}
	}

	/**
	 * Whether an address may receive email from this copy.
	 *
	 * @param string $address Email address.
	 * @return bool
	 */
	public function is_allowed( string $address ): bool {
		$address = strtolower( $address );

		foreach ( $this->policy()['allowlist'] as $entry ) {
			if ( $entry === $address || ( '@' === $entry[0] && substr( $address, -strlen( $entry ) ) === $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rewrites the recipients of an outgoing email.
	 *
	 * @param array $atts Arguments passed to wp_mail().
	 * @return array
	 */
	public function filter_mail( array $atts ): array {
		$this->block_next = false;
		$policy           = $this->policy();

		/**
		 * Filters whether one email goes out untouched, whoever it is for.
		 *
		 * For mail that must work on staging, such as monitoring alerts to
		 * your own team. It is not logged.
		 *
		 * @param bool  $allow Default false.
		 * @param array $atts  Arguments passed to wp_mail().
		 */
		if ( 'allow' === $policy['mode'] || apply_filters( 'axle_staging_guard_allow_email', false, $atts ) ) {
			return $atts;
		}

		$to      = self::split( $atts['to'] ?? '' );
		$allowed = array_values( array_filter( $to, array( $this, 'recipient_allowed' ) ) );
		$stopped = array_values( array_diff( $to, $allowed ) );

		list( $headers, $stopped_copies ) = $this->filter_copy_headers( $atts['headers'] ?? array() );
		$stopped                          = array_merge( $stopped, $stopped_copies );

		if ( ! $stopped ) {
			return $atts;
		}

		$atts['headers'] = $headers;
		$entry           = array(
			'to'        => implode( ', ', array_merge( $to, $stopped_copies ) ),
			'subject'   => (string) ( $atts['subject'] ?? '' ),
			'delivered' => implode( ', ', $allowed ),
			'body'      => is_string( $atts['message'] ?? null ) ? $atts['message'] : '',
		);

		if ( 'redirect' === $policy['mode'] ) {
			$atts['to'] = array_merge( $allowed, array( $policy['redirect_to'] ) );
			/**
			 * Filters the subject of a redirected email.
			 *
			 * @param string   $subject Default "[STAGING → original recipients] Original subject".
			 * @param string[] $stopped Recipients who did not get it.
			 * @param array    $atts    Arguments passed to wp_mail().
			 */
			$atts['subject'] = (string) apply_filters( 'axle_staging_guard_redirect_subject', sprintf( '[%s → %s] %s', strtoupper( $this->environment->label() ), implode( ', ', $stopped ), $entry['subject'] ), $stopped, $atts );
			$this->stopped( array( 'action' => 'redirected' ) + $entry, $atts );

			return $atts;
		}

		if ( $allowed ) {
			$atts['to'] = $allowed;
			$this->stopped( array( 'action' => 'partly_blocked' ) + $entry, $atts );

			return $atts;
		}

		$this->block_next = true;
		$this->stopped( array( 'action' => 'blocked' ) + $entry, $atts );

		return $atts;
	}

	/**
	 * Logs a stopped email and tells other code about it.
	 *
	 * @param array $entry What happened: to, subject, action, delivered and body.
	 * @param array $atts  The email as it now goes out, if it goes out at all.
	 */
	private function stopped( array $entry, array $atts ): void {
		$entry['id'] = $this->log->add( $entry );

		/**
		 * Fires when Axle Staging Guard blocks or redirects an email.
		 *
		 * @param array $entry What happened: id (log entry), to, subject, action (blocked, redirected or partly_blocked), delivered and body.
		 * @param array $atts  The wp_mail() arguments after Axle Staging Guard changed them.
		 */
		do_action( 'axle_staging_guard_mail_stopped', $entry, $atts );
	}

	/**
	 * Stops an email nobody may receive, and reports it as sent.
	 *
	 * Reporting success keeps flows like password resets from showing errors
	 * while testing. Runs first, so later `pre_wp_mail` senders see a handled value.
	 *
	 * @param null|bool $result Short-circuit value from earlier filters.
	 * @param array     $atts   Arguments passed to wp_mail().
	 * @return null|bool
	 */
	public function short_circuit( $result, $atts ) {
		if ( null !== $result || ! $this->block_next ) {
			return $result;
		}

		$this->block_next = false;

		return true;
	}

	/**
	 * Sends a test email and reports what Axle Staging Guard did with it.
	 *
	 * @param string $to Recipient.
	 * @return string blocked, redirected, partly_blocked, sent or failed.
	 */
	public function send_test( string $to ): string {
		$before = $this->log->latest()['id'] ?? 0;
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One test email, sent on demand by an admin.
		$sent = wp_mail(
			$to,
			/* translators: %s: site name. */
			sprintf( __( 'Axle Staging Guard test from %s', 'axle-staging-guard' ), get_bloginfo( 'name' ) ),
			sprintf(
				/* translators: 1: site address, 2: environment name. */
				__( "This is a test email from %1\$s (%2\$s).\n\nIf you are reading it, email from this copy of the site reached you.", 'axle-staging-guard' ),
				home_url(),
				$this->environment->label()
			)
		);
		$after = $this->log->latest();

		if ( $after && $after['id'] !== $before ) {
			return (string) $after['action'];
		}

		return $sent ? 'sent' : 'failed';
	}

	/**
	 * Whether another plugin replaced wp_mail(), which this plugin cannot intercept.
	 *
	 * @return bool
	 */
	public function wp_mail_is_replaced(): bool {
		if ( ! function_exists( 'wp_mail' ) ) {
			return false;
		}

		$file = ( new \ReflectionFunction( 'wp_mail' ) )->getFileName();

		return wp_normalize_path( (string) $file ) !== wp_normalize_path( ABSPATH . WPINC . '/pluggable.php' );
	}

	/**
	 * Whether a recipient string ("Name <a@b.c>" or "a@b.c") is allowlisted.
	 *
	 * @param string $recipient Recipient.
	 * @return bool
	 */
	public function recipient_allowed( string $recipient ): bool {
		return $this->is_allowed( self::address( $recipient ) );
	}

	/**
	 * Keeps only allowlisted addresses in Cc and Bcc headers.
	 *
	 * @param string|string[] $headers Headers passed to wp_mail().
	 * @return array{0: string[], 1: string[]} Kept header lines, and stopped addresses.
	 */
	private function filter_copy_headers( $headers ): array {
		$lines   = is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
		$kept    = array();
		$stopped = array();

		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}

			if ( ! preg_match( '/^\s*(b?cc)\s*:(.*)$/i', $line, $match ) ) {
				$kept[] = $line;
				continue;
			}

			$recipients = self::split( $match[2] );
			$allowed    = array_filter( $recipients, array( $this, 'recipient_allowed' ) );
			$stopped    = array_merge( $stopped, array_diff( $recipients, $allowed ) );

			if ( $allowed ) {
				$kept[] = $match[1] . ': ' . implode( ', ', $allowed );
			}
		}

		return array( $kept, $stopped );
	}

	/**
	 * Recipients as a list.
	 *
	 * @param string|string[] $recipients Comma-separated string or list.
	 * @return string[]
	 */
	public static function split( $recipients ): array {
		$list = is_array( $recipients ) ? $recipients : explode( ',', (string) $recipients );

		return array_values(
			array_filter(
				array_map( 'trim', $list ),
				static function ( string $recipient ): bool {
					return '' !== $recipient;
				}
			)
		);
	}

	/**
	 * Email address part of "Name <a@b.c>".
	 *
	 * @param string $recipient Recipient.
	 * @return string
	 */
	public static function address( string $recipient ): string {
		if ( preg_match( '/<([^>]+)>/', $recipient, $match ) ) {
			$recipient = $match[1];
		}

		return strtolower( trim( $recipient ) );
	}
}
