const { test, expect, wp, wpEval } = require( './utils' );

const PAGE = '/wp-admin/tools.php?page=axle-staging-guard';

// The lab has no WP_ENVIRONMENT_TYPE, so these simulate the two real situations.
const live = () => wp( 'option', 'delete', 'axle_staging_guard_settings' );
// The stopped email log, one private post per email.
const clearLog = () =>
	wpEval( `foreach ( get_posts( array( 'post_type' => 'axle_sg_mail', 'post_status' => 'any', 'numberposts' => 500, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }` );
const copiedToStaging = () =>
	wpEval( `update_option( 'axle_staging_guard_settings', array( 'production_hash' => AryanX\\Axle\\StagingGuard\\Environment::hash( 'live.example.com' ) ), false );` );

test.describe( 'Axle Staging Guard', () => {
	test.beforeEach( () => {
		live();
		clearLog();
		wpEval( `delete_user_meta( 1, 'axle_staging_guard_lock_notice_dismissed' );` );
	} );

	test.afterAll( () => {
		live();
		clearLog();
	} );

	test( 'leaves an unmarked site alone and recommends the production lock', async ( { page, request } ) => {
		await page.goto( PAGE );
		await expect( page.locator( '.asg-pill' ) ).toHaveText( 'Production' );
		await expect( page.locator( '.asg-card' ).first() ).toContainText( 'guards off' );
		await expect( page.locator( '#wp-admin-bar-axle-staging-guard' ) ).toHaveCount( 0 );

		const home = await request.get( '/' );
		expect( home.headers()[ 'x-robots-tag' ] ).toBeUndefined();

		await page.goto( '/wp-admin/site-health.php' );
		await expect( page.getByText( 'Staging copies of this site will not be detected' ) ).toBeVisible();
	} );

	test( 'a database copy on another domain is guarded everywhere', async ( { page, request } ) => {
		copiedToStaging();

		await page.goto( PAGE );
		await expect( page.locator( '.asg-pill' ) ).toHaveText( 'Staging' );
		await expect( page.locator( '.asg-card' ).first() ).toContainText( 'production lock was set on a different domain' );
		await expect( page.locator( '#wp-admin-bar-axle-staging-guard' ) ).toHaveText( 'Staging' );
		await expect( page.locator( '#wpadminbar' ) ).toHaveCSS( 'background-color', 'rgb(179, 71, 0)' );
		await expect( page ).toHaveTitle( /^\[Staging\] / );

		const home = await request.get( '/' );
		expect( home.headers()[ 'x-robots-tag' ] ).toBe( 'noindex, nofollow' );
		expect( await home.text() ).toMatch( /<meta name='robots' content='[^']*noindex, nofollow/ );
		expect( await ( await request.get( '/robots.txt' ) ).text() ).toContain( 'Disallow: /' );
		expect( ( await request.get( '/wp-sitemap.xml' ) ).status() ).toBe( 404 );

		// Only the lock makes this copy staging, so Site Health and every admin screen say so.
		await page.goto( '/wp-admin/site-health.php' );
		await expect( page.getByText( 'This site is treated as staging because of the production lock' ) ).toBeVisible();

		await page.goto( '/wp-admin/edit.php' );
		const notice = page.locator( '.asg-lock-notice' );
		await expect( notice ).toContainText( 'Axle Staging Guard treats this site as staging.' );
		await notice.getByRole( 'link', { name: 'This is staging, hide this' } ).click();
		await expect( page.locator( '.asg-lock-notice' ) ).toHaveCount( 0 );
		await page.goto( '/wp-admin/' );
		await expect( page.locator( '.asg-lock-notice' ) ).toHaveCount( 0 );
	} );

	test( 'production hosts in wp-config decide before the lock', () => {
		copiedToStaging();
		// AXLE_STAGING_GUARD_PRODUCTION_HOSTS feeds the same filter.
		const detect = ( hosts ) =>
			JSON.parse(
				wpEval( `
					add_filter( 'axle_staging_guard_production_hosts', function () { return ${ hosts }; } );
					echo wp_json_encode( ( new AryanX\\Axle\\StagingGuard\\Environment( new AryanX\\Axle\\StagingGuard\\Settings() ) )->detect() );
				` )
			);

		expect( detect( `array( wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT ) )` ) ).toEqual( { type: 'production', source: 'hosts' } );
		expect( detect( `array( 'shop.example.com' )` ) ).toEqual( { type: 'staging', source: 'hosts' } );
	} );

	test( 'blocks a test email and lists it', async ( { page } ) => {
		copiedToStaging();
		await page.goto( PAGE );

		await page.locator( '#asg-test-to' ).fill( 'customer@example.com' );
		await page.getByRole( 'button', { name: 'Send', exact: true } ).click();

		await expect( page.locator( '.asg-test-result' ) ).toContainText( 'Test email blocked' );
		const row = page.locator( '.asg-log__row' ).first();
		await expect( row ).toContainText( 'customer@example.com' );
		await expect( row ).toContainText( 'Axle Staging Guard test from' );
		await expect( row.locator( '.asg-badge' ) ).toHaveText( 'Blocked' );

		// The log takes part in Tools > Export / Erase Personal Data.
		const privacy = ( tool ) =>
			JSON.parse(
				wpEval( `
					$tools = apply_filters( 'wp_privacy_personal_data_${ tool }s', array() );
					echo wp_json_encode( call_user_func( $tools['axle-staging-guard']['callback'], 'customer@example.com', 1 ) );
				` )
			);
		const exported = privacy( 'exporter' );
		expect( exported.data ).toHaveLength( 1 );
		expect( exported.data[ 0 ].data.find( ( field ) => field.name === 'To' ).value ).toBe( 'customer@example.com' );
		expect( privacy( 'eraser' ) ).toMatchObject( { items_removed: true, done: true } );
		expect( wp( 'axle-staging-guard', 'log', '--format=count' ) ).toBe( '0' );
	} );

	test( 'redirect mode keeps allowlisted recipients and strips everyone else', async ( { page } ) => {
		copiedToStaging();
		await page.goto( PAGE );

		await page.getByLabel( 'Send everything to one test address' ).check();
		await page.locator( '#asg-redirect-to' ).fill( 'qa@agency.test' );
		await page.locator( '#asg-allowlist' ).fill( '@agency.test' );
		await page.getByLabel( 'Keep message bodies in the log' ).check();
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved' );
		await expect( page.locator( '.asg-card' ).nth( 1 ) ).toContainText( 'Redirected to qa@agency.test' );

		// Capture what PHPMailer would really send.
		const sent = JSON.parse(
			wpEval( `
				add_action( 'phpmailer_init', function ( $m ) {
					echo wp_json_encode( array(
						'to' => array_column( $m->getToAddresses(), 0 ),
						'cc' => array_column( $m->getCcAddresses(), 0 ),
						'subject' => $m->Subject,
					) );
				} );
				wp_mail( array( 'customer@example.com', 'dev@agency.test' ), 'Your order', 'Order body text', array( 'From: Shop <shop@example.com>', 'Cc: boss@example.com, lead@agency.test' ) );
			` )
		);
		expect( sent.to ).toEqual( [ 'dev@agency.test', 'qa@agency.test' ] );
		expect( sent.cc ).toEqual( [ 'lead@agency.test' ] );
		expect( sent.subject ).toBe( '[STAGING → customer@example.com, boss@example.com] Your order' );

		await page.reload();
		const row = page.locator( '.asg-log__row' ).first();
		await expect( row.locator( '.asg-badge' ) ).toHaveText( 'Redirected' );
		await expect( row ).toContainText( 'Still delivered to dev@agency.test' );
		await row.locator( 'summary' ).click();
		await expect( row.locator( '.asg-log__body' ) ).toHaveText( 'Order body text' );
	} );

	test( 'the production lock buttons switch this copy between live and staging', async ( { page } ) => {
		copiedToStaging();
		await page.goto( PAGE );

		await page.getByRole( 'button', { name: 'Remove production lock' } ).click();
		await expect( page.locator( '.asg-pill' ) ).toHaveText( 'Production' );

		await page.getByRole( 'button', { name: /This is the live site: lock to localhost:\d+/ } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Production is locked to this domain' );
		await expect( page.locator( '.asg-card' ).first() ).toContainText( /matches this domain \(localhost:\d+\)/ );
	} );

	test( 'abilities: an agent can ask which copy this is, test email and read the log', async ( { rest } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		const run = ( name, method, input ) => rest( method, `/wp-abilities/v1/abilities/axle-staging-guard/${ name }/run`, input ? { input } : undefined );

		const listed = await rest( 'GET', '/wp-abilities/v1/abilities?category=axle-staging-guard' );
		expect( listed.body.map( ( ability ) => ability.name ).sort() ).toEqual( [ 'axle-staging-guard/get-environment', 'axle-staging-guard/get-mail-log', 'axle-staging-guard/send-test-email' ] );
		// MCP clients only list abilities marked public.
		expect( JSON.parse( wpEval( `echo wp_json_encode( array_values( array_filter( array_map( function ( $ability ) { $meta = $ability->get_meta(); return ( ! empty( $meta['public'] ) && ! empty( $meta['mcp']['public'] ) ) ? '' : $ability->get_name(); }, wp_get_abilities() ), function ( $name ) { return 0 === strpos( $name, 'axle-staging-guard/' ); } ) ) );` ) ) ).toEqual( [] );

		expect( ( await run( 'get-environment', 'GET' ) ).body ).toMatchObject( { environment: 'production', is_live_site: true, source: 'default', email: 'allow' } );

		copiedToStaging();
		const staging = ( await run( 'get-environment', 'GET' ) ).body;
		expect( staging ).toMatchObject( { environment: 'staging', is_live_site: false, source: 'lock', email: 'block', noindex: true } );
		expect( staging.explanation ).toContain( 'different domain' );

		const sent = await run( 'send-test-email', 'POST', { to: 'customer@example.com' } );
		expect( sent.status ).toBe( 200 );
		expect( sent.body ).toEqual( { result: 'blocked' } );

		const invalid = await run( 'send-test-email', 'POST', { to: 'not an email' } );
		expect( invalid.status ).toBe( 400 );

		const log = await run( 'get-mail-log', 'GET' );
		expect( log.body ).toHaveLength( 1 );
		expect( log.body[ 0 ] ).toMatchObject( { to: 'customer@example.com', action: 'blocked' } );
	} );

	test( 'WP-CLI reports status and tests email', () => {
		copiedToStaging();

		const status = JSON.parse( wp( 'axle-staging-guard', 'status', '--format=json' ) );
		expect( status ).toMatchObject( { environment: 'staging', source: 'lock', guards: 'on', email: 'block' } );
		expect( wp( 'axle-staging-guard', 'test-email', 'someone@example.com' ) ).toBe( 'Result: blocked' );
		expect( wp( 'axle-staging-guard', 'log', '--format=count' ) ).toBe( '1' );
	} );
} );
