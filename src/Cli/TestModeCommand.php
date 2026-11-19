<?php
/**
 * `wp axle-staging-guard test-mode` commands.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Cli;

use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\TestMode\Adapters;
use AryanX\Axle\StagingGuard\TestMode\Recipe;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sets the test-mode recipe, switches a copy now and undoes one item.
 */
final class TestModeCommand {

	/**
	 * The recipe.
	 *
	 * @var Recipe
	 */
	private Recipe $recipe;

	/**
	 * Environment detection.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * Sets up the command.
	 *
	 * @param Recipe      $recipe      The recipe.
	 * @param Environment $environment Environment detection.
	 */
	public function __construct( Recipe $recipe, Environment $environment ) {
		$this->recipe      = $recipe;
		$this->environment = $environment;
	}

	/**
	 * Lists the items that can be switched to test mode.
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
	public function list_items( array $args, array $assoc_args ): void {
		$rows = array_map(
			static function ( array $row ): array {
				$row['ticked'] = $row['ticked'] ? 'yes' : 'no';
				unset( $row['note'] );

				return $row;
			},
			$this->recipe->items( false )
		);

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'label', 'ticked', 'mode', 'state' ) );
	}

	/**
	 * Ticks items, so every copy switches them to test mode on its first request.
	 *
	 * Run it on the live site. It changes nothing there.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more item ids. See `list`.
	 *
	 * @param array $args Positional arguments.
	 */
	public function tick( array $args ): void {
		$this->check_ids( $args );
		$this->recipe->save( array_merge( $this->recipe->ticked(), $args ), false );
		\WP_CLI::success( 'Ticked: ' . implode( ', ', $this->recipe->ticked() ) . '.' );
	}

	/**
	 * Unticks items.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more item ids.
	 *
	 * @param array $args Positional arguments.
	 */
	public function untick( array $args ): void {
		$this->check_ids( $args );
		$this->recipe->save( array_diff( $this->recipe->ticked(), $args ), false );
		$left = $this->recipe->ticked();
		\WP_CLI::success( 'Unticked. Still ticked: ' . ( $left ? implode( ', ', $left ) : 'none' ) . '.' );
	}

	/**
	 * Switches the ticked items to test mode now, on this copy.
	 *
	 * Refused on the live site. Old values are kept for `undo`.
	 */
	public function run(): void {
		if ( ! $this->environment->is_guarded() ) {
			\WP_CLI::error( 'This is the live site. Nothing is switched to test mode here.' );
		}

		$done = $this->recipe->apply_pending();
		\WP_CLI::success( $done ? 'Switched to test mode: ' . implode( ', ', $done ) . '.' : 'Nothing to switch. Every ticked item is done or already in test mode.' );
	}

	/**
	 * Puts one item back to what it was before this copy switched it.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The item id.
	 *
	 * @param array $args Positional arguments.
	 */
	public function undo( array $args ): void {
		$this->check_ids( $args );
		$result = $this->recipe->undo( $args[0] );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( $args[0] . ' is back to what it was before the switch.' );
	}

	/**
	 * Stops with an error for an unknown item id.
	 *
	 * @param string[] $ids Ids typed by the person.
	 */
	private function check_ids( array $ids ): void {
		foreach ( $ids as $id ) {
			if ( null === Adapters::get( (string) $id ) ) {
				\WP_CLI::error( sprintf( 'Unknown item "%s". Run `wp axle-staging-guard test-mode list`.', $id ) );
			}
		}
	}
}
