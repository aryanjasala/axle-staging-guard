// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp, wpEval } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );

test( 'demo: a staging copy blocks, then redirects, its email', async ( { browser } ) => {
	test.setTimeout( 180_000 );
	prepareSite( 'Example Shop' );
	// A copy of the live site at shop.example.com, so this domain counts as staging.
	wpEval( `update_option( 'axle_staging_guard_settings', array(
		'production_hash' => AryanX\\Axle\\StagingGuard\\Environment::hash( 'shop.example.com' ),
		'allowlist'       => array( '@youragency.com' ),
		'keep_bodies'     => true,
	), false );` );
	// The screens warn that only the lock makes this staging; hide that for the pictures.
	wpEval( `update_user_meta( 1, 'axle_staging_guard_lock_notice_dismissed', AryanX\\Axle\\StagingGuard\\Environment::hash( wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT ) ) );` );
	wpEval( `foreach ( get_posts( array( 'post_type' => 'axle_sg_mail', 'post_status' => 'any', 'numberposts' => 500, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }` );
	const post = wp( 'post', 'create', '--post_title=Spring menu is here', '--post_status=publish', '--post_content=Three new dishes and a lemon tart.', '--porcelain' );

	try {
		await recordDemo( browser, async ( page, demo ) => {
			await page.goto( '/' );
			await demo.start();
			await demo.pause( 1500 );
			await demo.click( page.locator( '#wp-admin-bar-axle-staging-guard > a' ), 300 );
			await expect( page.locator( '.axle-status .axle-badge' ) ).toHaveText( 'Staging' );
			await demo.pause( 1500 );

			// Email from a staging copy is stopped and logged.
			await demo.type( page.locator( '#asg-test-to' ), 'customer@example.com' );
			await demo.click( page.getByRole( 'button', { name: 'Send', exact: true } ), 300 );
			await expect( page.locator( '.asg-test-result' ) ).toContainText( 'Test email blocked' );
			await demo.moveTo( page.locator( '.asg-log__row' ).first().locator( '.axle-badge' ) );
			await demo.pause( 1800 );

			// Or sent to one test inbox instead.
			await page.locator( '#asg-redirect-to' ).scrollIntoViewIfNeeded();
			await demo.click( page.getByLabel( 'Send everything to one test address' ), 300 );
			await demo.type( page.locator( '#asg-redirect-to' ), 'qa@youragency.com' );
			await demo.click( page.getByRole( 'button', { name: 'Save settings' } ), 300 );
			await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved' );
			await page.evaluate( () => window.scrollTo( 0, 0 ) );
			await demo.moveTo( page.locator( '.asg-state' ).nth( 1 ) );
			await demo.pause( 1200 );

			await demo.type( page.locator( '#asg-test-to' ), 'customer@example.com' );
			await demo.click( page.getByRole( 'button', { name: 'Send', exact: true } ), 300 );
			await expect( page.locator( '.asg-log__row' ).first().locator( '.axle-badge' ) ).toHaveText( 'Redirected' );
			await demo.moveTo( page.locator( '.asg-log__row' ).first().locator( '.axle-badge' ) );
			await demo.pause( 2000 );
		} );
	} finally {
		wp( 'option', 'delete', 'axle_staging_guard_settings' );
		wpEval( `foreach ( get_posts( array( 'post_type' => 'axle_sg_mail', 'post_status' => 'any', 'numberposts' => 500, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }` );
		wp( 'post', 'delete', post, '--force' );
	}
} );
