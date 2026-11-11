<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard;

use AryanX\Axle\StagingGuard\Admin\AdminBar;
use AryanX\Axle\StagingGuard\Admin\LockNotice;
use AryanX\Axle\StagingGuard\Admin\SettingsPage;
use AryanX\Axle\StagingGuard\Cli\Command;
use AryanX\Axle\StagingGuard\Health\SiteHealth;
use AryanX\Axle\StagingGuard\Http\Guard as HttpGuard;
use AryanX\Axle\StagingGuard\Http\Log as HttpLog;
use AryanX\Axle\StagingGuard\Mail\Guard;
use AryanX\Axle\StagingGuard\Mail\Log;
use AryanX\Axle\StagingGuard\Search\Robots;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the services once and registers their hooks.
 *
 * The admin screen, Site Health check and WP-CLI commands load everywhere.
 * The guards (mail, search engines, admin bar) only load when this copy of
 * the site is not production.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Environment detection.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * Email guard.
	 *
	 * @var Guard
	 */
	private Guard $mail;

	/**
	 * Outgoing request guard.
	 *
	 * @var HttpGuard
	 */
	private HttpGuard $http;

	/**
	 * Runs once when the plugin is activated.
	 *
	 * A fresh install starts with outgoing requests set to "log only": nothing
	 * breaks, and the list shows which services the site calls, so the allowlist
	 * can be written from facts. Sites that already have settings keep "allow"
	 * (the default in Settings), so an update or a reactivation never changes
	 * what a staging site can reach.
	 */
	public static function activate(): void {
		if ( false === get_option( Settings::OPTION ) ) {
			( new Settings() )->update( array( 'http_mode' => 'log' ) );
		}
	}

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->settings    = new Settings();
		$this->environment = new Environment( $this->settings );
		$this->mail        = new Guard( $this->settings, $this->environment, new Log( $this->settings ) );
		$this->http        = new HttpGuard( $this->settings, $this->environment, new HttpLog( $this->mail->log() ) );
	}

	/**
	 * Environment detection, for other plugins.
	 *
	 * @return Environment
	 */
	public function environment(): Environment {
		return $this->environment;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		$services = array(
			$this->mail->log(),
			$this->http->log(),
			new SettingsPage( $this->settings, $this->environment, $this->mail, $this->http ),
			new SiteHealth( $this->settings, $this->environment, $this->mail, $this->http ),
			new LockNotice( $this->environment ),
			new Abilities( $this->settings, $this->environment, $this->mail, $this->http ),
		);

		if ( $this->environment->is_guarded() ) {
			$services[] = $this->mail;
			$services[] = $this->http;
			$services[] = new AdminBar( $this->environment, $this->mail );
			if ( $this->settings->get( 'noindex' ) ) {
				$services[] = new Robots();
			}
		}

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'axle-staging-guard', new Command( $this->settings, $this->environment, $this->mail, $this->http ) );
		}
	}
}
