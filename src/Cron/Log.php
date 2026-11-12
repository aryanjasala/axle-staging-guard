<?php
/**
 * The list of scheduled tasks staging held.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Cron;

use AryanX\Axle\StagingGuard\Mail\Log as MailLog;
use AryanX\Axle\StagingGuard\Service;

defined( 'ABSPATH' ) || exit;

/**
 * One private post per held run: the task, when it was due, who owns it and
 * a summary of its arguments (never their values). The same task with the same
 * due time and arguments is listed once, however often WordPress sees it overdue.
 * Retention follows the email log.
 */
final class Log implements Service {

	public const POST_TYPE = 'axle_sg_held';

	private const META_HOOK  = '_axle_staging_guard_hook';
	private const META_DUE   = '_axle_staging_guard_due';
	private const META_ARGS  = '_axle_staging_guard_args';
	private const META_OWNER = '_axle_staging_guard_owner';

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
	 * Registers the post type and the clean-up.
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
				'label'            => __( 'Held scheduled task', 'axle-staging-guard' ),
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
	 * Days an entry stays, the same as the email log.
	 *
	 * @return int
	 */
	public function days(): int {
		return $this->mail_log->days();
	}

	/**
	 * The most entries kept.
	 *
	 * @return int
	 */
	public function max_entries(): int {
		/**
		 * Filters how many held tasks the list keeps at most.
		 *
		 * The oldest go first.
		 *
		 * @param int $size Default 1000.
		 */
		return max( 1, (int) apply_filters( 'axle_staging_guard_held_log_size', self::MAX_ENTRIES ) );
	}

	/**
	 * Records a held run, unless the same one is already listed.
	 *
	 * @param array $entry hook, due (Unix time), args (summary text) and owner.
	 * @param array $args  The real arguments, only used to tell two runs of one hook apart. Never stored.
	 * @return int Entry ID, or 0 when it was listed already or could not be saved.
	 */
	public function add( array $entry, array $args = array() ): int {
		$hook  = substr( (string) ( $entry['hook'] ?? '' ), 0, 190 );
		$due   = (int) ( $entry['due'] ?? 0 );
		$owner = substr( (string) ( $entry['owner'] ?? '' ), 0, 120 );
		$key   = md5( $hook . '|' . $due . '|' . wp_json_encode( $args ) );

		if ( '' === $hook || $this->find( $key ) ) {
			return 0;
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_name'   => $key,
				'post_title'  => wp_slash( $hook ),
				'post_author' => 0,
				'meta_input'  => array(
					self::META_HOOK  => wp_slash( $hook ),
					self::META_DUE   => $due,
					self::META_ARGS  => wp_slash( substr( (string) ( $entry['args'] ?? '' ), 0, 200 ) ),
					self::META_OWNER => wp_slash( $owner ),
				),
			),
			true
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * The entries, newest first.
	 *
	 * @param int $limit How many.
	 * @return array<int, array{id: int, time: int, hook: string, due: int, args: string, owner: string}>
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
	 * How many runs were held since a time.
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
		do_action( 'axle_staging_guard_log_cleared', 'held', $removed );

		return $removed;
	}

	/**
	 * Removes entries older than the retention period, then the oldest beyond the size limit.
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
	 * Adds a line about the held task list to the suggested privacy policy text.
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
						'On staging and development copies of this site, Axle Staging Guard can hold scheduled tasks and list them. It keeps the name of the task, when it was due and the plugin that owns it. It does not keep the values the task was given. Entries are deleted after %d day. The live site keeps no such list.',
						'On staging and development copies of this site, Axle Staging Guard can hold scheduled tasks and list them. It keeps the name of the task, when it was due and the plugin that owns it. It does not keep the values the task was given. Entries are deleted after %d days. The live site keeps no such list.',
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
	 * @param string $key Hash of the run's identity.
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
	 * @return array{id: int, time: int, hook: string, due: int, args: string, owner: string}
	 */
	private function entry( \WP_Post $post ): array {
		return array(
			'id'    => (int) $post->ID,
			'time'  => (int) strtotime( $post->post_date_gmt . ' UTC' ),
			'hook'  => (string) get_post_meta( $post->ID, self::META_HOOK, true ),
			'due'   => (int) get_post_meta( $post->ID, self::META_DUE, true ),
			'args'  => (string) get_post_meta( $post->ID, self::META_ARGS, true ),
			'owner' => (string) get_post_meta( $post->ID, self::META_OWNER, true ),
		);
	}

	/**
	 * Entry IDs.
	 *
	 * @param array $args  Extra WP_Query arguments.
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
