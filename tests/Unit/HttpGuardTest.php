<?php
/**
 * Tests for what happens to outgoing requests on each kind of copy.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Tests\Unit;

use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Http\Guard;
use AryanX\Axle\StagingGuard\Http\Log;
use AryanX\Axle\StagingGuard\Mail\Log as MailLog;
use AryanX\Axle\StagingGuard\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class HttpGuardTest extends TestCase {

	/**
	 * Posts written through wp_insert_post().
	 *
	 * @var array[]
	 */
	private array $logged = array();

	protected function setUp(): void {
		parent::setUp();

		$this->logged = array();
		Functions\when( 'wp_insert_post' )->alias(
			function ( array $post ) {
				$this->logged[] = $post;
				return count( $this->logged );
			}
		);
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://staging.example.test' );
		Functions\when( 'site_url' )->justReturn( 'https://staging.example.test' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				return str_replace( '\\', '/', $path );
			}
		);
		Functions\when( 'trailingslashit' )->alias(
			static function ( $path ) {
				return rtrim( $path, '/' ) . '/';
			}
		);
		Functions\when( 'get_theme_root' )->justReturn( '/var/www/wp-content/themes' );
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', '/var/www/wp-content/plugins' );
		}
	}

	/**
	 * A guard on a copy whose production lock points at another domain, so it is staging.
	 *
	 * @param array  $settings Settings to store.
	 * @param string $type     Environment type to report, or empty for the lock's result.
	 * @param bool   $live     Whether this is the live site.
	 * @return Guard
	 */
	private function guard( array $settings = array(), string $type = '', bool $live = false ): Guard {
		$domain                            = $live ? 'staging.example.test' : 'live.example.com';
		$this->options[ Settings::OPTION ] = $settings + array( 'production_hash' => Environment::hash( $domain ) );

		$store = new Settings();
		if ( '' !== $type ) {
			Filters\expectApplied( 'axle_staging_guard_environment' )->andReturn(
				array(
					'type'   => $type,
					'source' => 'filter',
				)
			);
		}

		return new Guard( $store, new Environment( $store ), new Log( new MailLog( $store ) ) );
	}

	private function request( Guard $guard, string $url, string $method = 'GET' ) {
		return $guard->pre_request( false, array( 'method' => $method ), $url );
	}

	public function test_the_live_site_is_never_touched_in_any_mode(): void {
		foreach ( array( 'block', 'log', 'allow' ) as $mode ) {
			$guard = $this->guard(
				array(
					'http_mode'      => $mode,
					'http_blocklist' => array( 'api.stripe.com' ),
				),
				'',
				true
			);

			$this->assertFalse( $this->request( $guard, 'https://api.stripe.com/v1/charges' ), $mode );
			$this->assertSame( 'production', $guard->evaluate( 'https://api.stripe.com/v1/charges' )['reason'] );
		}

		$this->assertSame( array(), $this->logged );
	}

	/**
	 * @dataProvider guarded_environments
	 *
	 * @param string $type Environment type.
	 */
	public function test_staging_development_and_local_are_all_guarded( string $type ): void {
		$guard = $this->guard( array( 'http_mode' => 'block' ), $type );

		$this->assertInstanceOf( \WP_Error::class, $this->request( $guard, 'https://api.stripe.com/v1/charges' ) );
	}

	public function guarded_environments(): array {
		return array(
			'staging'     => array( 'staging' ),
			'development' => array( 'development' ),
			'local'       => array( 'local' ),
		);
	}

	public function test_block_mode_returns_an_error_never_a_fake_success_and_logs_it(): void {
		$guard = $this->guard( array( 'http_mode' => 'block' ) );
		Actions\expectDone( 'axle_staging_guard_http_blocked' )->once();

		$result = $guard->pre_request( false, array( 'method' => 'post' ), 'https://API.Stripe.com/v1/charges?api_key=sk_live_secret&email=a@b.co' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'axle_staging_guard_http_blocked', $result->get_error_code() );
		$this->assertStringContainsString( 'api.stripe.com', $result->get_error_message() );
		$this->assertStringContainsString( 'staging', strtolower( $result->get_error_message() ) );

		$this->assertCount( 1, $this->logged );
		$meta = $this->logged[0]['meta_input'];
		$this->assertSame( Log::POST_TYPE, $this->logged[0]['post_type'] );
		$this->assertSame( 'POST', $meta['_axle_staging_guard_method'] );
		$this->assertSame( 'api.stripe.com', $meta['_axle_staging_guard_host'] );
		$this->assertSame( '/v1/charges', $meta['_axle_staging_guard_path'] );
		$this->assertSame( 'blocked', $meta['_axle_staging_guard_action'] );
		$this->assertStringNotContainsString( 'sk_live_secret', wp_json_encode_stub( $this->logged ) );
	}

	public function test_log_only_lets_the_request_through_and_records_it(): void {
		$guard = $this->guard( array( 'http_mode' => 'log' ) );
		Actions\expectDone( 'axle_staging_guard_http_logged' )->once();
		Actions\expectDone( 'axle_staging_guard_http_blocked' )->never();

		$this->assertFalse( $this->request( $guard, 'https://api.hubapi.com/crm/v3/objects/contacts' ) );
		$this->assertSame( 'logged', $this->logged[0]['meta_input']['_axle_staging_guard_action'] );
	}

	public function test_allow_all_does_nothing_and_logs_nothing(): void {
		$guard = $this->guard( array( 'http_mode' => 'allow' ) );

		$this->assertFalse( $this->request( $guard, 'https://api.hubapi.com/crm/v3/objects/contacts' ) );
		$this->assertSame( array(), $this->logged );
	}

	public function test_allowed_hosts_and_the_built_in_ones_go_through_in_block_mode(): void {
		$guard = $this->guard(
			array(
				'http_mode'      => 'block',
				'http_allowlist' => array( 'sandbox.paypal.com', '*.agency.test' ),
			)
		);

		foreach ( array(
			'https://api.wordpress.org/core/version-check/1.7/',
			'https://downloads.wordpress.org/plugin/akismet.zip',
			'https://staging.example.test/wp-cron.php',
			'https://www.staging.example.test/',
			'http://localhost:8080/wp-json/',
			'http://127.0.0.1/wp-cron.php',
			'https://sandbox.paypal.com/v1/payments',
			'https://ci.agency.test/hook',
		) as $url ) {
			$this->assertFalse( $this->request( $guard, $url ), $url );
		}

		$this->assertInstanceOf( \WP_Error::class, $this->request( $guard, 'https://api.paypal.com/v1/payments' ) );
		$this->assertInstanceOf( \WP_Error::class, $this->request( $guard, 'https://agency.test/' ), 'A wildcard does not cover the bare domain.' );
		$this->assertCount( 2, $this->logged, 'Allowed requests are not logged.' );
	}

	public function test_the_blocklist_applies_in_every_mode_and_beats_the_allowlist(): void {
		foreach ( array( 'allow', 'log', 'block' ) as $mode ) {
			$this->logged = array();
			$guard        = $this->guard(
				array(
					'http_mode'      => $mode,
					'http_allowlist' => array( 'api.stripe.com' ),
					'http_blocklist' => array( '*.stripe.com' ),
				)
			);

			$this->assertInstanceOf( \WP_Error::class, $this->request( $guard, 'https://api.stripe.com/v1/charges' ), $mode );
			$this->assertSame( 'blocked', $this->logged[0]['meta_input']['_axle_staging_guard_action'] );
		}
	}

	public function test_an_earlier_short_circuit_is_respected(): void {
		$guard    = $this->guard( array( 'http_mode' => 'block' ) );
		$response = array( 'response' => array( 'code' => 200 ) );

		$this->assertSame( $response, $guard->pre_request( $response, array(), 'https://api.stripe.com/' ) );
		$this->assertSame( array(), $this->logged );
	}

	public function test_an_unknown_mode_blocks_rather_than_lets_calls_through(): void {
		$guard = $this->guard( array( 'http_mode' => 'block' ) );
		Filters\expectApplied( 'axle_staging_guard_http_mode' )->andReturn( 'blok' );

		$this->assertSame( 'block', $guard->policy()['mode'] );
	}

	public function test_filters_can_change_the_mode_the_hosts_and_the_decision(): void {
		$guard = $this->guard( array( 'http_mode' => 'block' ) );
		Filters\expectApplied( 'axle_staging_guard_http_mode' )->andReturn( 'allow' );
		Filters\expectApplied( 'axle_staging_guard_http_allowed_hosts' )->andReturn( array( 'API.Example.com', '*', 'bad host' ) );
		Filters\expectApplied( 'axle_staging_guard_http_blocked_hosts' )->andReturn( array( '*.stripe.com' ) );

		$policy = $guard->policy();
		$this->assertSame( 'allow', $policy['mode'] );
		$this->assertSame( array( 'api.example.com' ), $policy['allowlist'], 'Entries are cleaned, and a bare * and an invalid name are refused.' );
		$this->assertSame( array( '*.stripe.com' ), $policy['blocklist'] );
	}

	public function test_the_decision_filter_can_allow_or_block_one_request_but_cannot_return_nonsense(): void {
		$guard = $this->guard( array( 'http_mode' => 'block' ) );

		Filters\expectApplied( 'axle_staging_guard_http_decision' )->once()->andReturnUsing(
			static function ( array $decision, string $url ) {
				$decision['action'] = 'allow';
				return $decision;
			}
		);
		$this->assertSame( 'allow', $guard->evaluate( 'https://api.stripe.com/' )['action'] );

		Filters\expectApplied( 'axle_staging_guard_http_decision' )->once()->andReturn( array( 'action' => 'explode' ) );
		$this->assertSame( 'block', $guard->evaluate( 'https://api.stripe.com/' )['action'] );
	}

	public function test_the_error_message_is_filterable(): void {
		$guard = $this->guard( array( 'http_mode' => 'block' ) );
		Filters\expectApplied( 'axle_staging_guard_http_error_message' )->once()->andReturn( 'No calls from staging.' );

		$result = $this->request( $guard, 'https://api.stripe.com/' );
		$this->assertSame( 'No calls from staging.', $result->get_error_message() );
	}

	public function test_default_hosts_include_wordpress_org_and_the_site_itself(): void {
		$hosts = $this->guard()->default_hosts();

		$this->assertContains( 'wordpress.org', $hosts );
		$this->assertContains( '*.wordpress.org', $hosts );
		$this->assertContains( 'staging.example.test', $hosts );
		$this->assertContains( 'www.staging.example.test', $hosts );
	}

	public function test_test_url_explains_every_outcome_without_logging(): void {
		$guard = $this->guard(
			array(
				'http_mode'      => 'block',
				'http_allowlist' => array( 'sandbox.paypal.com' ),
				'http_blocklist' => array( 'hooks.slack.com' ),
			)
		);

		$cases = array(
			'https://api.stripe.com/v1/charges'    => array( 'block', 'mode_block' ),
			'https://sandbox.paypal.com/x'         => array( 'allow', 'allowlisted' ),
			'https://api.wordpress.org/'           => array( 'allow', 'always_allowed' ),
			'https://hooks.slack.com/services/a/b' => array( 'block', 'blocklisted' ),
		);
		foreach ( $cases as $url => $expected ) {
			$decision = $guard->evaluate( $url );
			$this->assertSame( $expected, array( $decision['action'], $decision['reason'] ), $url );
			$this->assertNotSame( '', $guard->explain( $decision ) );
		}

		$this->assertSame( array(), $this->logged );
	}

	public function test_a_repeating_request_raises_the_count_instead_of_adding_an_entry(): void {
		$store = new Settings();
		$log   = new Log( new MailLog( $store ) );
		$meta  = array( 7 => array( '_axle_staging_guard_count' => 4 ) );

		Functions\when( 'get_posts' )->justReturn( array( 7 ) );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) use ( &$meta ) {
				return $meta[ $id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$meta ) {
				$meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_date_from_gmt' )->returnArg();
		$updated = array();
		Functions\when( 'wp_update_post' )->alias(
			static function ( array $post ) use ( &$updated ) {
				$updated[] = $post;
				return $post['ID'];
			}
		);

		$id = $log->add(
			array(
				'method' => 'GET',
				'host'   => 'api.stripe.com',
				'path'   => '/v1/charges',
				'action' => 'blocked',
				'caller' => 'plugin:shop',
			)
		);

		$this->assertSame( 7, $id );
		$this->assertSame( array(), $this->logged, 'No second entry.' );
		$this->assertSame( 5, $meta[7]['_axle_staging_guard_count'] );
		$this->assertTrue( $updated[0]['edit_date'], 'The last-seen time moves.' );
	}

	public function test_a_new_entry_is_keyed_by_what_makes_the_request_distinct(): void {
		$log = new Log( new MailLog( new Settings() ) );
		$a   = array(
			'method' => 'GET',
			'host'   => 'api.stripe.com',
			'path'   => '/v1/charges',
			'action' => 'blocked',
			'caller' => 'plugin:shop',
		);

		$log->add( $a );
		$log->add( array( 'caller' => 'plugin:other' ) + $a );
		$log->add( array( 'method' => 'POST' ) + $a );

		$this->assertCount( 3, $this->logged );
		$this->assertCount( 3, array_unique( array_column( $this->logged, 'post_name' ) ) );
		$this->assertSame( 1, $this->logged[0]['meta_input']['_axle_staging_guard_count'] );
	}

	public function test_the_caller_is_the_nearest_plugin_or_theme_frame_and_never_this_plugin(): void {
		$roots = array(
			'plugin'    => '/var/www/wp-content/plugins',
			'mu-plugin' => '/var/www/wp-content/mu-plugins',
			'theme'     => '/var/www/wp-content/themes',
		);
		$own   = '/var/www/wp-content/plugins/axle-staging-guard';
		$from  = static function ( string ...$files ) use ( $roots, $own ): string {
			return Guard::caller_from_frames(
				array_map(
					static function ( string $file ): array {
						return array( 'file' => $file );
					},
					$files
				),
				$roots,
				$own
			);
		};

		$this->assertSame(
			'plugin:woocommerce-gateway-stripe',
			$from(
				'/var/www/wp-content/plugins/axle-staging-guard/src/Http/Guard.php',
				'/var/www/wp-includes/class-wp-http.php',
				'/var/www/wp-content/plugins/woocommerce-gateway-stripe/includes/class-api.php',
				'/var/www/wp-content/plugins/woocommerce/includes/class-wc-payment.php'
			),
			'The nearest plugin frame wins, not the one further up.'
		);
		$this->assertSame( 'plugin:hello', $from( '/var/www/wp-content/plugins/hello.php' ), 'A single-file plugin.' );
		$this->assertSame( 'mu-plugin:sync', $from( '/var/www/wp-includes/http.php', '/var/www/wp-content/mu-plugins/sync/loader.php' ) );
		$this->assertSame( 'theme:flavor', $from( '/var/www/wp-content/themes/flavor/functions.php' ) );
		$this->assertSame( '', $from( '/var/www/wp-includes/http.php', '/var/www/wp-cron.php' ), 'Core alone gives no name.' );
		$this->assertSame( '', $from( '/var/www/wp-content/plugins/axle-staging-guard/src/Http/Guard.php' ), 'This plugin is never the caller.' );
	}
}

/**
 * JSON for assertions, without needing WordPress.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_stub( $value ): string {
	return (string) json_encode( $value ); // phpcs:ignore
}
