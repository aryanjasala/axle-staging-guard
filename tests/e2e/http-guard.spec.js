const { test, expect, wp, wpEval } = require( './utils' );

const PAGE = '/wp-admin/tools.php?page=axle-staging-guard';
const OPTION = 'axle_staging_guard_settings';

// The lab has no WP_ENVIRONMENT_TYPE, so these simulate the two real situations.
const live = () => wp( 'option', 'delete', OPTION );
const clearLog = () =>
	wpEval( `foreach ( get_posts( array( 'post_type' => array( 'axle_sg_mail', 'axle_sg_http' ), 'post_status' => 'any', 'numberposts' => 500, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }` );
// A copy of the live database on another domain, with the given outgoing request settings.
const staging = ( settings = {} ) =>
	wpEval(
		`update_option( '${ OPTION }', ${ 'array( ' + Object.entries( settings ).map( ( [ key, value ] ) => `'${ key }' => ${ JSON.stringify( value ).replace( /^\[/, 'array( ' ).replace( /\]$/, ' )' ).replace( /"/g, "'" ) }` ).join( ', ' ) + ' )' } + array( 'production_hash' => AryanX\\Axle\\StagingGuard\\Environment::hash( 'live.example.com' ) ), false );`
	);
// Runs a request through the WordPress HTTP API and reports what came back.
const request = ( url, method = 'GET' ) =>
	JSON.parse(
		wpEval( `
			$r = wp_remote_request( '${ url }', array( 'method' => '${ method }', 'timeout' => 3 ) );
			echo wp_json_encode( is_wp_error( $r ) ? array( 'error' => $r->get_error_code(), 'message' => $r->get_error_message() ) : array( 'code' => wp_remote_retrieve_response_code( $r ) ) );
		` )
	);

test.describe( 'Outgoing requests', () => {
	test.beforeEach( () => {
		live();
		clearLog();
	} );

	test.afterAll( () => {
		live();
		clearLog();
	} );

	test( 'a fresh activation logs only, and an existing site stays on allow all', () => {
		wp( 'plugin', 'deactivate', 'axle-staging-guard' );
		wp( 'option', 'delete', OPTION );
		wp( 'plugin', 'activate', 'axle-staging-guard' );
		expect( wpEval( `echo get_option( '${ OPTION }' )['http_mode'];` ) ).toBe( 'log' );

		// Reactivating a site that has settings changes nothing.
		wpEval( `update_option( '${ OPTION }', array( 'mail_mode' => 'block' ), false );` );
		wp( 'plugin', 'deactivate', 'axle-staging-guard' );
		wp( 'plugin', 'activate', 'axle-staging-guard' );
		expect( wpEval( `echo isset( get_option( '${ OPTION }' )['http_mode'] ) ? 'set' : 'unset';` ) ).toBe( 'unset' );
		staging();
		expect( JSON.parse( wp( 'axle-staging-guard', 'status', '--format=json' ) ).http ).toBe( 'allow' );
	} );

	test( 'the live site is never touched, even with a blocklist', () => {
		wpEval( `update_option( '${ OPTION }', array( 'http_mode' => 'block', 'http_blocklist' => array( 'blocked.test' ) ), false );` );

		const result = request( 'http://blocked.test/hook' );
		expect( result.error ).not.toBe( 'axle_staging_guard_http_blocked' );
		expect( wp( 'axle-staging-guard', 'http-log', '--format=count' ) ).toBe( '0' );
		expect( JSON.parse( wp( 'axle-staging-guard', 'test-url', 'http://blocked.test/hook', '--format=json' ) ) ).toMatchObject( { result: 'allowed', reason: 'production' } );
	} );

	test( 'block mode stops a call with an error, hides secrets and collapses repeats', async ( { page } ) => {
		staging( { http_mode: 'block' } );

		for ( let i = 0; i < 3; i++ ) {
			const result = request( 'https://crm.example-vendor.test/v2/contacts?api_key=sk_live_SECRET&email=jo@example.com', 'POST' );
			expect( result.error ).toBe( 'axle_staging_guard_http_blocked' );
			expect( result.message ).toContain( 'crm.example-vendor.test' );
		}
		request( 'https://hooks.example-vendor.test/hooks/catch/123456/abcdefghijklmnopqrstuvwxyz1234' );

		// One row per distinct request, with the count.
		await page.goto( PAGE );
		const rows = page.locator( '.asg-http-row' );
		await expect( rows ).toHaveCount( 2 );
		const crm = rows.filter( { hasText: 'crm.example-vendor.test' } );
		await expect( crm.locator( 'td' ).nth( 1 ) ).toHaveText( 'POST crm.example-vendor.test/v2/contacts' );
		await expect( crm.locator( 'td' ).nth( 3 ) ).toHaveText( '3' );
		await expect( crm.locator( '.axle-badge' ) ).toHaveText( 'Blocked' );
		await expect( rows.filter( { hasText: 'hooks.example-vendor.test' } ).locator( 'td' ).nth( 1 ) ).toHaveText( 'GET hooks.example-vendor.test/hooks/catch/123456/{token}' );

		// Nothing secret reached the database.
		const stored = wpEval( `global $wpdb; echo wp_json_encode( $wpdb->get_col( "SELECT CONCAT( p.post_title, m.meta_value ) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = 'axle_sg_http'" ) );` );
		expect( stored ).not.toContain( 'SECRET' );
		expect( stored ).not.toContain( 'jo@example.com' );
		expect( stored ).not.toContain( 'abcdefghijklmnopqrstuvwxyz1234' );

		// The status card counts the different requests.
		await expect( page.locator( '.asg-state' ).nth( 2 ) ).toContainText( 'Blocked except allowed hosts' );
		await expect( page.locator( '.asg-state' ).nth( 2 ) ).toContainText( '2 different requests stopped or recorded' );
	} );

	test( 'the plugin that made the call is named', () => {
		staging( { http_mode: 'block' } );
		wpEval( `
			$dir = WP_PLUGIN_DIR . '/e2e-caller';
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/e2e-caller.php', "<?php\\nfunction e2e_caller_call() { return wp_remote_get( 'https://who.example-vendor.test/x' ); }" );
			require $dir . '/e2e-caller.php';
			e2e_caller_call();
			unlink( $dir . '/e2e-caller.php' );
			rmdir( $dir );
		` );

		const log = JSON.parse( wp( 'axle-staging-guard', 'http-log', '--format=json' ) );
		expect( log ).toHaveLength( 1 );
		expect( log[ 0 ] ).toMatchObject( { host: 'who.example-vendor.test', caller: 'plugin:e2e-caller', count: 1, action: 'blocked' } );
	} );

	test( 'log only lets the call through and records it', async ( { page } ) => {
		staging( { http_mode: 'log' } );

		const result = request( 'https://logged.example-vendor.test/ping' );
		expect( result.error ).not.toBe( 'axle_staging_guard_http_blocked' );

		await page.goto( PAGE );
		await expect( page.locator( '.asg-state' ).nth( 2 ) ).toContainText( 'Logged, not blocked' );
		const row = page.locator( '.asg-http-row' );
		await expect( row ).toHaveCount( 1 );
		await expect( row.locator( '.axle-badge' ) ).toHaveText( 'Logged' );
	} );

	test( 'allowed hosts, WordPress.org and this site always go through in block mode', () => {
		staging( { http_mode: 'block', http_allowlist: [ 'allowed.example-vendor.test' ] } );

		for ( const url of [ 'https://api.wordpress.org/core/version-check/1.7/', 'https://allowed.example-vendor.test/x', 'http://localhost/wp-cron.php' ] ) {
			expect( request( url ).error, url ).not.toBe( 'axle_staging_guard_http_blocked' );
		}
		expect( request( 'https://other.example-vendor.test/x' ).error ).toBe( 'axle_staging_guard_http_blocked' );
	} );

	test( 'the settings card saves the mode and both host lists, and shows the built-in hosts', async ( { page } ) => {
		staging();
		await page.goto( PAGE );

		await expect( page.locator( '.asg-always' ) ).toContainText( 'wordpress.org, *.wordpress.org' );
		await page.getByLabel( 'Block all except allowed hosts' ).check();
		await page.locator( '#asg-http-allow' ).fill( 'https://Sandbox.PayPal.com/v1\n*.youragency.com\n*' );
		await page.locator( '#asg-http-block' ).fill( 'api.stripe.com' );
		await page.getByRole( 'button', { name: 'Save request settings' } ).click();

		await expect( page.locator( '.notice-success' ) ).toContainText( 'Request settings saved.' );
		await expect( page.locator( '.asg-state' ).nth( 2 ) ).toContainText( 'Blocked except allowed hosts' );
		await expect( page.locator( '#asg-http-allow' ) ).toHaveValue( 'sandbox.paypal.com\n*.youragency.com' );
		await expect( page.locator( '#asg-http-block' ) ).toHaveValue( 'api.stripe.com' );

		const status = JSON.parse( wp( 'axle-staging-guard', 'status', '--format=json' ) );
		expect( status ).toMatchObject( { http: 'block', http_allowlist: 'sandbox.paypal.com, *.youragency.com', http_blocklist: 'api.stripe.com' } );

		// The email settings were not touched by saving this card.
		expect( wpEval( `echo get_option( '${ OPTION }' )['mail_mode'];` ) ).toBe( 'block' );
	} );

	test( 'test a URL says what would happen, sends nothing and logs nothing', async ( { page } ) => {
		staging( { http_mode: 'block', http_allowlist: [ 'sandbox.paypal.com' ], http_blocklist: [ 'hooks.slack.com' ] } );
		await page.goto( PAGE );

		const check = async ( url ) => {
			await page.locator( '#asg-test-url' ).fill( url );
			await page.getByRole( 'button', { name: 'Test URL' } ).click();
			return page.locator( '.asg-url-result' );
		};

		let result = await check( 'https://api.stripe.com/v1/charges?key=sk_live_SECRET' );
		await expect( result ).toContainText( 'Result for api.stripe.com/v1/charges:' );
		await expect( result ).toContainText( 'Blocked: api.stripe.com is not on the allowlist.' );
		// The address with its key never goes into the page address.
		expect( page.url() ).not.toContain( 'SECRET' );

		result = await check( 'https://sandbox.paypal.com/v1/payments' );
		await expect( result ).toContainText( 'Goes through: sandbox.paypal.com is on the allowlist.' );

		result = await check( 'https://api.wordpress.org/plugins/info/1.2/' );
		await expect( result ).toContainText( 'always allowed' );

		result = await check( 'https://hooks.slack.com/services/T01ABCDE/B02FGHIJ/abcdefghijklmnopqrstuv12' );
		await expect( result ).toContainText( 'Blocked: hooks.slack.com is on the blocklist.' );
		await expect( result ).not.toContainText( 'abcdefghijklmnopqrstuv12' );

		await expect( page.locator( '.asg-http-row' ) ).toHaveCount( 0 );
		expect( wp( 'axle-staging-guard', 'http-log', '--format=count' ) ).toBe( '0' );

		// On the live site it says nothing happens there.
		live();
		await page.goto( PAGE );
		result = await check( 'https://api.stripe.com/v1/charges' );
		await expect( result ).toContainText( 'this is the live site' );
	} );

	test( 'clearing the request list asks first', async ( { page } ) => {
		staging( { http_mode: 'block' } );
		request( 'https://clear.example-vendor.test/x' );
		await page.goto( PAGE );
		await expect( page.locator( '.asg-http-row' ) ).toHaveCount( 1 );

		const step = page.locator( '#asg-clear-http' );
		await page.locator( '.asg-confirm-open', { hasText: 'Clear request list' } ).click();
		await expect( step ).toContainText( 'cannot be undone' );
		await step.getByRole( 'button', { name: 'Keep the list' } ).click();
		await expect( step ).toBeHidden();
		expect( wp( 'axle-staging-guard', 'http-log', '--format=count' ) ).toBe( '1' );

		await page.locator( '.asg-confirm-open', { hasText: 'Clear request list' } ).click();
		await step.getByRole( 'button', { name: 'Clear request list' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Request list cleared.' );
		await expect( page.locator( '.asg-http-log .axle-empty' ) ).toContainText( 'No outgoing request has been stopped or recorded yet' );
	} );

	test( 'allow all lists nothing, and an empty list says why', async ( { page } ) => {
		staging( { http_mode: 'allow' } );
		request( 'https://quiet.example-vendor.test/x' );
		await page.goto( PAGE );

		await expect( page.locator( '.asg-state' ).nth( 2 ) ).toContainText( 'Allowed' );
		await expect( page.locator( '.asg-http-log .axle-empty' ) ).toContainText( 'set to allow all' );
	} );

	test( 'constants in wp-config.php fix the settings and win over saved values', async ( { page } ) => {
		staging( { http_mode: 'allow' } );
		const muPlugin = '/var/www/html/wp-content/mu-plugins/e2e-http-constants.php';
		wpEval( `
			wp_mkdir_p( dirname( '${ muPlugin }' ) );
			file_put_contents( '${ muPlugin }', "<?php\\ndefine( 'AXLE_STAGING_GUARD_HTTP_MODE', 'block' );\\ndefine( 'AXLE_STAGING_GUARD_HTTP_ALLOWED_HOSTS', 'const.example-vendor.test, *.agency.test' );\\n" );
		` );

		try {
			await page.goto( PAGE );
			await expect( page.locator( '.asg-state' ).nth( 2 ) ).toContainText( 'Blocked except allowed hosts' );
			await expect( page.getByLabel( 'Block all except allowed hosts' ) ).toBeDisabled();
			await expect( page.locator( '#asg-http-allow' ) ).toBeDisabled();
			// The blocklist is not fixed, so it stays editable.
			await expect( page.locator( '#asg-http-block' ) ).toBeEnabled();
			await expect( page.getByText( 'Greyed-out request settings are fixed in wp-config.php' ) ).toBeVisible();

			expect( JSON.parse( wp( 'axle-staging-guard', 'status', '--format=json' ) ) ).toMatchObject( { http: 'block', http_allowlist: 'const.example-vendor.test, *.agency.test' } );
			expect( request( 'https://const.example-vendor.test/x' ).error ).not.toBe( 'axle_staging_guard_http_blocked' );
			expect( request( 'https://x.agency.test/x' ).error ).not.toBe( 'axle_staging_guard_http_blocked' );
			expect( request( 'https://other.example-vendor.test/x' ).error ).toBe( 'axle_staging_guard_http_blocked' );

			// Saving the card keeps the saved values for fixed fields.
			await page.locator( '#asg-http-block' ).fill( 'blocked.example-vendor.test' );
			await page.getByRole( 'button', { name: 'Save request settings' } ).click();
			await expect( page.locator( '.notice-success' ) ).toContainText( 'Request settings saved.' );
			expect( wpEval( `echo get_option( '${ OPTION }' )['http_mode'];` ) ).toBe( 'allow' );
			expect( wpEval( `echo get_option( '${ OPTION }' )['http_blocklist'][0];` ) ).toBe( 'blocked.example-vendor.test' );
		} finally {
			wpEval( `unlink( '${ muPlugin }' );` );
		}
	} );

	test( 'filters change the mode, the decision and the message, and an action fires on a block', () => {
		staging( { http_mode: 'block' } );
		const result = JSON.parse(
			wpEval( `
				$blocked = array();
				add_action( 'axle_staging_guard_http_blocked', function ( $entry ) use ( &$blocked ) { $blocked[] = $entry['host']; } );
				add_filter( 'axle_staging_guard_http_decision', function ( $decision ) { if ( 'free.example-vendor.test' === $decision['host'] ) { $decision['action'] = 'allow'; } return $decision; } );
				add_filter( 'axle_staging_guard_http_error_message', function () { return 'Custom message.'; } );
				$free = wp_remote_get( 'https://free.example-vendor.test/x', array( 'timeout' => 2 ) );
				$stopped = wp_remote_get( 'https://stopped.example-vendor.test/x', array( 'timeout' => 2 ) );
				echo wp_json_encode( array( 'free' => is_wp_error( $free ) ? $free->get_error_code() : 'ok', 'message' => $stopped->get_error_message(), 'blocked' => $blocked ) );
			` )
		);

		expect( result.free ).not.toBe( 'axle_staging_guard_http_blocked' );
		expect( result.message ).toBe( 'Custom message.' );
		expect( result.blocked ).toEqual( [ 'stopped.example-vendor.test' ] );
	} );

	test( 'Site Health warns when a staging copy allows all outgoing calls', async ( { page } ) => {
		const warning = 'Outgoing requests are set to allow all';

		// The lab is staging by the production lock, so the lock's result carries the warning.
		staging( { http_mode: 'allow' } );
		await page.goto( '/wp-admin/site-health.php' );
		await page.getByRole( 'button', { name: /treated as staging because of the production lock/ } ).click();
		await expect( page.getByText( warning ) ).toBeVisible();

		staging( { http_mode: 'log' } );
		await page.goto( '/wp-admin/site-health.php' );
		await page.getByRole( 'button', { name: /treated as staging because of the production lock/ } ).click();
		await expect( page.getByText( warning ) ).toHaveCount( 0 );
		await expect( page.getByText( 'Outgoing requests: Logged, not blocked.' ) ).toBeVisible();

		// Marked as staging by WP_ENVIRONMENT_TYPE, with no lock, the warning is its own result.
		wpEval( `delete_option( '${ OPTION }' );` );
		const environment = wpEval( `
			update_option( '${ OPTION }', array( 'http_mode' => 'allow' ), false );
			add_filter( 'axle_staging_guard_environment', function () { return array( 'type' => 'staging', 'source' => 'constant' ); } );
			$settings = new AryanX\\Axle\\StagingGuard\\Settings();
			$env      = new AryanX\\Axle\\StagingGuard\\Environment( $settings );
			$mail_log = new AryanX\\Axle\\StagingGuard\\Mail\\Log( $settings );
			$mail     = new AryanX\\Axle\\StagingGuard\\Mail\\Guard( $settings, $env, $mail_log );
			$http     = new AryanX\\Axle\\StagingGuard\\Http\\Guard( $settings, $env, new AryanX\\Axle\\StagingGuard\\Http\\Log( $mail_log ) );
			$gate     = new AryanX\\Axle\\StagingGuard\\Gate\\Gate( $settings, $env, new AryanX\\Axle\\StagingGuard\\Gate\\Links( $settings ) );
			$hold     = new AryanX\\Axle\\StagingGuard\\Cron\\Hold( $settings, new AryanX\\Axle\\StagingGuard\\Cron\\Tasks( $settings ), new AryanX\\Axle\\StagingGuard\\Cron\\Log( $mail_log ) );
			$result   = ( new AryanX\\Axle\\StagingGuard\\Health\\SiteHealth( $settings, $env, $mail, $http, $gate, $hold ) )->run();
			echo wp_json_encode( array( $result['status'], $result['label'] ) );
		` );
		expect( JSON.parse( environment ) ).toEqual( [ 'recommended', 'This staging copy can call any outside service' ] );
	} );

	test( 'WP-CLI tests a URL and lists the requests', () => {
		staging( { http_mode: 'block', http_allowlist: [ 'ok.example-vendor.test' ] } );
		request( 'https://cli.example-vendor.test/a/b?token=SECRET', 'POST' );

		expect( wp( 'axle-staging-guard', 'test-url', 'https://ok.example-vendor.test/x' ) ).toContain( 'Result: allowed' );
		expect( wp( 'axle-staging-guard', 'test-url', 'https://cli.example-vendor.test/x' ) ).toContain( 'Result: blocked' );
		expect( JSON.parse( wp( 'axle-staging-guard', 'test-url', 'https://cli.example-vendor.test/x', '--format=json' ) ) ).toMatchObject( { result: 'blocked', reason: 'mode_block', host: 'cli.example-vendor.test' } );
		expect( wp( 'axle-staging-guard', 'status' ) ).toMatch( /Http:\s+block/ );

		const log = JSON.parse( wp( 'axle-staging-guard', 'http-log', '--format=json' ) );
		expect( log ).toHaveLength( 1 );
		expect( log[ 0 ] ).toMatchObject( { method: 'POST', host: 'cli.example-vendor.test', path: '/a/b', action: 'blocked', count: 1 } );
		expect( JSON.stringify( log ) ).not.toContain( 'SECRET' );
		expect( wp( 'axle-staging-guard', 'http-log', '--format=count' ) ).toBe( '1' );
	} );

	test( 'abilities: an agent can read the mode, test a URL and read the request list', async ( { rest } ) => {
		test.skip( 'yes' !== wpEval( "echo function_exists( 'wp_register_ability' ) ? 'yes' : 'no';" ), 'The Abilities API needs WordPress 6.9.' );
		const run = ( name, method, input ) => rest( method, `/wp-abilities/v1/abilities/axle-staging-guard/${ name }/run${ input && 'GET' === method ? '?' + new URLSearchParams( Object.entries( input ).map( ( [ k, v ] ) => [ `input[${ k }]`, v ] ) ) : '' }`, input && 'GET' !== method ? { input } : undefined );

		expect( ( await run( 'get-environment', 'GET' ) ).body ).toMatchObject( { environment: 'production', http: 'allow' } );

		staging( { http_mode: 'block' } );
		expect( ( await run( 'get-environment', 'GET' ) ).body ).toMatchObject( { environment: 'staging', http: 'block' } );

		const tested = await run( 'test-url', 'GET', { url: 'https://api.stripe.com/v1/charges' } );
		expect( tested.status ).toBe( 200 );
		expect( tested.body ).toMatchObject( { result: 'blocked', reason: 'mode_block', host: 'api.stripe.com' } );
		expect( ( await run( 'test-url', 'GET', { url: 'https://api.wordpress.org/' } ) ).body ).toMatchObject( { result: 'allowed', reason: 'always_allowed' } );
		expect( ( await run( 'test-url', 'GET', { url: 'not a url' } ) ).status ).toBeGreaterThanOrEqual( 400 );

		request( 'https://agent.example-vendor.test/x' );
		const log = await run( 'get-http-log', 'GET' );
		expect( log.body ).toHaveLength( 1 );
		expect( log.body[ 0 ] ).toMatchObject( { host: 'agent.example-vendor.test', path: '/x', action: 'blocked', count: 1, method: 'GET' } );
	} );

	test( 'old entries are purged with the email retention, and the list is bounded', () => {
		staging( { http_mode: 'block' } );
		request( 'https://old.example-vendor.test/x' );
		request( 'https://new.example-vendor.test/x' );
		wpEval( `
			global $wpdb;
			$old = get_posts( array( 'post_type' => 'axle_sg_http', 'title' => 'GET old.example-vendor.test/x', 'numberposts' => 1, 'fields' => 'ids' ) );
			$wpdb->update( $wpdb->posts, array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) ), array( 'ID' => $old[0] ) );
			do_action( 'axle_staging_guard_purge_log' );
		` );
		expect( JSON.parse( wp( 'axle-staging-guard', 'http-log', '--format=json' ) ).map( ( row ) => row.host ) ).toEqual( [ 'new.example-vendor.test' ] );

		expect(
			wpEval( `
				add_filter( 'axle_staging_guard_http_log_size', function () { return 1; } );
				wp_remote_get( 'https://a.example-vendor.test/' );
				wp_remote_get( 'https://b.example-vendor.test/' );
				do_action( 'axle_staging_guard_purge_log' );
				echo count( get_posts( array( 'post_type' => 'axle_sg_http', 'numberposts' => -1, 'fields' => 'ids' ) ) );
			` )
		).toBe( '1' );
	} );
} );
