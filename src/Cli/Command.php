<?php
/**
 * `wp axle-staging-guard` commands.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Cli;

use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Mail\Guard;
use AryanX\Axle\StagingGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the environment, sets the production lock and reads the email log.
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
	 * Sets up the command.
	 *
	 * @param Settings    $settings    Saved settings.
	 * @param Environment $environment Environment detection.
	 * @param Guard       $mail        Email guard.
	 */
	public function __construct( Settings $settings, Environment $environment, Guard $mail ) {
		$this->settings    = $settings;
		$this->environment = $environment;
		$this->mail        = $mail;
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
		$status = array(
			'environment' => $this->environment->type(),
			'source'      => $this->environment->source(),
			'domain'      => $this->environment->domain(),
			'guards'      => $this->environment->is_guarded() ? 'on' : 'off',
			'email'       => $policy['mode'] . ( 'redirect' === $policy['mode'] ? ' to ' . $policy['redirect_to'] : '' ),
			'allowlist'   => implode( ', ', $policy['allowlist'] ),
			'noindex'     => $this->settings->get( 'noindex' ) ? 'yes' : 'no',
		);

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $status ) );
			return;
		}

		foreach ( $status as $key => $value ) {
			\WP_CLI::line( str_pad( ucfirst( $key ) . ':', 13 ) . $value );
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
}
