const { request: playwrightRequest } = require( '@playwright/test' );
const { test, expect, wp, wpEval, appPasswordClient } = require( './utils' );
const { BASE_URL } = require( './utils/config' );

const PAGE = '/wp-admin/tools.php?page=axle-staging-guard&tab=gate';
const OPTION = 'axle_staging_guard_settings';
const PASSWORD = 'open sesame 2026';

const live = () => wp( 'option', 'delete', OPTION );
// A copy of the live database on another domain, with the gate settings given as PHP.
const staging = ( php = '' ) =>
	wpEval( `update_option( '${ OPTION }', array( 'production_hash' => AryanX\\Axle\\StagingGuard\\Environment::hash( 'live.example.com' ) ${ php ? ', ' + php : '' } ), false );` );
// Staging with the gate on and a password saved the way the plugin saves it.
const gated = ( extra = '' ) => {
	staging( `'gate_enabled' => true, 'gate_secret' => 'e2e-secret-value-for-the-gate', 'gate_hash' => wp_hash_password( '${ PASSWORD }' ) ${ extra ? ', ' + extra : '' }` );
};

// A visitor with no login and no cookies.
const visitor = () => playwrightRequest.newContext( { baseURL: BASE_URL, storageState: { cookies: [], origins: [] }, maxRedirects: 0 } );

const settings = () => JSON.parse( wpEval( `echo wp_json_encode( get_option( '${ OPTION }' ) );` ) );

test.describe( 'Password gate', () => {
	test.beforeEach( () => {
		live();
		wpEval( `delete_transient( 'axle_sg_held_tick' ); global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%axle_sg_gate_fail_%'" );` );
	} );

	test.afterAll( () => {
		live();
	} );

	test( 'is off by default: staging stays open, and Site Health says so', async ( { page } ) => {
		staging();
		const anyone = await visitor();
		expect( ( await anyone.get( '/' ) ).status() ).toBe( 200 );
		await anyone.dispose();

		await page.goto( '/wp-admin/site-health.php' );
		await page.getByRole( 'button', { name: /staging copy is open to anyone with its address/ } ).click();
		await expect( page.getByText( 'Turn on the password gate to keep them out' ) ).toBeVisible();
	} );

	test( 'a first visit shows what to do, and the tab saves a password and turns the gate on with an undo', async ( { page } ) => {
		staging();
		await page.goto( PAGE );
		await expect( page.getByRole( 'link', { name: 'Password gate' } ).first() ).toHaveAttribute( 'aria-current', 'page' );
		await expect( page.locator( '.axle-stat' ).first() ).toContainText( 'Off' );
		await expect( page.locator( '.asg-links .axle-empty' ) ).toContainText( 'No share links yet' );

		// Turning it on without a password says what to do and changes nothing.
		await page.getByLabel( 'Ask for a password before showing this copy' ).check();
		await page.getByRole( 'button', { name: 'Save gate settings' } ).click();
		await expect( page.locator( '.notice-error' ) ).toContainText( 'Set a password first' );
		expect( settings().gate_enabled ).toBeFalsy();

		// A short password is refused before anything is saved.
		await page.getByLabel( 'Ask for a password before showing this copy' ).check();
		await page.locator( '#asg-gate-password' ).fill( 'short' );
		await page.getByRole( 'button', { name: 'Save gate settings' } ).click();
		await expect( page.locator( '.notice-error' ) ).toContainText( 'at least 8 characters' );
		expect( settings().gate_hash ).toBe( '' );

		await page.getByLabel( 'Ask for a password before showing this copy' ).check();
		await page.locator( '#asg-gate-password' ).fill( PASSWORD );
		await page.locator( '#asg-gate-ips' ).fill( '203.0.113.0/24\nnot an address' );
		await page.locator( '#asg-gate-paths' ).fill( 'health\n/webhooks/*' );
		await page.getByRole( 'button', { name: 'Save gate settings' } ).click();

		const notice = page.locator( '.notice-success' );
		await expect( notice ).toContainText( 'The gate is on.' );
		await expect( page.locator( '.axle-stat' ).first() ).toContainText( 'On' );
		const saved = settings();
		expect( saved.gate_enabled ).toBe( true );
		expect( saved.gate_hash ).not.toContain( PASSWORD );
		expect( saved.gate_ips ).toEqual( [ '203.0.113.0/24' ] );
		expect( saved.gate_paths ).toEqual( [ '/health', '/webhooks/*' ] );

		// Undo turns it back off, and says so.
		await notice.getByRole( 'link', { name: 'Undo' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Undone.' );
		expect( settings().gate_enabled ).toBe( false );
		expect( settings().gate_hash ).toBe( saved.gate_hash );
	} );

	test( 'visitors see a 401 screen that is not cached or indexed, and the password lets them in', async () => {
		gated();
		const anyone = await visitor();

		const blocked = await anyone.get( '/?s=secret' );
		expect( blocked.status() ).toBe( 401 );
		expect( blocked.headers()[ 'x-robots-tag' ] ).toBe( 'noindex, nofollow' );
		expect( blocked.headers()[ 'cache-control' ] ).toContain( 'no-store' );
		const body = await blocked.text();
		expect( body ).toContain( 'name="robots" content="noindex, nofollow, noarchive"' );
		expect( body ).toContain( 'type="password"' );
		expect( body ).not.toContain( '/wp-content/themes/' );
		expect( ( await anyone.get( '/hello-world/' ) ).status() ).toBe( 401 );
		expect( ( await anyone.post( '/', { form: { whatever: '1' } } ) ).status() ).toBe( 401 );

		// Always open: robots.txt and the login screen.
		expect( ( await anyone.get( '/robots.txt' ) ).status() ).toBe( 200 );
		expect( ( await anyone.get( '/wp-login.php' ) ).status() ).toBe( 200 );

		// Wrong password: the screen says so and counts the try.
		const nonce = ( await ( await anyone.get( '/' ) ).text() ).match( /name="_wpnonce" value="([^"]+)"/ )[ 1 ];
		const wrong = await anyone.post( '/', { form: { axle_gate_password: 'nope', _wpnonce: nonce } } );
		expect( wrong.status() ).toBe( 401 );
		expect( await wrong.text() ).toContain( 'That password is not right.' );

		// A stale form is not counted against the visitor.
		const stale = await anyone.post( '/', { form: { axle_gate_password: PASSWORD, _wpnonce: 'bad' } } );
		expect( await stale.text() ).toContain( 'open for too long' );

		const right = await anyone.post( '/hello-world/', { form: { axle_gate_password: PASSWORD, _wpnonce: nonce } } );
		expect( right.status() ).toBe( 303 );
		expect( right.headers()[ 'set-cookie' ] ).toMatch( /httponly/i );
		expect( right.headers().location ).toContain( '/hello-world/' );
		expect( ( await anyone.get( '/' ) ).status() ).toBe( 200 );
		await anyone.dispose();
	} );

	test( 'five wrong passwords lock an address out, even for the right one', async () => {
		gated();
		const anyone = await visitor();
		const nonce = ( await ( await anyone.get( '/' ) ).text() ).match( /name="_wpnonce" value="([^"]+)"/ )[ 1 ];

		for ( let i = 0; i < 5; i++ ) {
			await anyone.post( '/', { form: { axle_gate_password: 'wrong ' + i, _wpnonce: nonce } } );
		}
		const locked = await anyone.post( '/', { form: { axle_gate_password: PASSWORD, _wpnonce: nonce } } );
		expect( locked.status() ).toBe( 429 );
		expect( locked.headers()[ 'retry-after' ] ).toBeTruthy();
		expect( await locked.text() ).toContain( 'Too many wrong passwords' );
		expect( ( await anyone.get( '/' ) ).status() ).toBe( 429 );
		// The counter holds a hash, never the address.
		expect( wpEval( `global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_axle_sg_gate_fail_%' AND option_name LIKE '%.%'" );` ) ).toBe( '0' );
		await anyone.dispose();
	} );

	test( 'logged-in users, listed IP ranges and listed paths skip the gate', async ( { page } ) => {
		gated( `'gate_paths' => array( '/health', '/webhooks/*' )` );
		await page.goto( '/' );
		await expect( page.locator( 'body' ) ).not.toContainText( 'password needed' );

		const anyone = await visitor();
		// Open paths reach WordPress, which has no such page, instead of the gate.
		expect( ( await anyone.get( '/health' ) ).status() ).toBe( 404 );
		expect( ( await anyone.get( '/webhooks/stripe' ) ).status() ).toBe( 404 );
		expect( ( await anyone.get( '/healthy' ) ).status() ).toBe( 401 );
		expect( ( await anyone.get( '/health/../about' ) ).status() ).toBe( 401 );

		gated( `'gate_ips' => array( '203.0.113.7', '0.0.0.0/0', '::/0' )` );
		expect( ( await anyone.get( '/' ) ).status() ).toBe( 200 );
		gated( `'gate_ips' => array( '203.0.113.7', '2001:db8::/32' )` );
		expect( ( await anyone.get( '/' ) ).status() ).toBe( 401 );
		await anyone.dispose();
	} );

	test( 'the REST API needs a login or an application password, and listed routes stay open', async () => {
		wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/asg-e2e-app-passwords.php', "<?php add_filter( 'wp_is_application_passwords_available', '__return_true' );" );` );
		try {
			gated( `'gate_paths' => array( '/wp-json/wp/v2/types' )` );
			const anyone = await visitor();
			const blocked = await anyone.get( '/wp-json/wp/v2/posts' );
			expect( blocked.status() ).toBe( 401 );
			expect( ( await blocked.json() ).code ).toBe( 'axle_staging_guard_gate' );
			expect( ( await anyone.get( '/?rest_route=/wp/v2/posts' ) ).status() ).toBe( 401 );
			expect( ( await anyone.get( '/wp-json/wp/v2/types' ) ).status() ).toBe( 200 );
			await anyone.dispose();

			const client = await appPasswordClient( 'admin', 'Staging Guard gate test' );
			try {
				expect( ( await client.call( 'GET', '/wp/v2/posts' ) ).status ).toBe( 200 );
			} finally {
				await client.dispose();
			}
		} finally {
			wpEval( `unlink( WPMU_PLUGIN_DIR . '/asg-e2e-app-passwords.php' );` );
		}
	} );

	test( 'a share link is named, timed, shown with its last use and revoked in place', async ( { page, browser } ) => {
		gated();
		await page.goto( PAGE );

		// Nothing is made without a name.
		await page.locator( '#asg-link-days' ).selectOption( '3' );
		await page.locator( '#asg-link-name' ).fill( 'For Priya at the client' );
		await page.getByRole( 'button', { name: 'Create link' } ).click();

		const created = page.locator( '.asg-link-created' );
		await expect( created ).toContainText( 'Link made: For Priya at the client' );
		const url = await page.locator( '#asg-new-link' ).inputValue();
		expect( url ).toContain( 'axle-gate-link=' );
		await expect( page.locator( '.asg-link-row' ) ).toHaveCount( 1 );
		await expect( page.locator( '.asg-link-row' ) ).toContainText( 'Never used' );
		await expect( page.locator( '.asg-link-row' ) ).toContainText( 'in 3 days' );

		// A JavaScript filter can change what is announced after copying.
		await page.evaluate( () => window.wp.hooks.addFilter( 'axle_staging_guard.copiedMessage', 'e2e', () => 'Copied for the test.' ) );
		await created.getByRole( 'button', { name: 'Copy link' } ).click();
		await expect( page.locator( '#a11y-speak-polite' ) ).toContainText( 'Copied for the test.' );

		// The person with the link gets in without an account, and the address is cleaned.
		const { context, page: guest } = await ( async () => {
			const ctx = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
			return { context: ctx, page: await ctx.newPage() };
		} )();
		const response = await guest.goto( url );
		expect( response.status() ).toBe( 200 );
		expect( guest.url() ).not.toContain( 'axle-gate-link' );
		await expect( guest.locator( 'body' ) ).not.toContainText( 'password needed' );

		await page.reload();
		await expect( page.locator( '.asg-link-row' ) ).toContainText( 'just now' );
		await expect( page.locator( '.asg-link-row td' ).nth( 4 ) ).toHaveText( '1' );
		expect( JSON.parse( wp( 'axle-staging-guard', 'gate', 'link', 'list', '--format=json' ) )[ 0 ] ).toMatchObject( { name: 'For Priya at the client', status: 'works', visits: 1 } );

		// Revoking asks in place first, and the guest is out on the next page view.
		const row = page.locator( '.asg-link-row' );
		await row.locator( '.asg-confirm-open' ).click();
		const step = row.locator( '.axle-confirm' );
		await expect( step ).toContainText( 'cannot be undone' );
		await step.getByRole( 'button', { name: 'Keep link' } ).click();
		await expect( step ).toBeHidden();
		expect( JSON.parse( wp( 'axle-staging-guard', 'gate', 'link', 'list', '--format=json' ) ) ).toHaveLength( 1 );
		await row.locator( '.asg-confirm-open' ).click();
		await step.getByRole( 'button', { name: 'Revoke link' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Link revoked.' );
		await expect( page.locator( '.asg-links .axle-empty' ) ).toBeVisible();

		const after = await guest.goto( '/hello-world/' );
		expect( after.status() ).toBe( 401 );
		await context.close();
	} );

	test( 'an expired or revoked link says so on the gate screen', async () => {
		gated();
		const url = wp( 'axle-staging-guard', 'gate', 'link', 'create', 'Expiring', '--days=1', '--porcelain' );
		const anyone = await visitor();
		expect( ( await anyone.get( url.replace( BASE_URL, '' ) ) ).status() ).toBe( 303 );

		wpEval( `$s = get_option( '${ OPTION }' ); foreach ( $s['gate_links'] as $i => $l ) { $s['gate_links'][ $i ]['expires'] = time() - 5; } update_option( '${ OPTION }', $s, false );` );
		const stranger = await visitor();
		const expired = await stranger.get( url.replace( BASE_URL, '' ) );
		expect( expired.status() ).toBe( 401 );
		expect( await expired.text() ).toContain( 'This link has expired or was turned off' );
		await stranger.dispose();
		await anyone.dispose();
	} );

	test( 'it never runs on the live site', async () => {
		wpEval( `update_option( '${ OPTION }', array( 'gate_enabled' => true, 'gate_secret' => 'x', 'gate_hash' => wp_hash_password( '${ PASSWORD }' ) ), false );` );
		const anyone = await visitor();
		expect( ( await anyone.get( '/' ) ).status() ).toBe( 200 );
		expect( JSON.parse( wp( 'axle-staging-guard', 'gate', 'status', '--format=json' ) ) ).toMatchObject( { active: 'no', switched_on: 'yes' } );
		await anyone.dispose();
	} );

	test( 'WP-CLI turns the gate on and off, makes and revokes links', async () => {
		staging();
		expect( () => wp( 'axle-staging-guard', 'gate', 'on' ) ).toThrow( /Set a password first/ );
		expect( () => wp( 'axle-staging-guard', 'gate', 'on', '--password=short' ) ).toThrow( /at least 8 characters/ );
		expect( wp( 'axle-staging-guard', 'gate', 'on', `--password=${ PASSWORD }` ) ).toContain( 'The gate is on.' );
		expect( JSON.parse( wp( 'axle-staging-guard', 'gate', 'status', '--format=json' ) ) ).toMatchObject( { active: 'yes', password_set: 'yes', active_links: '0' } );

		expect( () => wp( 'axle-staging-guard', 'gate', 'link', 'create', '  ' ) ).toThrow( /Give the link a name/ );
		expect( () => wp( 'axle-staging-guard', 'gate', 'link', 'create', 'Too long', '--days=45' ) ).toThrow( /Choose how long/ );
		const url = wp( 'axle-staging-guard', 'gate', 'link', 'create', 'For Sam', '--days=14', '--porcelain' );
		expect( url ).toContain( '?axle-gate-link=' );
		const [ listed ] = JSON.parse( wp( 'axle-staging-guard', 'gate', 'link', 'list', '--format=json' ) );
		expect( listed ).toMatchObject( { name: 'For Sam', status: 'works', visits: 0 } );
		expect( wp( 'axle-staging-guard', 'gate', 'link', 'revoke', listed.id ) ).toContain( 'Link revoked.' );
		expect( () => wp( 'axle-staging-guard', 'gate', 'link', 'revoke', listed.id ) ).toThrow( /no link with that ID/ );

		expect( wp( 'axle-staging-guard', 'gate', 'off' ) ).toContain( 'The gate is off.' );
		expect( JSON.parse( wp( 'axle-staging-guard', 'gate', 'status', '--format=json' ) ).active ).toBe( 'no' );
	} );

	test( 'abilities: an agent can read the gate, make a link and revoke it', async ( { rest } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		const run = ( name, method, input ) => rest( method, `/wp-abilities/v1/abilities/axle-staging-guard/${ name }/run`, input ? { input } : undefined );
		staging();

		expect( ( await run( 'get-gate', 'GET' ) ).body ).toMatchObject( { active: false, switched_on: false, password_set: false } );
		expect( ( await run( 'set-gate', 'POST', { enabled: true } ) ).status ).toBe( 400 );
		expect( ( await run( 'set-gate', 'POST', { enabled: true, password: PASSWORD } ) ).body ).toEqual( { active: true } );

		const made = await run( 'create-gate-link', 'POST', { name: 'For an agent', days: 7 } );
		expect( made.status ).toBe( 200 );
		expect( made.body.url ).toContain( 'axle-gate-link=' );
		const listed = ( await run( 'list-gate-links', 'GET' ) ).body;
		expect( listed ).toHaveLength( 1 );
		expect( JSON.stringify( listed ) ).not.toContain( 'axle-gate-link' );

		expect( ( await run( 'create-gate-link', 'POST', { name: '', days: 7 } ) ).status ).toBe( 400 );
		const revoked = await rest( 'DELETE', `/wp-abilities/v1/abilities/axle-staging-guard/revoke-gate-link/run?input[id]=${ made.body.id }` );
		expect( revoked.body ).toEqual( { revoked: true } );
		expect( ( await run( 'list-gate-links', 'GET' ) ).body ).toHaveLength( 0 );
	} );
} );
