<?php
/**
 * The plugins that have a test mode Staging Guard can switch.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\TestMode;

defined( 'ABSPATH' ) || exit;

/**
 * Knows where each supported plugin keeps its test-mode switch.
 *
 * An adapter is plain data: one or more changes, each naming an option, the
 * path to one value inside it, and the value that means test mode. Only that
 * one value is read and written. Keys, secrets and every other setting in the
 * option are never read into the recipe, never copied and never touched.
 */
final class Adapters {

	/**
	 * The built-in adapters.
	 *
	 * @return array<string, array{label: string, note: string, capability: string, changes: array<int, array{option: string, path: string[], test: mixed}>}>
	 */
	public static function builtin(): array {
		return array(
			'woopayments'            => array(
				'label'      => __( 'WooPayments', 'axle-staging-guard' ),
				'note'       => __( 'Turns on test mode, so the gateway uses its test account.', 'axle-staging-guard' ),
				'capability' => 'manage_woocommerce',
				'changes'    => array(
					array(
						'option' => 'woocommerce_woocommerce_payments_settings',
						'path'   => array( 'test_mode' ),
						'test'   => 'yes',
					),
				),
			),
			'stripe-for-woocommerce' => array(
				'label'      => __( 'Stripe for WooCommerce', 'axle-staging-guard' ),
				'note'       => __( 'Turns on test mode, so the gateway uses its test keys.', 'axle-staging-guard' ),
				'capability' => 'manage_woocommerce',
				'changes'    => array(
					array(
						'option' => 'woocommerce_stripe_settings',
						'path'   => array( 'testmode' ),
						'test'   => 'yes',
					),
				),
			),
			'paypal-payments'        => array(
				'label'      => __( 'PayPal Payments', 'axle-staging-guard' ),
				'note'       => __( 'Turns on sandbox mode.', 'axle-staging-guard' ),
				'capability' => 'manage_woocommerce',
				'changes'    => array(
					array(
						'option' => 'woocommerce-ppcp-settings',
						'path'   => array( 'sandbox_on' ),
						'test'   => true,
					),
				),
			),
			'gravityforms-stripe'    => array(
				'label'      => __( 'Gravity Forms Stripe Add-On', 'axle-staging-guard' ),
				'note'       => __( 'Switches the API mode to test.', 'axle-staging-guard' ),
				'capability' => 'gravityforms_edit_settings',
				'changes'    => array(
					array(
						'option' => 'gravityformsaddon_gravityformsstripe_settings',
						'path'   => array( 'api_mode' ),
						'test'   => 'test',
					),
				),
			),
			'wp-mail-smtp'           => array(
				'label'      => __( 'WP Mail SMTP', 'axle-staging-guard' ),
				'note'       => __( 'Turns on "Do Not Send", so the plugin sends no email at all.', 'axle-staging-guard' ),
				'capability' => 'manage_options',
				'changes'    => array(
					array(
						'option' => 'wp_mail_smtp',
						'path'   => array( 'general', 'do_not_send' ),
						'test'   => true,
					),
				),
			),
		);
	}

	/**
	 * Every adapter, after the filter, with bad entries dropped.
	 *
	 * @return array<string, array{label: string, note: string, capability: string, changes: array<int, array{option: string, path: string[], test: mixed}>}>
	 */
	public static function all(): array {
		/**
		 * Filters the plugins whose test mode Staging Guard can switch on a copy.
		 *
		 * Add your own: id => array( 'label' => string, 'note' => string, 'capability' => string, 'changes' => array of
		 * array( 'option' => string, 'path' => string[], 'test' => value that means test mode ) ).
		 * The path leads to one value inside the option. Remove an id to take a plugin out.
		 *
		 * @param array $adapters Adapter id => definition.
		 */
		$adapters = (array) apply_filters( 'axle_staging_guard_test_mode_adapters', self::builtin() );
		$clean    = array();

		foreach ( $adapters as $id => $adapter ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $adapter ) || empty( $adapter['changes'] ) || ! is_array( $adapter['changes'] ) ) {
				continue;
			}

			$changes = array();
			foreach ( $adapter['changes'] as $change ) {
				if ( ! is_array( $change ) || empty( $change['option'] ) || ! array_key_exists( 'test', $change ) ) {
					continue;
				}
				$changes[] = array(
					'option' => (string) $change['option'],
					'path'   => array_values( array_map( 'strval', (array) ( $change['path'] ?? array() ) ) ),
					'test'   => $change['test'],
				);
			}
			if ( ! $changes ) {
				continue;
			}

			$clean[ $id ] = array(
				'label'      => (string) ( $adapter['label'] ?? $id ),
				'note'       => (string) ( $adapter['note'] ?? '' ),
				'capability' => (string) ( $adapter['capability'] ?? 'manage_options' ),
				'changes'    => $changes,
			);
		}

		return $clean;
	}

	/**
	 * One adapter.
	 *
	 * @param string $id Adapter id.
	 * @return array|null
	 */
	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * Whether the plugin is set up on this site: every option the adapter changes exists.
	 *
	 * Nothing to switch means nothing to tick.
	 *
	 * @param array $adapter Adapter definition.
	 * @return bool
	 */
	public static function is_present( array $adapter ): bool {
		foreach ( $adapter['changes'] as $change ) {
			if ( false === get_option( $change['option'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether every value the adapter changes is already in test mode.
	 *
	 * @param array $adapter Adapter definition.
	 * @return bool
	 */
	public static function is_test( array $adapter ): bool {
		foreach ( $adapter['changes'] as $change ) {
			$found = false;
			$value = self::read( get_option( $change['option'] ), $change['path'], $found );
			if ( ! $found || ! self::same( $value, $change['test'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the current user may see and change an adapter.
	 *
	 * Per plugin, not only for this one: whoever manages the gateway decides about it.
	 *
	 * @param string $id      Adapter id.
	 * @param array  $adapter Adapter definition.
	 * @return bool
	 */
	public static function user_can( string $id, array $adapter ): bool {
		/**
		 * Filters the capability needed to tick, untick and undo one test-mode item.
		 *
		 * The person also needs the Staging Guard capability.
		 *
		 * @param string $capability Default from the adapter: manage_woocommerce for the WooCommerce gateways.
		 * @param string $id         Adapter id.
		 */
		$capability = (string) apply_filters( 'axle_staging_guard_test_mode_capability', $adapter['capability'], $id );

		return '' === $capability || current_user_can( $capability );
	}

	/**
	 * Reads one value out of an option.
	 *
	 * @param mixed    $option The option value.
	 * @param string[] $path   Keys to follow.
	 * @param bool     $found  Set to whether the value exists.
	 * @return mixed
	 */
	public static function read( $option, array $path, bool &$found ) {
		$found = true;
		$value = $option;

		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				$found = false;

				return null;
			}
			$value = $value[ $key ];
		}

		return $value;
	}

	/**
	 * Returns the option with one value set. Everything else in it stays as it was.
	 *
	 * @param mixed    $option The option value.
	 * @param string[] $path   Keys to follow.
	 * @param mixed    $value  The new value.
	 * @return mixed
	 */
	public static function write( $option, array $path, $value ) {
		if ( ! $path ) {
			return $value;
		}

		$key  = array_shift( $path );
		$base = is_array( $option ) ? $option : array();

		$base[ $key ] = self::write( $base[ $key ] ?? null, $path, $value );

		return $base;
	}

	/**
	 * Returns the option with one value removed.
	 *
	 * @param mixed    $option The option value.
	 * @param string[] $path   Keys to follow.
	 * @return mixed
	 */
	public static function remove( $option, array $path ) {
		if ( ! $path || ! is_array( $option ) ) {
			return $option;
		}

		$key = array_shift( $path );
		if ( ! array_key_exists( $key, $option ) ) {
			return $option;
		}

		if ( ! $path ) {
			unset( $option[ $key ] );
		} else {
			$option[ $key ] = self::remove( $option[ $key ], $path );
		}

		return $option;
	}

	/**
	 * Compares two settings values the way plugins do: "yes", true and 1 are the same switch.
	 *
	 * @param mixed $a One value.
	 * @param mixed $b Another.
	 * @return bool
	 */
	public static function same( $a, $b ): bool {
		$truthy = static function ( $v ): ?bool {
			if ( is_bool( $v ) ) {
				return $v;
			}
			if ( in_array( $v, array( 'yes', '1', 1 ), true ) ) {
				return true;
			}
			if ( in_array( $v, array( 'no', '0', 0, '' ), true ) ) {
				return false;
			}

			return null;
		};
		$x      = $truthy( $a );
		$y      = $truthy( $b );

		return null !== $x && null !== $y ? $x === $y : $a === $b;
	}
}
