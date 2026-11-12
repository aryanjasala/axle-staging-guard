<?php
/**
 * Site Health check.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Health;

use AryanX\Axle\StagingGuard\Admin\SettingsPage;
use AryanX\Axle\StagingGuard\Cron\Hold;
use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Gate\Gate;
use AryanX\Axle\StagingGuard\Http\Guard as HttpGuard;
use AryanX\Axle\StagingGuard\Mail\Guard;
use AryanX\Axle\StagingGuard\Service;
use AryanX\Axle\StagingGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a Axle Staging Guard test to Tools > Site Health.
 */
final class SiteHealth implements Service {

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
	 * The password gate.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Holds scheduled tasks.
	 *
	 * @var Hold
	 */
	private Hold $hold;

	/**
	 * Sets up the check.
	 *
	 * @param Settings    $settings    Saved settings.
	 * @param Environment $environment Environment detection.
	 * @param Guard       $mail        Email guard.
	 * @param HttpGuard   $http        Outgoing request guard.
	 * @param Gate        $gate        The password gate.
	 * @param Hold        $hold        Holds scheduled tasks.
	 */
	public function __construct( Settings $settings, Environment $environment, Guard $mail, HttpGuard $http, Gate $gate, Hold $hold ) {
		$this->settings    = $settings;
		$this->environment = $environment;
		$this->mail        = $mail;
		$this->http        = $http;
		$this->gate        = $gate;
		$this->hold        = $hold;
	}

	/**
	 * Registers the test.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Adds the test to the direct (server-side) tests.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function add_test( array $tests ): array {
		$tests['direct']['axle_staging_guard'] = array(
			'label' => __( 'Axle Staging Guard', 'axle-staging-guard' ),
			'test'  => array( $this, 'run' ),
		);

		// These two only make sense on a copy that is not the live site.
		if ( $this->environment->is_guarded() ) {
			$tests['direct']['axle_staging_guard_gate']  = array(
				'label' => __( 'Axle Staging Guard password gate', 'axle-staging-guard' ),
				'test'  => array( $this, 'run_gate' ),
			);
			$tests['direct']['axle_staging_guard_tasks'] = array(
				'label' => __( 'Axle Staging Guard scheduled tasks', 'axle-staging-guard' ),
				'test'  => array( $this, 'run_tasks' ),
			);
		}

		return $tests;
	}

	/**
	 * Tests whether this copy is open to anyone with its address.
	 *
	 * @return array Site Health result.
	 */
	public function run_gate(): array {
		$action = sprintf( '<p><a href="%s">%s</a></p>', esc_url( add_query_arg( 'tab', 'gate', admin_url( 'tools.php?page=' . SettingsPage::SLUG ) ) ), esc_html__( 'Open the password gate', 'axle-staging-guard' ) );

		if ( $this->gate->is_active() ) {
			return $this->result( 'good', __( 'This copy is behind a password', 'axle-staging-guard' ), __( 'Visitors who are not logged in need the password or a share link to see this copy.', 'axle-staging-guard' ), $action, 'axle_staging_guard_gate' );
		}

		return $this->result( 'recommended', __( 'This staging copy is open to anyone with its address', 'axle-staging-guard' ), __( 'Staging addresses show up in certificate logs and link lists, so people who were never told about this copy can find it. Turn on the password gate to keep them out, and let a client in with a share link.', 'axle-staging-guard' ), $action, 'axle_staging_guard_gate' );
	}

	/**
	 * Tests whether tasks that look risky are still running on this copy.
	 *
	 * @return array Site Health result.
	 */
	public function run_tasks(): array {
		$action = sprintf( '<p><a href="%s">%s</a></p>', esc_url( add_query_arg( 'tab', 'tasks', admin_url( 'tools.php?page=' . SettingsPage::SLUG ) ) ), esc_html__( 'Open scheduled tasks', 'axle-staging-guard' ) );
		$tasks  = $this->hold->tasks();
		$open   = array_values( array_diff( $tasks->undecided_suggestions(), $tasks->held() ) );

		if ( $open ) {
			return $this->result(
				'recommended',
				__( 'Scheduled tasks that may reach the outside world are still running', 'axle-staging-guard' ),
				sprintf(
					/* translators: %s: names of scheduled tasks, separated by commas. */
					__( 'These tasks look like they send, export or sync data and nothing has been decided about them on this copy: %s. Hold the ones that should not run here.', 'axle-staging-guard' ),
					implode( ', ', array_slice( $open, 0, 5 ) ) . ( count( $open ) > 5 ? '...' : '' )
				),
				$action,
				'axle_staging_guard_tasks'
			);
		}

		return $this->result( 'good', __( 'Scheduled tasks on this copy are accounted for', 'axle-staging-guard' ), __( 'Every task that looks like it reaches the outside world has been held or left running on purpose.', 'axle-staging-guard' ), $action, 'axle_staging_guard_tasks' );
	}

	/**
	 * Runs the test.
	 *
	 * @return array Site Health result.
	 */
	public function run(): array {
		$guarded = $this->environment->is_guarded();
		$action  = sprintf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'tools.php?page=' . SettingsPage::SLUG ) ), esc_html__( 'Open Axle Staging Guard', 'axle-staging-guard' ) );

		if ( $guarded && $this->mail->wp_mail_is_replaced() ) {
			return $this->result( 'critical', __( 'Email from this staging copy can reach real people', 'axle-staging-guard' ), __( 'Another plugin has replaced wp_mail(), so Axle Staging Guard cannot stop email here. Turn off sending in that plugin on this copy.', 'axle-staging-guard' ), $action );
		}

		if ( $guarded && 'allow' === $this->mail->policy()['mode'] ) {
			return $this->result( 'recommended', __( 'This staging copy sends email normally', 'axle-staging-guard' ), __( 'Axle Staging Guard is set to send email normally, so customers can receive email from this copy.', 'axle-staging-guard' ), $action );
		}

		$http_open = $guarded && 'allow' === $this->http->policy()['mode'];
		$http_note = __( 'Outgoing requests are set to allow all, so payment gateways, CRM and marketing syncs, webhooks and other services can still be reached from this copy with live keys. Block all except the hosts you list, or switch to log only to see what this site calls.', 'axle-staging-guard' );

		$summary = sprintf(
			/* translators: 1: what happens to email, e.g. "Blocked", 2: what happens to outgoing requests, e.g. "Logged, not blocked", 3: "hidden" or "not hidden". */
			__( 'Email: %1$s. Outgoing requests: %2$s. Search engines: %3$s.', 'axle-staging-guard' ),
			$this->mail->describe(),
			$this->http->describe(),
			$this->settings->get( 'noindex' ) ? __( 'hidden', 'axle-staging-guard' ) : __( 'not hidden', 'axle-staging-guard' )
		);

		if ( $this->environment->is_staging_by_lock() ) {
			return $this->result(
				'recommended',
				__( 'This site is treated as staging because of the production lock', 'axle-staging-guard' ),
				$this->environment->explain() . ' ' . $summary . ' ' . __( 'If this is the live site and its address changed, lock it to this domain again. On a real staging copy, set WP_ENVIRONMENT_TYPE to staging in its wp-config.php to make it explicit.', 'axle-staging-guard' ) . ( $http_open ? ' ' . $http_note : '' ),
				$action
			);
		}

		if ( $http_open ) {
			return $this->result( 'recommended', __( 'This staging copy can call any outside service', 'axle-staging-guard' ), $http_note, $action );
		}

		if ( $guarded ) {
			return $this->result(
				'good',
				/* translators: %s: environment name, e.g. "Staging". */
				sprintf( __( 'This copy is guarded (%s)', 'axle-staging-guard' ), $this->environment->label() ),
				$summary,
				$action
			);
		}

		if ( 'default' === $this->environment->source() ) {
			return $this->result( 'recommended', __( 'Staging copies of this site will not be detected', 'axle-staging-guard' ), __( 'Nothing marks this site as the live one, so a copy of it on staging would also count as production and send real email. Set the production lock, or set WP_ENVIRONMENT_TYPE on each environment.', 'axle-staging-guard' ), $action );
		}

		return $this->result( 'good', __( 'Staging copies of this site will be guarded', 'axle-staging-guard' ), $this->environment->explain(), $action );
	}

	/**
	 * Builds a Site Health result.
	 *
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Heading.
	 * @param string $description Explanation.
	 * @param string $actions     Action links HTML.
	 * @param string $test        Name of the test.
	 * @return array
	 */
	private function result( string $status, string $label, string $description, string $actions, string $test = 'axle_staging_guard' ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'axle-staging-guard' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => $test,
		);
	}
}
