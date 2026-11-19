const { test, expect, wp, wpEval } = require( './utils' );

const PAGE = '/wp-admin/tools.php?page=axle-staging-guard';
const OPTION = 'axle_staging_guard_settings';
const STRIPE = 'woocommerce_stripe_settings';

const reset = () => {
	wpEval( `delete_option( '${ OPTION }' ); delete_option( 'woocommerce_woocommerce_payments_settings' ); delete_option( 'wp_mail_smtp' ); update_option( '${ STRIPE }', array( 'enabled' => 'yes', 'testmode' => 'no', 'secret_key' => 'sk_live_keep_me', 'test_secret_key' => 'sk_test_x' ), false );` );
	// Administrators have no WooCommerce capability on a site without WooCommerce.
	wpEval( `get_role( 'administrator' )->add_cap( 'manage_woocommerce' );` );
};
// The live database copied to another domain, keeping the saved tick list.
const copy = () => wpEval( `$s = (array) get_option( '${ OPTION }', array() ); $s['production_hash'] = AryanX\\Axle\\StagingGuard\\Environment::hash( 'live.example.com' ); update_option( '${ OPTION }', $s, false );` );
const live = () => wpEval( `$s = (array) get_option( '${ OPTION }', array() ); $s['production_hash'] = AryanX\\Axle\\StagingGuard\\Environment::hash( wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT ) ); update_option( '${ OPTION }', $s, false );` );
const mode = () => wpEval( `echo get_option( '${ STRIPE }' )['testmode'];` );

test.describe( 'Test mode on every copy', () => {
	test.beforeEach( reset );
	test.afterAll( () => {
		wpEval( `delete_option( '${ OPTION }' ); delete_option( '${ STRIPE }' ); delete_option( 'wp_mail_smtp' ); get_role( 'administrator' )->remove_cap( 'manage_woocommerce' );` );
	} );

	test( 'ticked on live does nothing there, then a copy switches once, keeps the keys, and each item can be undone', async ( { page } ) => {
		live();
		await page.goto( PAGE );
		const card = page.locator( '#asg-test-mode' );
		await expect( card ).toContainText( 'Stripe for WooCommerce' );
		await expect( card.locator( '.axle-badge' ).first() ).toHaveText( 'Live' );
		await card.getByLabel( 'Stripe for WooCommerce' ).check();
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved' );
		await expect( card.getByLabel( 'Stripe for WooCommerce' ) ).toBeChecked();

		// On the live site nothing changes, however many requests come.
		await page.goto( '/wp-admin/' );
		expect( mode() ).toBe( 'no' );
		await expect( page.locator( '.asg-test-results' ) ).toHaveCount( 0 );

		// The database lands on a copy: the first request switches it.
		copy();
		await page.goto( '/wp-admin/index.php' );
		expect( mode() ).toBe( 'yes' );
		expect( wpEval( `echo get_option( '${ STRIPE }' )['secret_key'];` ) ).toBe( 'sk_live_keep_me' );
		const notice = page.locator( '.asg-notice' ).filter( { hasText: 'switched to test mode' } );
		await expect( notice ).toContainText( 'Stripe for WooCommerce' );

		// Switched once: a person who sets it live again on this copy is not overruled by the next request.
		wpEval( `$o = get_option( '${ STRIPE }' ); $o['testmode'] = 'no'; update_option( '${ STRIPE }', $o );` );
		await page.goto( '/wp-admin/index.php' );
		expect( mode() ).toBe( 'no' );
		wpEval( `$o = get_option( '${ STRIPE }' ); $o['testmode'] = 'yes'; update_option( '${ STRIPE }', $o );` );

		// The Guards tab keeps the table, and Undo is a row action that restores the old value.
		await page.goto( PAGE );
		const results = page.locator( '.asg-test-results' );
		await expect( results ).toContainText( 'Switched to test mode' );
		await results.getByRole( 'link', { name: 'Undo' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Undone' );
		expect( mode() ).toBe( 'no' );
		await page.goto( '/wp-admin/index.php' );
		expect( mode() ).toBe( 'no' );
		await page.goto( PAGE );
		await expect( page.locator( '.asg-test-results' ) ).toContainText( 'Undone: back to what it was' );
	} );

	test( '"Got it" hides the notice for good', async ( { page } ) => {
		live();
		wpEval( `$s = (array) get_option( '${ OPTION }', array() ); $s['test_mode'] = array( 'items' => array( 'stripe-for-woocommerce' ) ); update_option( '${ OPTION }', $s, false );` );
		copy();
		await page.goto( '/wp-admin/index.php' );
		const notice = page.locator( '.asg-notice' ).filter( { hasText: 'switched to test mode' } );
		await expect( notice ).toBeVisible();
		await notice.getByRole( 'link', { name: 'Got it' } ).click();
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '.asg-notice' ).filter( { hasText: 'switched to test mode' } ) ).toHaveCount( 0 );
		expect( mode() ).toBe( 'yes' );
	} );

	test( 'a person without the plugin\'s own capability does not see the item', async ( { page } ) => {
		live();
		wpEval( `get_role( 'administrator' )->remove_cap( 'manage_woocommerce' );` );
		await page.goto( PAGE );
		await expect( page.locator( '#asg-test-mode' ).getByLabel( 'Stripe for WooCommerce' ) ).toHaveCount( 0 );
		await expect( page.locator( '#asg-test-mode' ) ).toContainText( 'No supported plugin is set up' );
	} );

	test( 'Site Health lists live settings that remain on a copy', async ( { page } ) => {
		copy();
		await page.goto( '/wp-admin/site-health.php' );
		await page.getByRole( 'button', { name: /Live payment or mail settings are still on this copy/ } ).click();
		await expect( page.getByText( 'These plugins are not in test mode on this copy: Stripe for WooCommerce' ) ).toBeVisible();
	} );

	test( 'WP-CLI ticks on live, refuses to run there, and switches and undoes on a copy', () => {
		live();
		expect( wp( 'axle-staging-guard', 'test-mode', 'tick', 'stripe-for-woocommerce' ) ).toContain( 'Ticked: stripe-for-woocommerce' );
		expect( () => wp( 'axle-staging-guard', 'test-mode', 'run' ) ).toThrow( /live site/ );
		expect( mode() ).toBe( 'no' );
		expect( wp( 'axle-staging-guard', 'test-mode', 'list', '--format=json' ) ).toContain( '"ticked":"yes"' );

		copy();
		// The first request on the copy already switched it, so run has nothing left to do.
		expect( wp( 'axle-staging-guard', 'test-mode', 'run' ) ).toContain( 'Nothing to switch' );
		expect( mode() ).toBe( 'yes' );
		expect( wp( 'axle-staging-guard', 'test-mode', 'undo', 'stripe-for-woocommerce' ) ).toContain( 'back to what it was' );
		expect( mode() ).toBe( 'no' );
		expect( () => wp( 'axle-staging-guard', 'test-mode', 'tick', 'nonsense' ) ).toThrow( /Unknown item/ );
	} );

	test( 'abilities list the items, set the recipe and undo a switch', async ( { rest } ) => {
		live();
		const run = ( name, method, input ) => rest( method, `/wp-abilities/v1/abilities/axle-staging-guard/${ name }/run`, input ? { input } : undefined );

		const set = await run( 'set-test-mode-recipe', 'POST', { items: [ 'stripe-for-woocommerce' ] } );
		expect( set.status ).toBe( 200 );
		expect( set.body.items.find( ( item ) => item.id === 'stripe-for-woocommerce' ) ).toMatchObject( { ticked: true, mode: 'live' } );

		const refused = await run( 'apply-test-mode', 'POST' );
		expect( refused.status ).toBeGreaterThanOrEqual( 400 );
		expect( mode() ).toBe( 'no' );

		copy();
		expect( ( await rest( 'DELETE', '/wp-abilities/v1/abilities/axle-staging-guard/undo-test-mode/run?input[id]=stripe-for-woocommerce' ) ).status ).toBe( 200 );
		expect( mode() ).toBe( 'no' );
		const annotations = JSON.parse( wpEval( `echo wp_json_encode( array_map( function ( $n ) { return wp_get_ability( 'axle-staging-guard/' . $n )->get_meta()['annotations']; }, array( 'list-test-mode', 'undo-test-mode' ) ) );` ) );
		expect( annotations[ 0 ].readonly ).toBe( true );
		expect( annotations[ 1 ].destructive ).toBe( true );
	} );
} );
