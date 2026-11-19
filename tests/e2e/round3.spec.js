const { request: playwrightRequest } = require( '@playwright/test' );
const { test, expect, wp, wpEval } = require( './utils' );
const { BASE_URL } = require( './utils/config' );

const OPTION = 'axle_staging_guard_settings';
const PASSWORD = 'open sesame 2026';
const HASH = "AryanX\\Axle\\StagingGuard\\Environment::hash( 'live.example.com' )";

const live = () => wp( 'option', 'delete', OPTION );
const gated = ( extra = '' ) =>
	wpEval( `update_option( '${ OPTION }', array( 'production_hash' => ${ HASH }, 'gate_enabled' => true, 'gate_secret' => 'e2e-secret-value-for-the-gate', 'gate_hash' => wp_hash_password( '${ PASSWORD }' ) ${ extra ? ', ' + extra : '' } ), false );` );
const visitor = () => playwrightRequest.newContext( { baseURL: BASE_URL, storageState: { cookies: [], origins: [] }, maxRedirects: 0 } );

test.describe( 'Round 3', () => {
	test.beforeEach( live );
	test.afterAll( live );

	test( 'other Axle plugins are told a copy found by the lock is staging, and the live site is production', () => {
		const ask = () => wpEval( 'echo AryanX\\Axle\\StagingGuard\\Admin\\AxleUi::environment_type();' );
		expect( ask() ).toBe( 'production' );
		wpEval( `update_option( '${ OPTION }', array( 'production_hash' => ${ HASH } ), false );` );
		expect( ask() ).toBe( 'staging' );
	} );

	test( 'a signed loopback request passes the gate, an unsigned or forged one does not, and nobody is signed in', async () => {
		gated();
		const header = wpEval( '$h = AryanX\\Axle\\StagingGuard\\Admin\\AxleUi::loopback_headers( home_url() ); echo $h["X-Axle-Loopback"];' );
		const anyone = await visitor();
		expect( ( await anyone.get( '/' ) ).status() ).toBe( 401 );
		const through = await anyone.get( '/', { headers: { 'X-Axle-Loopback': header } } );
		expect( through.status() ).toBe( 200 );
		// It passes the gate only: the page is the public one, not an admin session.
		expect( await through.text() ).not.toContain( 'wp-admin-bar' );
		expect( ( await anyone.get( '/wp-admin/index.php', { headers: { 'X-Axle-Loopback': header } } ) ).status() ).toBe( 302 );
		expect( ( await anyone.get( '/', { headers: { 'X-Axle-Loopback': header.replace( /.$/, header.endsWith( 'a' ) ? 'b' : 'a' ) } } ) ).status() ).toBe( 401 );
		await anyone.dispose();
	} );

	test( 'the gate also covers anonymous admin-ajax.php and wc-ajax requests, and logged-in people pass', async ( { request } ) => {
		gated();
		const anyone = await visitor();
		const ajax = await anyone.post( '/wp-admin/admin-ajax.php', { form: { action: 'heartbeat', _nonce: 'x' } } );
		expect( ajax.status() ).toBe( 401 );
		expect( await ajax.text() ).toContain( 'This is a staging site' );
		expect( ( await anyone.get( '/?wc-ajax=get_refreshed_fragments' ) ).status() ).toBe( 401 );
		await anyone.dispose();

		expect( ( await request.post( '/wp-admin/admin-ajax.php', { form: { action: 'heartbeat', _nonce: 'x' } } ) ).status() ).toBe( 200 );

		// An open path lets a webhook or monitor through.
		gated( "'gate_paths' => array( '/wp-admin/admin-ajax.php' )" );
		const open = await visitor();
		expect( ( await open.post( '/wp-admin/admin-ajax.php', { form: { action: 'heartbeat', _nonce: 'x' } } ) ).status() ).toBe( 200 );
		await open.dispose();
	} );

	test( 'the gate screen is WordPress\'s login screen, with a Log in link that returns to the page', async ( { browser } ) => {
		gated();
		const context = await browser.newContext( { baseURL: BASE_URL, storageState: { cookies: [], origins: [] } } );
		const page = await context.newPage();
		await page.goto( '/hello-world/' );
		await expect( page.locator( 'body.login #login' ) ).toBeVisible();
		await expect( page.locator( '#login-message' ) ).toContainText( 'not public yet' );
		await expect( page.getByRole( 'button', { name: 'Continue' } ) ).toHaveClass( /button-primary/ );
		expect( await page.content() ).not.toContain( 'prefers-color-scheme' );

		const login = page.getByRole( 'link', { name: 'Log in' } );
		await expect( login ).toHaveAttribute( 'href', /wp-login\.php\?redirect_to=.*hello-world/ );

		await page.getByLabel( 'Password' ).fill( 'wrong password' );
		await page.getByRole( 'button', { name: 'Continue' } ).click();
		await expect( page.locator( '#login_error' ) ).toContainText( 'That password is not right' );
		await context.close();
	} );

	test( 'a locked-out address sees the lockout on the same screen, with the no form', async ( { browser } ) => {
		test.setTimeout( 120000 );
		gated();
		wpEval( `global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%axle_sg_gate_fail_%'" );` );
		const context = await browser.newContext( { baseURL: BASE_URL, storageState: { cookies: [], origins: [] } } );
		const page = await context.newPage();
		await page.goto( '/' );
		for ( let i = 0; i < 5; i++ ) {
			await page.getByLabel( 'Password' ).fill( 'wrong ' + i );
			await Promise.all( [ page.waitForNavigation(), page.getByRole( 'button', { name: 'Continue' } ).click() ] );
		}
		await expect( page.locator( '#login_error' ) ).toContainText( 'Too many wrong passwords' );
		await expect( page.getByLabel( 'Password' ) ).toHaveCount( 0 );
		await context.close();
		wpEval( `global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%axle_sg_gate_fail_%'" );` );
	} );

	test( 'the admin bar label stays visible on a phone, as an icon', async ( { browser } ) => {
		wpEval( `update_option( '${ OPTION }', array( 'production_hash' => ${ HASH } ), false );` );
		const context = await browser.newContext( { baseURL: BASE_URL, storageState: require( './utils/config' ).STORAGE_STATE, viewport: { width: 390, height: 844 } } );
		const page = await context.newPage();
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '#wp-admin-bar-axle-staging-guard' ) ).toBeVisible();
		await expect( page.locator( '#wp-admin-bar-axle-staging-guard .ab-icon' ) ).toBeVisible();
		await expect( page.locator( '#wp-admin-bar-axle-staging-guard' ) ).toContainText( 'Staging' );
		await context.close();
	} );

	test( 'the Guards tab has one Save, and the logs and tools live on the Logs tab', async ( { page } ) => {
		wpEval( `update_option( '${ OPTION }', array( 'production_hash' => ${ HASH} ), false );` );
		await page.goto( '/wp-admin/tools.php?page=axle-staging-guard' );
		await expect( page.getByRole( 'button', { name: /^Save/ } ) ).toHaveCount( 1 );
		await expect( page.getByRole( 'link', { name: 'Logs' } ).first() ).toBeVisible();
		await expect( page.locator( '.nav-tab' ) ).toHaveCount( 4 );
		await expect( page.locator( '#asg-test-url, #asg-test-to, .asg-log' ) ).toHaveCount( 0 );

		await page.goto( '/wp-admin/tools.php?page=axle-staging-guard&tab=logs' );
		await expect( page.locator( '#asg-test-url' ) ).toBeVisible();
		await expect( page.locator( '#asg-test-to' ) ).toBeVisible();
		await expect( page.locator( '.asg-log' ) ).toHaveCount( 2 );
	} );
} );
