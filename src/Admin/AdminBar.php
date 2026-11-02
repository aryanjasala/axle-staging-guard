<?php
/**
 * Environment label in the admin bar and browser tab.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Admin;

use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Mail\Guard;
use AryanX\Axle\StagingGuard\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Colours the admin bar and prefixes page titles, so nobody edits the wrong copy.
 */
final class AdminBar implements Service {

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
	 * Sets up the admin bar label.
	 *
	 * @param Environment $environment Environment detection.
	 * @param Guard       $mail        Email guard.
	 */
	public function __construct( Environment $environment, Guard $mail ) {
		$this->environment = $environment;
		$this->mail        = $mail;
	}

	/**
	 * Adds the admin bar and title hooks.
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'add_colour' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'add_colour' ) );
		add_filter( 'admin_title', array( $this, 'prefix_title' ) );
		add_filter( 'document_title_parts', array( $this, 'prefix_title_parts' ) );
	}

	/**
	 * Adds the environment label to the left of the admin bar.
	 *
	 * @param \WP_Admin_Bar $admin_bar Admin bar.
	 */
	public function add_node( \WP_Admin_Bar $admin_bar ): void {
		$admin_bar->add_node(
			array(
				'id'    => 'axle-staging-guard',
				'title' => esc_html( $this->environment->label() ),
				'href'  => current_user_can( SettingsPage::capability() ) ? admin_url( 'tools.php?page=' . SettingsPage::SLUG ) : false,
				'meta'  => array(
					// Only people who manage Axle Staging Guard see where email goes.
					'title' => current_user_can( SettingsPage::capability() )
						? sprintf(
							/* translators: %s: what happens to outgoing email, e.g. "Blocked". */
							__( 'Not the live site. Email: %s.', 'axle-staging-guard' ),
							$this->mail->describe()
						)
						: __( 'Not the live site.', 'axle-staging-guard' ),
				),
			)
		);
	}

	/**
	 * Colours the whole admin bar.
	 */
	public function add_colour(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		wp_add_inline_style(
			'admin-bar',
			sprintf(
				'html #wpadminbar{background:%1$s}#wpadminbar #wp-admin-bar-axle-staging-guard>.ab-item{font-weight:600;letter-spacing:.06em;background:rgba(0,0,0,.25)}',
				esc_attr( $this->environment->colour() )
			)
		);
	}

	/**
	 * Prefixes admin page titles, so browser tabs show the environment.
	 *
	 * @param string $title Admin page title.
	 * @return string
	 */
	public function prefix_title( string $title ): string {
		return '[' . $this->environment->label() . '] ' . $title;
	}

	/**
	 * Prefixes front-end page titles.
	 *
	 * @param array $parts Title parts.
	 * @return array
	 */
	public function prefix_title_parts( array $parts ): array {
		$parts['title'] = '[' . $this->environment->label() . '] ' . ( $parts['title'] ?? '' );

		return $parts;
	}
}
