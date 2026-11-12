<?php
/**
 * The list of outgoing requests that were blocked or logged.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Http;

use AryanX\Axle\StagingGuard\Mail\Log as MailLog;
use AryanX\Axle\StagingGuard\Service;

defined( 'ABSPATH' ) || exit;

/**
 * One private post per distinct request: method, host, path, what happened and
 * who made it. A request that repeats (a cron job in a loop) raises the count
 * and the last-seen time of its entry instead of adding another one.
 *
 * It stores no query string, body or header, and path segments that look like
 * secrets or identifiers are masked (see Rules::clean_path()), so the list
 * holds no personal data. Retention follows the email log.
 */
final class Log implements Service {

	public const POST_TYPE = 'axle_sg_http';

	private const META_METHOD = '_axle_staging_guard_method';
	private const META_HOST   = '_axle_staging_guard_host';
	private const META_PATH   = '_axle_staging_guard_path';
	private const META_ACTION = '_axle_staging_guard_action';
	private const META_CALLER = '_axle_staging_guard_caller';
	private const META_COUNT  = '_axle_staging_guard_count';
	private const META_FIRST  = '_axle_staging_guard_first';

	private const SHOWN       = 50;
	private const MAX_ENTRIES = 1000;
	private const BATCH       = 100;

	/**
	 * The email log, which decides how long entries stay.
	 *
	 * @var MailLog
	 */
	private MailLog $mail_log;

	/**
	 * Sets up the log.
	 *
	 * @param MailLog $mail_log The email log, for the retention period.
	 */
	public function __construct( MailLog $mail_log ) {
		$this->mail_log = $mail_log;
	}

	/**
	 * Registers the post type and the cleanup.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( MailLog::PURGE_HOOK, array( $this, 'purge' ) );
		add_action( 'admin_init', array( $this, 'privacy_policy' ) );
	}

	/**
	 * Registers the private post type that holds the entries.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'            => __( 'Stopped outgoing request', 'axle-staging-guard' ),
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
	 * The most entries kept.
	 *
	 * @return int
	 */
	public function max_entries(): int {
		/**
		 * Filters how many outgoing requests the list keeps at most.
		 *
		 * The oldest go first.
		 *
		 * @param int $size Default 1000.
		 */
		return max( 1, (int) apply_filters( 'axle_staging_guard_http_log_size', self::MAX_ENTRIES ) );
	}

	/**
	 * Days an entry stays, the same as the email log.
	 *
	 * @return int
	 */
	public function days(): int {
		return $this->mail_log->days();
	}

	/**
	 * Records a request, or counts it again when it was seen before.
	 *
	 * @param array $entry method, host, path (already cleaned), action (blocked or logged) and caller.
	 * @return int Entry ID, or 0 when it could not be saved.
	 */
	public function add( array $entry ): int {
		$method = strtoupper( substr( (string) ( $entry['method'] ?? 'GET' ), 0, 10 ) );
		$host   = substr( (string) ( $entry['host'] ?? '' ), 0, 255 );
		$path   = substr( (string) ( $entry['path'] ?? '/' ), 0, 200 );
		$action = 'blocked' === ( $entry['action'] ?? '' ) ? 'blocked' : 'logged';
		$caller = substr( (string) ( $entry['caller'] ?? '' ), 0, 120 );
		$key    = md5( implode( '|', array( $method, $host, $path, $action, $caller ) ) );
		$now    = time();

		$existing = $this->find( $key );
		if ( $existing ) {
			update_post_meta( $existing, self::META_COUNT, (int) get_post_meta( $existing, self::META_COUNT, true ) + 1 );
			wp_update_post(
				array(
					'ID'            => $existing,
					'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $now ) ),
					'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $now ),
					'edit_date'     => true,
				)
			);

			return $existing;
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_name'   => $key,
				'post_title'  => wp_slash( substr( $method . ' ' . $host . $path, 0, 255 ) ),
				'post_author' => 0,
				'meta_input'  => array(
					self::META_METHOD => $method,
					self::META_HOST   => wp_slash( $host ),
					self::META_PATH   => wp_slash( $path ),
					self::META_ACTION => $action,
					self::META_CALLER => wp_slash( $caller ),
					self::META_COUNT  => 1,
					self::META_FIRST  => $now,
				),
			),
			true
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * The entries, most recently seen first.
	 *
	 * @param int $limit How many.
	 * @return array[]
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
	 * How many different requests were seen since a time.
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
	 * Removes every entry.
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
		do_action( 'axle_staging_guard_log_cleared', 'http', $removed );

		return $removed;
	}

	/**
	 * Removes entries not seen within the retention period, then the oldest beyond the size limit.
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
	 * Adds a line about the request list to the suggested privacy policy text.
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
						'On staging and development copies of this site, Axle Staging Guard can also stop or record outgoing requests to other services. It keeps the address of the service, the request method and the plugin that made the call, never the data sent. Entries are deleted after %d day. The live site keeps no such list.',
						'On staging and development copies of this site, Axle Staging Guard can also stop or record outgoing requests to other services. It keeps the address of the service, the request method and the plugin that made the call, never the data sent. Entries are deleted after %d days. The live site keeps no such list.',
						$this->days(),
						'axle-staging-guard'
					),
					$this->days()
				)
			) . '</p>'
		);
	}

	/**
	 * Entry ID for a key, or 0.
	 *
	 * @param string $key Hash of the request's identity.
	 * @return int
	 */
	private function find( string $key ): int {
		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'name'             => $key,
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				'no_found_rows'    => true,
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * A post as an entry.
	 *
	 * @param \WP_Post $post Entry post.
	 * @return array{id: int, time: int, first: int, method: string, host: string, path: string, action: string, caller: string, count: int}
	 */
	private function entry( \WP_Post $post ): array {
		return array(
			'id'     => (int) $post->ID,
			'time'   => (int) strtotime( $post->post_date_gmt . ' UTC' ),
			'first'  => (int) get_post_meta( $post->ID, self::META_FIRST, true ),
			'method' => (string) get_post_meta( $post->ID, self::META_METHOD, true ),
			'host'   => (string) get_post_meta( $post->ID, self::META_HOST, true ),
			'path'   => (string) get_post_meta( $post->ID, self::META_PATH, true ),
			'action' => (string) get_post_meta( $post->ID, self::META_ACTION, true ),
			'caller' => (string) get_post_meta( $post->ID, self::META_CALLER, true ),
			'count'  => max( 1, (int) get_post_meta( $post->ID, self::META_COUNT, true ) ),
		);
	}

	/**
	 * Entry IDs.
	 *
	 * @param array $args Extra WP_Query arguments.
	 * @param int   $limit How many.
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
}
