<?php
/**
 * `wp axle-staging-guard gate` commands.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Cli;

use AryanX\Axle\StagingGuard\Gate\Gate;
use AryanX\Axle\StagingGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the staging password gate on and off, and shows its state.
 */
final class GateCommand {

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The gate.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Sets up the command.
	 *
	 * @param Settings $settings Saved settings.
	 * @param Gate     $gate     The gate.
	 */
	public function __construct( Settings $settings, Gate $gate ) {
		$this->settings = $settings;
		$this->gate     = $gate;
	}

	/**
	 * Turns the gate on.
	 *
	 * Visitors who are not logged in then see a password screen. It only works
	 * on staging, development and local copies. A password must be set first:
	 * pass --password, or set one on Tools > Staging Guard > Password gate.
	 *
	 * ## OPTIONS
	 *
	 * [--password=<password>]
	 * : Sets or changes the shared password. It is stored hashed. Mind the shell history.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-staging-guard gate on --password='open sesame 2026'
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function on( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['password'] ) ) {
			$result = $this->gate->set_password( (string) $assoc_args['password'] );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
		}

		$result = $this->gate->enable();
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( $this->gate->is_active() ? 'The gate is on.' : 'The gate is switched on, but this is the live site, so it does nothing here.' );
	}

	/**
	 * Turns the gate off.
	 */
	public function off(): void {
		$this->gate->disable();
		\WP_CLI::success( 'The gate is off.' );
	}

	/**
	 * Shows the state of the gate.
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
		$links  = $this->gate->links();
		$status = array(
			'active'       => $this->gate->is_active() ? 'yes' : 'no',
			'switched_on'  => $this->settings->get( 'gate_enabled' ) ? 'yes' : 'no',
			'password_set' => $this->gate->has_password() ? 'yes' : 'no',
			'allowed_ips'  => implode( ', ', $this->gate->open_ips() ),
			'open_paths'   => implode( ', ', $this->gate->open_paths() ),
			'active_links' => (string) count(
				array_filter(
					$links->all(),
					static function ( array $link ) use ( $links ): bool {
						return $links->is_active( $link );
					}
				)
			),
		);

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $status ) );
			return;
		}

		foreach ( $status as $key => $value ) {
			\WP_CLI::line( str_pad( ucfirst( str_replace( '_', ' ', $key ) ) . ':', 16 ) . $value );
		}
	}
}
