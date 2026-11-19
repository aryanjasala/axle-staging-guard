<?php
/**
 * The page a visitor sees at the gate.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Gate;

use AryanX\Axle\StagingGuard\Admin\AxleUi;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress's login screen with a password field: site icon, site name, white box.
 *
 * It loads core's login styles only, nothing from the theme and nothing from
 * outside, so it leaks nothing about the site's design or content.
 */
final class Screen {

	/**
	 * The message under the heading for each error code.
	 *
	 * @param string $error   password, expired, link, locked, rest or empty.
	 * @param int    $seconds Seconds left on a lockout.
	 * @return string
	 */
	public static function error_message( string $error, int $seconds = 0 ): string {
		switch ( $error ) {
			case 'password':
				return __( 'That password is not right. Try again.', 'axle-staging-guard' );

			case 'expired':
				return __( 'This page was open for too long. Enter the password again.', 'axle-staging-guard' );

			case 'link':
				return __( 'This link has expired or was turned off. Ask the person who sent it for a new one.', 'axle-staging-guard' );

			case 'locked':
				return sprintf(
					/* translators: %d: number of minutes. */
					_n( 'Too many wrong passwords. Try again in %d minute.', 'Too many wrong passwords. Try again in %d minutes.', max( 1, (int) ceil( $seconds / 60 ) ), 'axle-staging-guard' ),
					max( 1, (int) ceil( $seconds / 60 ) )
				);

			default:
				return '';
		}
	}

	/**
	 * Prints the page, in WordPress's own login-screen look.
	 *
	 * @param string $error   Error code, or an empty string.
	 * @param int    $seconds Seconds left on a lockout.
	 * @param string $nonce   Nonce for the form.
	 * @param int    $status  HTTP status code to send.
	 */
	public static function render( string $error, int $seconds, string $nonce, int $status = 401 ): void {
		$site    = (string) get_bloginfo( 'name' );
		$locked  = 'locked' === $error;
		$message = self::error_message( $error, $seconds );

		/**
		 * Filters the sentence above the password field.
		 *
		 * @param string $text Default: this site is not public yet.
		 */
		$intro = (string) apply_filters( 'axle_staging_guard_gate_message', __( 'This site is not public yet. Enter the password you were given to see it.', 'axle-staging-guard' ) );

		/**
		 * Filters the label of the password field.
		 *
		 * @param string $label Default Password.
		 */
		$label = (string) apply_filters( 'axle_staging_guard_gate_label', __( 'Password', 'axle-staging-guard' ) );

		/**
		 * Filters the text of the button.
		 *
		 * @param string $text Default Continue.
		 */
		$button = (string) apply_filters( 'axle_staging_guard_gate_button', __( 'Continue', 'axle-staging-guard' ) );

		$body = sprintf(
			'<form method="post" action=""><p><label for="axle-gate-password">%1$s</label><input type="password" id="axle-gate-password" name="%2$s" class="input" size="20" autocomplete="current-password" required%3$s></p><input type="hidden" name="_wpnonce" value="%4$s"><p class="submit"><button type="submit" class="button button-primary button-large"%3$s>%5$s</button></p></form>',
			esc_html( $label ),
			esc_attr( Gate::FIELD ),
			$locked ? ' disabled' : '',
			esc_attr( $nonce ),
			esc_html( $button )
		);

		// The shared page filters out the disabled attribute, so a lockout shows the message and no form.
		if ( $locked ) {
			$body = '';
		}

		AxleUi::guest_page(
			array(
				/* translators: %s: site name. */
				'title'   => sprintf( __( '%s: password needed', 'axle-staging-guard' ), '' === $site ? __( 'This site', 'axle-staging-guard' ) : $site ),
				'message' => $intro,
				'error'   => $message,
				'body'    => $body,
				// A team member whose session ran out has no password for this screen: they log in and come back.
				'links'   => array( array( wp_login_url( self::current_url() ), __( 'Log in', 'axle-staging-guard' ) ) ),
				'status'  => $status,
			)
		);
	}

	/**
	 * The address the visitor asked for, without a share-link token, for the "Log in" link.
	 *
	 * @return string
	 */
	private static function current_url(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only used to build a URL that is escaped on output.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$uri = remove_query_arg( Gate::LINK_PARAM, $uri );

		return home_url( '/' ) . ltrim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' ) . ( '' !== (string) wp_parse_url( $uri, PHP_URL_QUERY ) ? '?' . wp_parse_url( $uri, PHP_URL_QUERY ) : '' );
	}
}
