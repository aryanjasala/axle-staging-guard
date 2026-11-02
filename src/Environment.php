<?php
/**
 * Works out whether this copy of the site is production.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Environment detection and the production lock.
 *
 * WordPress calls every site "production" unless WP_ENVIRONMENT_TYPE is set,
 * and most staging copies never set it. The production lock covers that case:
 * it stores a hash of the live domain, which survives a database copy to
 * staging, because search-replace tools rewrite plain domain names but not
 * their hashes.
 */
final class Environment {

	public const COLOURS = array(
		'local'       => '#1b5e20',
		'development' => '#0d47a1',
		'staging'     => '#b34700',
		'production'  => '#1d2327',
	);

	private const HASH_CONTEXT = 'axle-staging-guard|';

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Detected environment, cached for the request.
	 *
	 * @var array{type: string, source: string}|null
	 */
	private ?array $detected = null;

	/**
	 * Sets up detection.
	 *
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Environment type and how it was found.
	 *
	 * @return array{type: string, source: string} Source is constant, hosts, lock, default or filter.
	 */
	public function detect(): array {
		if ( null !== $this->detected ) {
			return $this->detected;
		}

		$from_env = getenv( 'WP_ENVIRONMENT_TYPE' );

		if ( defined( 'WP_ENVIRONMENT_TYPE' ) || ( false !== $from_env && '' !== $from_env ) ) {
			$environment = array(
				'type'   => wp_get_environment_type(),
				'source' => 'constant',
			);
		} elseif ( $this->production_hosts() ) {
			$environment = array(
				'type'   => array_intersect( $this->domain_variants( $this->domain() ), $this->production_hosts() ) ? 'production' : 'staging',
				'source' => 'hosts',
			);
		} elseif ( $this->is_locked() ) {
			$environment = array(
				'type'   => $this->is_production_domain() ? 'production' : 'staging',
				'source' => 'lock',
			);
		} else {
			$environment = array(
				'type'   => 'production',
				'source' => 'default',
			);
		}

		/**
		 * Filters the detected environment.
		 *
		 * @param array $environment {
		 *     Detected environment.
		 *
		 *     @type string $type   One of production, staging, development or local.
		 *     @type string $source How it was detected: constant, hosts, lock, default or filter.
		 * }
		 */
		$this->detected = apply_filters( 'axle_staging_guard_environment', $environment );

		return $this->detected;
	}

	/**
	 * Environment type: production, staging, development or local.
	 *
	 * @return string
	 */
	public function type(): string {
		return (string) $this->detect()['type'];
	}

	/**
	 * How the type was found: constant, hosts, lock, default or filter.
	 *
	 * @return string
	 */
	public function source(): string {
		return (string) $this->detect()['source'];
	}

	/**
	 * Whether the guards should run.
	 *
	 * @return bool
	 */
	public function is_guarded(): bool {
		return 'production' !== $this->type();
	}

	/**
	 * Human name of the environment.
	 *
	 * @param string|null $type Environment type, or null for the current one.
	 * @return string
	 */
	public function label( ?string $type = null ): string {
		$labels = array(
			'production'  => __( 'Production', 'axle-staging-guard' ),
			'staging'     => __( 'Staging', 'axle-staging-guard' ),
			'development' => __( 'Development', 'axle-staging-guard' ),
			'local'       => __( 'Local', 'axle-staging-guard' ),
		);
		$type   = $type ?? $this->type();

		/**
		 * Filters the name of an environment, shown in the admin bar and on the screens.
		 *
		 * @param string $label Default Production, Staging, Development or Local.
		 * @param string $type  Environment type.
		 */
		return (string) apply_filters( 'axle_staging_guard_environment_label', $labels[ $type ] ?? ucfirst( $type ), $type );
	}

	/**
	 * Admin bar colour for the environment.
	 *
	 * @return string Hex colour.
	 */
	public function colour(): string {
		/**
		 * Filters the admin bar colour of each environment.
		 *
		 * @param array<string, string> $colours Environment type => hex colour.
		 */
		$colours = (array) apply_filters( 'axle_staging_guard_colours', self::COLOURS );
		$colour  = (string) ( $colours[ $this->type() ] ?? self::COLOURS['staging'] );

		return (string) ( sanitize_hex_color( $colour ) ?? self::COLOURS['staging'] );
	}

	/**
	 * Domain of this copy of the site, from the Site Address setting.
	 *
	 * @return string
	 */
	public function domain(): string {
		$parts = wp_parse_url( home_url() );
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';

		return isset( $parts['port'] ) ? $host . ':' . $parts['port'] : $host;
	}

	/**
	 * Domains of the live site, from AXLE_STAGING_GUARD_PRODUCTION_HOSTS or the filter.
	 *
	 * When set, they decide production or staging instead of the production
	 * lock. Useful when the live site answers on several domains, and on
	 * multisite networks with mapped domains.
	 *
	 * @return string[] Lowercase host names, with a port if they need one.
	 */
	public function production_hosts(): array {
		$hosts = defined( 'AXLE_STAGING_GUARD_PRODUCTION_HOSTS' ) ? preg_split( '/[\s,]+/', (string) AXLE_STAGING_GUARD_PRODUCTION_HOSTS ) : array();

		/**
		 * Filters the domains of the live site.
		 *
		 * @param string[] $hosts Host names, with a port if they need one. Default from AXLE_STAGING_GUARD_PRODUCTION_HOSTS.
		 */
		$hosts = (array) apply_filters( 'axle_staging_guard_production_hosts', array_filter( (array) $hosts ) );

		return array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'trim', array_map( 'strval', $hosts ) ) ) ) ) );
	}

	/**
	 * A domain with and without "www.", which are almost always the same site.
	 *
	 * @param string $domain Domain name, with port if any.
	 * @return string[]
	 */
	public function domain_variants( string $domain ): array {
		$domain = strtolower( $domain );

		return 0 === strpos( $domain, 'www.' ) ? array( $domain, substr( $domain, 4 ) ) : array( $domain, 'www.' . $domain );
	}

	/**
	 * Hash the production lock stores for a domain.
	 *
	 * @param string $domain Domain name, with port if any.
	 * @return string
	 */
	public static function hash( string $domain ): string {
		return hash( 'sha256', self::HASH_CONTEXT . strtolower( $domain ) );
	}

	/**
	 * Whether a production lock is set.
	 *
	 * @return bool
	 */
	public function is_locked(): bool {
		return '' !== (string) $this->settings->get( 'production_hash' );
	}

	/**
	 * Whether this copy runs on the domain the production lock was set on.
	 *
	 * @return bool
	 */
	public function is_production_domain(): bool {
		$hash = (string) $this->settings->get( 'production_hash' );
		if ( '' === $hash ) {
			return false;
		}

		foreach ( $this->domain_variants( $this->domain() ) as $domain ) {
			if ( hash_equals( $hash, self::hash( $domain ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether this copy counts as staging only because the lock was set on another domain.
	 *
	 * That is right for a database copy, and wrong when the live site's own
	 * address changed, so the screens warn about it.
	 *
	 * @return bool
	 */
	public function is_staging_by_lock(): bool {
		return 'lock' === $this->source() && 'staging' === $this->type();
	}

	/**
	 * Marks the current domain as the live site.
	 */
	public function lock(): void {
		$this->settings->update( array( 'production_hash' => self::hash( $this->domain() ) ) );
		$this->detected = null;

		/**
		 * Fires after the production lock is set on this domain.
		 *
		 * @param string $domain The domain now counted as the live site.
		 */
		do_action( 'axle_staging_guard_production_locked', $this->domain() );
	}

	/**
	 * Removes the production lock.
	 */
	public function unlock(): void {
		$this->settings->update( array( 'production_hash' => '' ) );
		$this->detected = null;

		/**
		 * Fires after the production lock is removed.
		 */
		do_action( 'axle_staging_guard_production_unlocked' );
	}

	/**
	 * One sentence explaining how the environment was detected.
	 *
	 * @return string
	 */
	public function explain(): string {
		switch ( $this->source() ) {
			case 'constant':
				return __( 'Set by WP_ENVIRONMENT_TYPE in wp-config.php or the server environment.', 'axle-staging-guard' );

			case 'hosts':
				return 'production' === $this->type()
					/* translators: %s: domain name. */
					? sprintf( __( 'This domain (%s) is in AXLE_STAGING_GUARD_PRODUCTION_HOSTS.', 'axle-staging-guard' ), $this->domain() )
					/* translators: %s: domain name. */
					: sprintf( __( 'This domain (%s) is not in AXLE_STAGING_GUARD_PRODUCTION_HOSTS, so it is treated as staging.', 'axle-staging-guard' ), $this->domain() );

			case 'lock':
				return $this->is_production_domain()
					/* translators: %s: domain name. */
					? sprintf( __( 'The production lock matches this domain (%s).', 'axle-staging-guard' ), $this->domain() )
					/* translators: %s: domain name. */
					: sprintf( __( 'The production lock was set on a different domain, so this copy on %s is treated as staging.', 'axle-staging-guard' ), $this->domain() );

			case 'default':
				return __( 'Nothing tells WordPress which environment this is, so it assumes production and the guards are off.', 'axle-staging-guard' );

			default:
				return __( 'Set by the axle_staging_guard_environment filter.', 'axle-staging-guard' );
		}
	}
}
