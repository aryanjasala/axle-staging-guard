<?php
/**
 * `wp axle-staging-guard` commands.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Cli;

use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Http\Guard as HttpGuard;
use AryanX\Axle\StagingGuard\Mail\Guard;
use AryanX\Axle\StagingGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the environment, sets the production lock, reads the email and request logs and tests URLs.
 */
final class Command {

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
	 * Email guard.
	 *
	 * @var Guard
	 */
	private Guard $mail;

	/**
	 * Outgoing request guard.
	 *
	 * @var HttpGuard
	 */
	private HttpGuard $http;

	/**
	 * Sets up the command.
	 *
	 * @param Settings    $settings    Saved settings.
	 * @param Environment $environment Environment detection.
	 * @param Guard       $mail        Email guard.
	 * @param HttpGuard   $http        Outgoing request guard.
	 */
	public function __construct( Settings $settings, Environment $environment, Guard $mail, HttpGuard $http ) {
		$this->settings    = $settings;
		$this->environment = $environment;
		$this->mail        = $mail;
		$this->http        = $http;
	}

	/**
	 * Shows how Axle Staging Guard sees this copy of the site.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$policy = $this->mail->policy();
		$http   = $this->http->policy();
		$status = array(
			'environment'    => $this->environment->type(),
			'source'         => $this->environment->source(),
			'domain'         => $this->environment->domain(),
			'guards'         => $this->environment->is_guarded() ? 'on' : 'off',
			'email'          => $policy['mode'] . ( 'redirect' === $policy['mode'] ? ' to ' . $policy['redirect_to'] : '' ),
			'allowlist'      => implode( ', ', $policy['allowlist'] ),
			'noindex'        => $this->settings->get( 'noindex' ) ? 'yes' : 'no',
			// The mode in effect on a staging copy. The live site never touches outgoing requests.
			'http'           => $this->environment->is_guarded() ? $http['mode'] : 'allow',
			'http_allowlist' => implode( ', ', $http['allowlist'] ),
			'http_blocklist' => implode( ', ', $http['blocklist'] ),
		);

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $status ) );
			return;
		}

		foreach ( $status as $key => $value ) {
			\WP_CLI::line( str_pad( ucfirst( $key ) . ':', 16 ) . $value );
		}
	}

	/**
	 * Marks the current domain as the live site.
	 *
	 * @subcommand lock-production
	 */
	public function lock_production(): void {
		$this->environment->lock();
		\WP_CLI::success( 'Production locked to ' . $this->environment->domain() . '. Copies on other domains are now treated as staging.' );
	}

	/**
	 * Removes the production lock.
	 *
	 * @subcommand unlock-production
	 */
	public function unlock_production(): void {
		$this->environment->unlock();
		\WP_CLI::success( 'Production lock removed.' );
	}

	/**
	 * Lists stopped email.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function log( array $args, array $assoc_args ): void {
		$rows = array_map(
			static function ( array $entry ): array {
				return array( 'time' => gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ) ) + $entry;
			},
			$this->mail->log()->all()
		);

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'time', 'action', 'to', 'delivered', 'subject' ) );
	}

	/**
	 * Clears a list of stopped items.
	 *
	 * ## OPTIONS
	 *
	 * [--list=<list>]
	 * : Which list to clear.
	 * ---
	 * default: all
	 * options:
	 *   - mail
	 *   - http
	 *   - all
	 * ---
	 *
	 * @subcommand clear-log
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function clear_log( array $args, array $assoc_args ): void {
		$list    = (string) ( $assoc_args['list'] ?? 'all' );
		$removed = 0;

		if ( 'mail' === $list || 'all' === $list ) {
			$removed += $this->mail->log()->clear();
		}
		if ( 'http' === $list || 'all' === $list ) {
			$removed += $this->http->log()->clear();
		}

		\WP_CLI::success( $removed . ' entries removed.' );
	}

	/**
	 * Changes one setting.
	 *
	 * Settings fixed in wp-config.php cannot be changed here.
	 *
	 * ## OPTIONS
	 *
	 * <setting>
	 * : One of mail_mode (block, redirect, allow), redirect_to, allowlist, keep_bodies (1 or 0), noindex (1 or 0), http_mode (allow, log, block), http_allowlist, http_blocklist. Lists are comma separated.
	 *
	 * <value>
	 * : The new value.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-staging-guard set mail_mode redirect
	 *     wp axle-staging-guard set http_allowlist "sandbox.paypal.com,*.youragency.com"
	 *
	 * @param array $args Positional arguments.
	 */
	public function set( array $args ): void {
		list( $key, $value ) = array_pad( $args, 2, '' );

		switch ( $key ) {
			case 'mail_mode':
				if ( ! in_array( $value, Settings::MAIL_MODES, true ) ) {
					\WP_CLI::error( 'mail_mode must be block, redirect or allow.' );
				}
				$change = array( 'mail_mode' => $value );
				break;

			case 'http_mode':
				if ( ! in_array( $value, Settings::HTTP_MODES, true ) ) {
					\WP_CLI::error( 'http_mode must be allow, log or block.' );
				}
				$change = array( 'http_mode' => $value );
				break;

			case 'redirect_to':
				if ( '' !== $value && ! is_email( $value ) ) {
					\WP_CLI::error( 'redirect_to must be an email address.' );
				}
				$change = array( 'redirect_to' => sanitize_email( $value ) );
				break;

			case 'allowlist':
				$change = array( 'allowlist' => Settings::parse_allowlist( $value ) );
				break;

			case 'http_allowlist':
			case 'http_blocklist':
				$change = array( $key => Settings::parse_hosts( $value ) );
				break;

			case 'keep_bodies':
			case 'noindex':
				$change = array( $key => in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true ) );
				break;

			default:
				\WP_CLI::error( 'Unknown setting. See wp help axle-staging-guard set.' );
				return;
		}

		$http_fixed = HttpGuard::fixed();
		$fixed      = $this->settings->is_fixed( $key )
			|| ( in_array( $key, array( 'mail_mode', 'redirect_to', 'allowlist' ), true ) && $this->mail->policy()['locked'] )
			|| ( 'http_mode' === $key && $http_fixed['mode'] )
			|| ( 'http_allowlist' === $key && $http_fixed['allowlist'] )
			|| ( 'http_blocklist' === $key && $http_fixed['blocklist'] );
		if ( $fixed ) {
			\WP_CLI::error( $key . ' is fixed in wp-config.php, so a saved value would have no effect.' );
		}

		$this->settings->update( $change );
		\WP_CLI::success( 'Saved ' . $key . '.' );
	}

	/**
	 * Sends a test email and reports what happened to it.
	 *
	 * ## OPTIONS
	 *
	 * <to>
	 * : Recipient.
	 *
	 * @subcommand test-email
	 *
	 * @param array $args Positional arguments.
	 */
	public function test_email( array $args ): void {
		\WP_CLI::line( 'Result: ' . $this->mail->send_test( $args[0] ) );
	}

	/**
	 * Lists outgoing requests that were blocked or logged, most recent first.
	 *
	 * Each line is one distinct request, with how many times it happened.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand http-log
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function http_log( array $args, array $assoc_args ): void {
		$rows = array_map(
			static function ( array $entry ): array {
				return array( 'time' => gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ) ) + $entry;
			},
			$this->http->log()->all()
		);

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'time', 'action', 'count', 'method', 'host', 'path', 'caller' ) );
	}

	/**
	 * Says what would happen to a request to a URL. Nothing is sent or logged.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The URL, such as https://api.stripe.com/v1/charges.
	 *
	 * [--method=<method>]
	 * : HTTP method.
	 * ---
	 * default: GET
	 * ---
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand test-url
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function test_url( array $args, array $assoc_args ): void {
		if ( ! \AryanX\Axle\StagingGuard\Http\Rules::is_url( (string) $args[0] ) ) {
			\WP_CLI::error( 'That is not a valid http or https URL.' );
		}

		$decision = $this->http->evaluate( (string) $args[0], (string) ( $assoc_args['method'] ?? 'GET' ) );
		$results  = array(
			'allow' => 'allowed',
			'log'   => 'logged',
			'block' => 'blocked',
		);
		$result   = array(
			'result'      => $results[ $decision['action'] ],
			'reason'      => $decision['reason'],
			'host'        => $decision['host'],
			'explanation' => $this->http->explain( $decision ),
		);

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $result ) );
			return;
		}

		\WP_CLI::line( 'Result: ' . $result['result'] );
		\WP_CLI::line( $result['explanation'] );
	}
}
