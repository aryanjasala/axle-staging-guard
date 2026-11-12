<?php
/**
 * The in-place confirm step before something that deletes or overwrites.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Prints a small form with an opener button and a `.axle-confirm` group that
 * says what will change and whether it can be undone. Without JavaScript the
 * group is simply shown, so nothing can be done by accident and nothing is out of reach.
 */
final class Confirm {

	/**
	 * Prints the form.
	 *
	 * @param array $args {
	 *     What to print.
	 *
	 *     @type string $id      Unique ID prefix.
	 *     @type string $action  The admin-post action the form posts to.
	 *     @type array  $fields  Hidden fields: name => value.
	 *     @type string $open    Text of the button that opens the step.
	 *     @type string $label   Longer accessible name for that button, such as which row it belongs to. Optional.
	 *     @type string $message What will change, and whether it can be undone.
	 *     @type string $submit  Text of the button that does it. A verb.
	 *     @type string $cancel  Text of the button that closes the step.
	 *     @type string $style   danger or empty. Danger gives the red button and the red step.
	 *     @type string $class   Class of the opener button. Default button-link.
	 * }
	 */
	public static function render( array $args ): void {
		$args   = array_merge(
			array(
				'id'      => 'asg-confirm',
				'action'  => '',
				'fields'  => array(),
				'open'    => '',
				'label'   => '',
				'message' => '',
				'submit'  => '',
				'cancel'  => __( 'Keep it', 'axle-staging-guard' ),
				'style'   => 'danger',
				'class'   => 'button-link',
			),
			$args
		);
		$danger = 'danger' === $args['style'];
		$id     = sanitize_html_class( (string) $args['id'] );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asg-confirm-form">
			<?php wp_nonce_field( (string) $args['action'] ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( (string) $args['action'] ); ?>">
			<?php foreach ( (array) $args['fields'] as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<button type="button" class="<?php echo esc_attr( (string) $args['class'] ); ?> asg-confirm-open"<?php echo '' !== $args['label'] ? ' aria-label="' . esc_attr( (string) $args['label'] ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped on the same line. ?> aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>" hidden><?php echo esc_html( (string) $args['open'] ); ?></button>
			<div class="axle-confirm<?php echo $danger ? ' axle-confirm--danger' : ''; ?> asg-confirm" id="<?php echo esc_attr( $id ); ?>" role="group" aria-labelledby="<?php echo esc_attr( $id ); ?>-text">
				<p id="<?php echo esc_attr( $id ); ?>-text"><?php echo esc_html( (string) $args['message'] ); ?></p>
				<div class="axle-actions">
					<button type="submit" class="button<?php echo $danger ? ' axle-button--danger' : ''; ?>"><?php echo esc_html( (string) $args['submit'] ); ?></button>
					<button type="button" class="button asg-confirm-cancel" hidden><?php echo esc_html( (string) $args['cancel'] ); ?></button>
				</div>
			</div>
		</form>
		<?php
	}
}
