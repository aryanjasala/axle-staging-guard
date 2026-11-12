<?php
/**
 * Tests for the gate's tokens, IP ranges and path patterns.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Tests\Unit;

use AryanX\Axle\StagingGuard\Gate\Access;

final class GateAccessTest extends TestCase {

	public function test_a_signature_only_verifies_with_the_same_secret_and_text(): void {
		$signature = Access::sign( 'link|abc|100', 'secret' );

		$this->assertTrue( Access::verify( 'link|abc|100', $signature, 'secret' ) );
		$this->assertFalse( Access::verify( 'link|abc|101', $signature, 'secret' ) );
		$this->assertFalse( Access::verify( 'link|abc|100', $signature, 'other' ) );
		$this->assertFalse( Access::verify( 'link|abc|100', $signature, '' ), 'No secret means nothing verifies.' );
	}

	public function test_tokens_parse_into_their_kind(): void {
		$link    = Access::parse_token( Access::link_token( 'a1b2c3d4e5', 200, 'secret' ) );
		$session = Access::parse_token( Access::session_token( 300, 'hash', 'secret' ) );

		$this->assertSame( 'link', $link['kind'] );
		$this->assertSame( 'a1b2c3d4e5', $link['id'] );
		$this->assertSame( 'session', $session['kind'] );
		$this->assertSame( 300, $session['expires'] );
	}

	public function test_a_password_change_invalidates_old_session_tokens(): void {
		$this->assertNotSame( Access::session_token( 300, 'old-hash', 'secret' ), Access::session_token( 300, 'new-hash', 'secret' ) );
	}

	/**
	 * @dataProvider bad_tokens
	 */
	public function test_values_that_are_not_tokens_are_refused( string $value ): void {
		$this->assertNull( Access::parse_token( $value ) );
	}

	public function bad_tokens(): array {
		return array(
			'empty'        => array( '' ),
			'spaces'       => array( 'a b.c' ),
			'one part'     => array( 'abcdef' ),
			'four parts'   => array( 'a.b.c.d' ),
			'session text' => array( 'pw.soon.sig' ),
			'too long'     => array( str_repeat( 'a', 130 ) . '.b' ),
			'markup'       => array( '<b>.x' ),
		);
	}

	public function test_ip_ranges_match_for_both_address_families(): void {
		$this->assertTrue( Access::ip_matches( '203.0.113.7', array( '203.0.113.7' ) ) );
		$this->assertTrue( Access::ip_matches( '10.4.5.6', array( '10.0.0.0/8' ) ) );
		$this->assertTrue( Access::ip_matches( '198.51.100.130', array( '198.51.100.128/25' ) ) );
		$this->assertFalse( Access::ip_matches( '198.51.100.127', array( '198.51.100.128/25' ) ) );
		$this->assertTrue( Access::ip_matches( '2001:db8::1', array( '2001:db8::/32' ) ) );
		$this->assertFalse( Access::ip_matches( '2001:db9::1', array( '2001:db8::/32' ) ) );
		$this->assertFalse( Access::ip_matches( '10.0.0.1', array( '2001:db8::/32' ) ), 'A v4 address never matches a v6 range.' );
		$this->assertFalse( Access::ip_matches( 'not an ip', array( '0.0.0.0/0' ) ) );
		$this->assertFalse( Access::ip_matches( '10.0.0.1', array( '', 'junk', '10.0.0.0/x' ) ) );
	}

	public function test_ip_lists_drop_invalid_entries(): void {
		$this->assertSame(
			array( '203.0.113.7', '10.0.0.0/8', '2001:db8::/32' ),
			Access::parse_ips( "203.0.113.7\n10.0.0.0/8, nonsense 10.0.0.0/33 2001:db8::/32 203.0.113.7" )
		);
	}

	public function test_path_lists_keep_only_path_characters_and_start_with_a_slash(): void {
		$this->assertSame( array( '/health', '/webhooks/*' ), Access::parse_paths( "health\n/webhooks/*?x=1#top" ) );
		$this->assertSame( array( '/wp-json/my-plugin/v1/*' ), Access::parse_paths( '/wp-json/my-plugin/v1/* /wp-json/my-plugin/v1/*' ) );
		$this->assertSame( array( '/scriptx' ), Access::parse_paths( '/script<x>' ) );
		$this->assertSame( array(), Access::parse_paths( "  \n<>" ) );
	}

	public function test_request_paths_are_normalised(): void {
		$this->assertSame( '/', Access::request_path( '/' ) );
		$this->assertSame( '/health', Access::request_path( '/health/?x=1' ) );
		$this->assertSame( '/health', Access::request_path( '/blog/health', '/blog' ) );
		$this->assertSame( '/x', Access::request_path( '/health/../x' ), 'A dot segment cannot pose as an open path.' );
		$this->assertSame( '/health', Access::request_path( '//health' ) );
		$this->assertSame( '/a b', Access::request_path( '/a%20b' ) );
	}

	public function test_path_patterns(): void {
		$this->assertTrue( Access::path_matches( '/health', array( '/health' ) ) );
		$this->assertTrue( Access::path_matches( '/health/', array( '/health' ) ) );
		$this->assertFalse( Access::path_matches( '/health/deep', array( '/health' ) ) );
		$this->assertTrue( Access::path_matches( '/webhooks/stripe', array( '/webhooks/*' ) ) );
		$this->assertFalse( Access::path_matches( '/webhookshop', array( '/webhooks/*' ) ) );
		$this->assertTrue( Access::path_matches( '/wp-json/my/v1/ping', array( '/wp-json/my/*' ) ) );
		$this->assertFalse( Access::path_matches( '/', array( '/health', '' ) ) );
		$this->assertTrue( Access::path_matches( '/', array( '/' ) ) );
	}
}
