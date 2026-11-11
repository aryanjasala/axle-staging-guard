<?php
/**
 * Saved settings.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's single settings option.
 *
 * The option is autoloaded because the guards read it on every request.
 * AXLE_STAGING_GUARD_KEEP_BODIES and AXLE_STAGING_GUARD_NOINDEX in wp-config.php win
 * over the saved values, the same way the mail constants do.
 */
final class Settings {

	public const OPTION     = 'axle_staging_guard_settings';
	public const MAIL_MODES = array( 'block', 'redirect', 'allow' );
	public const HTTP_MODES = array( 'allow', 'log', 'block' );

	private const DEFAULTS = array(
		'mail_mode'       => 'block',
		'redirect_to'     => '',
		'allowlist'       => array(),
		'keep_bodies'     => false,
		'noindex'         => true,
		// Existing installs keep "allow" so an update never breaks a staging site. New installs start on "log": see Plugin::activate().
		'http_mode'       => 'allow',
		'http_allowlist'  => array(),
		'http_blocklist'  => array(),
		'production_hash' => '',
		'log_version'     => 0,
	);

	/**
	 * Settings a wp-config.php constant can fix: setting => constant.
	 */
	private const CONSTANTS = array(
		'keep_bodies' => 'AXLE_STAGING_GUARD_KEEP_BODIES',
		'noindex'     => 'AXLE_STAGING_GUARD_NOINDEX',
	);

	/**
	 * All settings in effect: saved values, defaults, then wp-config.php constants.
	 *
	 * @return array{mail_mode: string, redirect_to: string, allowlist: string[], keep_bodies: bool, noindex: bool, http_mode: string, http_allowlist: string[], http_blocklist: string[], production_hash: string, log_version: int}
	 */
	public function all(): array {
		$all = $this->saved();
		foreach ( self::CONSTANTS as $key => $constant ) {
			if ( defined( $constant ) ) {
				$all[ $key ] = (bool) constant( $constant );
			}
		}

		/**
		 * Filters whether staging copies are hidden from search engines.
		 *
		 * @param bool $noindex From the setting or AXLE_STAGING_GUARD_NOINDEX. Default true.
		 */
		$all['noindex'] = (bool) apply_filters( 'axle_staging_guard_noindex', $all['noindex'] );

		return $all;
	}

	/**
	 * Whether a setting is fixed by a constant in wp-config.php.
	 *
	 * @param string $key Setting name.
	 * @return bool
	 */
	public function is_fixed( string $key ): bool {
		return isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] );
	}

	/**
	 * Saved settings merged with defaults, without constants.
	 *
	 * @return array
	 */
	private function saved(): array {
		$saved = get_option( self::OPTION, array() );

		return array_merge( self::DEFAULTS, is_array( $saved ) ? array_intersect_key( $saved, self::DEFAULTS ) : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Changes some settings and keeps the rest.
	 *
	 * @param array $changes Setting name => value.
	 */
	public function update( array $changes ): void {
		update_option( self::OPTION, array_merge( $this->saved(), array_intersect_key( $changes, self::DEFAULTS ) ), true );
	}

	/**
	 * Cleans a submitted allowlist: one address or @domain per line or comma.
	 *
	 * @param string $raw Submitted text.
	 * @return string[]
	 */
	public static function parse_allowlist( string $raw ): array {
		$entries = array();

		foreach ( preg_split( '/[\s,]+/', strtolower( $raw ) ) as $entry ) {
			$entry = trim( $entry );
			if ( '' === $entry ) {
				continue;
			}

			if ( '@' === $entry[0] ) {
				$domain = preg_replace( '/[^a-z0-9.\-]/', '', substr( $entry, 1 ) );
				if ( '' !== $domain && false !== strpos( $domain, '.' ) ) {
					$entries[] = '@' . $domain;
				}
			} elseif ( is_email( $entry ) ) {
				$entries[] = $entry;
			}
		}

		return array_values( array_unique( $entries ) );
	}

	/**
	 * Cleans submitted host patterns: one host or `*.domain` per line or comma.
	 *
	 * @param string $raw Submitted text.
	 * @return string[]
	 */
	public static function parse_hosts( string $raw ): array {
		return Http\Rules::parse_patterns( $raw );
	}
}
