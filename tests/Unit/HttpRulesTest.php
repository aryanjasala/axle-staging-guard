<?php
/**
 * Tests for the host rules for outgoing requests.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Tests\Unit;

use AryanX\Axle\StagingGuard\Http\Rules;
use Brain\Monkey\Functions;

final class HttpRulesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	/**
	 * @dataProvider hosts
	 *
	 * @param string $url      URL.
	 * @param string $expected Host.
	 */
	public function test_host_is_lowercase_without_port_credentials_or_brackets( string $url, string $expected ): void {
		$this->assertSame( $expected, Rules::host( $url ) );
	}

	public function hosts(): array {
		return array(
			'plain'       => array( 'https://API.Stripe.com/v1/charges?key=sk_live_x', 'api.stripe.com' ),
			'port'        => array( 'http://staging.test:8080/x', 'staging.test' ),
			'credentials' => array( 'https://user:secret@hooks.example.com/a', 'hooks.example.com' ),
			'ipv6'        => array( 'http://[::1]:8080/', '::1' ),
			'trailing'    => array( 'https://example.com./x', 'example.com' ),
			'no host'     => array( '/just/a/path', '' ),
			'garbage'     => array( 'http:///', '' ),
		);
	}

	public function test_only_http_and_https_addresses_with_a_host_count_as_urls(): void {
		$this->assertTrue( Rules::is_url( 'https://api.stripe.com/v1/charges?x=1' ) );
		$this->assertTrue( Rules::is_url( 'http://localhost:8080/' ) );
		foreach ( array( 'not a url', 'api.stripe.com', 'ftp://example.com/file', 'javascript:alert(1)', 'https://', '' ) as $value ) {
			$this->assertFalse( Rules::is_url( $value ), $value );
		}
	}

	public function test_a_wildcard_matches_subdomains_at_any_depth_but_not_the_bare_domain(): void {
		$this->assertTrue( Rules::matches( 'api.example.com', '*.example.com' ) );
		$this->assertTrue( Rules::matches( 'a.b.example.com', '*.example.com' ) );
		$this->assertFalse( Rules::matches( 'example.com', '*.example.com' ) );
		$this->assertFalse( Rules::matches( 'notexample.com', '*.example.com' ) );
		$this->assertFalse( Rules::matches( 'example.com.evil.net', '*.example.com' ) );
	}

	public function test_a_plain_pattern_matches_only_that_host(): void {
		$this->assertTrue( Rules::matches( 'api.example.com', 'api.example.com' ) );
		$this->assertFalse( Rules::matches( 'x.api.example.com', 'api.example.com' ) );
		$this->assertFalse( Rules::matches( 'example.com', 'api.example.com' ) );
		$this->assertFalse( Rules::matches( '', 'api.example.com' ) );
		$this->assertFalse( Rules::matches( 'api.example.com', '' ) );
	}

	public function test_patterns_are_cleaned_and_unsafe_ones_refused(): void {
		$raw = "https://API.Mailchimp.com/3.0/lists?x=1, *.zapier.com\nhooks.slack.com:443\n*\n*.com\n*.\nbad_host!\n@example.com\nuser@example.net\nlocalhost\n*.zapier.com";

		$this->assertSame(
			array( 'api.mailchimp.com', '*.zapier.com', 'hooks.slack.com', 'example.com', 'example.net', 'localhost' ),
			Rules::parse_patterns( $raw ),
			'Schemes, ports and paths are dropped (an email address gives its domain), "*", "*.com" and invalid names are refused, duplicates collapse.'
		);
	}

	public function test_loopback_is_recognised(): void {
		foreach ( array( 'localhost', '127.0.0.1', '127.1.2.3', '::1', 'site.localhost' ) as $host ) {
			$this->assertTrue( Rules::is_loopback( $host ), $host );
		}
		foreach ( array( '10.0.0.5', '192.168.1.1', 'localhost.evil.com', '1270.0.0.1', 'example.com' ) as $host ) {
			$this->assertFalse( Rules::is_loopback( $host ), $host );
		}
	}

	/**
	 * @dataProvider decisions
	 *
	 * @param string $host     Host.
	 * @param string $mode     Mode.
	 * @param string $action   Expected action.
	 * @param string $reason   Expected reason.
	 */
	public function test_decisions( string $host, string $mode, string $action, string $reason ): void {
		$result = Rules::decide(
			$host,
			$mode,
			array( 'api.mailchimp.com', '*.agency.test' ),
			array( '*.stripe.com', 'hooks.agency.test' ),
			array( 'wordpress.org', '*.wordpress.org', 'staging.example.com' )
		);

		$this->assertSame( array( 'action' => $action, 'reason' => $reason ), $result );
	}

	public function decisions(): array {
		return array(
			'block: unknown host'            => array( 'api.paypal.com', 'block', 'block', 'mode_block' ),
			'log: unknown host'              => array( 'api.paypal.com', 'log', 'log', 'mode_log' ),
			'allow: unknown host'            => array( 'api.paypal.com', 'allow', 'allow', 'mode_allow' ),
			'block: allowlisted'             => array( 'api.mailchimp.com', 'block', 'allow', 'allowlisted' ),
			'block: allowlisted wildcard'    => array( 'x.agency.test', 'block', 'allow', 'allowlisted' ),
			'block: always allowed host'     => array( 'api.wordpress.org', 'block', 'allow', 'always_allowed' ),
			'block: the site itself'         => array( 'staging.example.com', 'block', 'allow', 'always_allowed' ),
			'block: loopback'                => array( '127.0.0.1', 'block', 'allow', 'always_allowed' ),
			'block: localhost'               => array( 'localhost', 'block', 'allow', 'always_allowed' ),
			'blocklist beats allow all'      => array( 'api.stripe.com', 'allow', 'block', 'blocklisted' ),
			'blocklist beats log only'       => array( 'api.stripe.com', 'log', 'block', 'blocklisted' ),
			'blocklist beats allowlist'      => array( 'hooks.agency.test', 'block', 'block', 'blocklisted' ),
			'a lookalike is not allowed'     => array( 'wordpress.org.evil.net', 'block', 'block', 'mode_block' ),
			'bare domain vs wildcard'        => array( 'agency.test', 'block', 'block', 'mode_block' ),
			'no host is left alone'          => array( '', 'block', 'allow', 'no_host' ),
		);
	}

	public function test_paths_lose_the_query_and_mask_secrets_and_identifiers(): void {
		$this->assertSame( '/v1/charges', Rules::clean_path( 'https://api.stripe.com/v1/charges?api_key=sk_live_123&email=a@b.co' ) );
		$this->assertSame( '/', Rules::clean_path( 'https://example.com' ) );
		$this->assertSame( '/services/T01ABCDE/B02FGHIJ/{token}', Rules::clean_path( 'https://hooks.slack.com/services/T01ABCDE/B02FGHIJ/XXXXXXXXXXXXXXXXXXXXXXXX1a' ) );
		$this->assertSame( '/3.0/lists/abc123/members/{hash}', Rules::clean_path( 'https://us1.api.mailchimp.com/3.0/lists/abc123/members/' . md5( 'sam@example.com' ) ) );
		$this->assertSame( '/users/{email}/profile', Rules::clean_path( 'https://api.example.com/users/sam%40example.com/profile' ) );
		$this->assertSame( '/{token}/sendMessage', Rules::clean_path( 'https://api.telegram.org/bot123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/sendMessage' ) );
		$this->assertSame( '/wp-json/wp/v2/posts', Rules::clean_path( 'https://example.com/wp-json/wp/v2/posts#top' ) );
		$this->assertLessThanOrEqual( 200, strlen( Rules::clean_path( 'https://example.com/' . str_repeat( 'a/', 400 ) ) ) );
	}
}
