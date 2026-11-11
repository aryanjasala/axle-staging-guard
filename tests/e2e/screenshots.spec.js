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
		post = wp( 'post', 'create', '--post_title=Spring menu is here', '--post_status=publish', '--post_content=Three new dishes and a lemon tart.', '--porcelain' );
	} );

	test.afterAll( () => {
		wp( 'option', 'delete', 'axle_staging_guard_settings' );
		wpEval( `foreach ( get_posts( array( 'post_type' => 'axle_sg_mail', 'post_status' => 'any', 'numberposts' => 500, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }` );
		wp( 'post', 'delete', post, '--force' );
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
} );
