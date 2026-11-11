<?php
/**
 * Tests for the default outgoing request mode on new and existing installs.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Tests\Unit;

use AryanX\Axle\StagingGuard\Plugin;
use AryanX\Axle\StagingGuard\Settings;

final class HttpModeDefaultsTest extends TestCase {

	public function test_an_existing_install_that_updates_keeps_allow_all(): void {
		// Settings saved by 2.0.x: no http keys at all.
		$this->options[ Settings::OPTION ] = array( 'mail_mode' => 'redirect' );

		$settings = new Settings();
		$this->assertSame( 'allow', $settings->get( 'http_mode' ) );
		$this->assertSame( array(), $settings->get( 'http_allowlist' ) );
		$this->assertSame( array(), $settings->get( 'http_blocklist' ) );
	}

	public function test_an_install_with_no_settings_yet_also_reads_allow_all_until_activated(): void {
		$this->assertSame( 'allow', ( new Settings() )->get( 'http_mode' ) );
	}

	public function test_a_fresh_activation_starts_on_log_only(): void {
		Plugin::activate();

		$this->assertSame( 'log', $this->options[ Settings::OPTION ]['http_mode'] );
		$this->assertSame( 'block', $this->options[ Settings::OPTION ]['mail_mode'], 'Email keeps its own default.' );
	}

	public function test_reactivating_never_changes_a_site_that_already_has_settings(): void {
		$this->options[ Settings::OPTION ] = array( 'mail_mode' => 'allow' );

		Plugin::activate();

		$this->assertArrayNotHasKey( 'http_mode', $this->options[ Settings::OPTION ] );
		$this->assertSame( 'allow', ( new Settings() )->get( 'http_mode' ) );
	}

	public function test_saved_hosts_are_cleaned_by_the_settings_parser(): void {
		$this->assertSame( array( 'api.example.com', '*.example.org' ), Settings::parse_hosts( "https://API.example.com/x\n*.example.org\n*" ) );
	}
}
