<?php
/**
 * Tests for environment detection and the production lock.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Tests\Unit;

use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Settings;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class EnvironmentTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'home_url' )->justReturn( 'https://www.example.com' );
		Functions\when( 'sanitize_hex_color' )->alias(
			static function ( $colour ) {
				return preg_match( '/^#[0-9a-f]{6}$/i', (string) $colour ) ? $colour : null;
			}
		);
	}

	private function environment( string $production_hash = '' ): Environment {
		$this->options[ Settings::OPTION ] = array( 'production_hash' => $production_hash );

		return new Environment( new Settings() );
	}

	public function test_an_unmarked_site_is_production_and_unguarded(): void {
		$environment = $this->environment();

		$this->assertSame( 'production', $environment->type() );
		$this->assertSame( 'default', $environment->source() );
		$this->assertFalse( $environment->is_guarded() );
	}

	public function test_the_locked_domain_is_production(): void {
		$environment = $this->environment( Environment::hash( 'www.example.com' ) );

		$this->assertSame( 'production', $environment->type() );
		$this->assertSame( 'lock', $environment->source() );
	}

	public function test_a_copy_on_another_domain_is_staging(): void {
		$environment = $this->environment( Environment::hash( 'live.example.com' ) );

		$this->assertSame( 'staging', $environment->type() );
		$this->assertTrue( $environment->is_guarded() );
		$this->assertSame( '#b34700', $environment->colour() );
		$this->assertTrue( $environment->is_staging_by_lock() );
	}

	public function test_colours_and_labels_can_be_filtered_but_stay_valid(): void {
		$environment = $this->environment( Environment::hash( 'live.example.com' ) );

		Filters\expectApplied( 'axle_staging_guard_colours' )->once()->andReturn( array( 'staging' => '#6b21a8' ) );
		$this->assertSame( '#6b21a8', $environment->colour() );

		Filters\expectApplied( 'axle_staging_guard_colours' )->once()->andReturn( array( 'staging' => 'red; background: url(x)' ) );
		$this->assertSame( '#b34700', $environment->colour(), 'Anything but a hex colour falls back.' );

		Filters\expectApplied( 'axle_staging_guard_environment_label' )->andReturn( 'UAT' );
		$this->assertSame( 'UAT', $environment->label() );
	}

	public function test_a_lock_set_with_or_without_www_still_matches(): void {
		// The site runs on www.example.com. A lock set on the bare domain still counts.
		$this->assertSame( 'production', $this->environment( Environment::hash( 'example.com' ) )->type() );

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$this->assertSame( 'production', $this->environment( Environment::hash( 'www.example.com' ) )->type() );
		$this->assertFalse( $this->environment( Environment::hash( 'www.example.com' ) )->is_staging_by_lock() );
	}

	public function test_production_hosts_decide_before_the_lock(): void {
		Filters\expectApplied( 'axle_staging_guard_production_hosts' )->andReturn( array( ' Shop.Example.com ', 'example.com' ) );

		// The lock points elsewhere, but www.example.com matches example.com in the hosts list.
		$environment = $this->environment( Environment::hash( 'live.example.org' ) );
		$this->assertSame( 'production', $environment->type() );
		$this->assertSame( 'hosts', $environment->source() );
		$this->assertSame( array( 'shop.example.com', 'example.com' ), $environment->production_hosts() );
		$this->assertFalse( $environment->is_staging_by_lock() );
	}

	public function test_a_domain_outside_the_production_hosts_is_staging(): void {
		Filters\expectApplied( 'axle_staging_guard_production_hosts' )->andReturn( array( 'shop.example.com' ) );

		$environment = $this->environment();
		$this->assertSame( 'staging', $environment->type() );
		$this->assertSame( 'hosts', $environment->source() );
	}

	public function test_the_hash_survives_search_replace_and_ignores_case(): void {
		$hash = Environment::hash( 'WWW.Example.com' );

		$this->assertSame( Environment::hash( 'www.example.com' ), $hash );
		$this->assertStringNotContainsString( 'example', $hash, 'The domain itself must not appear, or search-replace would rewrite it.' );
	}

	public function test_the_port_is_part_of_the_domain(): void {
		Functions\when( 'home_url' )->justReturn( 'http://localhost:8895' );

		$this->assertSame( 'localhost:8895', $this->environment()->domain() );
	}

	public function test_locking_stores_the_hash_of_the_current_domain(): void {
		$environment = $this->environment();
		$environment->lock();

		$this->assertSame( Environment::hash( 'www.example.com' ), $this->options[ Settings::OPTION ]['production_hash'] );
		$this->assertTrue( $environment->is_production_domain() );
	}

	public function test_allowlist_parsing_keeps_valid_addresses_and_domains_once(): void {
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
			}
		);

		$this->assertSame(
			array( '@agency.test', 'qa@client.test' ),
			Settings::parse_allowlist( "@Agency.test\nqa@client.test, not-an-email @nodot\n@agency.test" )
		);
	}
}
