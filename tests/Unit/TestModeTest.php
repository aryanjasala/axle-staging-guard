<?php
/**
 * Tests for the test-mode recipe: what a copy switches, once, with Undo.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Tests\Unit;

use AryanX\Axle\StagingGuard\Environment;
use AryanX\Axle\StagingGuard\Settings;
use AryanX\Axle\StagingGuard\TestMode\Adapters;
use AryanX\Axle\StagingGuard\TestMode\Recipe;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class TestModeTest extends TestCase {

	private const STRIPE = 'woocommerce_stripe_settings';

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'home_url' )->justReturn( 'https://staging.example.test' );
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ) );
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	private function recipe( string $live_host = 'live.example.test', array $extra = array() ): Recipe {
		$this->options[ Settings::OPTION ] = $extra + array( 'production_hash' => Environment::hash( $live_host ) );
		$settings                          = new Settings();

		return new Recipe( $settings, new Environment( $settings ) );
	}

	private function stripe( string $mode = 'no' ): void {
		$this->options[ self::STRIPE ] = array(
			'enabled'         => 'yes',
			'testmode'        => $mode,
			'secret_key'      => 'sk_live_keep_me',
			'test_secret_key' => 'sk_test_x',
		);
	}

	public function test_a_copy_switches_a_ticked_item_once_and_keeps_every_other_setting(): void {
		$this->stripe();
		$recipe = $this->recipe();
		$recipe->save( array( 'stripe-for-woocommerce' ) );

		Actions\expectDone( 'axle_staging_guard_test_mode_applied' )->once();
		$this->assertSame( array( 'stripe-for-woocommerce' ), $recipe->apply_pending() );

		$this->assertSame( 'yes', $this->options[ self::STRIPE ]['testmode'] );
		$this->assertSame( 'sk_live_keep_me', $this->options[ self::STRIPE ]['secret_key'], 'Keys are never touched.' );
		$this->assertSame( array(), $recipe->apply_pending(), 'The second request does nothing.' );
	}

	public function test_nothing_happens_on_the_live_site(): void {
		$this->stripe();
		$recipe = $this->recipe( 'staging.example.test' );
		$recipe->save( array( 'stripe-for-woocommerce' ) );

		$this->assertSame( array(), $recipe->apply_pending() );
		$this->assertSame( 'no', $this->options[ self::STRIPE ]['testmode'] );
		$this->assertSame( 'axle_staging_guard_test_mode_live', $recipe->apply( 'stripe-for-woocommerce' )->get_error_code() );
	}

	public function test_nothing_happens_when_a_filter_turns_the_feature_off(): void {
		$this->stripe();
		$recipe = $this->recipe();
		$recipe->save( array( 'stripe-for-woocommerce' ) );
		Filters\expectApplied( 'axle_staging_guard_test_mode_enabled' )->andReturn( false );

		$this->assertSame( array(), $recipe->apply_pending() );
		$this->assertSame( 'no', $this->options[ self::STRIPE ]['testmode'] );
	}

	public function test_undo_puts_the_old_value_back_and_it_does_not_switch_again(): void {
		$this->stripe();
		$recipe = $this->recipe();
		$recipe->save( array( 'stripe-for-woocommerce' ) );
		$recipe->apply_pending();

		Actions\expectDone( 'axle_staging_guard_test_mode_undone' )->once();
		$this->assertTrue( $recipe->undo( 'stripe-for-woocommerce' ) );
		$this->assertSame( 'no', $this->options[ self::STRIPE ]['testmode'] );
		$this->assertSame( array(), $recipe->apply_pending(), 'An undone item stays as the person left it.' );
		$this->assertSame( 'axle_staging_guard_test_mode_nothing_to_undo', $recipe->undo( 'stripe-for-woocommerce' )->get_error_code() );
	}

	public function test_an_item_already_in_test_mode_is_left_alone(): void {
		$this->stripe( 'yes' );
		$recipe = $this->recipe();
		$recipe->save( array( 'stripe-for-woocommerce' ) );

		$this->assertSame( array(), $recipe->apply_pending() );
		$this->assertSame( 'skipped', $recipe->applied()['stripe-for-woocommerce']['state'] );
		$this->assertSame( 'axle_staging_guard_test_mode_nothing_to_undo', $recipe->undo( 'stripe-for-woocommerce' )->get_error_code() );
	}

	public function test_a_nested_setting_that_did_not_exist_is_removed_again_on_undo(): void {
		$this->options['wp_mail_smtp'] = array( 'mail' => array( 'mailer' => 'smtp' ) );
		$recipe                        = $this->recipe();
		$recipe->save( array( 'wp-mail-smtp' ) );
		$recipe->apply_pending();

		$this->assertTrue( $this->options['wp_mail_smtp']['general']['do_not_send'] );
		$this->assertSame( 'smtp', $this->options['wp_mail_smtp']['mail']['mailer'] );

		$recipe->undo( 'wp-mail-smtp' );
		$this->assertArrayNotHasKey( 'do_not_send', $this->options['wp_mail_smtp']['general'] );
	}

	public function test_the_recipe_applies_again_on_a_copy_on_another_address(): void {
		$this->stripe();
		$recipe = $this->recipe();
		$recipe->save( array( 'stripe-for-woocommerce' ) );
		$recipe->apply_pending();
		$saved = $this->options[ Settings::OPTION ];

		// The same database lands on a second copy at another address, with its own live value back.
		Functions\when( 'home_url' )->justReturn( 'https://other.example.test' );
		$this->stripe();
		$this->options[ Settings::OPTION ] = $saved;
		$settings                          = new Settings();
		$second                            = new Recipe( $settings, new Environment( $settings ) );

		$this->assertSame( array( 'stripe-for-woocommerce' ), $second->apply_pending() );
		$this->assertSame( 'yes', $this->options[ self::STRIPE ]['testmode'] );
	}

	public function test_only_items_that_are_set_up_can_be_ticked_and_unknown_ids_are_ignored(): void {
		$recipe = $this->recipe();
		$recipe->save( array( 'woopayments', 'nonsense' ) );

		$this->assertSame( array(), $recipe->ticked() );

		$this->options['woocommerce_woocommerce_payments_settings'] = array( 'test_mode' => 'no' );
		$recipe->save( array( 'woopayments' ) );
		$this->assertSame( array( 'woopayments' ), $recipe->ticked() );
	}

	public function test_a_person_cannot_change_an_item_they_do_not_manage(): void {
		$this->stripe();
		$this->options['wp_mail_smtp'] = array();
		$recipe                        = $this->recipe();
		$recipe->save( array( 'stripe-for-woocommerce', 'wp-mail-smtp' ) );

		// A mail manager without WooCommerce rights saves an empty list.
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_woocommerce' !== $cap );
		$recipe->save( array() );
		$this->assertSame( array( 'stripe-for-woocommerce' ), $recipe->ticked(), 'Their item is unticked. The one they cannot manage stays.' );

		$labels = array_column( $recipe->items(), 'id' );
		$this->assertNotContains( 'stripe-for-woocommerce', $labels, 'It is not even listed to them.' );
		$this->assertSame( '', $recipe->undo_link( 'stripe-for-woocommerce' ) );
	}

	public function test_live_items_lists_only_what_is_still_live_on_a_copy(): void {
		$this->stripe();
		$recipe = $this->recipe();
		$this->assertSame( array( 'stripe-for-woocommerce' => 'Stripe for WooCommerce' ), $recipe->live_items() );

		$this->stripe( 'yes' );
		$this->assertSame( array(), $recipe->live_items() );

		$this->stripe();
		$this->assertSame( array(), $this->recipe( 'staging.example.test' )->live_items(), 'The live site is never flagged.' );
	}

	public function test_adapters_can_be_added_and_removed_with_a_filter(): void {
		Filters\expectApplied( 'axle_staging_guard_test_mode_adapters' )->andReturnUsing(
			static function ( array $adapters ): array {
				unset( $adapters['woopayments'] );
				$adapters['mine'] = array(
					'label'   => 'Mine',
					'changes' => array(
						array(
							'option' => 'my_opt',
							'path'   => array( 'mode' ),
							'test'   => 'sandbox',
						),
					),
				);
				$adapters['broken'] = array( 'label' => 'No changes' );

				return $adapters;
			}
		);

		$all = Adapters::all();
		$this->assertArrayNotHasKey( 'woopayments', $all );
		$this->assertArrayNotHasKey( 'broken', $all );
		$this->assertSame( 'manage_options', $all['mine']['capability'] );
	}

	public function test_switch_words_compare_by_meaning(): void {
		$this->assertTrue( Adapters::same( 'yes', true ) );
		$this->assertTrue( Adapters::same( '1', 'yes' ) );
		$this->assertFalse( Adapters::same( 'no', 'yes' ) );
		$this->assertFalse( Adapters::same( 'live', 'test' ) );
		$this->assertTrue( Adapters::same( 'test', 'test' ) );
	}
}
