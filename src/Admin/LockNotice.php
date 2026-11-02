<?php
/**
 * Warns when the production lock is what makes this copy staging.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Admin;

use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a notice on every admin screen while this copy counts as staging
 * because the production lock was set on another domain.
 *
 * That is exactly right for a database copy on staging. It is wrong when the
 * live site's own address changed (www to the bare domain, a new domain, a
 * mapped domain on multisite): then live email is blocked and search engines
 * are asked to stay away. The notice makes that impossible to miss. Each user
 * can dismiss it for the current domain.
 */
final class LockNotice implements Service {

	public const ACTION = 'axle_staging_guard_dismiss_lock_notice';

	private const USER_META = 'axle_staging_guard_lock_notice_dismissed';

	/**
	 * Environment detection.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * Sets up the notice.
	 *
	 * @param Environment $environment Environment detection.
	 */
	public function __construct( Environment $environment ) {
		$this->environment = $environment;
	}

	/**
	 * Hooks the notice and the dismiss link.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'network_admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'dismiss' ) );
	}

	/**
	 * Prints the notice.
	 */
	public function render(): void {
		if ( ! $this->environment->is_staging_by_lock() || ! current_user_can( SettingsPage::capability() ) ) {
			return;
		}

		$dismissed = (string) get_user_meta( get_current_user_id(), self::USER_META, true );
		if ( hash_equals( Environment::hash( $this->environment->domain() ), $dismissed ) ) {
			return;
		}

		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );

		printf(
			'<div class="notice notice-warning asg-lock-notice" role="alert"><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a> <a class="button" href="%5$s">%6$s</a></p></div>',
			esc_html__( 'Axle Staging Guard treats this site as staging.', 'axle-staging-guard' ),
			esc_html(
				sprintf(
					/* translators: %s: domain name. */
					__( 'The production lock was set on a different domain, so email from %s is held back and search engines are asked to stay away. That is right for a staging copy. If this is the live site and its address changed, lock it to this domain on Tools > Axle Staging Guard.', 'axle-staging-guard' ),
					$this->environment->domain()
				)
			),
			esc_url( admin_url( 'tools.php?page=' . SettingsPage::SLUG ) ),
			esc_html__( 'Open Axle Staging Guard', 'axle-staging-guard' ),
			esc_url( $dismiss ),
			esc_html__( 'This is staging, hide this', 'axle-staging-guard' )
		);
	}

	/**
	 * Hides the notice for the current user on the current domain.
	 */
	public function dismiss(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( SettingsPage::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'axle-staging-guard' ), 403 );
		}

		update_user_meta( get_current_user_id(), self::USER_META, Environment::hash( $this->environment->domain() ) );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
