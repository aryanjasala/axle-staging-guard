<?php
/**
 * Tests for the scheduled task list and the hold.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Tests\Unit;

use AryanX\Axle\StagingGuard\Cron\Hold;
use AryanX\Axle\StagingGuard\Cron\Log;
use AryanX\Axle\StagingGuard\Cron\Tasks;
use AryanX\Axle\StagingGuard\Mail\Log as MailLog;
use AryanX\Axle\StagingGuard\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class CronTasksTest extends TestCase {

	/**
	 * Entries written through wp_insert_post().
	 *
	 * @var array[]
	 */
	private array $listed = array();

	protected function setUp(): void {
		parent::setUp();

		$this->listed = array();
		$GLOBALS['axle_sg_cron'] = array();
		Functions\when( 'wp_normalize_path' )->alias( static fn( $path ) => str_replace( '\\', '/', $path ) );
		Functions\when( 'trailingslashit' )->alias( static fn( $path ) => rtrim( $path, '/' ) . '/' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_posts' )->alias(
			function ( array $args ) {
				foreach ( $this->listed as $id => $post ) {
					if ( ( $args['name'] ?? '' ) === $post['post_name'] ) {
						return array( $id );
					}
				}
				return array();
			}
		);
		Functions\when( 'wp_insert_post' )->alias(
			function ( array $post ) {
				$this->listed[ count( $this->listed ) + 1 ] = $post;
				return count( $this->listed );
			}
		);
		$_SERVER['argv'] = array( 'wp' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['axle_sg_cron'] );
		parent::tearDown();
	}

	private function schedule( array $events ): void {
		$GLOBALS['axle_sg_cron'] = $events;
	}

	private function tasks( array $saved = array() ): Tasks {
		$this->options[ Settings::OPTION ] = $saved;

		return new Tasks( new Settings() );
	}

	private function hold( array $saved = array() ): Hold {
		$this->options[ Settings::OPTION ] = $saved;
		$settings                          = new Settings();

		return new Hold( $settings, new Tasks( $settings ), new Log( new MailLog( $settings ) ) );
	}

	/**
	 * @dataProvider protected_hooks
	 */
	public function test_core_hooks_the_site_needs_are_never_held( string $hook ): void {
		$tasks = $this->tasks( array( 'cron_held' => array( $hook ) ) );

		$this->assertTrue( $tasks->is_protected( $hook ) );
		$this->assertSame( array(), $tasks->held() );
		$this->assertFalse( $tasks->is_suggested( $hook ) );
		$this->assertFalse( $tasks->is_ticked( $hook ) );
	}

	public function protected_hooks(): array {
		return array(
			'scheduled delete'   => array( 'wp_scheduled_delete' ),
			'auto draft delete'  => array( 'wp_scheduled_auto_draft_delete' ),
			'privacy files'      => array( 'wp_privacy_delete_old_export_files' ),
			'privacy any'        => array( 'wp_privacy_something_new' ),
			'recovery keys'      => array( 'recovery_mode_clean_expired_keys' ),
			'own purge'          => array( 'axle_staging_guard_purge_log' ),
		);
	}

	public function test_a_filter_can_allow_holding_a_protected_hook(): void {
		Filters\expectApplied( 'axle_staging_guard_cron_protected_hooks' )->andReturn( array() );

		$this->assertTrue( $this->tasks( array( 'cron_held' => array( 'wp_scheduled_delete' ) ) )->held() === array( 'wp_scheduled_delete' ) );
	}

	public function test_update_checks_can_be_held(): void {
		$tasks = $this->tasks( array( 'cron_held' => array( 'wp_update_plugins' ) ) );

		$this->assertSame( array( 'wp_update_plugins' ), $tasks->held() );
	}

	/**
	 * @dataProvider risky_names
	 */
	public function test_names_that_suggest_the_outside_world_are_suggested( string $hook, bool $expected ): void {
		$this->assertSame( $expected, $this->tasks()->is_suggested( $hook ) );
	}

	public function risky_names(): array {
		return array(
			'newsletter'            => array( 'weekly_newsletter_export', true ),
			'export'                => array( 'my_plugin_export_orders', true ),
			'sync'                  => array( 'crm_Sync_contacts', true ),
			'feed'                  => array( 'refresh_product_feed', true ),
			'renewal'               => array( 'membership_renewal_check', true ),
			'subscription payment'  => array( 'woocommerce_scheduled_subscription_payment', true ),
			'payment only'          => array( 'clear_payment_cache', false ),
			'plain'                 => array( 'wp_version_check', false ),
		);
	}

	public function test_suggestions_are_ticked_but_nothing_is_held_until_saved(): void {
		$tasks = $this->tasks();

		$this->assertTrue( $tasks->is_ticked( 'weekly_newsletter_export' ) );
		$this->assertSame( array(), $tasks->held(), 'A suggestion enforces nothing.' );
	}

	public function test_a_decision_the_person_saved_wins_over_the_suggestion(): void {
		$tasks = $this->tasks(
			array(
				'cron_held'    => array(),
				'cron_decided' => array( 'weekly_newsletter_export' ),
			)
		);

		$this->assertFalse( $tasks->is_ticked( 'weekly_newsletter_export' ), 'They unticked it on purpose.' );
	}

	public function test_a_bad_suggestion_pattern_matches_nothing(): void {
		Filters\expectApplied( 'axle_staging_guard_cron_suggestions' )->andReturn( array( '(unclosed' ) );

		$this->assertFalse( $this->tasks()->is_suggested( 'anything' ) );
	}

	public function test_hooks_are_listed_soonest_first_with_their_schedule_and_arguments(): void {
		$this->schedule(
			array(
				100 => array(
					'a_job' => array(
						'k1' => array(
							'schedule' => 'daily',
							'args'     => array( 5, 'x' ),
							'interval' => 86400,
						),
					),
				),
				200 => array(
					'a_job' => array(
						'k2' => array(
							'schedule' => 'daily',
							'args'     => array(),
							'interval' => 86400,
						),
					),
					'b_job' => array(
						'k3' => array(
							'schedule' => false,
							'args'     => array(),
						),
					),
				),
			)
		);

		$list = $this->tasks()->hooks();

		$this->assertSame( array( 'a_job', 'b_job' ), array_keys( $list ) );
		$this->assertSame( 100, $list['a_job']['next'] );
		$this->assertSame( 2, $list['a_job']['count'] );
		$this->assertSame( 'daily', $list['a_job']['schedule'] );
		$this->assertSame( '', $list['b_job']['schedule'] );
	}

	public function test_arguments_are_summarised_without_their_values(): void {
		$summary = Tasks::summarise_args( 'job', array( 42, true, 'jane@example.test', array( 1, 2 ), new \stdClass(), null ) );

		$this->assertStringContainsString( '42', $summary );
		$this->assertStringContainsString( 'true', $summary );
		$this->assertStringContainsString( 'text (17 characters)', $summary );
		$this->assertStringContainsString( 'list (2 items)', $summary );
		$this->assertStringContainsString( 'stdClass', $summary );
		$this->assertStringNotContainsString( 'jane', $summary );
	}

	public function test_owners_are_named_from_the_file(): void {
		$roots = array(
			'plugin'    => '/var/www/wp-content/plugins',
			'mu-plugin' => '/var/www/wp-content/mu-plugins',
			'theme'     => '/var/www/wp-content/themes',
		);

		$this->assertSame( 'plugin:woocommerce', Tasks::owner_from_file( '/var/www/wp-content/plugins/woocommerce/includes/a.php', $roots, '/var/www/' ) );
		$this->assertSame( 'plugin:hello', Tasks::owner_from_file( '/var/www/wp-content/plugins/hello.php', $roots, '/var/www/' ) );
		$this->assertSame( 'theme:twentytwentyfive', Tasks::owner_from_file( '/var/www/wp-content/themes/twentytwentyfive/functions.php', $roots, '/var/www/' ) );
		$this->assertSame( 'core', Tasks::owner_from_file( '/var/www/wp-includes/update.php', $roots, '/var/www/' ) );
		$this->assertSame( '', Tasks::owner_from_file( '/elsewhere/x.php', $roots, '/var/www/' ) );
	}

	public function test_a_callback_s_file_is_found(): void {
		$this->assertSame( __FILE__, Tasks::callback_file( array( $this, 'test_a_callback_s_file_is_found' ) ) );
		$this->assertSame( __FILE__, Tasks::callback_file( static function () {} ) );
		$this->assertSame( '', Tasks::callback_file( 'no_such_function_here' ) );
	}

	public function test_due_held_tasks_are_left_out_of_what_cron_runs_and_listed_once(): void {
		$this->schedule(
			array(
				time() - 60 => array(
					'weekly_newsletter_export' => array( 'k1' => array( 'schedule' => 'weekly', 'args' => array( 7 ) ) ),
					'wp_version_check'         => array( 'k2' => array( 'schedule' => 'twicedaily', 'args' => array() ) ),
				),
				time() + 3600 => array(
					'later_job' => array( 'k3' => array( 'schedule' => false, 'args' => array() ) ),
				),
			)
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Actions\expectDone( 'axle_staging_guard_cron_held' )->once();

		$hold  = $this->hold( array( 'cron_held' => array( 'weekly_newsletter_export' ) ) );
		$ready = $hold->ready_jobs( null );

		$this->assertCount( 1, $ready, 'Only the due time that still has work is returned.' );
		$jobs = array_values( $ready )[0];
		$this->assertArrayHasKey( 'wp_version_check', $jobs );
		$this->assertArrayNotHasKey( 'weekly_newsletter_export', $jobs );
		$this->assertCount( 1, $this->listed );
		$this->assertSame( 'weekly_newsletter_export', $this->listed[1]['post_title'] );
	}

	public function test_nothing_changes_when_nothing_is_held_or_another_filter_answered(): void {
		$hold = $this->hold();
		$this->assertNull( $hold->ready_jobs( null ) );

		$held = $this->hold( array( 'cron_held' => array( 'x_job' ) ) );
		$this->assertSame( array( 'other' ), $held->ready_jobs( array( 'other' ) ) );
	}

	public function test_the_same_run_is_listed_once_and_arguments_tell_runs_apart(): void {
		$log = new Log( new MailLog( new Settings() ) );
		$row = array(
			'hook'  => 'x_job',
			'due'   => 100,
			'args'  => 'text (3 characters)',
			'owner' => '',
		);

		$this->assertGreaterThan( 0, $log->add( $row, array( 'abc' ) ) );
		$this->assertSame( 0, $log->add( $row, array( 'abc' ) ) );
		$this->assertGreaterThan( 0, $log->add( $row, array( 'abd' ) ) );
	}

	public function test_saving_drops_protected_hooks_and_remembers_what_was_decided(): void {
		Actions\expectDone( 'axle_staging_guard_cron_hold_changed' )->once();

		$hold  = $this->hold( array( 'cron_held' => array( 'old_job' ) ) );
		$saved = $hold->save( array( 'new_job', 'wp_scheduled_delete', 'new_job' ), array( 'new_job', 'old_job', 'wp_scheduled_delete' ) );

		$this->assertSame( array( 'new_job' ), $saved );
		$this->assertEqualsCanonicalizing( array( 'new_job', 'old_job', 'wp_scheduled_delete' ), $this->options[ Settings::OPTION ]['cron_decided'] );
	}

	public function test_running_once_reschedules_a_repeating_task_and_drops_a_single_event(): void {
		$this->schedule(
			array(
				500 => array(
					'repeat_job' => array( 'k1' => array( 'schedule' => 'daily', 'args' => array( 1 ) ) ),
					'single_job'  => array( 'k2' => array( 'schedule' => false, 'args' => array() ) ),
				),
			)
		);
		Functions\expect( 'wp_reschedule_event' )->once()->with( 500, 'daily', 'repeat_job', array( 1 ) );
		Functions\expect( 'wp_unschedule_event' )->once()->with( 500, 'repeat_job', array( 1 ) );
		Functions\expect( 'wp_unschedule_event' )->once()->with( 500, 'single_job', array() );
		Actions\expectDone( 'axle_staging_guard_cron_run_once' )->twice();
		Actions\expectDone( 'repeat_job' )->once()->with( 1 );
		Actions\expectDone( 'single_job' )->once();

		$hold = $this->hold();

		$this->assertTrue( $hold->run_once( 'repeat_job' ) );
		$this->assertTrue( $hold->run_once( 'single_job' ) );
		$this->assertSame( 'axle_staging_guard_cron_not_scheduled', $hold->run_once( 'missing_job' )->get_error_code() );
	}
}
