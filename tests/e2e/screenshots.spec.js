// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp, wpEval } = require( './utils' );
const { prepareSite, shot } = require( './utils/media' );

test.describe( 'Screenshots', () => {
	test.use( { viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
	let post;

	test.beforeAll( () => {
		prepareSite( 'Example Shop' );
		// A copy of a live site that runs on another domain, with a team allowlist and kept bodies.
		wpEval( `update_option( 'axle_staging_guard_settings', array(
			'production_hash' => AryanX\\Axle\\StagingGuard\\Environment::hash( 'shop.example.com' ),
			'allowlist'       => array( '@youragency.com' ),
			'keep_bodies'     => true,
		), false );` );
		// The screens warn that only the lock makes this staging; hide that for the pictures.
		wpEval( `update_user_meta( 1, 'axle_staging_guard_lock_notice_dismissed', AryanX\\Axle\\StagingGuard\\Environment::hash( wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT ) ) );` );
		wpEval( `foreach ( get_posts( array( 'post_type' => 'axle_sg_mail', 'post_status' => 'any', 'numberposts' => 500, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }` );
		wpEval( `
			$from = 'From: Example Shop <shop@example.com>';
			wp_mail( 'sam.lee@example.net', 'Your order #1042 has shipped', "Hi Sam,\\n\\nYour order is on its way.", $from );
			wp_mail( array( 'priya@example.org', 'dev@youragency.com' ), 'New comment on "Spring menu"', 'A new comment is waiting for approval.', $from );
			wp_mail( 'admin@example.com', '[Example Shop] Password reset', 'Someone requested a password reset for your account.', $from );
		` );
		// A gate with a password, two share links, and three tasks of the kind a shop has, one of them held.
		wpEval( `
			$settings = new AryanX\\Axle\\StagingGuard\\Settings();
			$settings->update( array(
				'gate_enabled' => true,
				'gate_hash'    => wp_hash_password( 'open sesame 2026' ),
				'gate_ips'     => array( '203.0.113.0/24' ),
				'gate_paths'   => array( '/health', '/webhooks/*' ),
				'cron_held'    => array( 'mailchimp_weekly_newsletter_export' ),
				'cron_decided' => array( 'mailchimp_weekly_newsletter_export' ),
			) );
			$links = new AryanX\\Axle\\StagingGuard\\Gate\\Links( $settings );
			$links->create( 'For Priya at the client', 7, 1 );
			$links->create( 'For the design agency', 3, 1 );
			$made = $links->all();
			$settings->update( array( 'gate_links' => array_map( function ( $link ) { $link['last_used'] = 'For Priya at the client' === $link['name'] ? time() - 2 * HOUR_IN_SECONDS : 0; $link['uses'] = $link['last_used'] ? 4 : 0; return $link; }, $made ) ), false );
			foreach ( array( 'mailchimp_weekly_newsletter_export' => 'weekly', 'woocommerce_scheduled_subscription_payment' => 'hourly', 'shop_cleanup_old_carts' => 'daily' ) as $hook => $recurrence ) {
				wp_clear_scheduled_hook( $hook );
				wp_schedule_event( time() - 600, $recurrence, $hook );
			}
			delete_transient( 'axle_sg_held_tick' );
			$tasks = new AryanX\\Axle\\StagingGuard\\Cron\\Tasks( $settings );
			( new AryanX\\Axle\\StagingGuard\\Cron\\Hold( $settings, $tasks, new AryanX\\Axle\\StagingGuard\\Cron\\Log( new AryanX\\Axle\\StagingGuard\\Mail\\Log( $settings ) ) ) )->ready_jobs( null );
		` );
		post = wp( 'post', 'create', '--post_title=Spring menu is here', '--post_status=publish', '--post_content=Three new dishes and a lemon tart.', '--porcelain' );
	} );

	test.afterAll( () => {
		wp( 'option', 'delete', 'axle_staging_guard_settings' );
		wpEval( `foreach ( get_posts( array( 'post_type' => 'axle_sg_mail', 'post_status' => 'any', 'numberposts' => 500, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }` );
		wp( 'post', 'delete', post, '--force' );
		wpEval( `
			foreach ( get_posts( array( 'post_type' => 'axle_sg_held', 'post_status' => 'any', 'numberposts' => 500, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }
			foreach ( array( 'mailchimp_weekly_newsletter_export', 'woocommerce_scheduled_subscription_payment', 'shop_cleanup_old_carts' ) as $hook ) { wp_clear_scheduled_hook( $hook ); }
			delete_transient( 'axle_sg_held_tick' );
		` );
	} );

	test( '1: the coloured admin bar on a staging copy', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#wp-admin-bar-axle-staging-guard' ) ).toHaveText( 'Staging' );
		await shot( page, 1, { clip: { x: 0, y: 0, width: 1280, height: 480 } } );
	} );

	test( '2: Tools > Staging Guard with the email log', async ( { page } ) => {
		await page.goto( '/wp-admin/tools.php?page=axle-staging-guard' );
		await expect( page.locator( '.asg-log__row' ) ).toHaveCount( 3 );
		await shot( page, 2, { fullPage: true } );
	} );

	test( '3: the password screen a visitor sees', async ( { browser } ) => {
		const context = await browser.newContext( { viewport: { width: 1280, height: 720 }, deviceScaleFactor: 2, storageState: { cookies: [], origins: [] } } );
		const page = await context.newPage();
		await page.goto( '/' );
		await expect( page.locator( 'input[type=password]' ) ).toBeVisible();
		await shot( page, 3, { clip: { x: 340, y: 130, width: 600, height: 460 } } );
		await context.close();
	} );

	test( '4: share links, named and timed', async ( { page } ) => {
		await page.goto( '/wp-admin/tools.php?page=axle-staging-guard&tab=gate' );
		await expect( page.locator( '.asg-link-row' ) ).toHaveCount( 2 );
		await shot( page, 4, { fullPage: true } );
	} );

	test( '5: scheduled tasks, with the ones held on staging', async ( { page } ) => {
		await page.goto( '/wp-admin/tools.php?page=axle-staging-guard&tab=tasks' );
		await expect( page.locator( '.asg-held-row' ) ).toHaveCount( 1 );
		await shot( page, 5, { fullPage: true } );
	} );
} );
