<?php
/**
 * Tools > Staging Guard > Scheduled tasks.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Admin;

use AryanX\Axle\StagingGuard\Cron\Hold;
use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Service;
use AryanX\Axle\StagingGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The site's scheduled tasks: tick the ones staging should hold, run one by hand, see what was held.
 */
final class TasksTab implements Service {

	public const ACTION = 'axle_staging_guard_tasks';

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
	 * The hold.
	 *
	 * @var Hold
	 */
	private Hold $hold;

	/**
	 * Sets up the tab.
	 *
	 * @param Settings    $settings    Saved settings.
	 * @param Environment $environment Environment detection.
	 * @param Hold        $hold        The hold.
	 */
	public function __construct( Settings $settings, Environment $environment, Hold $hold ) {
		$this->settings    = $settings;
		$this->environment = $environment;
		$this->hold        = $hold;
	}

	/**
	 * Adds the form handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_form' ) );
	}

	/**
	 * The Help tabs for this screen.
	 *
	 * @return array<int, array{id: string, title: string, content: string}>
	 */
	public static function help(): array {
		return array(
			array(
				'id'      => 'tasks-overview',
				'title'   => __( 'Scheduled tasks', 'axle-staging-guard' ),
				'content' => '<p>' . esc_html__( 'WordPress and its plugins run tasks on a schedule, such as sending a newsletter or syncing with another service. A copy of the live site runs them too, with the live site\'s keys. Tick a task to hold it on this copy: it does not run, and it is listed instead.', 'axle-staging-guard' ) . '</p>',
			),
			array(
				'id'      => 'tasks-suggested',
				'title'   => __( 'Suggestions', 'axle-staging-guard' ),
				'content' => '<p>' . esc_html__( 'Tasks whose names suggest they reach the outside world, such as newsletter, export, sync, feed, renewal or subscription payment, are ticked for you. Nothing is held until you save. WordPress\'s own clean-up, privacy and recovery tasks can never be held.', 'axle-staging-guard' ) . '</p>',
			),
			array(
				'id'      => 'tasks-run',
				'title'   => __( 'Running a held task', 'axle-staging-guard' ),
				'content' => '<p>' . esc_html__( 'Run once starts the task now and puts it back on its schedule. Use it when you want to test the task on this copy. A held task that was due while the copy ran stays listed below, with when it was due.', 'axle-staging-guard' ) . '</p>',
			),
		);
	}

	/**
	 * Prints the notice for the last action.
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		$message = isset( $_GET['sg_message'] ) ? sanitize_key( wp_unslash( $_GET['sg_message'] ) ) : '';
		$error   = isset( $_GET['sg_error'] ) ? sanitize_key( wp_unslash( $_GET['sg_error'] ) ) : '';
		$undo    = isset( $_GET['sg_undo'] ) ? sanitize_key( wp_unslash( $_GET['sg_undo'] ) ) : '';
		$count   = isset( $_GET['sg_count'] ) ? absint( wp_unslash( $_GET['sg_count'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$text = '';
		switch ( $message ) {
			case 'tasks_saved':
				$text = sprintf(
					/* translators: %d: number of tasks held. */
					_n( 'Saved. %d task is held on this copy.', 'Saved. %d tasks are held on this copy.', $count, 'axle-staging-guard' ),
					$count
				);
				break;

			case 'task_ran':
				$text = __( 'The task ran once and is back on its schedule.', 'axle-staging-guard' );
				break;

			case 'undone':
				$text = __( 'Undone. The held tasks are back to what they were.', 'axle-staging-guard' );
				break;

			case 'tasks_cleared':
				$text = __( 'Held list cleared. The tasks themselves are not affected.', 'axle-staging-guard' );
				break;
		}

		if ( '' !== $text ) {
			$undo_link = SettingsPage::undo_link( $undo, 'tasks' );
			printf(
				'<div class="notice notice-success is-dismissible asg-notice" role="status"><p>%1$s%2$s</p></div>',
				esc_html( $text ),
				'' !== $undo_link ? ' ' . $undo_link : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by SettingsPage::undo_link().
			);
		}

		if ( 'nothing_to_undo' === $error ) {
			printf( '<div class="notice notice-error is-dismissible asg-notice" role="alert"><p>%s</p></div>', esc_html__( 'There is nothing to undo any more. The saved choice was kept for ten minutes.', 'axle-staging-guard' ) );
		}

		if ( 'not_scheduled' === $error ) {
			printf( '<div class="notice notice-error is-dismissible asg-notice" role="alert"><p>%s</p></div>', esc_html__( 'That task is not scheduled any more, so there was nothing to run. Reload the page to see the current list.', 'axle-staging-guard' ) );
		}
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		$tasks   = $this->hold->tasks();
		$log     = $this->hold->log();
		$guarded = $this->environment->is_guarded();
		$all     = $tasks->hooks();
		$held    = $tasks->held();
		$today   = $log->count_since( time() - DAY_IN_SECONDS );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters for a list; nothing is changed.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$view = in_array( $view, array( 'all', 'suggested', 'held' ), true ) ? $view : 'all';

		$rows = array_filter(
			$all,
			function ( array $row ) use ( $search, $view, $tasks, $held ): bool {
				if ( '' !== $search && false === stripos( $row['hook'], $search ) ) {
					return false;
				}
				if ( 'held' === $view ) {
					return in_array( $row['hook'], $held, true );
				}

				return 'suggested' !== $view || $tasks->is_suggested( $row['hook'] );
			}
		);

		$this->render_notices();
		?>
		<p class="axle-intro"><?php esc_html_e( 'Scheduled tasks on a copy of the live site run with the live site\'s keys: newsletters go out, exports reach the real server, subscriptions try to charge. Hold the ones that touch the outside world. A held task does not run, and you see what it would have done.', 'axle-staging-guard' ); ?></p>

		<?php if ( ! $guarded ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'This is the live site, so nothing is held here. Choices you save are used on staging copies of this database.', 'axle-staging-guard' ); ?></p></div>
		<?php endif; ?>

		<div class="axle-stats">
			<div class="axle-stat">
				<span class="axle-stat__value"><?php echo esc_html( number_format_i18n( count( $all ) ) ); ?></span>
				<span class="axle-stat__label"><?php esc_html_e( 'scheduled tasks', 'axle-staging-guard' ); ?></span>
			</div>
			<div class="axle-stat axle-stat--<?php echo $held ? 'good' : 'neutral'; ?>">
				<span class="axle-stat__value"><?php echo esc_html( number_format_i18n( count( $held ) ) ); ?></span>
				<span class="axle-stat__label"><?php esc_html_e( 'held on this copy', 'axle-staging-guard' ); ?></span>
			</div>
			<div class="axle-stat axle-stat--<?php echo $today ? 'warning' : 'neutral'; ?>">
				<span class="axle-stat__value"><?php echo esc_html( number_format_i18n( $today ) ); ?></span>
				<span class="axle-stat__label"><?php esc_html_e( 'held in the last 24 hours', 'axle-staging-guard' ); ?></span>
			</div>
		</div>

		<section class="axle-card asg-tasks">
			<h2 class="axle-card__title"><?php esc_html_e( 'Tasks to hold', 'axle-staging-guard' ); ?></h2>

			<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>" class="axle-toolbar">
				<input type="hidden" name="page" value="<?php echo esc_attr( SettingsPage::SLUG ); ?>">
				<input type="hidden" name="tab" value="tasks">
				<label for="asg-task-search" class="screen-reader-text"><?php esc_html_e( 'Search tasks', 'axle-staging-guard' ); ?></label>
				<input type="search" id="asg-task-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search tasks', 'axle-staging-guard' ); ?>">
				<label for="asg-task-view" class="screen-reader-text"><?php esc_html_e( 'Show', 'axle-staging-guard' ); ?></label>
				<select id="asg-task-view" name="view">
					<option value="all" <?php selected( 'all', $view ); ?>><?php esc_html_e( 'All tasks', 'axle-staging-guard' ); ?></option>
					<option value="suggested" <?php selected( 'suggested', $view ); ?>><?php esc_html_e( 'Suggested to hold', 'axle-staging-guard' ); ?></option>
					<option value="held" <?php selected( 'held', $view ); ?>><?php esc_html_e( 'Held', 'axle-staging-guard' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'axle-staging-guard' ); ?></button>
			</form>

			<?php if ( ! $all ) : ?>
				<div class="axle-empty">
					<p><?php esc_html_e( 'Nothing is scheduled yet. Tasks from WordPress and your plugins appear here once they are on the schedule, so you can hold the ones that reach other services.', 'axle-staging-guard' ); ?></p>
				</div>
			<?php elseif ( ! $rows ) : ?>
				<div class="axle-empty">
					<p><?php esc_html_e( 'No task matches. Clear the search or choose All tasks to see the whole schedule.', 'axle-staging-guard' ); ?></p>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'tasks' ), admin_url( 'tools.php?page=' . SettingsPage::SLUG ) ) ); ?>"><?php esc_html_e( 'Show all tasks', 'axle-staging-guard' ); ?></a>
				</div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="asg-tasks-form">
					<?php wp_nonce_field( self::ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="task" value="save_tasks">
					<?php foreach ( array_keys( $rows ) as $hook ) : ?>
						<input type="hidden" name="shown[]" value="<?php echo esc_attr( (string) $hook ); ?>">
					<?php endforeach; ?>
				</form>

				<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Scheduled tasks', 'axle-staging-guard' ); ?>">
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Hold', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Task', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Owner', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Next run', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Repeats', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Run', 'axle-staging-guard' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<?php
								$hook      = $row['hook'];
								$protected = $tasks->is_protected( $hook );
								$is_held   = in_array( $hook, $held, true );
								$ticked    = $tasks->is_ticked( $hook );
								$schedules = wp_get_schedules();
								$repeats   = '' === $row['schedule'] ? __( 'Once', 'axle-staging-guard' ) : (string) ( $schedules[ $row['schedule'] ]['display'] ?? $row['schedule'] );
								?>
								<tr class="asg-task-row" data-hook="<?php echo esc_attr( $hook ); ?>">
									<td>
										<input type="checkbox" form="asg-tasks-form" name="held[]" value="<?php echo esc_attr( $hook ); ?>" id="asg-hold-<?php echo esc_attr( md5( $hook ) ); ?>" <?php checked( $ticked ); ?> <?php disabled( $protected ); ?>>
										<label class="screen-reader-text" for="asg-hold-<?php echo esc_attr( md5( $hook ) ); ?>">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %s: name of a scheduled task. */
													__( 'Hold %s', 'axle-staging-guard' ),
													$hook
												)
											);
											?>
										</label>
									</td>
									<td>
										<code class="asg-hook"><?php echo esc_html( $hook ); ?></code>
										<?php if ( $row['count'] > 1 ) : ?>
											<br><small>
												<?php
												echo esc_html(
													sprintf(
														/* translators: %d: number of scheduled runs. */
														_n( '%d scheduled run', '%d scheduled runs', $row['count'], 'axle-staging-guard' ),
														$row['count']
													)
												);
												?>
											</small>
										<?php endif; ?>
										<?php if ( $row['args'] ) : ?>
											<br><small>
												<?php
												echo esc_html(
													sprintf(
														/* translators: %s: short description of a task's arguments. */
														__( 'Arguments: %s', 'axle-staging-guard' ),
														\AryanX\Axle\StagingGuard\Cron\Tasks::summarise_args( $hook, $row['args'] )
													)
												);
												?>
											</small>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( \AryanX\Axle\StagingGuard\Cron\Tasks::owner_label( $tasks->owner( $hook ) ) ); ?></td>
									<td><?php echo AxleUi::relative_time( (int) $row['next'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside relative_time(). ?></td>
									<td><?php echo esc_html( $repeats ); ?></td>
									<td>
										<?php if ( $protected ) : ?>
											<span class="axle-badge axle-badge--neutral"><?php esc_html_e( 'Protected', 'axle-staging-guard' ); ?></span>
										<?php elseif ( $is_held ) : ?>
											<span class="axle-badge axle-badge--warning"><?php esc_html_e( 'Held', 'axle-staging-guard' ); ?></span>
										<?php elseif ( $tasks->is_suggested( $hook ) ) : ?>
											<span class="axle-badge axle-badge--info"><?php echo $ticked ? esc_html__( 'Suggested, not saved yet', 'axle-staging-guard' ) : esc_html__( 'Suggested, left running', 'axle-staging-guard' ); ?></span>
										<?php else : ?>
											<span class="axle-badge axle-badge--neutral"><?php esc_html_e( 'Runs normally', 'axle-staging-guard' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php
										Confirm::render(
											array(
												'id'      => 'asg-run-' . md5( $hook ),
												'action'  => self::ACTION,
												'fields'  => array(
													'task' => 'run_task',
													'hook' => $hook,
												),
												'open'    => __( 'Run once', 'axle-staging-guard' ),
												/* translators: %s: name of a scheduled task. */
												'label'   => sprintf( __( 'Run once: %s', 'axle-staging-guard' ), $hook ),
												'message' => __( 'This runs the task now, on this copy, and it may contact the outside service it belongs to. It goes back on its schedule afterwards. This cannot be undone.', 'axle-staging-guard' ),
												'submit'  => __( 'Run once', 'axle-staging-guard' ),
												'cancel'  => __( 'Do not run', 'axle-staging-guard' ),
												'style'   => 'danger',
											)
										);
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="description"><?php esc_html_e( 'Held tasks stay on the schedule and show as late in Site Health. That is expected on a copy.', 'axle-staging-guard' ); ?></p>
				<p><button type="submit" class="button button-primary" form="asg-tasks-form"><?php esc_html_e( 'Save held tasks', 'axle-staging-guard' ); ?></button></p>
			<?php endif; ?>
		</section>

		<?php $this->render_log(); ?>
		<?php
	}

	/**
	 * Prints the list of held runs.
	 */
	private function render_log(): void {
		$log     = $this->hold->log();
		$entries = $log->all();
		?>
		<section class="axle-card asg-log">
			<h2 class="axle-card__title"><?php esc_html_e( 'Held tasks', 'axle-staging-guard' ); ?></h2>
			<?php if ( ! $entries ) : ?>
				<div class="axle-empty">
					<p><?php esc_html_e( 'Nothing has been held yet. When a task you hold comes due on this copy, it is listed here with when it was due and who owns it.', 'axle-staging-guard' ); ?></p>
				</div>
			<?php else : ?>
				<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Held tasks', 'axle-staging-guard' ); ?>">
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Held', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Task', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Owner', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Was due', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Arguments', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'What happened', 'axle-staging-guard' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $entries as $entry ) : ?>
								<tr class="asg-held-row">
									<td><?php echo AxleUi::relative_time( (int) $entry['time'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside relative_time(). ?></td>
									<td><code class="asg-hook"><?php echo esc_html( $entry['hook'] ); ?></code></td>
									<td><?php echo esc_html( \AryanX\Axle\StagingGuard\Cron\Tasks::owner_label( $entry['owner'] ) ); ?></td>
									<td><?php echo AxleUi::relative_time( (int) $entry['due'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside relative_time(). ?></td>
									<td><?php echo '' === $entry['args'] ? '<span class="axle-muted">' . esc_html__( 'None', 'axle-staging-guard' ) . '</span>' : esc_html( $entry['args'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped on both sides. ?></td>
									<td><span class="axle-badge axle-badge--warning"><?php esc_html_e( 'Held', 'axle-staging-guard' ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of days. */
							_n( 'Only the task name, the owner and a summary of its arguments are kept, never the values. Entries are deleted after %d day.', 'Only the task name, the owner and a summary of its arguments are kept, never the values. Entries are deleted after %d days.', $log->days(), 'axle-staging-guard' ),
							$log->days()
						)
					);
					?>
				</p>
			<?php endif; ?>
		</section>

		<?php if ( $entries ) : ?>
			<section class="axle-card axle-card--danger">
				<h2 class="axle-card__title"><?php esc_html_e( 'Clear the held list', 'axle-staging-guard' ); ?></h2>
				<p><?php esc_html_e( 'Removes every entry from the list above. The tasks themselves are not affected, and a task that is still held is listed again the next time it is due.', 'axle-staging-guard' ); ?></p>
				<?php
				Confirm::render(
					array(
						'id'      => 'asg-clear-held',
						'action'  => self::ACTION,
						'fields'  => array( 'task' => 'clear_held' ),
						'open'    => __( 'Clear list', 'axle-staging-guard' ),
						'message' => __( 'This removes every entry from the held list. The tasks are not affected. It cannot be undone.', 'axle-staging-guard' ),
						'submit'  => __( 'Clear list', 'axle-staging-guard' ),
						'cancel'  => __( 'Keep the list', 'axle-staging-guard' ),
						'class'   => 'button axle-button--danger',
					)
				);
				?>
			</section>
		<?php endif; ?>
		<?php
	}

	/**
	 * Handles the forms on the tab.
	 */
	public function handle_form(): void {
		if ( ! current_user_can( SettingsPage::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'axle-staging-guard' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		$args = array( 'tab' => 'tasks' );

		switch ( $task ) {
			case 'save_tasks':
				$shown = isset( $_POST['shown'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['shown'] ) ) : array();
				$ticks = isset( $_POST['held'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['held'] ) ) : array();
				$known = array_keys( $this->hold->tasks()->hooks() );

				// Only hooks that are scheduled can be ticked, and tasks that were not on the screen keep their state.
				$shown    = array_values( array_intersect( $shown, $known ) );
				$ticks    = array_values( array_intersect( $ticks, $shown ) );
				$previous = (array) $this->settings->get( 'cron_held' );
				$decided  = (array) $this->settings->get( 'cron_decided' );
				$kept     = array_diff( $previous, $shown );

				set_transient( $this->undo_key(), array( $previous, $decided ), 10 * MINUTE_IN_SECONDS );

				$saved              = $this->hold->save( array_merge( $kept, $ticks ), $shown );
				$args['sg_message'] = 'tasks_saved';
				$args['sg_count']   = count( $saved );
				$args['sg_undo']    = 'tasks';
				break;

			case 'run_task':
				$hook   = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
				$result = in_array( $hook, array_keys( $this->hold->tasks()->hooks() ), true ) ? $this->hold->run_once( $hook ) : false;
				if ( true === $result ) {
					$args['sg_message'] = 'task_ran';
				} else {
					$args['sg_error'] = 'not_scheduled';
				}
				break;

			case 'clear_held':
				$this->hold->log()->clear();
				$args['sg_message'] = 'tasks_cleared';
				break;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php?page=' . SettingsPage::SLUG ) ) );
		exit;
	}

	/**
	 * Name of the transient that keeps the last saved state for Undo.
	 *
	 * @return string
	 */
	public static function undo_key(): string {
		return 'axle_sg_undo_tasks_' . get_current_user_id();
	}
}
