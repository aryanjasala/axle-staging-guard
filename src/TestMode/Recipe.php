<?php
/**
 * The test-mode recipe: what a fresh copy of the live site switches to test mode.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\TestMode;

use AryanX\Axle\StagingGuard\Admin\SettingsPage;
use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Service;
use AryanX\Axle\StagingGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A person ticks items on the live site. The tick list lives in the database,
 * does nothing on live and travels with every copy of the database. On the
 * first request on a copy each ticked item is switched to test mode once, the
 * old value is kept so it can be undone, and a notice says what changed.
 *
 * Nothing here runs on the live site: apply() refuses unless the guards are on.
 */
final class Recipe implements Service {

	public const DISMISS_ACTION = 'axle_staging_guard_test_mode_dismiss';

	private const LOCK = 'axle_staging_guard_test_mode_lock';

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
	 * Sets up the recipe.
	 *
	 * @param Settings    $settings    Saved settings.
	 * @param Environment $environment Environment detection.
	 */
	public function __construct( Settings $settings, Environment $environment ) {
		$this->settings    = $settings;
		$this->environment = $environment;
	}

	/**
	 * Registered on copies only: the first-request switch and the notice.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );

		// On init, not earlier: the item names are translated, and a translation before init loads the text domain too soon.
		add_action( 'init', array( $this, 'apply_on_first_request' ), 1 );
	}

	/**
	 * Switches the pending items on the first request on a copy.
	 */
	public function apply_on_first_request(): void {
		if ( ! wp_installing() ) {
			$this->apply_pending();
		}
	}

	/**
	 * Whether the feature is on at all.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		/**
		 * Filters whether copies switch ticked items to test mode by themselves.
		 *
		 * It cannot make anything run on the live site.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'axle_staging_guard_test_mode_enabled', true );
	}

	/**
	 * The saved recipe state.
	 *
	 * @return array{items: string[], applied: array}
	 */
	private function state(): array {
		$state = (array) $this->settings->get( 'test_mode' );

		return array(
			'items'   => array_values( array_filter( array_map( 'strval', (array) ( $state['items'] ?? array() ) ) ) ),
			'applied' => (array) ( $state['applied'] ?? array() ),
		);
	}

	/**
	 * Saves the state without a "settings saved" action.
	 *
	 * @param array $state Recipe state.
	 */
	private function save_state( array $state ): void {
		$this->settings->update( array( 'test_mode' => $state ), false );
	}

	/**
	 * Identifies this copy. The recipe applies once for each.
	 *
	 * @return string
	 */
	public function copy_id(): string {
		return substr( hash( 'sha256', 'axle-staging-guard-copy|' . $this->environment->domain() ), 0, 16 );
	}

	/**
	 * The ticked items that still exist as adapters.
	 *
	 * @return string[]
	 */
	public function ticked(): array {
		$adapters = Adapters::all();

		return array_values( array_filter( $this->state()['items'], static fn( string $id ): bool => isset( $adapters[ $id ] ) ) );
	}

	/**
	 * Changes the ticked items.
	 *
	 * The person may only add or remove the items they manage. Items they
	 * cannot manage stay as they were, so saving never changes them silently.
	 *
	 * @param string[] $ids         Item ids that should be ticked.
	 * @param bool     $only_allowed Whether to limit changes to items the current user manages. False for WP-CLI.
	 * @return string[] The ticked items now.
	 */
	public function save( array $ids, bool $only_allowed = true ): array {
		$state    = $this->state();
		$old      = $state['items'];
		$adapters = Adapters::all();
		$ids      = array_values( array_unique( array_map( 'sanitize_key', array_map( 'strval', $ids ) ) ) );
		$new      = array();

		foreach ( $adapters as $id => $adapter ) {
			$allowed = ! $only_allowed || Adapters::user_can( $id, $adapter );
			$was     = in_array( $id, $old, true );
			$want    = in_array( $id, $ids, true );

			if ( ! $allowed ) {
				$want = $was;
			} elseif ( $want && ! $was && ! Adapters::is_present( $adapter ) ) {
				// Nothing to switch: the plugin has no saved settings here.
				$want = false;
			}

			if ( $want ) {
				$new[] = $id;
			}

			// Ticking an item again after an Undo lets it switch on the next request.
			if ( $want && ! $was && isset( $state['applied']['items'][ $id ] ) && 'applied' !== ( $state['applied']['items'][ $id ]['state'] ?? '' ) ) {
				unset( $state['applied']['items'][ $id ] );
			}
		}

		// Keep ticked ids whose adapter was removed by a filter, so a filter change loses nothing.
		foreach ( $old as $id ) {
			if ( ! isset( $adapters[ $id ] ) ) {
				$new[] = $id;
			}
		}

		$state['items'] = array_values( array_unique( $new ) );
		$this->save_state( $state );

		if ( $state['items'] !== $old ) {
			/**
			 * Fires after the test-mode recipe changes.
			 *
			 * @param string[] $items The ticked items now.
			 * @param string[] $old   The ticked items before.
			 */
			do_action( 'axle_staging_guard_test_mode_recipe_saved', $state['items'], $old );
		}

		return $state['items'];
	}

	/**
	 * What was done on this copy, by item.
	 *
	 * @return array<string, array>
	 */
	public function applied(): array {
		$applied = $this->state()['applied'];

		if ( ( $applied['copy'] ?? '' ) !== $this->copy_id() ) {
			return array();
		}

		return (array) ( $applied['items'] ?? array() );
	}

	/**
	 * Ticked items that have not been switched on this copy yet.
	 *
	 * @return string[]
	 */
	public function pending(): array {
		$done = $this->applied();

		return array_values( array_filter( $this->ticked(), static fn( string $id ): bool => ! isset( $done[ $id ] ) ) );
	}

	/**
	 * Switches every pending item to test mode. Runs on the first request on a copy.
	 *
	 * @return string[] The items switched.
	 */
	public function apply_pending(): array {
		// One saved list is read on every request on a copy. Nothing ticked means nothing more to do.
		if ( ! $this->state()['items'] || ! $this->environment->is_guarded() || ! $this->is_enabled() || ! $this->pending() ) {
			return array();
		}

		// Two first requests at once must not both record "old" values: the second would record the test value.
		if ( ! add_option( self::LOCK, time(), '', false ) ) {
			if ( time() - (int) get_option( self::LOCK ) < MINUTE_IN_SECONDS ) {
				return array();
			}
			update_option( self::LOCK, time(), false );
		}

		$done = array();
		foreach ( $this->pending() as $id ) {
			$result = $this->apply( $id );
			if ( is_array( $result ) && 'applied' === $result['state'] ) {
				$done[] = $id;
			}
		}

		delete_option( self::LOCK );

		if ( $done ) {
			/**
			 * Fires after a copy switched its ticked items to test mode.
			 *
			 * @param string[] $items The items switched, by id.
			 */
			do_action( 'axle_staging_guard_test_mode_finished', $done );
		}

		return $done;
	}

	/**
	 * Switches one item to test mode and keeps the old value.
	 *
	 * @param string $id Item id.
	 * @return array{state: string, changes: array}|\WP_Error
	 */
	public function apply( string $id ) {
		$adapter = Adapters::get( $id );

		if ( null === $adapter ) {
			return new \WP_Error( 'axle_staging_guard_test_mode_unknown', __( 'That is not a known item.', 'axle-staging-guard' ), array( 'status' => 404 ) );
		}

		if ( ! $this->environment->is_guarded() ) {
			return new \WP_Error( 'axle_staging_guard_test_mode_live', __( 'This is the live site, so nothing is switched to test mode here.', 'axle-staging-guard' ), array( 'status' => 409 ) );
		}

		$changes = array();

		foreach ( $adapter['changes'] as $change ) {
			if ( false === get_option( $change['option'] ) ) {
				continue;
			}

			$option = get_option( $change['option'] );
			$found  = false;
			$old    = Adapters::read( $option, $change['path'], $found );

			if ( $found && Adapters::same( $old, $change['test'] ) ) {
				continue;
			}

			update_option( $change['option'], Adapters::write( $option, $change['path'], $change['test'] ) );

			$changes[] = array(
				'option' => $change['option'],
				'path'   => $change['path'],
				'had'    => $found,
				'old'    => $old,
				'new'    => $change['test'],
			);
		}

		$result = array(
			'state'   => $changes ? 'applied' : 'skipped',
			'changes' => $changes,
		);

		$state = $this->state();
		if ( ( $state['applied']['copy'] ?? '' ) !== $this->copy_id() ) {
			$state['applied'] = array(
				'copy'  => $this->copy_id(),
				'at'    => time(),
				'seen'  => false,
				'items' => array(),
			);
		}
		$state['applied']['items'][ $id ] = $result;
		$state['applied']['seen']         = 'applied' !== $result['state'] && ! empty( $state['applied']['seen'] );
		$this->save_state( $state );

		if ( $changes ) {
			/**
			 * Fires after one item is switched to test mode on a copy.
			 *
			 * @param string $id      Item id.
			 * @param array  $changes Each change: option, path, had, old and new.
			 */
			do_action( 'axle_staging_guard_test_mode_applied', $id, $changes );
		}

		return $result;
	}

	/**
	 * Puts one item back the way it was before it was switched.
	 *
	 * @param string $id Item id.
	 * @return true|\WP_Error
	 */
	public function undo( string $id ) {
		$state = $this->state();
		$item  = $this->applied()[ $id ] ?? null;

		if ( ! is_array( $item ) || 'applied' !== ( $item['state'] ?? '' ) ) {
			return new \WP_Error( 'axle_staging_guard_test_mode_nothing_to_undo', __( 'Nothing was switched for that item on this copy, so there is nothing to undo.', 'axle-staging-guard' ), array( 'status' => 409 ) );
		}

		foreach ( array_reverse( (array) $item['changes'] ) as $change ) {
			$option = get_option( $change['option'] );
			update_option(
				$change['option'],
				! empty( $change['had'] ) ? Adapters::write( $option, (array) $change['path'], $change['old'] ) : Adapters::remove( $option, (array) $change['path'] )
			);
		}

		$state['applied']['items'][ $id ]['state'] = 'undone';
		$this->save_state( $state );

		/**
		 * Fires after a person puts an item back to what it was before the switch.
		 *
		 * @param string $id Item id.
		 */
		do_action( 'axle_staging_guard_test_mode_undone', $id );

		return true;
	}

	/**
	 * The adapters that are set up here and still in live mode, on a copy.
	 *
	 * @return array<string, string> Item id => label.
	 */
	public function live_items(): array {
		$live = array();

		if ( ! $this->environment->is_guarded() ) {
			return $live;
		}

		foreach ( Adapters::all() as $id => $adapter ) {
			if ( Adapters::is_present( $adapter ) && ! Adapters::is_test( $adapter ) ) {
				$live[ $id ] = $adapter['label'];
			}
		}

		return $live;
	}

	/**
	 * Every item with its state, for the screen, WP-CLI and abilities.
	 *
	 * Items the current user cannot manage are left out when $check_user is true, so a label never reaches someone who may not see it.
	 *
	 * @param bool $check_user Whether to hide items the current user does not manage.
	 * @return array<int, array{id: string, label: string, note: string, ticked: bool, mode: string, state: string}>
	 */
	public function items( bool $check_user = true ): array {
		$rows   = array();
		$ticked = $this->ticked();
		$done   = $this->applied();

		foreach ( Adapters::all() as $id => $adapter ) {
			if ( $check_user && ! Adapters::user_can( $id, $adapter ) ) {
				continue;
			}

			$present = Adapters::is_present( $adapter );
			$ticked_ = in_array( $id, $ticked, true );
			if ( ! $present && ! $ticked_ ) {
				continue;
			}

			$rows[] = array(
				'id'     => $id,
				'label'  => $adapter['label'],
				'note'   => $adapter['note'],
				'ticked' => $ticked_,
				'mode'   => ! $present ? 'missing' : ( Adapters::is_test( $adapter ) ? 'test' : 'live' ),
				'state'  => $done[ $id ]['state'] ?? ( $ticked_ && $this->environment->is_guarded() ? 'pending' : 'none' ),
			);
		}

		return $rows;
	}

	/**
	 * Marks the "what changed" notice as seen.
	 */
	public function dismiss(): void {
		$state = $this->state();
		if ( isset( $state['applied']['items'] ) ) {
			$state['applied']['seen'] = true;
			$this->save_state( $state );
		}
	}

	/**
	 * A link that undoes one item. Escaped HTML, or an empty string when the person may not.
	 *
	 * @param string $id Item id.
	 * @return string
	 */
	public function undo_link( string $id ): string {
		$adapter = Adapters::get( $id );
		if ( null === $adapter || ! Adapters::user_can( $id, $adapter ) || 'applied' !== ( $this->applied()[ $id ]['state'] ?? '' ) ) {
			return '';
		}

		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action' => SettingsPage::UNDO_ACTION,
							'what'   => 'test_mode',
							'item'   => $id,
							'tab'    => 'settings',
						),
						admin_url( 'admin-post.php' )
					),
					SettingsPage::UNDO_ACTION
				)
			),
			esc_html__( 'Undo', 'axle-staging-guard' )
		);
	}

	/**
	 * Tells the people who manage Staging Guard what the first request on this copy changed.
	 */
	public function render_notice(): void {
		$state = $this->state();

		if ( ! current_user_can( SettingsPage::capability() ) || ! $this->environment->is_guarded() || ! empty( $state['applied']['seen'] ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'tools_page_' . SettingsPage::SLUG === $screen->id ) {
			return;
		}

		$lines = array();
		foreach ( $this->applied() as $id => $item ) {
			$adapter = Adapters::get( (string) $id );
			if ( null === $adapter || 'applied' !== ( $item['state'] ?? '' ) || ! Adapters::user_can( (string) $id, $adapter ) ) {
				continue;
			}
			$undo    = $this->undo_link( (string) $id );
			$lines[] = '<li>' . esc_html( $adapter['label'] ) . ( '' !== $undo ? ' &middot; ' . $undo : '' ) . '</li>';
		}

		if ( ! $lines ) {
			return;
		}

		printf(
			'<div class="notice notice-warning asg-notice" role="status"><p><strong>%1$s</strong> %2$s</p><ul class="ul-disc">%3$s</ul><p><a href="%4$s" class="button">%5$s</a></p></div>',
			esc_html__( 'This copy was switched to test mode.', 'axle-staging-guard' ),
			esc_html__( 'The first request on this copy changed these settings, as ticked on the live site:', 'axle-staging-guard' ),
			implode( '', $lines ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each line is built from escaped parts above.
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION ) ),
			esc_html__( 'Got it', 'axle-staging-guard' )
		);
	}

	/**
	 * Handles the "Got it" button.
	 */
	public function handle_dismiss(): void {
		if ( ! current_user_can( SettingsPage::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'axle-staging-guard' ), 403 );
		}

		check_admin_referer( self::DISMISS_ACTION );
		$this->dismiss();

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
