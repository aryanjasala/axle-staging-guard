const { test, expect, wp, wpEval } = require( './utils' );

const PAGE = '/wp-admin/tools.php?page=axle-staging-guard&tab=tasks';
const OPTION = 'axle_staging_guard_settings';
const LOG = 'axle_sg_held';

const live = () => wp( 'option', 'delete', OPTION );
const staging = ( php = '' ) =>
	wpEval( `update_option( '${ OPTION }', array( 'production_hash' => AryanX\\Axle\\StagingGuard\\Environment::hash( 'live.example.com' ) ${ php ? ', ' + php : '' } ), false );` );
const clearHeld = () =>
	wpEval( `foreach ( get_posts( array( 'post_type' => '${ LOG }', 'post_status' => 'any', 'numberposts' => 500, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }` );

// Three tasks of the kind a real site has, owned by a must-use plugin so the owner can be traced.
const FIXTURE = `<?php
add_action( 'asg_e2e_weekly_newsletter_export', function ( $audience = '' ) { update_option( 'asg_e2e_ran', ( (int) get_option( 'asg_e2e_ran', 0 ) ) + 1, false ); }, 10, 1 );
add_action( 'asg_e2e_plain_cleanup', function () { update_option( 'asg_e2e_plain_ran', time(), false ); } );
`;
const install = () =>
	wpEval( `
		wp_mkdir_p( WPMU_PLUGIN_DIR );
		file_put_contents( WPMU_PLUGIN_DIR . '/asg-e2e-tasks.php', ${ JSON.stringify( FIXTURE ).replace( /\$/g, '\\$' ) } );
	` );
const schedule = () =>
	wpEval( `
		wp_clear_scheduled_hook( 'asg_e2e_weekly_newsletter_export', array( 'all-subscribers' ) );
		wp_clear_scheduled_hook( 'asg_e2e_plain_cleanup' );
		wp_schedule_event( time() - 300, 'weekly', 'asg_e2e_weekly_newsletter_export', array( 'all-subscribers' ) );
		wp_schedule_event( time() - 300, 'daily', 'asg_e2e_plain_cleanup' );
	` );
// Runs what WP-Cron would: the jobs WordPress reports as due, one by one, the way wp-cron.php does. Held hooks are left out of that list.
const runDue = () =>
	wpEval( `
		foreach ( wp_get_ready_cron_jobs() as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				foreach ( $events as $event ) {
					if ( $event['schedule'] ) { wp_reschedule_event( $timestamp, $event['schedule'], $hook, $event['args'] ); }
					wp_unschedule_event( $timestamp, $hook, $event['args'] );
					do_action_ref_array( $hook, $event['args'] );
				}
			}
		}
	` );
const ran = ( option ) => wpEval( `echo (int) get_option( '${ option }', 0 );` );

test.describe( 'Scheduled tasks', () => {
	test.beforeEach( () => {
		live();
		clearHeld();
		install();
		schedule();
		wpEval( `delete_option( 'asg_e2e_ran' ); delete_option( 'asg_e2e_plain_ran' ); delete_transient( 'axle_sg_held_tick' ); delete_user_meta( 1, 'axle_staging_guard_steps_dismissed' );` );
	} );

	test.afterAll( () => {
		live();
		clearHeld();
		wpEval( `
			wp_clear_scheduled_hook( 'asg_e2e_weekly_newsletter_export', array( 'all-subscribers' ) );
			wp_clear_scheduled_hook( 'asg_e2e_plain_cleanup' );
			@unlink( WPMU_PLUGIN_DIR . '/asg-e2e-tasks.php' );
			delete_option( 'asg_e2e_ran' ); delete_option( 'asg_e2e_plain_ran' );
		` );
	} );

	test( 'lists tasks with their owner and next run, and pre-ticks the suggested ones without holding them', async ( { page } ) => {
		staging();
		await page.goto( PAGE );

		const risky = page.locator( '.asg-task-row[data-hook="asg_e2e_weekly_newsletter_export"]' );
		await expect( risky ).toContainText( 'Must-use plugin: asg-e2e-tasks' );
		await expect( risky ).toContainText( 'Arguments: text (15 characters)' );
		await expect( risky ).toContainText( 'ago' );
		await expect( risky ).toContainText( 'Suggested, not saved yet' );
		await expect( risky.getByRole( 'checkbox' ) ).toBeChecked();
		await expect( page.locator( '.asg-task-row[data-hook="asg_e2e_plain_cleanup"]' ).getByRole( 'checkbox' ) ).not.toBeChecked();

		// Core's clean-up task is listed, protected and cannot be ticked.
		const core = page.locator( '.asg-task-row[data-hook="wp_scheduled_delete"]' );
		await expect( core ).toContainText( 'Protected' );
		await expect( core.getByRole( 'checkbox' ) ).toBeDisabled();

		// Nothing is enforced until it is saved.
		expect( wpEval( `echo count( (array) ( get_option( '${ OPTION }' )['cron_held'] ?? array() ) );` ) ).toBe( '0' );
		runDue();
		expect( ran( 'asg_e2e_ran' ) ).toBe( '1' );
	} );

	test( 'search and the suggested view narrow the list, and an empty result says what to do', async ( { page } ) => {
		staging();
		await page.goto( PAGE );
		await page.getByLabel( 'Show' ).selectOption( 'suggested' );
		await page.getByRole( 'button', { name: 'Filter' } ).click();
		await expect( page.locator( '.asg-task-row[data-hook="asg_e2e_plain_cleanup"]' ) ).toHaveCount( 0 );
		await expect( page.locator( '.asg-task-row[data-hook="asg_e2e_weekly_newsletter_export"]' ) ).toHaveCount( 1 );

		await page.getByLabel( 'Search tasks' ).fill( 'nothing-matches-this' );
		await page.getByRole( 'button', { name: 'Filter' } ).click();
		await expect( page.locator( '.asg-tasks .axle-empty' ) ).toContainText( 'No task matches' );
		await page.getByRole( 'link', { name: 'Show all tasks' } ).click();
		await expect( page.locator( '.asg-task-row' ).first() ).toBeVisible();
	} );

	test( 'a held task does not run, is listed once with its owner and a summary, and can be run once by hand', async ( { page } ) => {
		staging();
		await page.goto( PAGE );
		await page.getByRole( 'button', { name: 'Save held tasks' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Saved. 1 task is held on this copy.' );
		await expect( page.locator( '.asg-task-row[data-hook="asg_e2e_weekly_newsletter_export"]' ) ).toContainText( 'Held' );
		expect( JSON.parse( wpEval( `echo wp_json_encode( get_option( '${ OPTION }' )['cron_held'] );` ) ) ).toEqual( [ 'asg_e2e_weekly_newsletter_export' ] );

		// Cron comes by twice. The held task stays put, and the other one runs.
		runDue();
		runDue();
		expect( ran( 'asg_e2e_ran' ) ).toBe( '0' );
		expect( ran( 'asg_e2e_plain_ran' ) ).not.toBe( '0' );
		expect( wp( 'axle-staging-guard', 'hold', 'log', '--format=count' ) ).toBe( '1' );

		await page.goto( PAGE );
		const held = page.locator( '.asg-held-row' );
		await expect( held ).toHaveCount( 1 );
		await expect( held ).toContainText( 'asg_e2e_weekly_newsletter_export' );
		await expect( held ).toContainText( 'Must-use plugin: asg-e2e-tasks' );
		await expect( held ).toContainText( 'text (15 characters)' );
		await expect( held ).not.toContainText( 'all-subscribers' );

		// Run once asks in place, says what happens, then runs it and keeps its schedule.
		const row = page.locator( '.asg-task-row[data-hook="asg_e2e_weekly_newsletter_export"]' );
		await row.locator( '.asg-confirm-open' ).click();
		await expect( row.locator( '.axle-confirm' ) ).toContainText( 'may contact the outside service' );
		await row.getByRole( 'button', { name: 'Run once', exact: true } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'The task ran once and is back on its schedule.' );
		expect( ran( 'asg_e2e_ran' ) ).toBe( '1' );
		expect( wpEval( `echo wp_next_scheduled( 'asg_e2e_weekly_newsletter_export', array( 'all-subscribers' ) ) > time() ? 'later' : 'not later';` ) ).toBe( 'later' );
	} );

	test( 'a held task that WP-CLI runs is held too, and the plugin\'s own command can run it', () => {
		staging( `'cron_held' => array( 'asg_e2e_weekly_newsletter_export' )` );

		wp( 'cron', 'event', 'run', 'asg_e2e_weekly_newsletter_export' );
		expect( ran( 'asg_e2e_ran' ) ).toBe( '0' );
		expect( wp( 'axle-staging-guard', 'hold', 'log', '--format=json' ) ).toContain( 'asg_e2e_weekly_newsletter_export' );

		expect( wp( 'axle-staging-guard', 'hold', 'run', 'asg_e2e_weekly_newsletter_export' ) ).toContain( 'Ran asg_e2e_weekly_newsletter_export once.' );
		expect( ran( 'asg_e2e_ran' ) ).toBe( '1' );
		expect( () => wp( 'axle-staging-guard', 'hold', 'run', 'asg_e2e_not_scheduled' ) ).toThrow( /not scheduled/ );
	} );

	test( 'core hooks that keep the site healthy are never held, unless a filter says so', () => {
		staging( `'cron_held' => array( 'wp_scheduled_delete', 'wp_privacy_delete_old_export_files', 'wp_update_plugins' )` );
		expect( JSON.parse( wpEval( `echo wp_json_encode( ( new AryanX\\Axle\\StagingGuard\\Cron\\Tasks( new AryanX\\Axle\\StagingGuard\\Settings() ) )->held() );` ) ) ).toEqual( [ 'wp_update_plugins' ] );
		expect( JSON.parse( wpEval( `add_filter( 'axle_staging_guard_cron_protected_hooks', '__return_empty_array' ); add_filter( 'axle_staging_guard_cron_protected_prefixes', '__return_empty_array' ); echo wp_json_encode( ( new AryanX\\Axle\\StagingGuard\\Cron\\Tasks( new AryanX\\Axle\\StagingGuard\\Settings() ) )->held() );` ) ) ).toEqual( [ 'wp_scheduled_delete', 'wp_privacy_delete_old_export_files', 'wp_update_plugins' ] );
		expect( wp( 'axle-staging-guard', 'hold', 'add', 'wp_scheduled_delete' ) ).toContain( '0 task(s) held' );
	} );

	test( 'saving can be undone, and the live site never holds anything', async ( { page } ) => {
		staging();
		await page.goto( PAGE );
		await page.getByRole( 'button', { name: 'Save held tasks' } ).click();
		await page.locator( '.notice-success' ).getByRole( 'link', { name: 'Undo' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Undone.' );
		expect( wpEval( `echo count( (array) get_option( '${ OPTION }' )['cron_held'] );` ) ).toBe( '0' );
		// Back to "nothing decided", so the suggestion is ticked again.
		await expect( page.locator( '.asg-task-row[data-hook="asg_e2e_weekly_newsletter_export"]' ).getByRole( 'checkbox' ) ).toBeChecked();

		wpEval( `update_option( '${ OPTION }', array( 'cron_held' => array( 'asg_e2e_weekly_newsletter_export' ) ), false );` );
		clearHeld();
		runDue();
		expect( ran( 'asg_e2e_ran' ) ).toBe( '1' );
		expect( wp( 'axle-staging-guard', 'hold', 'log', '--format=count' ) ).toBe( '0' );
	} );

	test( 'Site Health asks about risky tasks nobody has decided on yet', async ( { page } ) => {
		staging();
		await page.goto( '/wp-admin/site-health.php' );
		await page.getByRole( 'button', { name: /Scheduled tasks that may reach the outside world are still running/ } ).click();
		await expect( page.getByText( /These tasks look like they send, export or sync data.*asg_e2e_weekly_newsletter_export/ ) ).toBeVisible();

		staging( `'cron_held' => array( 'asg_e2e_weekly_newsletter_export' ), 'cron_decided' => array( 'asg_e2e_weekly_newsletter_export' )` );
		await page.goto( '/wp-admin/site-health.php' );
		await page.getByRole( 'button', { name: /Passed tests/ } ).click();
		await expect( page.getByRole( 'button', { name: /Scheduled tasks on this copy are accounted for/ } ) ).toBeVisible();
	} );

	test( 'the held list is cleared in place, and WP-CLI lists, holds and releases', async ( { page } ) => {
		staging( `'cron_held' => array( 'asg_e2e_weekly_newsletter_export' )` );
		runDue();
		await page.goto( PAGE );
		await expect( page.locator( '.asg-held-row' ) ).toHaveCount( 1 );

		const danger = page.locator( '.axle-card--danger' );
		await danger.locator( '.asg-confirm-open' ).click();
		await expect( danger.locator( '.axle-confirm' ) ).toContainText( 'cannot be undone' );
		await danger.getByRole( 'button', { name: 'Clear list' } ).last().click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Held list cleared.' );
		await expect( page.locator( '.asg-log .axle-empty' ) ).toContainText( 'Nothing has been held yet' );

		const rows = JSON.parse( wp( 'axle-staging-guard', 'hold', 'list', '--format=json' ) );
		expect( rows.find( ( row ) => 'asg_e2e_weekly_newsletter_export' === row.hook ) ).toMatchObject( { status: 'held', owner: 'mu-plugin:asg-e2e-tasks', schedule: 'weekly' } );
		expect( rows.find( ( row ) => 'wp_scheduled_delete' === row.hook ).status ).toBe( 'protected' );
		wp( 'axle-staging-guard', 'hold', 'remove', 'asg_e2e_weekly_newsletter_export' );
		expect( JSON.parse( wp( 'axle-staging-guard', 'hold', 'list', '--format=json' ) ).find( ( row ) => 'asg_e2e_weekly_newsletter_export' === row.hook ).status ).toBe( 'suggested' );
		schedule();
		wp( 'axle-staging-guard', 'hold', 'add', 'asg_e2e_plain_cleanup' );
		expect( JSON.parse( wp( 'axle-staging-guard', 'hold', 'list', '--format=json' ) ).find( ( row ) => 'asg_e2e_plain_cleanup' === row.hook ).status ).toBe( 'held' );
	} );

	test( 'abilities: an agent can hold and release a task and read what was held', async ( { rest } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		const run = ( name, method, input ) => rest( method, `/wp-abilities/v1/abilities/axle-staging-guard/${ name }/run`, input ? { input } : undefined );
		staging();

		expect( ( await run( 'hold-task', 'POST', { hook: 'asg_e2e_weekly_newsletter_export', held: true } ) ).body ).toEqual( { held: [ 'asg_e2e_weekly_newsletter_export' ] } );
		expect( ( await run( 'hold-task', 'POST', { hook: 'wp_scheduled_delete', held: true } ) ).status ).toBe( 400 );
		runDue();
		const listed = ( await run( 'list-held-tasks', 'GET' ) ).body;
		expect( listed.held ).toEqual( [ 'asg_e2e_weekly_newsletter_export' ] );
		expect( listed.log ).toHaveLength( 1 );
		expect( listed.log[ 0 ] ).toMatchObject( { hook: 'asg_e2e_weekly_newsletter_export', owner: 'mu-plugin:asg-e2e-tasks' } );

		const cleared = await rest( 'DELETE', '/wp-abilities/v1/abilities/axle-staging-guard/clear-log/run?input[list]=held' );
		expect( cleared.body ).toEqual( { removed: 1 } );
		expect( ( await run( 'hold-task', 'POST', { hook: 'asg_e2e_weekly_newsletter_export', held: false } ) ).body ).toEqual( { held: [] } );
	} );
} );
