<?php
/**
 * Tests for who an email may reach on a non-production copy.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Tests\Unit;

use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Mail\Guard;
use AryanX\Axle\StagingGuard\Mail\Log;
use AryanX\Axle\StagingGuard\Settings;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class MailGuardTest extends TestCase {

	/**
	 * Log entries written through wp_insert_post(), newest last.
	 *
	 * @var array[]
	 */
	private array $logged = array();

	/**
	 * The meta of the newest log entry.
	 *
	 * @return array
	 */
	private function last_entry(): array {
		$this->assertNotEmpty( $this->logged, 'Nothing was logged.' );

		return end( $this->logged )['meta_input'];
	}

	protected function setUp(): void {
		parent::setUp();

		$this->logged = array();
		Functions\when( 'wp_insert_post' )->alias(
			function ( array $post ) {
				$this->logged[] = $post;
				return count( $this->logged );
			}
		);
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'is_wp_error' )->justReturn( false );

		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'http://staging.example.test' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
	}

	/**
	 * A guard on a copy whose production lock points at another domain, so it is staging.
	 *
	 * @param array $settings Settings to store.
	 * @return Guard
	 */
	private function guard( array $settings ): Guard {
		$this->options[ Settings::OPTION ] = $settings + array( 'production_hash' => Environment::hash( 'live.example.com' ) );

		$store       = new Settings();
		$environment = new Environment( $store );

		return new Guard( $store, $environment, new Log( $store ) );
	}

	public function test_a_filter_can_let_one_email_through_untouched(): void {
		$guard = $this->guard( array( 'mail_mode' => 'block' ) );
		$atts  = array(
			'to'      => 'oncall@example.com',
			'subject' => 'Disk almost full',
			'message' => 'Alert',
		);
		Filters\expectApplied( 'axle_staging_guard_allow_email' )->once()->andReturn( true );

		$this->assertSame( $atts, $guard->filter_mail( $atts ) );
		$this->assertNull( $guard->short_circuit( null, $atts ) );
		$this->assertSame( array(), $this->logged, 'Allowed email is not logged.' );
	}

	public function test_the_policy_filter_can_switch_modes_but_not_skip_the_address_check(): void {
		$guard = $this->guard( array( 'mail_mode' => 'block' ) );

		Filters\expectApplied( 'axle_staging_guard_mail_policy' )->once()->andReturn(
			array(
				'mode'        => 'redirect',
				'redirect_to' => 'qa@agency.test',
				'allowlist'   => array(),
			)
		);
		$this->assertSame( 'redirect', $guard->policy()['mode'] );

		Filters\expectApplied( 'axle_staging_guard_mail_policy' )->once()->andReturn(
			array(
				'mode'        => 'redirect',
				'redirect_to' => 'nope',
				'allowlist'   => array(),
			)
		);
		$this->assertSame( 'block', $guard->policy()['mode'] );
	}

	public function test_split_accepts_strings_and_arrays_and_drops_blanks(): void {
		$this->assertSame( array( 'a@example.com', 'b@example.com' ), Guard::split( 'a@example.com, ,b@example.com' ) );
		$this->assertSame( array( 'a@example.com' ), Guard::split( array( ' a@example.com ', '' ) ) );
	}

	public function test_address_reads_the_part_inside_angle_brackets(): void {
		$this->assertSame( 'sam@example.com', Guard::address( 'Sam Lee <Sam@Example.com>' ) );
		$this->assertSame( 'sam@example.com', Guard::address( ' sam@example.com ' ) );
	}

	public function test_allowlist_matches_exact_addresses_and_whole_domains(): void {
		$guard = $this->guard( array( 'allowlist' => array( '@agency.test', 'qa@client.test' ) ) );

		$this->assertTrue( $guard->is_allowed( 'dev@agency.test' ) );
		$this->assertTrue( $guard->is_allowed( 'QA@client.test' ) );
		$this->assertFalse( $guard->is_allowed( 'ceo@client.test' ) );
		$this->assertFalse( $guard->is_allowed( 'dev@notagency.test.evil.com' ) );
	}

	public function test_redirect_without_a_valid_address_falls_back_to_blocking(): void {
		$guard = $this->guard(
			array(
				'mail_mode'   => 'redirect',
				'redirect_to' => 'not-an-email',
			)
		);

		$this->assertSame( 'block', $guard->policy()['mode'] );
	}

	public function test_block_mode_stops_the_email_reports_success_and_logs_it(): void {
		$guard = $this->guard( array( 'mail_mode' => 'block' ) );

		$guard->filter_mail(
			array(
				'to'      => 'customer@example.com',
				'subject' => 'Your order',
				'message' => 'Body',
			)
		);

		$this->assertTrue( $guard->short_circuit( null, array() ) );
		$this->assertNull( $guard->short_circuit( null, array() ), 'Only the email that was blocked is stopped.' );

		$entry = $this->last_entry();
		$this->assertSame( 'blocked', $entry['_axle_staging_guard_action'] );
		$this->assertSame( 'customer@example.com', $entry['_axle_staging_guard_to'] );
		$this->assertSame( '', $entry['_axle_staging_guard_body'], 'Bodies are not kept unless the setting is on.' );
		$this->assertSame( Log::POST_TYPE, $this->logged[0]['post_type'] );
	}

	public function test_block_mode_still_delivers_to_allowlisted_recipients(): void {
		$guard = $this->guard(
			array(
				'mail_mode' => 'block',
				'allowlist' => array( '@agency.test' ),
			)
		);

		$atts = $guard->filter_mail(
			array(
				'to'      => array( 'customer@example.com', 'dev@agency.test' ),
				'subject' => 'Your order',
				'headers' => "Cc: boss@example.com, lead@agency.test\r\nFrom: Shop <shop@example.com>",
			)
		);

		$this->assertSame( array( 'dev@agency.test' ), $atts['to'] );
		$this->assertSame( array( 'Cc: lead@agency.test', 'From: Shop <shop@example.com>' ), $atts['headers'] );
		$this->assertNull( $guard->short_circuit( null, array() ) );
		$this->assertSame( 'partly_blocked', $this->last_entry()['_axle_staging_guard_action'] );
	}

	public function test_redirect_mode_sends_to_the_test_address_with_the_real_recipients_in_the_subject(): void {
		$guard = $this->guard(
			array(
				'mail_mode'   => 'redirect',
				'redirect_to' => 'qa@agency.test',
				'allowlist'   => array( '@agency.test' ),
				'keep_bodies' => true,
			)
		);

		$atts = $guard->filter_mail(
			array(
				'to'      => array( 'customer@example.com', 'dev@agency.test' ),
				'subject' => 'Your order',
				'message' => 'Order body',
				'headers' => array( 'Bcc: audit@example.com' ),
			)
		);

		$this->assertSame( array( 'dev@agency.test', 'qa@agency.test' ), $atts['to'] );
		$this->assertSame( '[STAGING → customer@example.com, audit@example.com] Your order', $atts['subject'] );
		$this->assertSame( array(), $atts['headers'] );
		$this->assertSame( 'Order body', $this->last_entry()['_axle_staging_guard_body'] );
	}

	public function test_allow_mode_leaves_email_alone(): void {
		$guard = $this->guard( array( 'mail_mode' => 'allow' ) );
		$atts  = array(
			'to'      => 'customer@example.com',
			'subject' => 'Hi',
		);

		$this->assertSame( $atts, $guard->filter_mail( $atts ) );
		$this->assertSame( array(), $this->logged );
	}
}
