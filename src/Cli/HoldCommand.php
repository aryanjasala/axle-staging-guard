<?php
/**
 * `wp axle-staging-guard hold` commands.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Cli;

use AryanX\Axle\StagingGuard\Cron\Hold;
use AryanX\Axle\StagingGuard\Cron\Tasks;
use AryanX\Axle\StagingGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lists scheduled tasks, holds and releases them on staging, runs one by hand and reads the held list.
 */
final class HoldCommand {

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The hold.
	 *
	 * @var Hold
	 */
	private Hold $hold;

	/**
	 * Sets up the command.
	 *
	 * @param Settings $settings Saved settings.
	 * @param Hold     $hold     The hold.
	 */
	public function __construct( Settings $settings, Hold $hold ) {
		$this->settings = $settings;
		$this->hold     = $hold;
	}

	/**
	 * Lists the scheduled tasks and whether each is held.
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
	public function list_tasks( array $args, array $assoc_args ): void {
		$tasks = $this->hold->tasks();
		$held  = $tasks->held();
		$rows  = array();

		foreach ( $tasks->hooks() as $hook => $row ) {
			$rows[] = array(
				'hook'      => $hook,
				'status'    => $tasks->is_protected( $hook ) ? 'protected' : ( in_array( $hook, $held, true ) ? 'held' : ( $tasks->is_suggested( $hook ) ? 'suggested' : 'runs' ) ),
				'owner'     => $tasks->owner( $hook ),
				'next_run'  => gmdate( 'Y-m-d H:i:s', $row['next'] ),
				'schedule'  => '' === $row['schedule'] ? 'once' : $row['schedule'],
				'arguments' => Tasks::summarise_args( $hook, $row['args'] ),
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'hook', 'status', 'owner', 'next_run', 'schedule', 'arguments' ) );
	}

	/**
	 * Holds scheduled tasks on staging.
	 *
	 * Protected hooks, such as WordPress's clean-up and privacy tasks, cannot be held.
	 *
	 * ## OPTIONS
	 *
	 * <hook>...
	 * : One or more hook names.
	 *
	 * @param array $args Positional arguments.
	 */
	public function add( array $args ): void {
		$tasks   = $this->hold->tasks();
		$refused = array_filter( $args, array( $tasks, 'is_protected' ) );
		$current = (array) $this->settings->get( 'cron_held' );

		$this->hold->save( array_merge( $current, array_diff( $args, $refused ) ), $args );

		foreach ( $refused as $hook ) {
			\WP_CLI::warning( $hook . ' is protected and cannot be held.' );
		}
		\WP_CLI::success( count( $args ) - count( $refused ) . ' task(s) held.' );
	}

	/**
	 * Lets held tasks run again.
	 *
	 * ## OPTIONS
	 *
	 * <hook>...
	 * : One or more hook names.
	 *
	 * @param array $args Positional arguments.
	 */
	public function remove( array $args ): void {
		$this->hold->save( array_diff( (array) $this->settings->get( 'cron_held' ), $args ), $args );
		\WP_CLI::success( count( $args ) . ' task(s) released.' );
	}

	/**
	 * Runs a scheduled task once, now, and puts it back on its schedule.
	 *
	 * The task may contact the outside service it belongs to.
	 *
	 * ## OPTIONS
	 *
	 * <hook>
	 * : Hook name.
	 *
	 * @param array $args Positional arguments.
	 */
	public function run( array $args ): void {
		$result = $this->hold->run_once( (string) $args[0] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( 'Ran ' . $args[0] . ' once.' );
	}

	/**
	 * Lists tasks that were held, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand log
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function log( array $args, array $assoc_args ): void {
		$rows = array_map(
			static function ( array $entry ): array {
				return array(
					'time'      => gmdate( 'Y-m-d H:i:s', $entry['time'] ),
					'hook'      => $entry['hook'],
					'owner'     => $entry['owner'],
					'due'       => $entry['due'] > 0 ? gmdate( 'Y-m-d H:i:s', $entry['due'] ) : '',
					'arguments' => $entry['args'],
				);
			},
			$this->hold->log()->all()
		);

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'time', 'hook', 'owner', 'due', 'arguments' ) );
	}

	/**
	 * Clears the held list.
	 *
	 * @subcommand clear-log
	 */
	public function clear_log(): void {
		\WP_CLI::success( $this->hold->log()->clear() . ' entries removed.' );
	}
}
