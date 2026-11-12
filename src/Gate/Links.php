<?php
/**
 * Share links: named, timed, revocable ways into the gate without a password.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Gate;

use AryanX\Axle\StagingGuard\Mail\Log as MailLog;
use AryanX\Axle\StagingGuard\Service;
use AryanX\Axle\StagingGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the share links in the plugin's settings.
 *
 * A link is a record (ID, name, when it expires, last use) plus a signature
 * made with the gate's secret. The link itself is never stored: it is built
 * from the record, so a person who manages the plugin can copy it again later.
 * Revoking a link deletes its record, which also ends every cookie it set.
 */
final class Links implements Service {

	private const NAME_LENGTH = 60;
	private const MAX_LINKS   = 50;
	private const TOUCH_EVERY = 10 * MINUTE_IN_SECONDS;

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the links.
	 *
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the clean-up and the privacy tools.
	 */
	public function register(): void {
		add_action( MailLog::PURGE_HOOK, array( $this, 'purge' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'privacy_policy' ) );
	}

	/**
	 * The lengths a link can have, in days.
	 *
	 * @return int[] Between 1 and 30 days.
	 */
	public function durations(): array {
		/**
		 * Filters how many days a share link can last, as the choices offered when it is made.
		 *
		 * @param int[] $days Default 1, 3, 7, 14 and 30. Anything outside 1 to 30 is dropped.
		 */
		$days = (array) apply_filters( 'axle_staging_guard_gate_link_durations', array( 1, 3, 7, 14, 30 ) );
		$days = array_values( array_unique( array_filter( array_map( 'intval', $days ), static fn( int $day ): bool => $day >= 1 && $day <= 30 ) ) );
		sort( $days );

		return $days ? $days : array( 7 );
	}

	/**
	 * Most links the gate keeps at once.
	 *
	 * @return int
	 */
	public function max_links(): int {
		/**
		 * Filters how many share links can exist at once.
		 *
		 * @param int $max Default 50.
		 */
		return max( 1, (int) apply_filters( 'axle_staging_guard_gate_max_links', self::MAX_LINKS ) );
	}

	/**
	 * All links, newest first.
	 *
	 * @return array<int, array{id: string, name: string, created: int, expires: int, last_used: int, uses: int, by: int}>
	 */
	public function all(): array {
		$links = array();

		foreach ( (array) $this->settings->get( 'gate_links' ) as $link ) {
			if ( is_array( $link ) && ! empty( $link['id'] ) ) {
				$links[] = array(
					'id'        => (string) $link['id'],
					'name'      => (string) ( $link['name'] ?? '' ),
					'created'   => (int) ( $link['created'] ?? 0 ),
					'expires'   => (int) ( $link['expires'] ?? 0 ),
					'last_used' => (int) ( $link['last_used'] ?? 0 ),
					'uses'      => (int) ( $link['uses'] ?? 0 ),
					'by'        => (int) ( $link['by'] ?? 0 ),
				);
			}
		}

		usort(
			$links,
			static function ( array $a, array $b ): int {
				return $b['created'] <=> $a['created'];
			}
		);

		return $links;
	}

	/**
	 * One link by ID.
	 *
	 * @param string $id Link ID.
	 * @return array|null
	 */
	public function find( string $id ): ?array {
		foreach ( $this->all() as $link ) {
			if ( $link['id'] === $id ) {
				return $link;
			}
		}

		return null;
	}

	/**
	 * Whether a link still works.
	 *
	 * @param array $link A link.
	 * @return bool
	 */
	public function is_active( array $link ): bool {
		return (int) $link['expires'] > time();
	}

	/**
	 * Makes a link.
	 *
	 * @param string $name    Who it is for, such as "For Priya at the client".
	 * @param int    $days    How long it works, one of durations().
	 * @param int    $user_id Who made it.
	 * @return array|\WP_Error The link, or an error that says what to change.
	 */
	public function create( string $name, int $days, int $user_id = 0 ) {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return new \WP_Error( 'axle_staging_guard_link_name', __( 'Give the link a name, such as "For Priya at the client", so you know whose it is.', 'axle-staging-guard' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $days, $this->durations(), true ) ) {
			return new \WP_Error( 'axle_staging_guard_link_days', __( 'Choose how long the link should work.', 'axle-staging-guard' ), array( 'status' => 400 ) );
		}

		$links = $this->all();
		if ( count( $links ) >= $this->max_links() ) {
			return new \WP_Error( 'axle_staging_guard_link_limit', __( 'There are too many share links. Revoke the ones you no longer need, then try again.', 'axle-staging-guard' ), array( 'status' => 400 ) );
		}

		$now  = time();
		$link = array(
			'id'        => bin2hex( random_bytes( 5 ) ),
			'name'      => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::NAME_LENGTH ) : substr( $name, 0, self::NAME_LENGTH ),
			'created'   => $now,
			'expires'   => $now + $days * DAY_IN_SECONDS,
			'last_used' => 0,
			'uses'      => 0,
			'by'        => $user_id,
		);

		$links[] = $link;
		$this->settings->update(
			array(
				'gate_links'  => $links,
				'gate_secret' => $this->secret(),
			),
			false
		);

		/**
		 * Fires after a share link is made.
		 *
		 * @param array $link id, name, created, expires, last_used, uses and by (user ID).
		 */
		do_action( 'axle_staging_guard_gate_link_created', $link );

		return $link;
	}

	/**
	 * Revokes a link. Anyone who used it is signed out on their next request.
	 *
	 * @param string $id Link ID.
	 * @return bool False when there is no such link.
	 */
	public function revoke( string $id ): bool {
		$link = $this->find( $id );
		if ( null === $link ) {
			return false;
		}

		$this->settings->update(
			array(
				'gate_links' => array_values(
					array_filter(
						$this->all(),
						static function ( array $other ) use ( $id ): bool {
							return $other['id'] !== $id;
						}
					)
				),
			),
			false
		);

		/**
		 * Fires after a share link is revoked.
		 *
		 * @param array $link The link that was removed.
		 */
		do_action( 'axle_staging_guard_gate_link_revoked', $link );

		return true;
	}

	/**
	 * Notes that a link was used.
	 *
	 * @param string $id    Link ID.
	 * @param bool   $count Whether this is a new visit through the link (true) or a request with its cookie (false).
	 *                      Cookie requests only refresh the last-used time, at most every ten minutes.
	 */
	public function touch( string $id, bool $count ): void {
		$now   = time();
		$found = false;
		$links = $this->all();

		foreach ( $links as $index => $link ) {
			if ( $link['id'] !== $id ) {
				continue;
			}
			if ( ! $count && $now - $link['last_used'] < self::TOUCH_EVERY ) {
				return;
			}

			$links[ $index ]['last_used'] = $now;
			if ( $count ) {
				++$links[ $index ]['uses'];
			}
			$found = true;
			break;
		}

		if ( $found ) {
			$this->settings->update( array( 'gate_links' => $links ), false );
		}
	}

	/**
	 * The signing secret, made on first use.
	 *
	 * @return string
	 */
	public function secret(): string {
		$secret = (string) $this->settings->get( 'gate_secret' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 64, true, true );
			$this->settings->update( array( 'gate_secret' => $secret ), false );
		}

		return $secret;
	}

	/**
	 * What a link and its cookie carry.
	 *
	 * @param array $link A link.
	 * @return string
	 */
	public function token( array $link ): string {
		return Access::link_token( $link['id'], (int) $link['expires'], $this->secret() );
	}

	/**
	 * The address a person opens to get in.
	 *
	 * @param array $link A link.
	 * @return string
	 */
	public function url( array $link ): string {
		$url = add_query_arg( Gate::LINK_PARAM, rawurlencode( $this->token( $link ) ), home_url( '/' ) );

		/**
		 * Filters the address of a share link.
		 *
		 * @param string $url  Site address with the link's token.
		 * @param array  $link The link record.
		 */
		return (string) apply_filters( 'axle_staging_guard_gate_link_url', $url, $link );
	}

	/**
	 * Finds the active link a token belongs to.
	 *
	 * @param string $token Value from the address or the cookie.
	 * @return array|null The link, or null when the token is wrong, expired or revoked.
	 */
	public function from_token( string $token ): ?array {
		$parsed = Access::parse_token( $token );
		if ( null === $parsed || 'link' !== $parsed['kind'] ) {
			return null;
		}

		$link = $this->find( $parsed['id'] );
		if ( null === $link || ! $this->is_active( $link ) ) {
			return null;
		}

		return hash_equals( $this->token( $link ), $token ) ? $link : null;
	}

	/**
	 * Removes links that expired long ago. Runs with the hourly clean-up.
	 */
	public function purge(): void {
		/**
		 * Filters how many days an expired share link stays in the list before it is removed.
		 *
		 * @param int $days Default 30.
		 */
		$days   = max( 1, (int) apply_filters( 'axle_staging_guard_gate_link_retention_days', 30 ) );
		$cutoff = time() - $days * DAY_IN_SECONDS;
		$links  = $this->all();
		$keep   = array_values(
			array_filter(
				$links,
				static function ( array $link ) use ( $cutoff ): bool {
					return (int) $link['expires'] >= $cutoff;
				}
			)
		);

		if ( count( $keep ) !== count( $links ) ) {
			$this->settings->update( array( 'gate_links' => $keep ), false );
		}
	}

	/**
	 * Adds the links to Tools > Export Personal Data.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function add_exporter( array $exporters ): array {
		$exporters['axle-staging-guard-gate'] = array(
			'exporter_friendly_name' => __( 'Axle Staging Guard share links', 'axle-staging-guard' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds the links to Tools > Erase Personal Data.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function add_eraser( array $erasers ): array {
		$erasers['axle-staging-guard-gate'] = array(
			'eraser_friendly_name' => __( 'Axle Staging Guard share links', 'axle-staging-guard' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Personal data exporter: the share links a person made.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		$data = array();

		if ( $user ) {
			foreach ( $this->made_by( (int) $user->ID ) as $link ) {
				$data[] = array(
					'group_id'    => 'axle-staging-guard-gate',
					'group_label' => __( 'Staging share links', 'axle-staging-guard' ),
					'item_id'     => 'axle-staging-guard-link-' . $link['id'],
					'data'        => array(
						array(
							'name'  => __( 'Name', 'axle-staging-guard' ),
							'value' => $link['name'],
						),
						array(
							'name'  => __( 'Made', 'axle-staging-guard' ),
							'value' => gmdate( 'Y-m-d H:i:s', $link['created'] ),
						),
						array(
							'name'  => __( 'Expires', 'axle-staging-guard' ),
							'value' => gmdate( 'Y-m-d H:i:s', $link['expires'] ),
						),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Personal data eraser: forgets who made a link and clears the name, but keeps the link working.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email ): array {
		$user  = get_user_by( 'email', (string) $email );
		$count = 0;

		if ( $user ) {
			$links = $this->all();
			foreach ( $links as $index => $link ) {
				if ( $link['by'] === (int) $user->ID ) {
					$links[ $index ]['by']   = 0;
					$links[ $index ]['name'] = __( 'Name removed', 'axle-staging-guard' );
					++$count;
				}
			}
			if ( $count ) {
				$this->settings->update( array( 'gate_links' => $links ), false );
			}
		}

		return array(
			'items_removed'  => $count > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested text for Settings > Privacy.
	 */
	public function privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Axle Staging Guard', 'axle-staging-guard' ),
			'<p>' . esc_html__( 'On staging and development copies of this site, an administrator can protect the front end with a password and make share links for named visitors. The plugin keeps the name an administrator typed for each link, who made it, and when it was last used. A visitor who gets in receives one cookie that holds a signed token, and failed password attempts are counted against a hashed form of the IP address for a few minutes. The live site keeps none of this.', 'axle-staging-guard' ) . '</p>'
		);
	}

	/**
	 * Links a user made.
	 *
	 * @param int $user_id User ID.
	 * @return array[]
	 */
	private function made_by( int $user_id ): array {
		return array_values(
			array_filter(
				$this->all(),
				static function ( array $link ) use ( $user_id ): bool {
					return $link['by'] === $user_id;
				}
			)
		);
	}
}
