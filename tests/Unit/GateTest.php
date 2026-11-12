<?php
/**
 * Tests for when the gate runs and who gets through.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Tests\Unit;

use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Gate\Access;
use AryanX\Axle\StagingGuard\Gate\Gate;
use AryanX\Axle\StagingGuard\Gate\Links;
use AryanX\Axle\StagingGuard\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class GateTest extends TestCase {

	/**
	 * Transients, keyed by name.
	 *
	 * @var array
	 */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();

		$this->transients = array();
		unset( $_COOKIE, $_SERVER['REMOTE_ADDR'] );
		$_COOKIE = array();

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'home_url' )->justReturn( 'https://staging.example.test' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof \WP_Error );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_hash_password' )->alias( static fn( $password ) => 'hash:' . $password );
		Functions\when( 'wp_check_password' )->alias( static fn( $password, $hash ) => 'hash:' . $password === $hash );
		Functions\when( 'wp_generate_password' )->justReturn( 'a-long-random-secret' );
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$_COOKIE = array();
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	private function gate( array $saved = array(), string $hash_for_domain = 'live.example.test' ): Gate {
		$this->options[ Settings::OPTION ] = $saved + array( 'production_hash' => Environment::hash( $hash_for_domain ) );
		$settings                          = new Settings();

		return new Gate( $settings, new Environment( $settings ), new Links( $settings ) );
	}

	private function with_password( array $extra = array() ): Gate {
		return $this->gate(
			$extra + array(
				'gate_enabled' => true,
				'gate_hash'    => 'hash:open sesame',
				'gate_secret'  => 'a-long-random-secret',
			)
		);
	}

	public function test_it_is_off_until_switched_on_and_a_password_exists(): void {
		$this->assertFalse( $this->gate()->is_active() );
		$this->assertFalse( $this->gate( array( 'gate_enabled' => true ) )->is_active(), 'On without a password stays off.' );
		$this->assertTrue( $this->with_password()->is_active() );
	}

	public function test_it_never_runs_on_the_live_site_even_when_a_filter_says_so(): void {
		Filters\expectApplied( 'axle_staging_guard_gate_enabled' )->andReturn( true );

		$live = $this->gate( array( 'gate_enabled' => true, 'gate_hash' => 'x' ), 'staging.example.test' );

		$this->assertFalse( $live->is_active() );
	}

	public function test_enabling_needs_a_password_and_fires_the_actions(): void {
		$gate = $this->gate();
		$this->assertSame( 'axle_staging_guard_gate_no_password', $gate->enable()->get_error_code() );

		Actions\expectDone( 'axle_staging_guard_gate_password_changed' )->once();
		Actions\expectDone( 'axle_staging_guard_gate_turned_on' )->once();
		Actions\expectDone( 'axle_staging_guard_gate_turned_off' )->once();

		$this->assertTrue( $gate->set_password( 'open sesame' ) );
		$this->assertTrue( $gate->enable() );
		$this->assertTrue( $gate->enable(), 'Turning it on twice fires once.' );
		$gate->disable();
		$gate->disable();
	}

	public function test_a_short_password_is_refused_and_the_minimum_can_be_filtered(): void {
		$gate = $this->gate();

		$this->assertSame( 'axle_staging_guard_gate_password', $gate->set_password( 'short' )->get_error_code() );
		$this->assertFalse( $gate->has_password() );

		Filters\expectApplied( 'axle_staging_guard_gate_min_password_length' )->andReturn( 1 );
		$this->assertSame( 4, $gate->min_password_length(), 'Never below four.' );
	}

	public function test_the_password_is_stored_hashed(): void {
		$gate = $this->gate();
		$gate->set_password( 'open sesame' );

		$saved = $this->options[ Settings::OPTION ];
		$this->assertNotSame( 'open sesame', $saved['gate_hash'] );
		$this->assertSame( 'hash:open sesame', $saved['gate_hash'] );
		$this->assertNotSame( '', $saved['gate_secret'] );
	}

	public function test_logged_in_users_skip_the_gate_unless_a_filter_says_no(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn( new \WP_User( 5 ) );

		$this->assertSame( 'user', $this->with_password()->allowed_by( '/' ) );

		Filters\expectApplied( 'axle_staging_guard_gate_user_allowed' )->andReturn( false );
		$this->assertSame( '', $this->with_password()->allowed_by( '/' ) );
	}

	public function test_listed_ip_addresses_and_paths_skip_the_gate(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		$gate                   = $this->with_password( array( 'gate_ips' => array( '198.51.100.0/24' ), 'gate_paths' => array( '/health' ) ) );

		$this->assertSame( 'ip', $gate->allowed_by( '/anything' ) );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertSame( 'path', $gate->allowed_by( '/health' ) );
		$this->assertSame( 'path', $gate->allowed_by( '/robots.txt' ) );
		$this->assertSame( 'path', $gate->allowed_by( '/wp-login.php' ) );
		$this->assertSame( '', $gate->allowed_by( '/about' ) );
	}

	public function test_forwarded_headers_are_not_trusted(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
		$gate                            = $this->with_password( array( 'gate_ips' => array( '198.51.100.9' ) ) );

		$this->assertSame( '', $gate->allowed_by( '/' ) );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	public function test_a_correct_session_cookie_gets_in_and_a_changed_password_does_not(): void {
		$gate   = $this->with_password();
		$cookie = $gate->cookie_name();

		$_COOKIE[ $cookie ] = Access::session_token( time() + 600, 'hash:open sesame', 'a-long-random-secret' );
		$this->assertSame( 'session', $gate->allowed_by( '/' ) );

		$changed = $this->with_password( array( 'gate_hash' => 'hash:a new password' ) );
		$this->assertSame( '', $changed->allowed_by( '/' ), 'Changing the password signs everyone out.' );

		$_COOKIE[ $cookie ] = Access::session_token( time() - 5, 'hash:open sesame', 'a-long-random-secret' );
		$this->assertSame( '', $gate->allowed_by( '/' ), 'An expired cookie is refused.' );

		$_COOKIE[ $cookie ] = 'pw.' . ( time() + 600 ) . '.forged';
		$this->assertSame( '', $gate->allowed_by( '/' ) );
	}

	public function test_a_link_cookie_works_until_the_link_is_revoked_or_expires(): void {
		$gate = $this->with_password();
		$link = $gate->links()->create( 'For Priya at the client', 7, 3 );

		$_COOKIE[ $gate->cookie_name() ] = $gate->links()->token( $link );
		$this->assertSame( 'link', $gate->allowed_by( '/' ) );

		$gate->links()->revoke( $link['id'] );
		$this->assertSame( '', $gate->allowed_by( '/' ), 'A revoked link ends its cookies too.' );
	}

	public function test_a_link_is_refused_after_it_expires(): void {
		$gate = $this->with_password();
		$link = $gate->links()->create( 'For Priya', 1, 3 );

		$this->options[ Settings::OPTION ]['gate_links'][0]['expires'] = time() - 1;
		$this->assertNull( $gate->links()->from_token( $gate->links()->token( $link ) ) );
	}

	public function test_a_filter_can_let_a_request_through_and_names_the_reason(): void {
		Filters\expectApplied( 'axle_staging_guard_gate_allow_request' )->andReturn( true );

		$this->assertSame( 'filter', $this->with_password()->allowed_by( '/x' ) );
	}

	public function test_too_many_wrong_passwords_lock_an_address_out(): void {
		$gate = $this->with_password();

		Actions\expectDone( 'axle_staging_guard_gate_locked_out' )->once();

		for ( $i = 1; $i < $gate->max_attempts(); $i++ ) {
			$gate->record_failure( '203.0.113.9' );
			$this->assertSame( 0, $gate->locked_for( '203.0.113.9' ) );
		}

		$gate->record_failure( '203.0.113.9' );
		$this->assertGreaterThan( 0, $gate->locked_for( '203.0.113.9' ) );
		$this->assertSame( 0, $gate->locked_for( '203.0.113.10' ), 'Another address is not affected.' );
	}

	public function test_the_lockout_counter_never_holds_the_address(): void {
		$gate = $this->with_password();
		$gate->record_failure( '203.0.113.9' );

		$this->assertStringNotContainsString( '203.0.113.9', implode( ' ', array_keys( $this->transients ) ) );
	}

	public function test_limits_are_clamped(): void {
		$gate = $this->with_password();

		Filters\expectApplied( 'axle_staging_guard_gate_max_attempts' )->andReturn( 0 );
		Filters\expectApplied( 'axle_staging_guard_gate_session_hours' )->andReturn( 99999 );
		Filters\expectApplied( 'axle_staging_guard_gate_status' )->andReturn( 200 );

		$this->assertSame( 1, $gate->max_attempts() );
		$this->assertSame( 720, $gate->session_hours() );
		$this->assertSame( 400, $gate->status() );
	}
}
