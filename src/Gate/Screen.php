<?php
/**
 * The page a visitor sees at the gate.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Gate;

defined( 'ABSPATH' ) || exit;

/**
 * A small, self-contained page: the site's name and a password field.
 *
 * It loads nothing from the theme and nothing from outside, so it works the
 * same on every site and leaks nothing about the site's design or content.
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
	 * Prints the page.
	 *
	 * @param string $error   Error code, or an empty string.
	 * @param int    $seconds Seconds left on a lockout.
	 * @param string $nonce   Nonce for the form.
	 */
	public static function render( string $error, int $seconds, string $nonce ): void {
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

		/* translators: %s: site name. */
		$title = sprintf( __( '%s: password needed', 'axle-staging-guard' ), '' === $site ? __( 'This site', 'axle-staging-guard' ) : $site );
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', (string) get_bloginfo( 'language' ) ) ); ?>">
<head>
<meta charset="<?php echo esc_attr( (string) get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title><?php echo esc_html( $title ); ?></title>
<style>
:root{color-scheme:light dark;--bg:#f6f7f7;--card:#fff;--text:#1d2327;--muted:#50575e;--line:#c3c4c7;--accent:#2271b1;--accent-text:#fff;--error-bg:#fcf0f1;--error-line:#d63638;--error-text:#8a2424}
@media (prefers-color-scheme:dark){:root{--bg:#1d2327;--card:#2c3338;--text:#f0f0f1;--muted:#c3c4c7;--line:#646970;--accent:#72aee6;--accent-text:#1d2327;--error-bg:#3a1d1f;--error-line:#f86368;--error-text:#ffd2d3}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;background:var(--bg);color:var(--text);font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif}
main{width:100%;max-width:380px;background:var(--card);border:1px solid var(--line);border-radius:4px;padding:24px}
h1{margin:0 0 8px;font-size:1.4em;line-height:1.3;overflow-wrap:anywhere}
p{margin:0 0 16px;color:var(--muted)}
label{display:block;margin:0 0 4px;font-weight:600;color:var(--text)}
input[type=password]{width:100%;padding:8px 12px;font:inherit;color:var(--text);background:var(--card);border:1px solid var(--muted);border-radius:4px}
input:focus-visible,button:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
button{margin-top:16px;width:100%;padding:8px 16px;font:inherit;font-weight:600;color:var(--accent-text);background:var(--accent);border:1px solid var(--accent);border-radius:4px;cursor:pointer}
button[disabled]{opacity:.6;cursor:not-allowed}
.error{margin:0 0 16px;padding:8px 12px;color:var(--error-text);background:var(--error-bg);border-left:4px solid var(--error-line)}
</style>
</head>
<body>
<main>
	<h1><?php echo esc_html( '' === $site ? __( 'This site', 'axle-staging-guard' ) : $site ); ?></h1>
	<p><?php echo esc_html( $intro ); ?></p>
		<?php if ( '' !== $message ) : ?>
		<p class="error" role="alert"><?php echo esc_html( $message ); ?></p>
	<?php endif; ?>
	<form method="post" action="">
		<label for="axle-gate-password"><?php echo esc_html( $label ); ?></label>
		<input type="password" id="axle-gate-password" name="<?php echo esc_attr( Gate::FIELD ); ?>" autocomplete="current-password" required<?php echo $locked ? ' disabled' : ''; ?>>
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
		<button type="submit"<?php echo $locked ? ' disabled' : ''; ?>><?php echo esc_html( $button ); ?></button>
	</form>
</main>
</body>
</html>
		<?php
	}
}
