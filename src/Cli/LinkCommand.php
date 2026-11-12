<?php
/**
 * `wp axle-staging-guard gate link` commands.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Cli;

use AryanX\Axle\StagingGuard\Gate\Gate;

defined( 'ABSPATH' ) || exit;

/**
 * Makes, lists and revokes share links for the staging password gate.
 */
final class LinkCommand {

	/**
	 * The gate.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Sets up the command.
	 *
	 * @param Gate $gate The gate.
	 */
	public function __construct( Gate $gate ) {
		$this->gate = $gate;
	}

	/**
	 * Makes a share link.
	 *
	 * Prints the link. Anyone who has it can see the site until it expires or is revoked.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Who it is for, such as "For Priya at the client".
	 *
	 * [--days=<days>]
	 * : How long it works, 1 to 30 days.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--porcelain]
	 * : Print only the link.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function create( array $args, array $assoc_args ): void {
		$link = $this->gate->links()->create( (string) $args[0], (int) ( $assoc_args['days'] ?? 7 ) );
		if ( is_wp_error( $link ) ) {
			\WP_CLI::error( $link->get_error_message() );
		}

		$url = $this->gate->links()->url( $link );
		if ( ! empty( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( $url );
			return;
		}

		\WP_CLI::success( 'Link ' . $link['id'] . ' made for "' . $link['name'] . '". It works until ' . gmdate( 'Y-m-d H:i', $link['expires'] ) . ' UTC.' );
		\WP_CLI::line( $url );
	}

	/**
	 * Lists share links, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_links( array $args, array $assoc_args ): void {
		$store = $this->gate->links();
		$rows  = array_map(
			static function ( array $link ) use ( $store ): array {
				return array(
					'id'        => $link['id'],
					'name'      => $link['name'],
					'status'    => $store->is_active( $link ) ? 'works' : 'expired',
					'expires'   => gmdate( 'Y-m-d H:i:s', $link['expires'] ),
					'last_used' => $link['last_used'] > 0 ? gmdate( 'Y-m-d H:i:s', $link['last_used'] ) : '',
					'visits'    => $link['uses'],
				);
			},
			$store->all()
		);

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'name', 'status', 'expires', 'last_used', 'visits' ) );
	}

	/**
	 * Revokes a share link. The person who had it is out on their next page view.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Link ID from the list.
	 *
	 * @param array $args Positional arguments.
	 */
	public function revoke( array $args ): void {
		if ( ! $this->gate->links()->revoke( (string) $args[0] ) ) {
			\WP_CLI::error( 'There is no link with that ID.' );
		}

		\WP_CLI::success( 'Link revoked.' );
	}
}
