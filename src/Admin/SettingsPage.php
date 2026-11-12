<?php
/**
 * Tools > Staging Guard.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Admin;

use AryanX\Axle\StagingGuard\Cron\Hold;
use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Gate\Gate;
use AryanX\Axle\StagingGuard\Http\Guard as HttpGuard;
use AryanX\Axle\StagingGuard\Http\Rules as HttpRules;
use AryanX\Axle\StagingGuard\Mail\Guard;
use AryanX\Axle\StagingGuard\Service;
use AryanX\Axle\StagingGuard\Settings;
use const AryanX\Axle\StagingGuard\PLUGIN_FILE;
use const AryanX\Axle\StagingGuard\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Status, settings, production lock, test email, test URL and the stopped email and request lists,
 * with tabs for the password gate and the scheduled tasks.
 */
final class SettingsPage implements Service {

	public const SLUG = 'axle-staging-guard';

	private const ACTION = 'axle_staging_guard';

	public const UNDO_ACTION = 'axle_staging_guard_undo';

	private const STEPS_META = 'axle_staging_guard_steps_dismissed';

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
	 * The Password gate tab.
	 *
	 * @var GateTab
	 */
	private GateTab $gate_tab;

	/**
	 * The Scheduled tasks tab.
	 *
	 * @var TasksTab
	 */
	private TasksTab $tasks_tab;

	/**
	 * Sets up the screen.
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
		$this->gate_tab    = new GateTab( $settings, $environment, $gate );
		$this->tasks_tab   = new TasksTab( $settings, $environment, $hold );
	}

	/**
	 * Adds the menu entry and form handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_form' ) );
		add_action( 'admin_post_' . self::UNDO_ACTION, array( $this, 'handle_undo' ) );
		$this->gate_tab->register();
		$this->tasks_tab->register();
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Capability needed to see and change Axle Staging Guard.
	 *
	 * On multisite only network admins may, so one site admin on a staging
	 * network cannot switch email back on for everyone.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to see and change Axle Staging Guard.
		 *
		 * @param string $capability Default manage_options, or manage_network_options on multisite.
		 */
		return (string) apply_filters( 'axle_staging_guard_capability', is_multisite() ? 'manage_network_options' : 'manage_options' );
	}

	/**
	 * Registers Tools > Staging Guard.
	 */
	public function add_page(): void {
		$hook = add_management_page(
			__( 'Axle Staging Guard', 'axle-staging-guard' ),
			__( 'Staging Guard', 'axle-staging-guard' ),
			self::capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue_styles' ) );
			add_action( "admin_print_scripts-{$hook}", array( $this, 'enqueue_scripts' ) );
			add_action( "load-{$hook}", array( $this, 'add_help' ) );
		}
	}

	/**
	 * The tab being shown: settings, gate or tasks.
	 *
	 * @return string
	 */
	private function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses which tab to show; nothing is changed.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

		return in_array( $tab, array( 'settings', 'gate', 'tasks' ), true ) ? $tab : 'settings';
	}

	/**
	 * Adds the Help tabs for the tab being shown.
	 */
	public function add_help(): void {
		switch ( $this->current_tab() ) {
			case 'gate':
				AxleUi::help_tabs( GateTab::help() );
				return;

			case 'tasks':
				AxleUi::help_tabs( TasksTab::help() );
				return;
		}

		AxleUi::help_tabs(
			array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'axle-staging-guard' ),
					'content' => '<p>' . esc_html__( 'Staging Guard makes a copy of your site safe to work on. On any copy that is not the live site it stops email from reaching real people, can stop calls to other services, asks search engines to stay away and labels the admin bar. On the live site it does nothing.', 'axle-staging-guard' ) . '</p>',
				),
				array(
					'id'      => 'lock',
					'title'   => __( 'Production lock', 'axle-staging-guard' ),
					'content' => '<p>' . esc_html__( 'WordPress cannot tell a copy from the live site unless you say so. Lock production once, on the live site. Every copy of its database on another domain is then treated as staging, with no change to wp-config.php.', 'axle-staging-guard' ) . '</p>',
				),
				array(
					'id'      => 'email',
					'title'   => __( 'Email', 'axle-staging-guard' ),
					'content' => '<p>' . esc_html__( 'Blocking is the safe choice. Redirect sends every email to one test address. The "always deliver to" list lets your own team receive real email. Stopped email is listed at the bottom, and message bodies are only kept if you turn that on.', 'axle-staging-guard' ) . '</p>',
				),
				array(
					'id'      => 'requests',
					'title'   => __( 'Calls to other services', 'axle-staging-guard' ),
					'content' => '<p>' . esc_html__( 'Start with log only to see which services this site calls. Then block everything except the hosts you list. Use Test a URL to check what would happen to an address before anything is sent.', 'axle-staging-guard' ) . '</p>',
				),
			)
		);
	}

	/**
	 * Screen script: copy buttons, in-place confirm steps and live announcements.
	 */
	public function enqueue_scripts(): void {
		wp_enqueue_script( 'axle-staging-guard-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-a11y', 'wp-i18n', 'wp-hooks' ), VERSION, true );
		wp_set_script_translations( 'axle-staging-guard-admin', 'axle-staging-guard' );
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public function add_settings_link( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ), esc_html__( 'Settings', 'axle-staging-guard' ) ) );

		return $links;
	}

	/**
	 * Screen styles.
	 */
	public function enqueue_styles(): void {
		AxleUi::enqueue();
		wp_enqueue_style( 'axle-staging-guard-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
	}

	/**
	 * Prints the screen: header, tabs and the tab being shown.
	 */
	public function render(): void {
		$tab   = $this->current_tab();
		$tones = array(
			'production'  => 'neutral',
			'staging'     => 'warning',
			'development' => 'info',
			'local'       => 'good',
		);
		$tone  = $tones[ $this->environment->type() ] ?? 'neutral';
		?>
		<div class="wrap axle-ui asg-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Staging Guard', 'axle-staging-guard' ); ?></h1>
			<span class="axle-status"><span class="axle-badge axle-badge--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $this->environment->label() ); ?></span></span>
			<hr class="wp-header-end">

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Staging Guard sections', 'axle-staging-guard' ); ?>">
				<?php
				$tabs = array(
					'settings' => __( 'Guards', 'axle-staging-guard' ),
					'gate'     => __( 'Password gate', 'axle-staging-guard' ),
					'tasks'    => __( 'Scheduled tasks', 'axle-staging-guard' ),
				);
				foreach ( $tabs as $slug => $label ) {
					printf(
						'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
						esc_url( add_query_arg( 'tab', $slug, admin_url( 'tools.php?page=' . self::SLUG ) ) ),
						$slug === $tab ? ' nav-tab-active' : '',
						$slug === $tab ? ' aria-current="page"' : '',
						esc_html( $label )
					);
				}
				?>
			</nav>

			<?php
			if ( 'gate' === $tab ) {
				$this->gate_tab->render();
			} elseif ( 'tasks' === $tab ) {
				$this->tasks_tab->render();
			} else {
				$this->render_overview();
			}
			?>

			<?php AxleUi::footer(); ?>
		</div>
		<?php
	}

	/**
	 * Prints the first tab: what the guards do and their settings.
	 */
	private function render_overview(): void {
		$guarded = $this->environment->is_guarded();
		$policy  = $this->mail->policy();
		$log     = $this->mail->log()->all();
		$today   = $this->mail->log()->count_since( time() - DAY_IN_SECONDS );
		$mail_on = $guarded && 'allow' !== $policy['mode'];
		$http    = $this->http->policy();
		$http_in = $this->http->log()->count_since( time() - DAY_IN_SECONDS );
		$http_ui = array(
			'block' => 'good',
			'log'   => 'info',
			'allow' => 'warning',
		);
		$hide_on = $guarded && $this->settings->get( 'noindex' );
		?>
			<?php $this->render_notices(); ?>

			<p class="axle-intro"><?php esc_html_e( 'On every copy of your site that is not the live one, this stops email from reaching real people, can stop or record calls to other services such as payment gateways and CRMs, asks search engines to stay away and labels the admin bar. On the live site it does nothing.', 'axle-staging-guard' ); ?></p>

			<?php $this->render_steps(); ?>

			<div class="axle-grid asg-states">
				<section class="axle-card asg-state">
					<h2 class="axle-card__title"><?php esc_html_e( 'This copy', 'axle-staging-guard' ); ?></h2>
					<p><span class="axle-badge axle-badge--<?php echo $guarded ? 'good' : 'neutral'; ?>"><?php echo $guarded ? esc_html__( 'Guards on', 'axle-staging-guard' ) : esc_html__( 'Guards off', 'axle-staging-guard' ); ?></span></p>
					<p><?php echo esc_html( $this->environment->explain() ); ?></p>
				</section>
				<section class="axle-card asg-state">
					<h2 class="axle-card__title"><?php esc_html_e( 'Email', 'axle-staging-guard' ); ?></h2>
					<p><span class="axle-badge axle-badge--<?php echo $mail_on ? 'good' : ( $guarded ? 'warning' : 'neutral' ); ?>"><?php echo esc_html( $guarded ? $this->mail->describe() : __( 'Sent normally (live site)', 'axle-staging-guard' ) ); ?></span></p>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of emails. */
								_n( '%d email stopped in the last 24 hours.', '%d emails stopped in the last 24 hours.', $today, 'axle-staging-guard' ),
								$today
							)
						);
						?>
					</p>
				</section>
				<section class="axle-card asg-state">
					<h2 class="axle-card__title"><?php esc_html_e( 'Outgoing requests', 'axle-staging-guard' ); ?></h2>
					<p><span class="axle-badge axle-badge--<?php echo esc_attr( $guarded ? $http_ui[ $http['mode'] ] : 'neutral' ); ?>"><?php echo esc_html( $guarded ? $this->http->describe() : __( 'Not changed (live site)', 'axle-staging-guard' ) ); ?></span></p>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of different requests. */
								_n( '%d different request stopped or recorded in the last 24 hours.', '%d different requests stopped or recorded in the last 24 hours.', $http_in, 'axle-staging-guard' ),
								$http_in
							)
						);
						?>
					</p>
				</section>
				<section class="axle-card asg-state">
					<h2 class="axle-card__title"><?php esc_html_e( 'Search engines', 'axle-staging-guard' ); ?></h2>
					<p>
						<span class="axle-badge axle-badge--<?php echo $hide_on ? 'good' : ( $guarded ? 'warning' : 'neutral' ); ?>">
							<?php
							if ( ! $guarded ) {
								esc_html_e( 'Not changed (live site)', 'axle-staging-guard' );
							} elseif ( $this->settings->get( 'noindex' ) ) {
								esc_html_e( 'Hidden', 'axle-staging-guard' );
							} else {
								esc_html_e( 'Not hidden', 'axle-staging-guard' );
							}
							?>
						</span>
					</p>
					<p><?php esc_html_e( 'Noindex header and meta tag, a blocking robots.txt, and no sitemap.', 'axle-staging-guard' ); ?></p>
				</section>
			</div>

			<?php if ( $this->mail->wp_mail_is_replaced() ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Another plugin has replaced the wp_mail() function, so Axle Staging Guard cannot stop or redirect email on this site. Turn off sending in that plugin on staging.', 'axle-staging-guard' ); ?></p></div>
			<?php endif; ?>

			<div class="asg-columns">
				<div class="asg-main">
					<section class="axle-card">
						<h2 class="axle-card__title"><?php esc_html_e( 'When this is not the live site', 'axle-staging-guard' ); ?></h2>
						<?php $this->render_settings_form( $policy ); ?>
					</section>

					<section class="axle-card asg-http" id="asg-http">
						<h2 class="axle-card__title"><?php esc_html_e( 'Calls to other services', 'axle-staging-guard' ); ?></h2>
						<?php $this->render_http_form( $http ); ?>
					</section>
				</div>

				<div class="asg-side">
					<?php if ( 'constant' !== $this->environment->source() ) : ?>
						<section class="axle-card">
							<h2 class="axle-card__title" id="asg-lock"><?php esc_html_e( 'Production lock', 'axle-staging-guard' ); ?></h2>
							<p><?php esc_html_e( 'Set this once on the live site. Every copy of its database on another domain is then treated as staging, with no change to wp-config.php.', 'axle-staging-guard' ); ?></p>
							<?php
							if ( $this->environment->is_locked() ) {
								$this->render_button( 'unlock', __( 'Remove production lock', 'axle-staging-guard' ) );
							} else {
								/* translators: %s: domain name. */
								$this->render_button( 'lock', sprintf( __( 'This is the live site: lock to %s', 'axle-staging-guard' ), $this->environment->domain() ) );
							}
							?>
						</section>
					<?php endif; ?>

					<section class="axle-card">
						<h2 class="axle-card__title"><?php esc_html_e( 'Send a test email', 'axle-staging-guard' ); ?></h2>
						<p><?php echo esc_html( $guarded ? __( 'See what happens to email from this copy.', 'axle-staging-guard' ) : __( 'This is the live site, so the test email is really sent.', 'axle-staging-guard' ) ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asg-inline-form">
							<?php wp_nonce_field( self::ACTION ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
							<input type="hidden" name="task" value="test_email">
							<label for="asg-test-to" class="screen-reader-text"><?php esc_html_e( 'Send to', 'axle-staging-guard' ); ?></label>
							<input type="email" id="asg-test-to" name="test_to" class="regular-text" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required>
							<?php submit_button( __( 'Send', 'axle-staging-guard' ), 'secondary', 'submit', false ); ?>
						</form>
					</section>

					<section class="axle-card">
						<h2 class="axle-card__title"><?php esc_html_e( 'Test a URL', 'axle-staging-guard' ); ?></h2>
						<p><?php esc_html_e( 'See what would happen to a request to an address. Nothing is sent and nothing is recorded.', 'axle-staging-guard' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asg-inline-form">
							<?php wp_nonce_field( self::ACTION ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
							<input type="hidden" name="task" value="test_url">
							<label for="asg-test-url" class="screen-reader-text"><?php esc_html_e( 'Address to test', 'axle-staging-guard' ); ?></label>
							<input type="url" id="asg-test-url" name="test_url" class="regular-text" placeholder="https://api.stripe.com/v1/charges" required>
							<?php submit_button( __( 'Test URL', 'axle-staging-guard' ), 'secondary', 'submit', false ); ?>
						</form>
					</section>
				</div>
			</div>

			<?php $this->render_log( $log ); ?>

			<?php $this->render_http_log( $http ); ?>
		<?php
	}

	/**
	 * Prints the settings form.
	 *
	 * @param array $policy Mail policy in effect.
	 */
	private function render_settings_form( array $policy ): void {
		$settings = $this->settings->all();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="task" value="save">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Outgoing email', 'axle-staging-guard' ); ?></th>
					<td>
						<fieldset <?php disabled( $policy['locked'] ); ?>>
							<legend class="screen-reader-text"><?php esc_html_e( 'Outgoing email', 'axle-staging-guard' ); ?></legend>
							<label><input type="radio" name="mail_mode" value="block" <?php checked( 'block', $settings['mail_mode'] ); ?>> <?php esc_html_e( 'Block (recommended)', 'axle-staging-guard' ); ?></label><br>
							<label><input type="radio" name="mail_mode" value="redirect" <?php checked( 'redirect', $settings['mail_mode'] ); ?>> <?php esc_html_e( 'Send everything to one test address', 'axle-staging-guard' ); ?></label><br>
							<label for="asg-redirect-to" class="screen-reader-text"><?php esc_html_e( 'Test address', 'axle-staging-guard' ); ?></label>
							<input type="email" id="asg-redirect-to" class="regular-text asg-indent" name="redirect_to" value="<?php echo esc_attr( $settings['redirect_to'] ); ?>" placeholder="qa@example.com"><br>
							<label><input type="radio" name="mail_mode" value="allow" <?php checked( 'allow', $settings['mail_mode'] ); ?>> <?php esc_html_e( 'Send normally', 'axle-staging-guard' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="asg-allowlist"><?php esc_html_e( 'Always deliver to', 'axle-staging-guard' ); ?></label></th>
					<td>
						<textarea id="asg-allowlist" name="allowlist" rows="3" class="large-text code" placeholder="@youragency.com&#10;client@example.com" <?php disabled( $policy['locked'] ); ?>><?php echo esc_textarea( implode( "\n", (array) $settings['allowlist'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One address or @domain per line. These recipients get email normally, so your team can test real flows. Everyone else is blocked or redirected.', 'axle-staging-guard' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email log', 'axle-staging-guard' ); ?></th>
					<td>
						<label><input type="checkbox" name="keep_bodies" value="1" <?php checked( $settings['keep_bodies'] ); ?> <?php disabled( $this->settings->is_fixed( 'keep_bodies' ) ); ?>> <?php esc_html_e( 'Keep message bodies in the log', 'axle-staging-guard' ); ?></label>
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of days. */
									_n( 'Useful for checking email content on staging. Off by default because emails can contain personal data and password reset links. Entries are deleted after %d day.', 'Useful for checking email content on staging. Off by default because emails can contain personal data and password reset links. Entries are deleted after %d days.', $this->mail->log()->days(), 'axle-staging-guard' ),
									$this->mail->log()->days()
								)
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Search engines', 'axle-staging-guard' ); ?></th>
					<td>
						<label><input type="checkbox" name="noindex" value="1" <?php checked( $settings['noindex'] ); ?> <?php disabled( $this->settings->is_fixed( 'noindex' ) ); ?>> <?php esc_html_e( 'Hide from search engines', 'axle-staging-guard' ); ?></label>
					</td>
				</tr>
			</table>
			<?php if ( $policy['locked'] ) : ?>
				<p class="description"><?php esc_html_e( 'Email settings are fixed in wp-config.php (AXLE_STAGING_GUARD_MAIL_MODE, AXLE_STAGING_GUARD_REDIRECT_TO or AXLE_STAGING_GUARD_ALLOWLIST).', 'axle-staging-guard' ); ?></p>
			<?php endif; ?>
			<?php if ( $this->settings->is_fixed( 'keep_bodies' ) || $this->settings->is_fixed( 'noindex' ) ) : ?>
				<p class="description"><?php esc_html_e( 'Greyed-out settings are fixed in wp-config.php (AXLE_STAGING_GUARD_KEEP_BODIES or AXLE_STAGING_GUARD_NOINDEX).', 'axle-staging-guard' ); ?></p>
			<?php endif; ?>
			<?php submit_button( __( 'Save settings', 'axle-staging-guard' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Prints the stopped email log.
	 *
	 * @param array[] $log Log entries.
	 */
	private function render_log( array $log ): void {
		$actions = array(
			'blocked'        => __( 'Blocked', 'axle-staging-guard' ),
			'redirected'     => __( 'Redirected', 'axle-staging-guard' ),
			'partly_blocked' => __( 'Partly blocked', 'axle-staging-guard' ),
		);
		$tones   = array(
			'blocked'        => 'error',
			'redirected'     => 'info',
			'partly_blocked' => 'warning',
		);
		?>
		<section class="axle-card asg-log">
			<h2 class="axle-card__title"><?php esc_html_e( 'Stopped email', 'axle-staging-guard' ); ?></h2>
			<?php if ( ! $log ) : ?>
				<div class="axle-empty">
					<p><?php esc_html_e( 'No email has been stopped yet. Email that this copy blocks or redirects is listed here.', 'axle-staging-guard' ); ?></p>
				</div>
			<?php else : ?>
				<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Stopped email', 'axle-staging-guard' ); ?>">
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'When', 'axle-staging-guard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'To', 'axle-staging-guard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Subject', 'axle-staging-guard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What happened', 'axle-staging-guard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) : ?>
							<tr class="asg-log__row">
								<td>
									<?php echo AxleUi::relative_time( (int) $entry['time'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside relative_time(). ?>
								</td>
								<td><?php echo esc_html( $entry['to'] ); ?></td>
								<td>
									<?php if ( ! empty( $entry['body'] ) ) : ?>
										<details>
											<summary><?php echo esc_html( $entry['subject'] ); ?></summary>
											<pre class="asg-log__body"><?php echo esc_html( $entry['body'] ); ?></pre>
										</details>
									<?php else : ?>
										<?php echo esc_html( $entry['subject'] ); ?>
									<?php endif; ?>
								</td>
								<td>
									<span class="axle-badge axle-badge--<?php echo esc_attr( $tones[ $entry['action'] ] ?? 'neutral' ); ?>"><?php echo esc_html( $actions[ $entry['action'] ] ?? $entry['action'] ); ?></span>
									<?php if ( ! empty( $entry['delivered'] ) ) : ?>
										<br><small>
											<?php
											/* translators: %s: email addresses. */
											echo esc_html( sprintf( __( 'Still delivered to %s', 'axle-staging-guard' ), $entry['delivered'] ) );
											?>
										</small>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</section>

		<?php if ( $log ) : ?>
			<section class="axle-card axle-card--danger">
				<h2 class="axle-card__title"><?php esc_html_e( 'Clear the email list', 'axle-staging-guard' ); ?></h2>
				<p><?php esc_html_e( 'Removes every entry from the list above, including saved message bodies. The emails themselves are not affected. This cannot be undone.', 'axle-staging-guard' ); ?></p>
				<?php
				Confirm::render(
					array(
						'id'      => 'asg-clear-mail',
						'action'  => self::ACTION,
						'fields'  => array( 'task' => 'clear_log' ),
						'open'    => __( 'Clear list', 'axle-staging-guard' ),
						'message' => __( 'This removes every stopped email from the list, including saved message bodies. The emails themselves are not affected. It cannot be undone.', 'axle-staging-guard' ),
						'submit'  => __( 'Clear list', 'axle-staging-guard' ),
						'cancel'  => __( 'Keep the list', 'axle-staging-guard' ),
						'class'   => 'button axle-button--danger',
					)
				);
				?>
			</section>
		<?php endif; ?>
		<?php
	}

	/**
	 * Prints the outgoing request settings form.
	 *
	 * @param array $http Outgoing request policy in effect.
	 */
	private function render_http_form( array $http ): void {
		$saved = $this->settings->all();
		$fixed = HttpGuard::fixed();
		?>
		<p><?php esc_html_e( 'Payment gateways, CRM and marketing syncs, webhooks and license checks make requests from the server, so a copy of the live site can still reach them with live keys. This applies to requests made through the WordPress HTTP API.', 'axle-staging-guard' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="task" value="save_http">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Outgoing requests', 'axle-staging-guard' ); ?></th>
					<td>
						<fieldset <?php disabled( $fixed['mode'] ); ?>>
							<legend class="screen-reader-text"><?php esc_html_e( 'Outgoing requests', 'axle-staging-guard' ); ?></legend>
							<label><input type="radio" name="http_mode" value="block" <?php checked( 'block', $saved['http_mode'] ); ?>> <?php esc_html_e( 'Block all except allowed hosts', 'axle-staging-guard' ); ?></label><br>
							<label><input type="radio" name="http_mode" value="log" <?php checked( 'log', $saved['http_mode'] ); ?>> <?php esc_html_e( 'Log only: let everything through and record it', 'axle-staging-guard' ); ?></label><br>
							<label><input type="radio" name="http_mode" value="allow" <?php checked( 'allow', $saved['http_mode'] ); ?>> <?php esc_html_e( 'Allow all', 'axle-staging-guard' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'A blocked request fails with an error, so the plugin that made it handles it like a network problem. Start with log only to see which services this site calls.', 'axle-staging-guard' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="asg-http-allow"><?php esc_html_e( 'Allowed hosts', 'axle-staging-guard' ); ?></label></th>
					<td>
						<textarea id="asg-http-allow" name="http_allowlist" rows="3" class="large-text code" placeholder="sandbox.paypal.com&#10;*.youragency.com" <?php disabled( $fixed['allowlist'] ); ?>><?php echo esc_textarea( implode( "\n", (array) $saved['http_allowlist'] ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'One host per line. Requests to these always go through. A name like *.example.com covers every subdomain but not example.com itself.', 'axle-staging-guard' ); ?>
						</p>
						<p class="description asg-always">
							<?php
							/* translators: %s: list of host names. */
							echo esc_html( sprintf( __( 'Always allowed: %s, and this machine (localhost, 127.0.0.1).', 'axle-staging-guard' ), implode( ', ', $http['defaults'] ) ) );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="asg-http-block"><?php esc_html_e( 'Blocked hosts', 'axle-staging-guard' ); ?></label></th>
					<td>
						<textarea id="asg-http-block" name="http_blocklist" rows="3" class="large-text code" placeholder="api.stripe.com&#10;*.mailchimp.com" <?php disabled( $fixed['blocklist'] ); ?>><?php echo esc_textarea( implode( "\n", (array) $saved['http_blocklist'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Optional. One host per line. These are blocked in every mode, even in allow all and log only, and even when they are also allowed above.', 'axle-staging-guard' ); ?></p>
					</td>
				</tr>
			</table>
			<?php if ( $http['locked'] ) : ?>
				<p class="description"><?php esc_html_e( 'Greyed-out request settings are fixed in wp-config.php (AXLE_STAGING_GUARD_HTTP_MODE, AXLE_STAGING_GUARD_HTTP_ALLOWED_HOSTS or AXLE_STAGING_GUARD_HTTP_BLOCKED_HOSTS).', 'axle-staging-guard' ); ?></p>
			<?php endif; ?>
			<?php submit_button( __( 'Save request settings', 'axle-staging-guard' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Prints the list of stopped and recorded requests.
	 *
	 * @param array $http Outgoing request policy in effect.
	 */
	private function render_http_log( array $http ): void {
		$log     = $this->http->log()->all();
		$actions = array(
			'blocked' => __( 'Blocked', 'axle-staging-guard' ),
			'logged'  => __( 'Logged', 'axle-staging-guard' ),
		);
		$tones   = array(
			'blocked' => 'error',
			'logged'  => 'info',
		);
		$callers = array(
			'plugin'    => __( 'Plugin', 'axle-staging-guard' ),
			'mu-plugin' => __( 'Must-use plugin', 'axle-staging-guard' ),
			'theme'     => __( 'Theme', 'axle-staging-guard' ),
		);
		?>
		<section class="axle-card asg-log asg-http-log">
			<h2 class="axle-card__title"><?php esc_html_e( 'Stopped and recorded requests', 'axle-staging-guard' ); ?></h2>
			<?php if ( ! $log ) : ?>
				<div class="axle-empty">
					<p>
						<?php
						echo esc_html(
							'allow' === $http['mode'] && ! $http['blocklist']
								? __( 'Nothing is listed because outgoing requests are set to allow all. Switch to log only to see which services this site calls.', 'axle-staging-guard' )
								: __( 'No outgoing request has been stopped or recorded yet. Requests to other services that this copy blocks or logs are listed here, with how often they repeat.', 'axle-staging-guard' )
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Stopped and recorded requests', 'axle-staging-guard' ); ?>">
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Last seen', 'axle-staging-guard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Request', 'axle-staging-guard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Made by', 'axle-staging-guard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Times', 'axle-staging-guard' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What happened', 'axle-staging-guard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $log as $entry ) :
							list( $caller_type, $caller_slug ) = array_pad( explode( ':', (string) $entry['caller'], 2 ), 2, '' );
							?>
							<tr class="asg-http-row">
								<td>
									<?php echo AxleUi::relative_time( (int) $entry['time'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside relative_time(). ?>
									<br><small>
										<?php
										/* translators: %s: how long ago, such as "2 hours ago". */
										printf( esc_html__( 'First seen %s', 'axle-staging-guard' ), AxleUi::relative_time( (int) $entry['first'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside relative_time().
										?>
									</small>
								</td>
								<td><code class="asg-http-url"><?php echo esc_html( $entry['method'] . ' ' . $entry['host'] . $entry['path'] ); ?></code></td>
								<td>
									<?php
									if ( isset( $callers[ $caller_type ] ) && '' !== $caller_slug ) {
										echo esc_html( $callers[ $caller_type ] . ': ' . $caller_slug );
									} else {
										esc_html_e( 'Not found', 'axle-staging-guard' );
									}
									?>
								</td>
								<td><?php echo esc_html( number_format_i18n( (int) $entry['count'] ) ); ?></td>
								<td><span class="axle-badge axle-badge--<?php echo esc_attr( $tones[ $entry['action'] ] ?? 'neutral' ); ?>"><?php echo esc_html( $actions[ $entry['action'] ] ?? $entry['action'] ); ?></span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of days. */
							_n( 'No query strings, bodies or headers are kept, and parts of the path that look like keys or email hashes are hidden. Entries are deleted after %d day.', 'No query strings, bodies or headers are kept, and parts of the path that look like keys or email hashes are hidden. Entries are deleted after %d days.', $this->http->log()->days(), 'axle-staging-guard' ),
							$this->http->log()->days()
						)
					);
					?>
				</p>
			<?php endif; ?>
		</section>

		<?php if ( $log ) : ?>
			<section class="axle-card axle-card--danger">
				<h2 class="axle-card__title"><?php esc_html_e( 'Clear the request list', 'axle-staging-guard' ); ?></h2>
				<p><?php esc_html_e( 'Removes every entry from the request list above. The requests themselves are not affected. This cannot be undone.', 'axle-staging-guard' ); ?></p>
				<?php
				Confirm::render(
					array(
						'id'      => 'asg-clear-http',
						'action'  => self::ACTION,
						'fields'  => array( 'task' => 'clear_http_log' ),
						'open'    => __( 'Clear request list', 'axle-staging-guard' ),
						'message' => __( 'This removes every stopped or recorded request from the list. The requests themselves are not affected. It cannot be undone.', 'axle-staging-guard' ),
						'submit'  => __( 'Clear request list', 'axle-staging-guard' ),
						'cancel'  => __( 'Keep the list', 'axle-staging-guard' ),
						'class'   => 'button axle-button--danger',
					)
				);
				?>
			</section>
		<?php endif; ?>
		<?php
	}

	/**
	 * Name of the short-lived transient that carries a "test a URL" result to the next page view.
	 *
	 * @return string
	 */
	private function url_result_key(): string {
		return 'axle_sg_url_' . get_current_user_id();
	}

	/**
	 * Prints a one-button form that runs a task.
	 *
	 * @param string $task  Task name.
	 * @param string $label Button text.
	 */
	private function render_button( string $task, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asg-button-form">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="task" value="<?php echo esc_attr( $task ); ?>">
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Prints the "Get started" card until every step is done or the user dismisses it.
	 */
	private function render_steps(): void {
		$dismissed = (array) get_user_meta( get_current_user_id(), self::STEPS_META, true );
		if ( in_array( 'overview', $dismissed, true ) ) {
			return;
		}

		$tab_url = static function ( string $tab ): string {
			return add_query_arg( 'tab', $tab, admin_url( 'tools.php?page=' . self::SLUG ) );
		};
		$steps   = array(
			array(
				'title' => __( 'Tell Staging Guard which copy is the live site', 'axle-staging-guard' ),
				'text'  => __( 'Lock production once, on the live site. Without it, a copy of the site cannot tell it is a copy.', 'axle-staging-guard' ),
				'done'  => 'default' !== $this->environment->source(),
				'url'   => '#asg-lock',
				'label' => __( 'Set the lock', 'axle-staging-guard' ),
			),
			array(
				'title' => __( 'See which services this site calls', 'axle-staging-guard' ),
				'text'  => __( 'Keep outgoing requests on log only for a day, then block everything except the hosts you list.', 'axle-staging-guard' ),
				'done'  => 'block' === $this->http->policy()['mode'] || $this->http->log()->count_since( 0 ) > 0,
				'url'   => '#asg-http',
				'label' => __( 'Open request settings', 'axle-staging-guard' ),
			),
			array(
				'title' => __( 'Put a password in front of the copy', 'axle-staging-guard' ),
				'text'  => __( 'Keep the public out and let a client in with one link.', 'axle-staging-guard' ),
				'done'  => $this->gate->is_active(),
				'url'   => $tab_url( 'gate' ),
				'label' => __( 'Set up the gate', 'axle-staging-guard' ),
			),
		);

		if ( ! in_array( false, wp_list_pluck( $steps, 'done' ), true ) ) {
			return;
		}
		?>
		<section class="axle-card asg-steps" aria-labelledby="asg-steps-title">
			<h2 class="axle-card__title" id="asg-steps-title"><?php esc_html_e( 'Get started', 'axle-staging-guard' ); ?></h2>
			<ol class="axle-steps">
				<?php foreach ( $steps as $step ) : ?>
					<li class="axle-step">
						<div class="axle-step__body">
							<strong class="axle-step__title"><?php echo esc_html( $step['title'] ); ?></strong>
							<p><?php echo esc_html( $step['text'] ); ?></p>
						</div>
						<?php if ( $step['done'] ) : ?>
							<span class="axle-badge axle-badge--good"><?php esc_html_e( 'Done', 'axle-staging-guard' ); ?></span>
						<?php else : ?>
							<a class="button" href="<?php echo esc_url( $step['url'] ); ?>"><?php echo esc_html( $step['label'] ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
			<?php $this->render_button( 'dismiss_steps', __( 'Hide this card', 'axle-staging-guard' ) ); ?>
		</section>
		<?php
	}

	/**
	 * The Undo link for a notice.
	 *
	 * @param string $what What to undo: gate_on, gate_off, lock, unlock or tasks. Anything else gives no link.
	 * @param string $tab  Tab to return to.
	 * @return string Escaped HTML, or an empty string.
	 */
	public static function undo_link( string $what, string $tab ): string {
		if ( ! in_array( $what, array( 'gate_on', 'gate_off', 'lock', 'unlock', 'tasks' ), true ) ) {
			return '';
		}

		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action' => self::UNDO_ACTION,
							'what'   => $what,
							'tab'    => $tab,
						),
						admin_url( 'admin-post.php' )
					),
					self::UNDO_ACTION
				)
			),
			esc_html__( 'Undo', 'axle-staging-guard' )
		);
	}

	/**
	 * Undoes the last change that offered an Undo link.
	 */
	public function handle_undo(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'axle-staging-guard' ), 403 );
		}

		check_admin_referer( self::UNDO_ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked above.
		$what = isset( $_GET['what'] ) ? sanitize_key( wp_unslash( $_GET['what'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$args = array( 'tab' => $tab );

		switch ( $what ) {
			case 'gate_on':
				$result = $this->gate->enable();
				if ( is_wp_error( $result ) ) {
					$args['sg_error'] = $result->get_error_code();
				}
				break;

			case 'gate_off':
				$this->gate->disable();
				break;

			case 'lock':
				$this->environment->lock();
				break;

			case 'unlock':
				$this->environment->unlock();
				break;

			case 'tasks':
				$state = get_transient( TasksTab::undo_key() );
				if ( is_array( $state ) && 2 === count( $state ) ) {
					delete_transient( TasksTab::undo_key() );
					$this->hold->save( (array) $state[0], array() );
					$this->settings->update( array( 'cron_decided' => (array) $state[1] ), false );
				} else {
					$args['sg_error'] = 'nothing_to_undo';
				}
				break;
		}

		if ( ! isset( $args['sg_error'] ) ) {
			$args['sg_message'] = 'undone';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Prints the result of the last action.
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		$message = isset( $_GET['sg_message'] ) ? sanitize_key( wp_unslash( $_GET['sg_message'] ) ) : '';
		$result  = isset( $_GET['sg_test'] ) ? sanitize_key( wp_unslash( $_GET['sg_test'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'saved'        => __( 'Settings saved.', 'axle-staging-guard' ),
			'locked'       => __( 'Production is locked to this domain. Copies on other domains are now treated as staging.', 'axle-staging-guard' ),
			'unlocked'     => __( 'Production lock removed.', 'axle-staging-guard' ),
			'cleared'      => __( 'Email list cleared.', 'axle-staging-guard' ),
			'http_saved'   => __( 'Request settings saved.', 'axle-staging-guard' ),
			'http_cleared' => __( 'Request list cleared.', 'axle-staging-guard' ),
			'undone'       => __( 'Undone.', 'axle-staging-guard' ),
			'steps_hidden' => __( 'The Get started card is hidden for you.', 'axle-staging-guard' ),
		);
		$errors   = array(
			'bad_url'         => __( 'That is not a valid http or https address.', 'axle-staging-guard' ),
			'nothing_to_undo' => __( 'There is nothing to undo any more. The saved choice was kept for ten minutes.', 'axle-staging-guard' ),
		);
		$tests    = array(
			'blocked'        => __( 'Test email blocked. It is listed under Stopped email.', 'axle-staging-guard' ),
			'redirected'     => __( 'Test email redirected to the test address.', 'axle-staging-guard' ),
			'partly_blocked' => __( 'Test email partly blocked.', 'axle-staging-guard' ),
			'sent'           => __( 'Test email handed to the mail server.', 'axle-staging-guard' ),
			'failed'         => __( 'WordPress could not send the test email. Check the site\'s mail setup.', 'axle-staging-guard' ),
		);

		if ( isset( $messages[ $message ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only value set by our own redirect.
			$undo = isset( $_GET['sg_undo'] ) ? self::undo_link( sanitize_key( wp_unslash( $_GET['sg_undo'] ) ), 'settings' ) : '';
			printf(
				'<div class="notice notice-success is-dismissible asg-notice" role="status"><p>%1$s%2$s</p></div>',
				esc_html( $messages[ $message ] ),
				'' !== $undo ? ' ' . $undo : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by undo_link().
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
		$checked = isset( $_GET['sg_url'] ) ? get_transient( $this->url_result_key() ) : false;
		if ( is_array( $checked ) ) {
			delete_transient( $this->url_result_key() );
			printf(
				'<div class="notice notice-info is-dismissible asg-notice asg-url-result" role="status"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html( sprintf( /* translators: %s: address without query string. */ __( 'Result for %s:', 'axle-staging-guard' ), $checked['host'] . $checked['path'] ) ),
				esc_html( $checked['explanation'] )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only value set by our own redirect.
		$error = isset( $_GET['sg_error'] ) ? sanitize_key( wp_unslash( $_GET['sg_error'] ) ) : $message;
		if ( isset( $errors[ $error ] ) ) {
			printf( '<div class="notice notice-error is-dismissible asg-notice" role="alert"><p>%s</p></div>', esc_html( $errors[ $error ] ) );
		}

		if ( isset( $tests[ $result ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible asg-notice asg-test-result" role="status"><p>%s</p></div>', 'failed' === $result ? 'error' : 'info', esc_html( $tests[ $result ] ) );
		}
	}

	/**
	 * Handles every form on the screen.
	 */
	public function handle_form(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'axle-staging-guard' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		$args = array();

		switch ( $task ) {
			case 'lock':
				$this->environment->lock();
				$args['sg_message'] = 'locked';
				$args['sg_undo']    = 'unlock';
				break;

			case 'unlock':
				$this->environment->unlock();
				$args['sg_message'] = 'unlocked';
				$args['sg_undo']    = 'lock';
				break;

			case 'clear_log':
				$this->mail->log()->clear();
				$args['sg_message'] = 'cleared';
				break;

			case 'clear_http_log':
				$this->http->log()->clear();
				$args['sg_message'] = 'http_cleared';
				break;

			case 'dismiss_steps':
				$dismissed   = array_filter( (array) get_user_meta( get_current_user_id(), self::STEPS_META, true ) );
				$dismissed[] = 'overview';
				update_user_meta( get_current_user_id(), self::STEPS_META, array_values( array_unique( $dismissed ) ) );
				$args['sg_message'] = 'steps_hidden';
				break;

			case 'test_url':
				$url = isset( $_POST['test_url'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['test_url'] ) ) ) : '';
				if ( ! HttpRules::is_url( $url ) ) {
					$args['sg_message'] = 'bad_url';
				} else {
					$decision = $this->http->evaluate( $url );
					// Kept for one page view. The address itself, which may carry a key, never goes into a redirect.
					set_transient(
						$this->url_result_key(),
						array(
							'host'        => $decision['host'],
							'path'        => $decision['path'],
							'explanation' => $this->http->explain( $decision ),
						),
						MINUTE_IN_SECONDS
					);
					$args['sg_url'] = 1;
				}
				break;

			case 'save_http':
				$fixed   = HttpGuard::fixed();
				$mode    = isset( $_POST['http_mode'] ) ? sanitize_key( wp_unslash( $_POST['http_mode'] ) ) : '';
				$changes = array(
					'http_mode'      => in_array( $mode, Settings::HTTP_MODES, true ) ? $mode : (string) $this->settings->get( 'http_mode' ),
					'http_allowlist' => isset( $_POST['http_allowlist'] ) ? Settings::parse_hosts( sanitize_textarea_field( wp_unslash( $_POST['http_allowlist'] ) ) ) : array(),
					'http_blocklist' => isset( $_POST['http_blocklist'] ) ? Settings::parse_hosts( sanitize_textarea_field( wp_unslash( $_POST['http_blocklist'] ) ) ) : array(),
				);
				foreach ( array(
					'mode'      => 'http_mode',
					'allowlist' => 'http_allowlist',
					'blocklist' => 'http_blocklist',
				) as $part => $key ) {
					// Fields fixed in wp-config.php are disabled, so the form does not send them.
					if ( $fixed[ $part ] ) {
						unset( $changes[ $key ] );
					}
				}
				$this->settings->update( $changes );
				$args['sg_message'] = 'http_saved';
				break;

			case 'test_email':
				$to              = isset( $_POST['test_to'] ) ? sanitize_email( wp_unslash( $_POST['test_to'] ) ) : '';
				$args['sg_test'] = is_email( $to ) ? $this->mail->send_test( $to ) : 'failed';
				break;

			case 'save':
				$mode    = isset( $_POST['mail_mode'] ) ? sanitize_key( wp_unslash( $_POST['mail_mode'] ) ) : 'block';
				$changes = array(
					'mail_mode'   => in_array( $mode, Settings::MAIL_MODES, true ) ? $mode : 'block',
					'redirect_to' => isset( $_POST['redirect_to'] ) ? sanitize_email( wp_unslash( $_POST['redirect_to'] ) ) : '',
					'allowlist'   => isset( $_POST['allowlist'] ) ? Settings::parse_allowlist( sanitize_textarea_field( wp_unslash( $_POST['allowlist'] ) ) ) : array(),
					'keep_bodies' => ! empty( $_POST['keep_bodies'] ),
					'noindex'     => ! empty( $_POST['noindex'] ),
				);
				foreach ( array( 'keep_bodies', 'noindex' ) as $key ) {
					if ( $this->settings->is_fixed( $key ) ) {
						unset( $changes[ $key ] );
					}
				}
				$this->settings->update( $changes );
				$args['sg_message'] = 'saved';
				break;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php?page=' . self::SLUG ) ) );
		exit;
	}
}
