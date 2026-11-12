<?php
/**
 * Tests for share links.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Tests\Unit;

use AryanX\Axle\StagingGuard\Gate\Links;
use AryanX\Axle\StagingGuard\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class GateLinksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_generate_password' )->justReturn( 'a-long-random-secret' );
		Functions\when( 'home_url' )->justReturn( 'https://staging.example.test/' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value, $url ) {
				return $url . '?' . $key . '=' . $value;
			}
		);
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof \WP_Error );
	}

	private function links(): Links {
		return new Links( new Settings() );
	}

	public function test_a_link_needs_a_name_and_one_of_the_offered_durations(): void {
		$links = $this->links();

		$this->assertSame( 'axle_staging_guard_link_name', $links->create( '   ', 7 )->get_error_code() );
		$this->assertSame( 'axle_staging_guard_link_days', $links->create( 'For Priya', 45 )->get_error_code() );
		$this->assertSame( 'axle_staging_guard_link_days', $links->create( 'For Priya', 0 )->get_error_code() );
		$this->assertSame( array(), $links->all() );
	}

	public function test_durations_stay_between_one_and_thirty_days(): void {
		Filters\expectApplied( 'axle_staging_guard_gate_link_durations' )->andReturn( array( 0, 2, 90, '5', 2 ) );

		$this->assertSame( array( 2, 5 ), $this->links()->durations() );
	}

	public function test_a_made_link_is_listed_with_its_expiry_and_fires_an_action(): void {
		Actions\expectDone( 'axle_staging_guard_gate_link_created' )->once();

		$link = $this->links()->create( 'For Priya at the client', 7, 3 );

		$this->assertSame( 'For Priya at the client', $link['name'] );
		$this->assertSame( 3, $link['by'] );
		$this->assertEqualsWithDelta( time() + 7 * DAY_IN_SECONDS, $link['expires'], 2 );
		$this->assertCount( 1, $this->links()->all() );
		$this->assertTrue( $this->links()->is_active( $link ) );
		$this->assertSame( 0, $link['last_used'] );
	}

	public function test_the_address_carries_a_token_only_that_link_can_make(): void {
		$links = $this->links();
		$one   = $links->create( 'One', 1 );
		$two   = $links->create( 'Two', 1 );

		$this->assertStringStartsWith( 'https://staging.example.test/?axle-gate-link=' . $one['id'], $links->url( $one ) );
		$this->assertSame( $one['id'], $links->from_token( $links->token( $one ) )['id'] );
		$this->assertNull( $links->from_token( $one['id'] . '.' . substr( $links->token( $two ), -40 ) ) );
		$this->assertNull( $links->from_token( 'garbage' ) );
	}

	public function test_revoking_removes_the_link_once(): void {
		$links = $this->links();
		$link  = $links->create( 'For Priya', 7 );

		Actions\expectDone( 'axle_staging_guard_gate_link_revoked' )->once();

		$this->assertTrue( $links->revoke( $link['id'] ) );
		$this->assertFalse( $links->revoke( $link['id'] ) );
		$this->assertNull( $links->from_token( $links->token( $link ) ) );
	}

	public function test_use_is_counted_and_cookie_requests_only_refresh_now_and_then(): void {
		$links = $this->links();
		$link  = $links->create( 'For Priya', 7 );

		$links->touch( $link['id'], true );
		$after = $links->find( $link['id'] );
		$this->assertSame( 1, $after['uses'] );
		$this->assertGreaterThan( 0, $after['last_used'] );

		$links->touch( $link['id'], false );
		$this->assertSame( 1, $links->find( $link['id'] )['uses'], 'A cookie request is not a new visit.' );

		$this->options[ Settings::OPTION ]['gate_links'][0]['last_used'] = time() - HOUR_IN_SECONDS;
		$links->touch( $link['id'], false );
		$this->assertEqualsWithDelta( time(), $links->find( $link['id'] )['last_used'], 2 );
	}

	public function test_the_number_of_links_is_limited(): void {
		Filters\expectApplied( 'axle_staging_guard_gate_max_links' )->andReturn( 2 );
		$links = $this->links();
		$links->create( 'One', 7 );
		$links->create( 'Two', 7 );

		$this->assertSame( 'axle_staging_guard_link_limit', $links->create( 'Three', 7 )->get_error_code() );
	}

	public function test_expired_links_are_removed_after_the_retention_period(): void {
		$links = $this->links();
		$links->create( 'Old', 1 );
		$links->create( 'Fresh', 1 );

		$this->options[ Settings::OPTION ]['gate_links'][0]['expires'] = time() - 40 * DAY_IN_SECONDS;
		$this->options[ Settings::OPTION ]['gate_links'][1]['expires'] = time() - 2 * DAY_IN_SECONDS;
		$links->purge();

		$this->assertCount( 1, $links->all() );
	}

	public function test_the_eraser_forgets_who_made_a_link_and_clears_its_name(): void {
		Functions\when( 'get_user_by' )->justReturn( new \WP_User( 3 ) );
		$links = $this->links();
		$links->create( 'For Priya', 7, 3 );
		$links->create( 'For Sam', 7, 4 );

		$export = $links->export( 'a@example.test' );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'For Priya', $export['data'][0]['data'][0]['value'] );

		$result = $links->erase( 'a@example.test' );
		$this->assertTrue( $result['items_removed'] );
		$this->assertEqualsCanonicalizing( array( 0, 4 ), array_column( $links->all(), 'by' ) );
		$this->assertCount( 2, $links->all(), 'The link keeps working.' );
		$this->assertNotContains( 'For Priya', array_column( $links->all(), 'name' ) );
	}
}
