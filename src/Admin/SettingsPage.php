<?php
/**
 * Tools > Axle Staging Guard.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Admin;

use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Mail\Guard;
use AryanX\Axle\StagingGuard\Service;
use AryanX\Axle\StagingGuard\Settings;
use const AryanX\Axle\StagingGuard\PLUGIN_FILE;
use const AryanX\Axle\StagingGuard\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Status, settings, production lock, test email and the stopped email log.
 */
final class SettingsPage implements Service {

	public const SLUG = 'axle-staging-guard';

	private const ACTION = 'axle_staging_guard';

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
	 * Sets up the screen.
	 *
	 * @param Settings    $settings    Saved settings.
	 * @param Environment $environment Environment detection.
	 * @param Guard       $mail        Email guard.
	 */
	public function __construct( Settings $settings, Environment $environment, Guard $mail ) {
		$this->settings    = $settings;
		$this->environment = $environment;
		$this->mail        = $mail;
	}

	/**
	 * Adds the menu entry and form handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_form' ) );
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
	 * Registers Tools > Axle Staging Guard.
	 */
	public function add_page(): void {
		$hook = add_management_page(
			__( 'Axle Staging Guard', 'axle-staging-guard' ),
			__( 'Axle Staging Guard', 'axle-staging-guard' ),
			self::capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue_styles' ) );
		}
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
	 * Prints the screen.
	 */
	public function render(): void {
		$guarded = $this->environment->is_guarded();
		$policy  = $this->mail->policy();
		$log     = $this->mail->log()->all();
		$today   = $this->mail->log()->count_since( time() - DAY_IN_SECONDS );
		$tones   = array(
			'production'  => 'neutral',
			'staging'     => 'warning',
			'development' => 'info',
			'local'       => 'good',
		);
		$tone    = $tones[ $this->environment->type() ] ?? 'neutral';
		$mail_on = $guarded && 'allow' !== $policy['mode'];
		$hide_on = $guarded && $this->settings->get( 'noindex' );
		?>
		<div class="wrap axle-ui asg-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Staging Guard', 'axle-staging-guard' ); ?></h1>
			<span class="axle-status"><span class="axle-badge axle-badge--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $this->environment->label() ); ?></span></span>
			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<p class="axle-intro"><?php esc_html_e( 'On every copy of your site that is not the live one, this stops email from reaching real people, asks search engines to stay away and labels the admin bar. On the live site it does nothing.', 'axle-staging-guard' ); ?></p>

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
				<section class="axle-card">
					<h2 class="axle-card__title"><?php esc_html_e( 'When this is not the live site', 'axle-staging-guard' ); ?></h2>
					<?php $this->render_settings_form( $policy ); ?>
				</section>

				<div class="asg-side">
					<?php if ( 'constant' !== $this->environment->source() ) : ?>
						<section class="axle-card">
							<h2 class="axle-card__title"><?php esc_html_e( 'Production lock', 'axle-staging-guard' ); ?></h2>
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
				</div>
			</div>

			<?php $this->render_log( $log ); ?>

			<?php AxleUi::footer(); ?>
		</div>
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
									<time datetime="<?php echo esc_attr( gmdate( 'c', (int) $entry['time'] ) ); ?>" title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ); ?>">
										<?php
										/* translators: %s: time ago, e.g. "5 minutes". */
										echo esc_html( sprintf( __( '%s ago', 'axle-staging-guard' ), human_time_diff( (int) $entry['time'] ) ) );
										?>
									</time>
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
			<?php endif; ?>
		</section>

		<?php if ( $log ) : ?>
			<section class="axle-card axle-card--danger">
				<h2 class="axle-card__title"><?php esc_html_e( 'Clear the email list', 'axle-staging-guard' ); ?></h2>
				<p><?php esc_html_e( 'Removes every entry from the list above, including saved message bodies. The emails themselves are not affected. This cannot be undone.', 'axle-staging-guard' ); ?></p>
				<?php $this->render_button( 'clear_log', __( 'Clear list', 'axle-staging-guard' ), 'danger', __( 'Remove every entry from the stopped email list? This cannot be undone.', 'axle-staging-guard' ) ); ?>
			</section>
		<?php endif; ?>
		<?php
	}

	/**
	 * Prints a one-button form that runs a task.
	 *
	 * @param string $task    Task name.
	 * @param string $label   Button text.
	 * @param string $style   secondary or danger.
	 * @param string $confirm Question to ask first, for actions that cannot be undone.
	 */
	private function render_button( string $task, string $label, string $style = 'secondary', string $confirm = '' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asg-button-form">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="task" value="<?php echo esc_attr( $task ); ?>">
			<button type="submit" class="<?php echo esc_attr( 'danger' === $style ? 'button axle-button--danger' : 'button' ); ?>"<?php echo $confirm ? ' onclick="' . esc_attr( 'return window.confirm( ' . wp_json_encode( $confirm ) . ' );' ) . '"' : ''; ?>><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
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
			'saved'    => __( 'Settings saved.', 'axle-staging-guard' ),
			'locked'   => __( 'Production is locked to this domain. Copies on other domains are now treated as staging.', 'axle-staging-guard' ),
			'unlocked' => __( 'Production lock removed.', 'axle-staging-guard' ),
			'cleared'  => __( 'Email list cleared.', 'axle-staging-guard' ),
		);
		$tests    = array(
			'blocked'        => __( 'Test email blocked. It is listed under Stopped email.', 'axle-staging-guard' ),
			'redirected'     => __( 'Test email redirected to the test address.', 'axle-staging-guard' ),
			'partly_blocked' => __( 'Test email partly blocked.', 'axle-staging-guard' ),
			'sent'           => __( 'Test email handed to the mail server.', 'axle-staging-guard' ),
			'failed'         => __( 'WordPress could not send the test email. Check the site\'s mail setup.', 'axle-staging-guard' ),
		);

		if ( isset( $messages[ $message ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $message ] ) );
		}

		if ( isset( $tests[ $result ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible asg-test-result"><p>%s</p></div>', 'failed' === $result ? 'error' : 'info', esc_html( $tests[ $result ] ) );
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
				break;

			case 'unlock':
				$this->environment->unlock();
				$args['sg_message'] = 'unlocked';
				break;

			case 'clear_log':
				$this->mail->log()->clear();
				$args['sg_message'] = 'cleared';
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
