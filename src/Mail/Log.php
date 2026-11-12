<?php
/**
 * Record of stopped email.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Mail;

use AryanX\Axle\StagingGuard\Service;
use AryanX\Axle\StagingGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps blocked or redirected emails as private posts, one per email.
 *
 * One row per email means web servers writing at the same time never lose
 * each other's entries. Entries are deleted after a few days (7 by default)
 * and the log never holds more than MAX_ENTRIES. Message bodies are only kept
 * when the "keep message bodies" setting is on, because emails can contain
 * personal data and password reset links. The log takes part in the WordPress
 * personal data export and erase tools.
 */
final class Log implements Service {

	public const POST_TYPE  = 'axle_sg_mail';
	public const PURGE_HOOK = 'axle_staging_guard_purge_log';

	/**
	 * Option that held the log in version 1.1.0 and earlier.
	 */
	public const LEGACY_OPTION = 'axle_staging_guard_mail_log';

	private const META_TO        = '_axle_staging_guard_to';
	private const META_DELIVERED = '_axle_staging_guard_delivered';
	private const META_ACTION    = '_axle_staging_guard_action';
	private const META_BODY      = '_axle_staging_guard_body';

	private const SHOWN       = 50;
	private const MAX_ENTRIES = 1000;
	private const BODY_SIZE   = 10000;
	private const BATCH       = 100;

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the log.
	 *
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the post type, the clean-up and the privacy tools.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( self::PURGE_HOOK, array( $this, 'purge' ) );
		add_action( 'admin_init', array( $this, 'schedule' ) );
		add_action( 'admin_init', array( $this, 'privacy_policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	/**
	 * Registers the private post type that holds entries.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'            => __( 'Stopped email', 'axle-staging-guard' ),
				'public'           => false,
				'show_ui'          => false,
				'rewrite'          => false,
				'query_var'        => false,
				'can_export'       => false,
				'supports'         => array( 'title' ),
				'delete_with_user' => false,
			)
		);
	}

	/**
	 * Keeps the hourly clean-up scheduled, and drops the 1.1.0 log option once.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::PURGE_HOOK );
		}

		if ( (int) $this->settings->get( 'log_version' ) < 2 ) {
			delete_option( self::LEGACY_OPTION );
			$this->settings->update( array( 'log_version' => 2 ) );
		}
	}

	/**
	 * Days an entry is kept.
	 *
	 * @return int
	 */
	public function days(): int {
		$days = defined( 'AXLE_STAGING_GUARD_LOG_DAYS' ) ? (int) AXLE_STAGING_GUARD_LOG_DAYS : 7;

		/**
		 * Filters how many days stopped emails stay in the log.
		 *
		 * @param int $days Days. Default 7, or AXLE_STAGING_GUARD_LOG_DAYS.
		 */
		return max( 1, (int) apply_filters( 'axle_staging_guard_log_days', $days ) );
	}

	/**
	 * Most entries the log keeps.
	 *
	 * @return int
	 */
	public function max_entries(): int {
		/**
		 * Filters how many stopped emails the log keeps at most.
		 *
		 * @param int $size Default 1000.
		 */
		return max( 1, (int) apply_filters( 'axle_staging_guard_log_size', self::MAX_ENTRIES ) );
	}

	/**
	 * Adds an entry.
	 *
	 * @param array $entry {
	 *     What happened.
	 *
	 *     @type string $to        Original recipients.
	 *     @type string $subject   Subject line.
	 *     @type string $action    blocked, redirected or partly_blocked.
	 *     @type string $delivered Recipients the email still went to.
	 *     @type string $body      Message body.
	 * }
	 * @return int Entry ID, or 0 if it could not be saved.
	 */
	public function add( array $entry ): int {
		$keep_body = $this->keeps_bodies();
		$body      = $keep_body ? substr( (string) ( $entry['body'] ?? '' ), 0, self::BODY_SIZE ) : '';

		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => wp_slash( substr( (string) ( $entry['subject'] ?? '' ), 0, 200 ) ),
				'post_author' => 0,
				'meta_input'  => array(
					self::META_TO        => wp_slash( (string) ( $entry['to'] ?? '' ) ),
					self::META_DELIVERED => wp_slash( (string) ( $entry['delivered'] ?? '' ) ),
					self::META_ACTION    => (string) ( $entry['action'] ?? 'blocked' ),
					self::META_BODY      => wp_slash( $body ),
				),
			),
			true
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Whether message bodies are kept, after wp-config.php overrides.
	 *
	 * @return bool
	 */
	public function keeps_bodies(): bool {
		return defined( 'AXLE_STAGING_GUARD_KEEP_BODIES' ) ? (bool) AXLE_STAGING_GUARD_KEEP_BODIES : (bool) $this->settings->get( 'keep_bodies' );
	}

	/**
	 * The newest entries.
	 *
	 * @param int $limit Entries to return.
	 * @return array[] Each with id, time, to, subject, action, delivered and body.
	 */
	public function all( int $limit = self::SHOWN ): array {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => max( 1, min( $this->max_entries(), $limit ) ),
				'orderby'          => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'suppress_filters' => false,
			)
		);

		return array_map( array( $this, 'entry' ), $posts );
	}

	/**
	 * Most recent entry.
	 *
	 * @return array|null
	 */
	public function latest(): ?array {
		return $this->all( 1 )[0] ?? null;
	}

	/**
	 * Number of entries since a time.
	 *
	 * @param int $since Unix time.
	 * @return int
	 */
	public function count_since( int $since ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'date_query'     => array(
					array(
						'column' => 'post_date_gmt',
						'after'  => gmdate( 'Y-m-d H:i:s', $since ),
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Empties the log.
	 *
	 * @return int How many entries were removed.
	 */
	public function clear(): int {
		$removed = 0;

		do {
			$ids = $this->ids( array() );
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}
			$removed += count( $ids );
		} while ( $ids );

		/**
		 * Fires after a list of stopped items is cleared by hand.
		 *
		 * @param string $list    Which list: mail, http or held.
		 * @param int    $removed How many entries were removed.
		 */
		do_action( 'axle_staging_guard_log_cleared', 'mail', $removed );

		return $removed;
	}

	/**
	 * Cron callback: deletes entries past their days, and the oldest beyond MAX_ENTRIES.
	 */
	public function purge(): void {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $this->days() * DAY_IN_SECONDS );

		for ( $batch = 0; $batch < 20; $batch++ ) {
			$ids = $this->ids(
				array(
					'date_query' => array(
						array(
							'column' => 'post_date_gmt',
							'before' => $cutoff,
						),
					),
				)
			);
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}
		}

		$extra = (int) wp_count_posts( self::POST_TYPE )->publish - $this->max_entries();
		while ( $extra > 0 ) {
			$ids = $this->ids( array( 'order' => 'ASC' ), min( $extra, self::BATCH ) );
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}
			$extra -= count( $ids );
		}
	}

	/**
	 * Adds the log to Tools > Export Personal Data.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function add_exporter( array $exporters ): array {
		$exporters['axle-staging-guard'] = array(
			'exporter_friendly_name' => __( 'Axle Staging Guard stopped email', 'axle-staging-guard' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds the log to Tools > Erase Personal Data.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function add_eraser( array $erasers ): array {
		$erasers['axle-staging-guard'] = array(
			'eraser_friendly_name' => __( 'Axle Staging Guard stopped email', 'axle-staging-guard' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Personal data exporter: entries sent to an address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$ids  = $this->ids_for_address( (string) $email, (int) $page );
		$data = array();

		foreach ( $ids as $id ) {
			$entry  = $this->entry( get_post( $id ) );
			$fields = array(
				array(
					'name'  => __( 'Date', 'axle-staging-guard' ),
					'value' => gmdate( 'Y-m-d H:i:s', $entry['time'] ),
				),
				array(
					'name'  => __( 'To', 'axle-staging-guard' ),
					'value' => $entry['to'],
				),
				array(
					'name'  => __( 'Subject', 'axle-staging-guard' ),
					'value' => $entry['subject'],
				),
				array(
					'name'  => __( 'What happened', 'axle-staging-guard' ),
					'value' => $entry['action'],
				),
			);
			if ( '' !== $entry['body'] ) {
				$fields[] = array(
					'name'  => __( 'Message', 'axle-staging-guard' ),
					'value' => $entry['body'],
				);
			}

			$data[] = array(
				'group_id'    => 'axle-staging-guard-mail',
				'group_label' => __( 'Email stopped on a staging copy', 'axle-staging-guard' ),
				'item_id'     => 'axle-staging-guard-mail-' . $id,
				'data'        => $fields,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $ids ) < self::BATCH,
		);
	}

	/**
	 * Personal data eraser: deletes entries sent to an address.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email ): array {
		// Always page 1: deleted entries drop out of the next query.
		$ids = $this->ids_for_address( (string) $email, 1 );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}

		return array(
			'items_removed'  => (bool) $ids,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < self::BATCH,
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
			'<p>' . esc_html(
				sprintf(
					/* translators: %d: number of days. */
					_n(
						'On staging and development copies of this site, Axle Staging Guard stops outgoing email and keeps a log of it: who it was for, the subject and, if a site administrator turns it on, the message. Entries are deleted after %d day. The live site keeps no such log.',
						'On staging and development copies of this site, Axle Staging Guard stops outgoing email and keeps a log of it: who it was for, the subject and, if a site administrator turns it on, the message. Entries are deleted after %d days. The live site keeps no such log.',
						$this->days(),
						'axle-staging-guard'
					),
					$this->days()
				)
			) . '</p>'
		);
	}

	/**
	 * An entry as an array.
	 *
	 * @param \WP_Post $post Entry post.
	 * @return array{id: int, time: int, to: string, subject: string, action: string, delivered: string, body: string}
	 */
	private function entry( \WP_Post $post ): array {
		return array(
			'id'        => (int) $post->ID,
			'time'      => (int) strtotime( $post->post_date_gmt . ' UTC' ),
			'to'        => (string) get_post_meta( $post->ID, self::META_TO, true ),
			'subject'   => $post->post_title,
			'action'    => (string) get_post_meta( $post->ID, self::META_ACTION, true ),
			'delivered' => (string) get_post_meta( $post->ID, self::META_DELIVERED, true ),
			'body'      => (string) get_post_meta( $post->ID, self::META_BODY, true ),
		);
	}

	/**
	 * Entry IDs, a batch at a time.
	 *
	 * @param array $args  Extra query arguments.
	 * @param int   $limit Batch size.
	 * @return int[]
	 */
	private function ids( array $args, int $limit = self::BATCH ): array {
		$query = new \WP_Query(
			array_merge(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				),
				$args
			)
		);

		return wp_parse_id_list( $query->posts );
	}

	/**
	 * Entries sent, or still delivered, to an address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return int[]
	 */
	private function ids_for_address( string $email, int $page ): array {
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return array();
		}

		return $this->ids(
			array(
				'paged'      => max( 1, $page ),
				// The log is small and short-lived, and only searched from the privacy tools.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'     => self::META_TO,
						'value'   => $email,
						'compare' => 'LIKE',
					),
					array(
						'key'     => self::META_DELIVERED,
						'value'   => $email,
						'compare' => 'LIKE',
					),
				),
			)
		);
	}
}
