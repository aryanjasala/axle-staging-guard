<?php
/**
 * Tools > Staging Guard > Password gate.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Admin;

use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Gate\Access;
use AryanX\Axle\StagingGuard\Gate\Gate;
use AryanX\Axle\StagingGuard\Service;
use AryanX\Axle\StagingGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The gate's password, who skips it, and the share links.
 */
final class GateTab implements Service {

	public const ACTION = 'axle_staging_guard_gate';

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
	 * The gate.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Sets up the tab.
	 *
	 * @param Settings    $settings    Saved settings.
	 * @param Environment $environment Environment detection.
	 * @param Gate        $gate        The gate.
	 */
	public function __construct( Settings $settings, Environment $environment, Gate $gate ) {
		$this->settings    = $settings;
		$this->environment = $environment;
		$this->gate        = $gate;
	}

	/**
	 * Adds the form handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_form' ) );
	}

	/**
	 * The Help tabs for this screen.
	 *
	 * @return array<int, array{id: string, title: string, content: string}>
	 */
	public static function help(): array {
		return array(
			array(
				'id'      => 'gate-overview',
				'title'   => __( 'Password gate', 'axle-staging-guard' ),
				'content' => '<p>' . esc_html__( 'The gate puts a password screen in front of this copy of the site. Visitors who are not logged in see the screen instead of the site. It only works on staging, development and local copies, and never on the live site. It is off until you turn it on.', 'axle-staging-guard' ) . '</p>',
			),
			array(
				'id'      => 'gate-links',
				'title'   => __( 'Share links', 'axle-staging-guard' ),
				'content' => '<p>' . esc_html__( 'A share link lets one person in without the password, for 1 to 30 days. Give each link a name, such as who it is for. The list shows when each link was last used. Revoke a link and that person is out on their next page view.', 'axle-staging-guard' ) . '</p>',
			),
			array(
				'id'      => 'gate-skip',
				'title'   => __( 'Who skips the gate', 'axle-staging-guard' ),
				'content' => '<p>' . esc_html__( 'Logged-in users skip the gate. So do the IP addresses you list, and the paths you list, such as a health check or a webhook. The login screen, WP-Cron and robots.txt always stay open. REST requests need a logged-in user, for example with an application password.', 'axle-staging-guard' ) . '</p>',
			),
		);
	}

	/**
	 * Maps an error code to its message.
	 *
	 * @param string $code Error code from a failed action.
	 * @return string
	 */
	private function error_text( string $code ): string {
		$errors = array(
			'axle_staging_guard_gate_password'    => sprintf(
				/* translators: %d: smallest number of characters. */
				_n( 'Use a password with at least %d character. The gate settings were not saved.', 'Use a password with at least %d characters. The gate settings were not saved.', $this->gate->min_password_length(), 'axle-staging-guard' ),
				$this->gate->min_password_length()
			),
			'axle_staging_guard_gate_no_password' => __( 'Set a password first. The gate cannot open without one, so it was not turned on.', 'axle-staging-guard' ),
			'axle_staging_guard_link_name'        => __( 'Give the link a name, such as "For Priya at the client", so you know whose it is.', 'axle-staging-guard' ),
			'axle_staging_guard_link_days'        => __( 'Choose how long the link should work.', 'axle-staging-guard' ),
			'axle_staging_guard_link_limit'       => __( 'There are too many share links. Revoke the ones you no longer need, then try again.', 'axle-staging-guard' ),
			'not_found'                           => __( 'That link no longer exists. Reload the page to see the current list.', 'axle-staging-guard' ),
		);

		return $errors[ $code ] ?? __( 'Something went wrong. Try again.', 'axle-staging-guard' );
	}

	/**
	 * Prints the notice for the last action.
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		$message = isset( $_GET['sg_message'] ) ? sanitize_key( wp_unslash( $_GET['sg_message'] ) ) : '';
		$error   = isset( $_GET['sg_error'] ) ? sanitize_key( wp_unslash( $_GET['sg_error'] ) ) : '';
		$link_id = isset( $_GET['sg_link'] ) ? sanitize_key( wp_unslash( $_GET['sg_link'] ) ) : '';
		$undo    = isset( $_GET['sg_undo'] ) ? sanitize_key( wp_unslash( $_GET['sg_undo'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'gate_saved'     => __( 'Gate settings saved.', 'axle-staging-guard' ),
			'gate_on'        => __( 'The gate is on. Visitors who are not logged in now see the password screen.', 'axle-staging-guard' ),
			'gate_off'       => __( 'The gate is off. This copy is open to anyone with the address.', 'axle-staging-guard' ),
			'link_revoked'   => __( 'Link revoked. The person who had it is out on their next page view.', 'axle-staging-guard' ),
			'undone'         => __( 'Undone.', 'axle-staging-guard' ),
			'password_saved' => __( 'Password changed. Everyone who entered the old one has to enter the new one.', 'axle-staging-guard' ),
		);

		if ( isset( $messages[ $message ] ) ) {
			$undo_link = SettingsPage::undo_link( $undo, 'gate' );
			printf(
				'<div class="notice notice-success is-dismissible asg-notice" role="status"><p>%1$s%2$s</p></div>',
				esc_html( $messages[ $message ] ),
				'' !== $undo_link ? ' ' . $undo_link : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by SettingsPage::undo_link().
			);
		}

		if ( 'link_created' === $message ) {
			$link = $this->gate->links()->find( $link_id );
			if ( $link ) {
				printf(
					'<div class="notice notice-success is-dismissible asg-notice asg-link-created" role="status"><p><strong>%1$s</strong></p><p><label for="asg-new-link" class="screen-reader-text">%2$s</label><input type="text" id="asg-new-link" class="large-text code" value="%3$s" readonly> <button type="button" class="button asg-copy" data-asg-copy="#asg-new-link">%4$s</button></p><p class="description">%5$s</p></div>',
					esc_html(
						sprintf(
							/* translators: %s: name of the link, such as "For Priya at the client". */
							__( 'Link made: %s', 'axle-staging-guard' ),
							$link['name']
						)
					),
					esc_html__( 'Share link', 'axle-staging-guard' ),
					esc_attr( $this->gate->links()->url( $link ) ),
					esc_html__( 'Copy link', 'axle-staging-guard' ),
					esc_html__( 'Anyone with this link can see the site until it expires or you revoke it. You can copy it again from the list below.', 'axle-staging-guard' )
				);
			}
		}

		if ( '' !== $error ) {
			printf( '<div class="notice notice-error is-dismissible asg-notice" role="alert"><p>%s</p></div>', esc_html( $this->error_text( $error ) ) );
		}
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		$guarded = $this->environment->is_guarded();
		$active  = $this->gate->is_active();
		$links   = $this->gate->links()->all();
		$live    = array_filter(
			$links,
			function ( array $link ): bool {
				return $this->gate->links()->is_active( $link );
			}
		);
		$saved   = $this->settings->all();

		$this->render_notices();
		?>
		<p class="axle-intro"><?php esc_html_e( 'Put a password in front of this copy, so only the people you choose can see it. Visitors who are not logged in get a password screen, or they use a share link you made for them.', 'axle-staging-guard' ); ?></p>

		<?php if ( ! $guarded ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'This is the live site, so the gate does nothing here. It only works on staging, development and local copies. You can set it up now and it will be ready on the copy.', 'axle-staging-guard' ); ?></p></div>
		<?php endif; ?>

		<div class="axle-stats">
			<div class="axle-stat axle-stat--<?php echo $active ? 'good' : 'warning'; ?>">
				<span class="axle-stat__value"><?php echo $active ? esc_html__( 'On', 'axle-staging-guard' ) : esc_html__( 'Off', 'axle-staging-guard' ); ?></span>
				<span class="axle-stat__label"><?php esc_html_e( 'password gate', 'axle-staging-guard' ); ?></span>
			</div>
			<div class="axle-stat">
				<span class="axle-stat__value"><?php echo esc_html( number_format_i18n( count( $live ) ) ); ?></span>
				<span class="axle-stat__label"><?php esc_html_e( 'share links that work', 'axle-staging-guard' ); ?></span>
			</div>
		</div>

		<section class="axle-card">
			<h2 class="axle-card__title"><?php esc_html_e( 'Password and exceptions', 'axle-staging-guard' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="task" value="save_gate">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Gate', 'axle-staging-guard' ); ?></th>
						<td>
							<label><input type="checkbox" name="gate_enabled" value="1" <?php checked( (bool) $saved['gate_enabled'] ); ?>> <?php esc_html_e( 'Ask for a password before showing this copy', 'axle-staging-guard' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default. Logged-in users never see the gate.', 'axle-staging-guard' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asg-gate-password"><?php esc_html_e( 'Password', 'axle-staging-guard' ); ?></label></th>
						<td>
							<input type="password" id="asg-gate-password" name="gate_password" class="regular-text" autocomplete="new-password" aria-describedby="asg-gate-password-help">
							<p class="description" id="asg-gate-password-help">
								<?php
								echo $this->gate->has_password()
									? esc_html__( 'A password is set. Type a new one to change it. Everyone who entered the old one has to enter the new one.', 'axle-staging-guard' )
									: esc_html__( 'One password for everyone you share the site with. It is stored hashed, so nobody can read it back.', 'axle-staging-guard' );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asg-gate-ips"><?php esc_html_e( 'Skip the gate for IP addresses', 'axle-staging-guard' ); ?></label></th>
						<td>
							<textarea id="asg-gate-ips" name="gate_ips" rows="3" class="large-text code" placeholder="203.0.113.7&#10;198.51.100.0/24"><?php echo esc_textarea( implode( "\n", (array) $saved['gate_ips'] ) ); ?></textarea>
							<p class="description">
								<?php
								$ip = $this->gate->client_ip();
								echo esc_html__( 'One address or range per line, such as your office. Anything that is not a valid address is dropped.', 'axle-staging-guard' );
								if ( '' !== $ip ) {
									echo ' ' . esc_html(
										sprintf(
											/* translators: %s: the visitor's IP address. */
											__( 'Your address right now is %s.', 'axle-staging-guard' ),
											$ip
										)
									);
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="asg-gate-paths"><?php esc_html_e( 'Skip the gate for paths', 'axle-staging-guard' ); ?></label></th>
						<td>
							<textarea id="asg-gate-paths" name="gate_paths" rows="3" class="large-text code" placeholder="/health&#10;/webhooks/*&#10;/wp-json/my-plugin/v1/*"><?php echo esc_textarea( implode( "\n", (array) $saved['gate_paths'] ) ); ?></textarea>
							<p class="description">
								<?php
								echo esc_html__( 'One path per line, for health checks and webhooks. A * matches anything, so /webhooks/* covers everything below it. Without these, a request to a path is stopped like any other visit.', 'axle-staging-guard' );
								echo ' ' . esc_html(
									sprintf(
										/* translators: %s: list of paths. */
										__( 'Always open: %s.', 'axle-staging-guard' ),
										implode( ', ', Gate::DEFAULT_PATHS )
									)
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save gate settings', 'axle-staging-guard' ) ); ?>
			</form>
		</section>

		<?php $this->render_links( $links ); ?>
		<?php
	}

	/**
	 * Prints the share links card.
	 *
	 * @param array[] $links The links.
	 */
	private function render_links( array $links ): void {
		$store = $this->gate->links();
		?>
		<section class="axle-card asg-links">
			<h2 class="axle-card__title"><?php esc_html_e( 'Share links', 'axle-staging-guard' ); ?></h2>
			<p><?php esc_html_e( 'A link lets one person in without the password, for a time you choose. Name it after the person, so you know whose it is.', 'axle-staging-guard' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="axle-toolbar">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="task" value="link_create">
				<label for="asg-link-name" class="screen-reader-text"><?php esc_html_e( 'Name of the link', 'axle-staging-guard' ); ?></label>
				<input type="text" id="asg-link-name" name="link_name" class="regular-text" maxlength="60" placeholder="<?php esc_attr_e( 'For Priya at the client', 'axle-staging-guard' ); ?>" required>
				<label for="asg-link-days" class="screen-reader-text"><?php esc_html_e( 'How long it works', 'axle-staging-guard' ); ?></label>
				<select id="asg-link-days" name="link_days">
					<?php foreach ( $store->durations() as $days ) : ?>
						<option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( 7, $days ); ?>>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of days. */
									_n( '%d day', '%d days', $days, 'axle-staging-guard' ),
									$days
								)
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Create link', 'axle-staging-guard' ); ?></button>
			</form>

			<?php if ( ! $links ) : ?>
				<div class="axle-empty">
					<p><?php esc_html_e( 'No share links yet. Make one above to let a client or a colleague see this copy without the password. Each link is named, expires, and can be revoked.', 'axle-staging-guard' ); ?></p>
				</div>
			<?php else : ?>
				<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Share links', 'axle-staging-guard' ); ?>">
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Name', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Expires', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Last used', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Visits', 'axle-staging-guard' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actions', 'axle-staging-guard' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $links as $link ) : ?>
								<?php $works = $store->is_active( $link ); ?>
								<tr class="asg-link-row">
									<td><?php echo esc_html( $link['name'] ); ?></td>
									<td><span class="axle-badge axle-badge--<?php echo $works ? 'good' : 'neutral'; ?>"><?php echo $works ? esc_html__( 'Works', 'axle-staging-guard' ) : esc_html__( 'Expired', 'axle-staging-guard' ); ?></span></td>
									<td><?php echo AxleUi::relative_time( (int) $link['expires'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside relative_time(). ?></td>
									<td><?php echo AxleUi::relative_time( (int) $link['last_used'], __( 'Never used', 'axle-staging-guard' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside relative_time(). ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $link['uses'] ) ); ?></td>
									<td>
										<div class="asg-row-actions">
											<?php if ( $works ) : ?>
												<label class="screen-reader-text" for="asg-link-url-<?php echo esc_attr( $link['id'] ); ?>">
													<?php
													echo esc_html(
														sprintf(
															/* translators: %s: name of the link. */
															__( 'Address of the link %s', 'axle-staging-guard' ),
															$link['name']
														)
													);
													?>
												</label>
												<input type="text" id="asg-link-url-<?php echo esc_attr( $link['id'] ); ?>" class="asg-link-url" value="<?php echo esc_attr( $store->url( $link ) ); ?>" readonly>
												<button type="button" class="button asg-copy" data-asg-copy="#asg-link-url-<?php echo esc_attr( $link['id'] ); ?>"><?php esc_html_e( 'Copy link', 'axle-staging-guard' ); ?></button>
											<?php endif; ?>
											<?php
											Confirm::render(
												array(
													'id'   => 'asg-revoke-' . $link['id'],
													'action' => self::ACTION,
													'fields' => array(
														'task'    => 'link_revoke',
														'link_id' => $link['id'],
													),
													/* translators: %s: name of the link. */
													'open' => __( 'Revoke', 'axle-staging-guard' ),
													/* translators: %s: name of the link. */
													'label' => sprintf( __( 'Revoke %s', 'axle-staging-guard' ), $link['name'] ),
													'message' => sprintf(
														/* translators: %s: name of the link. */
														__( 'Revoking the link for %s ends their access on their next page view. This cannot be undone, but you can make a new link.', 'axle-staging-guard' ),
														$link['name']
													),
													'submit' => __( 'Revoke link', 'axle-staging-guard' ),
													'cancel' => __( 'Keep link', 'axle-staging-guard' ),
												)
											);
											?>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="description">
					<?php esc_html_e( 'Expired links are removed 30 days after they expire.', 'axle-staging-guard' ); ?>
				</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Handles the forms on the tab.
	 */
	public function handle_form(): void {
		if ( ! current_user_can( SettingsPage::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'axle-staging-guard' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		$args = array( 'tab' => 'gate' );

		switch ( $task ) {
			case 'save_gate':
				$args += $this->save_gate();
				break;

			case 'link_create':
				$name   = isset( $_POST['link_name'] ) ? sanitize_text_field( wp_unslash( $_POST['link_name'] ) ) : '';
				$days   = isset( $_POST['link_days'] ) ? absint( wp_unslash( $_POST['link_days'] ) ) : 0;
				$result = $this->gate->links()->create( $name, $days, get_current_user_id() );
				if ( is_wp_error( $result ) ) {
					$args['sg_error'] = $result->get_error_code();
				} else {
					$args['sg_message'] = 'link_created';
					$args['sg_link']    = $result['id'];
				}
				break;

			case 'link_revoke':
				$id = isset( $_POST['link_id'] ) ? sanitize_key( wp_unslash( $_POST['link_id'] ) ) : '';
				if ( $this->gate->links()->revoke( $id ) ) {
					$args['sg_message'] = 'link_revoked';
				} else {
					$args['sg_error'] = 'not_found';
				}
				break;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php?page=' . SettingsPage::SLUG ) ) );
		exit;
	}

	/**
	 * Saves the password, the switch and the exceptions.
	 *
	 * @return array Query arguments for the redirect.
	 */
	private function save_gate(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in handle_form().
		$password = isset( $_POST['gate_password'] ) ? (string) wp_unslash( $_POST['gate_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is stored hashed, exactly as typed.
		$enable   = ! empty( $_POST['gate_enabled'] );
		$ips      = isset( $_POST['gate_ips'] ) ? Access::parse_ips( sanitize_textarea_field( wp_unslash( $_POST['gate_ips'] ) ) ) : array();
		$paths    = isset( $_POST['gate_paths'] ) ? Access::parse_paths( sanitize_textarea_field( wp_unslash( $_POST['gate_paths'] ) ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$was_on  = (bool) $this->settings->get( 'gate_enabled' );
		$changed = '';

		if ( '' !== $password ) {
			$result = $this->gate->set_password( $password );
			if ( is_wp_error( $result ) ) {
				return array( 'sg_error' => $result->get_error_code() );
			}
			$changed = 'password_saved';
		}

		$this->settings->update(
			array(
				'gate_ips'   => $ips,
				'gate_paths' => $paths,
			)
		);

		if ( $enable ) {
			$result = $this->gate->enable();
			if ( is_wp_error( $result ) ) {
				return array( 'sg_error' => $result->get_error_code() );
			}
		} else {
			$this->gate->disable();
		}

		if ( $enable && ! $was_on ) {
			return array(
				'sg_message' => 'gate_on',
				'sg_undo'    => 'gate_off',
			);
		}

		if ( ! $enable && $was_on ) {
			return array(
				'sg_message' => 'gate_off',
				'sg_undo'    => 'gate_on',
			);
		}

		return array( 'sg_message' => '' !== $changed ? $changed : 'gate_saved' );
	}
}
