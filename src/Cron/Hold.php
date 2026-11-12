<?php
/**
 * Holds chosen scheduled tasks on staging.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Cron;

use AryanX\Axle\StagingGuard\Service;
use AryanX\Axle\StagingGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A held task is not run, and is listed instead.
 *
 * WP-Cron asks for the tasks that are due on `pre_get_ready_cron_jobs`. Held
 * hooks are left out of that answer, so the task stays in the schedule, is
 * never started and never reschedules itself, and the screen can run it once
 * on request. Tasks started a different way, such as `wp cron event run`, have
 * the callbacks of held hooks taken off for that command.
 *
 * It only runs on a copy that is not production.
 */
final class Hold implements Service {

	private const TICK = 'axle_sg_held_tick';

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The scheduled tasks.
	 *
	 * @var Tasks
	 */
	private Tasks $tasks;

	/**
	 * The held list.
	 *
	 * @var Log
	 */
	private Log $log;

	/**
	 * Sets up the hold.
	 *
	 * @param Settings $settings Saved settings.
	 * @param Tasks    $tasks    The scheduled tasks.
	 * @param Log      $log      The held list.
	 */
	public function __construct( Settings $settings, Tasks $tasks, Log $log ) {
		$this->settings = $settings;
		$this->tasks    = $tasks;
		$this->log      = $log;
	}

	/**
	 * Hooks into WP-Cron.
	 */
	public function register(): void {
		add_filter( 'pre_get_ready_cron_jobs', array( $this, 'ready_jobs' ) );
		add_action( 'wp_loaded', array( $this, 'hold_command_line_runs' ), PHP_INT_MAX );
	}

	/**
	 * The scheduled tasks.
	 *
	 * @return Tasks
	 */
	public function tasks(): Tasks {
		return $this->tasks;
	}

	/**
	 * The held list.
	 *
	 * @return Log
	 */
	public function log(): Log {
		return $this->log;
	}

	/**
	 * The jobs that are due, without the held ones.
	 *
	 * @param array|null $pre Answer from an earlier filter.
	 * @return array|null
	 */
	public function ready_jobs( $pre ) {
		$held = $this->tasks->held();
		if ( null !== $pre || ! $held ) {
			return $pre;
		}

		$now   = microtime( true );
		$ready = array();
		$seen  = array();

		foreach ( _get_cron_array() as $timestamp => $hooks ) {
			if ( $timestamp > $now ) {
				break;
			}

			foreach ( $hooks as $hook => $instances ) {
				if ( in_array( (string) $hook, $held, true ) ) {
					$seen[] = array( (string) $hook, (int) $timestamp, (array) $instances );
					continue;
				}
				$ready[ $timestamp ][ $hook ] = $instances;
			}
		}

		if ( $seen && false === get_transient( self::TICK ) ) {
			// Looking each overdue run up in the list costs a query, so do it once a minute at most.
			set_transient( self::TICK, 1, MINUTE_IN_SECONDS );
			foreach ( $seen as list( $hook, $timestamp, $instances ) ) {
				foreach ( $instances as $instance ) {
					$this->record( $hook, $timestamp, (array) ( $instance['args'] ?? array() ) );
				}
			}
		}

		return $ready;
	}

	/**
	 * Takes the callbacks off held hooks for a WP-CLI command that runs tasks.
	 *
	 * This plugin's own commands are left alone, so `hold run` can run one.
	 */
	public function hold_command_line_runs(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || self::is_own_command() ) {
			return;
		}

		foreach ( $this->tasks->held() as $hook ) {
			if ( ! has_action( $hook ) ) {
				continue;
			}

			remove_all_actions( $hook );
			add_action(
				$hook,
				function ( ...$args ) use ( $hook ): void {
					$this->record( $hook, time(), $args );
				},
				10,
				99
			);
		}
	}

	/**
	 * Whether the command being run is this plugin's.
	 *
	 * @return bool
	 */
	private static function is_own_command(): bool {
		$argv = isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ? $GLOBALS['argv'] : array();

		return in_array( 'axle-staging-guard', $argv, true );
	}

	/**
	 * Writes a held run to the list and tells listeners.
	 *
	 * @param string $hook Hook name.
	 * @param int    $due  Unix time it was due.
	 * @param array  $args The task's arguments.
	 */
	private function record( string $hook, int $due, array $args ): void {
		$entry       = array(
			'hook'  => $hook,
			'due'   => $due,
			'args'  => Tasks::summarise_args( $hook, $args ),
			'owner' => $this->tasks->owner( $hook ),
		);
		$entry['id'] = $this->log->add( $entry, $args );

		if ( $entry['id'] > 0 ) {
			/**
			 * Fires when a scheduled task is held and listed.
			 *
			 * @param array $entry id (list entry), hook, due (Unix time), args (a summary, not the values) and owner.
			 */
			do_action( 'axle_staging_guard_cron_held', $entry );
		}
	}

	/**
	 * Runs a scheduled task once, now, the way WP-Cron would.
	 *
	 * The task keeps its place in the schedule: a repeating task is
	 * rescheduled and a single event is removed, as core does.
	 *
	 * @param string $hook Hook name.
	 * @return true|\WP_Error An error when nothing is scheduled under that name.
	 */
	public function run_once( string $hook ) {
		foreach ( _get_cron_array() as $timestamp => $hooks ) {
			if ( empty( $hooks[ $hook ] ) ) {
				continue;
			}

			$event = reset( $hooks[ $hook ] );
			$args  = (array) ( $event['args'] ?? array() );

			/**
			 * Fires just before a held task is run once by hand.
			 *
			 * @param string $hook Hook name.
			 * @param array  $args The task's arguments.
			 */
			do_action( 'axle_staging_guard_cron_run_once', $hook, $args );

			if ( ! empty( $event['schedule'] ) ) {
				wp_reschedule_event( (int) $timestamp, (string) $event['schedule'], $hook, $args );
			}
			wp_unschedule_event( (int) $timestamp, $hook, $args );

			do_action_ref_array( $hook, $args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Runs the task's own hook, as WP-Cron does.

			return true;
		}

		return new \WP_Error( 'axle_staging_guard_cron_not_scheduled', __( 'That task is not scheduled, so there is nothing to run.', 'axle-staging-guard' ) );
	}

	/**
	 * Changes which hooks are held.
	 *
	 * @param string[] $held    Hooks to hold. Protected hooks are dropped.
	 * @param string[] $decided Hooks the person has now decided about, ticked or not.
	 * @return string[] The held hooks now saved.
	 */
	public function save( array $held, array $decided ): array {
		$previous = (array) $this->settings->get( 'cron_held' );
		$held     = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $held ),
					function ( string $hook ): bool {
						return '' !== $hook && ! $this->tasks->is_protected( $hook );
					}
				)
			)
		);
		$decided  = array_values( array_unique( array_merge( array_map( 'strval', (array) $this->settings->get( 'cron_decided' ) ), array_map( 'strval', $decided ) ) ) );

		$this->settings->update(
			array(
				'cron_held'    => $held,
				'cron_decided' => $decided,
			)
		);

		/**
		 * Fires after the list of held tasks is saved.
		 *
		 * @param string[] $held     Hooks held now.
		 * @param string[] $previous Hooks held before.
		 */
		do_action( 'axle_staging_guard_cron_hold_changed', $held, $previous );

		return $held;
	}
}
