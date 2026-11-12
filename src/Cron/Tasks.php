<?php
/**
 * The site's scheduled tasks, and which of them staging holds.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Cron;

use AryanX\Axle\StagingGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the WP-Cron schedule and answers: what is it, who owns it, should it be held?
 */
final class Tasks {

	/**
	 * Hooks that WordPress needs to stay healthy. They are never held, unless a filter says so.
	 */
	public const NEVER_HELD = array(
		'wp_scheduled_delete',
		'wp_scheduled_auto_draft_delete',
		'delete_expired_transients',
		'wp_privacy_delete_old_export_files',
		'recovery_mode_clean_expired_keys',
		'wp_delete_temp_updater_backups',
		'axle_staging_guard_purge_log',
	);

	/**
	 * Name parts that suggest a task touches the outside world: a regular expression each.
	 */
	public const SUGGESTIONS = array(
		'newsletter',
		'export',
		'sync',
		'feed',
		'renewal',
		'subscription.*payment|payment.*subscription',
	);

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the list.
	 *
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks that are never held.
	 *
	 * @return string[]
	 */
	public function protected_hooks(): array {
		/**
		 * Filters the scheduled hooks that are never held, because the site needs them.
		 *
		 * Remove a hook from the list to allow holding it. Privacy and recovery
		 * hooks (names starting with wp_privacy_ or recovery_mode_) are protected
		 * by the axle_staging_guard_cron_protected_prefixes filter.
		 *
		 * @param string[] $hooks Default: WordPress's clean-up hooks and this plugin's own.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'axle_staging_guard_cron_protected_hooks', self::NEVER_HELD ) ) ) );
	}

	/**
	 * Name starts that are never held: privacy and recovery tasks.
	 *
	 * @return string[]
	 */
	public function protected_prefixes(): array {
		/**
		 * Filters the starts of scheduled hook names that are never held.
		 *
		 * @param string[] $prefixes Default wp_privacy_ and recovery_mode_.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'axle_staging_guard_cron_protected_prefixes', array( 'wp_privacy_', 'recovery_mode_' ) ) ) ) );
	}

	/**
	 * Whether a hook may never be held.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	public function is_protected( string $hook ): bool {
		if ( in_array( $hook, $this->protected_hooks(), true ) ) {
			return true;
		}

		foreach ( $this->protected_prefixes() as $prefix ) {
			if ( 0 === strpos( $hook, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Patterns that suggest holding a task.
	 *
	 * @return string[]
	 */
	public function suggestion_patterns(): array {
		/**
		 * Filters the name parts that suggest holding a scheduled task on staging.
		 *
		 * Each entry is a regular expression, without delimiters, matched without regard to case.
		 * A suggestion only pre-ticks the box on the Scheduled tasks tab. It holds nothing until it is saved.
		 *
		 * @param string[] $patterns Default: newsletter, export, sync, feed, renewal and subscription payment.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'axle_staging_guard_cron_suggestions', self::SUGGESTIONS ) ) ) );
	}

	/**
	 * Whether a hook's name suggests it touches the outside world.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	public function is_suggested( string $hook ): bool {
		if ( $this->is_protected( $hook ) ) {
			return false;
		}

		foreach ( $this->suggestion_patterns() as $pattern ) {
			if ( 1 === @preg_match( '#' . str_replace( '#', '\#', $pattern ) . '#i', $hook ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A filtered pattern can be invalid; that means no match.
				return true;
			}
		}

		return false;
	}

	/**
	 * The hooks held on this copy.
	 *
	 * @return string[] Saved hooks, minus protected ones, after the filter.
	 */
	public function held(): array {
		$held = array_values( array_unique( array_map( 'strval', (array) $this->settings->get( 'cron_held' ) ) ) );

		/**
		 * Filters the scheduled hooks held on this copy.
		 *
		 * Protected hooks are removed after this filter, unless the
		 * axle_staging_guard_cron_protected_hooks filter allows them.
		 *
		 * @param string[] $hooks Saved on the Scheduled tasks tab.
		 */
		$held = (array) apply_filters( 'axle_staging_guard_cron_held_hooks', $held );

		return array_values(
			array_filter(
				array_unique( array_map( 'strval', $held ) ),
				function ( string $hook ): bool {
					return '' !== $hook && ! $this->is_protected( $hook );
				}
			)
		);
	}

	/**
	 * Whether the box for a hook is ticked on the screen.
	 *
	 * A hook the person already decided about shows their choice. One they have
	 * not seen yet is ticked when its name suggests it, but nothing is held until they save.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	public function is_ticked( string $hook ): bool {
		if ( $this->is_protected( $hook ) ) {
			return false;
		}

		if ( in_array( $hook, (array) $this->settings->get( 'cron_decided' ), true ) ) {
			return in_array( $hook, (array) $this->settings->get( 'cron_held' ), true );
		}

		return $this->is_suggested( $hook );
	}

	/**
	 * Hooks that look risky and have not been decided about yet.
	 *
	 * @return string[]
	 */
	public function undecided_suggestions(): array {
		$decided = (array) $this->settings->get( 'cron_decided' );
		$found   = array();

		foreach ( array_keys( $this->hooks() ) as $hook ) {
			if ( ! in_array( $hook, $decided, true ) && $this->is_suggested( $hook ) ) {
				$found[] = $hook;
			}
		}

		return $found;
	}

	/**
	 * Every scheduled hook, soonest first.
	 *
	 * @return array<string, array{hook: string, next: int, schedule: string, interval: int, count: int, args: array, owner: string}>
	 */
	public function hooks(): array {
		$list = array();

		foreach ( _get_cron_array() as $timestamp => $events ) {
			foreach ( $events as $hook => $instances ) {
				$hook = (string) $hook;

				if ( ! isset( $list[ $hook ] ) ) {
					$first         = reset( $instances );
					$list[ $hook ] = array(
						'hook'     => $hook,
						'next'     => (int) $timestamp,
						'schedule' => ! empty( $first['schedule'] ) ? (string) $first['schedule'] : '',
						'interval' => (int) ( $first['interval'] ?? 0 ),
						'count'    => 0,
						'args'     => (array) ( $first['args'] ?? array() ),
						'owner'    => '',
					);
				}

				$list[ $hook ]['count'] += count( $instances );
			}
		}

		/**
		 * Filters how many scheduled hooks the screen lists at most.
		 *
		 * @param int $limit Default 500.
		 */
		$limit = max( 1, (int) apply_filters( 'axle_staging_guard_cron_list_limit', 500 ) );

		return array_slice( $list, 0, $limit, true );
	}

	/**
	 * Which plugin, theme or WordPress registered the callbacks of a hook.
	 *
	 * Only callbacks added while this request loaded are known. A task whose
	 * plugin adds its callback late may show no owner.
	 *
	 * @param string $hook Hook name.
	 * @return string `plugin:slug`, `mu-plugin:slug`, `theme:slug`, `core` or an empty string.
	 */
	public function owner( string $hook ): string {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ]->callbacks ) ) {
			return '';
		}

		$roots = array(
			'plugin'    => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
			'mu-plugin' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
			'theme'     => function_exists( 'get_theme_root' ) ? get_theme_root() : '',
		);

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$file = self::callback_file( $callback['function'] ?? null );
				$name = '' === $file ? '' : self::owner_from_file( $file, $roots, defined( 'ABSPATH' ) ? ABSPATH : '' );
				if ( '' !== $name ) {
					return $name;
				}
			}
		}

		return '';
	}

	/**
	 * The file a callback is defined in.
	 *
	 * @param mixed $callback A callable.
	 * @return string Empty when it cannot be found.
	 */
	public static function callback_file( $callback ): string {
		try {
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$callback = explode( '::', $callback, 2 );
			}

			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$reflection = new \ReflectionMethod( $callback[0], (string) $callback[1] );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) && ! ( $callback instanceof \Closure ) ) {
				$reflection = new \ReflectionMethod( $callback, '__invoke' );
			} elseif ( is_string( $callback ) || $callback instanceof \Closure ) {
				$reflection = new \ReflectionFunction( $callback );
			} else {
				return '';
			}

			return (string) $reflection->getFileName();
		} catch ( \ReflectionException $e ) {
			return '';
		}
	}

	/**
	 * Names the plugin, theme or core a file belongs to.
	 *
	 * @param string   $file  Absolute path.
	 * @param string[] $roots Folder of each kind: plugin, mu-plugin and theme.
	 * @param string   $core  WordPress's own folder.
	 * @return string `plugin:slug`, `mu-plugin:slug`, `theme:slug`, `core` or an empty string.
	 */
	public static function owner_from_file( string $file, array $roots, string $core = '' ): string {
		$file = wp_normalize_path( $file );

		foreach ( $roots as $type => $root ) {
			$root = '' === $root ? '' : trailingslashit( wp_normalize_path( $root ) );
			if ( '' === $root || 0 !== strpos( $file, $root ) ) {
				continue;
			}

			$slug = (string) preg_replace( '/\.php$/', '', explode( '/', substr( $file, strlen( $root ) ) )[0] );
			if ( '' !== $slug ) {
				return $type . ':' . $slug;
			}
		}

		$core = '' === $core ? '' : trailingslashit( wp_normalize_path( $core ) );
		if ( '' !== $core && ( 0 === strpos( $file, $core . 'wp-includes/' ) || 0 === strpos( $file, $core . 'wp-admin/' ) ) ) {
			return 'core';
		}

		return '';
	}

	/**
	 * The owner as words.
	 *
	 * @param string $owner Value from owner().
	 * @return string
	 */
	public static function owner_label( string $owner ): string {
		if ( '' === $owner ) {
			return __( 'Not found', 'axle-staging-guard' );
		}

		if ( 'core' === $owner ) {
			return __( 'WordPress', 'axle-staging-guard' );
		}

		list( $type, $slug ) = array_pad( explode( ':', $owner, 2 ), 2, '' );
		$types               = array(
			/* translators: %s: plugin folder name. */
			'plugin'    => __( 'Plugin: %s', 'axle-staging-guard' ),
			/* translators: %s: must-use plugin file name. */
			'mu-plugin' => __( 'Must-use plugin: %s', 'axle-staging-guard' ),
			/* translators: %s: theme folder name. */
			'theme'     => __( 'Theme: %s', 'axle-staging-guard' ),
		);

		return isset( $types[ $type ] ) && '' !== $slug ? sprintf( $types[ $type ], $slug ) : __( 'Not found', 'axle-staging-guard' );
	}

	/**
	 * Says what the arguments of a task are, without keeping their values.
	 *
	 * Numbers and true/false are shown. Text is shown as its length, and
	 * arrays and objects as their size and class, so no personal data ends up in the list.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args The arguments.
	 * @return string
	 */
	public static function summarise_args( string $hook, array $args ): string {
		$parts = array();

		foreach ( $args as $arg ) {
			if ( is_bool( $arg ) ) {
				$parts[] = $arg ? 'true' : 'false';
			} elseif ( is_int( $arg ) || is_float( $arg ) ) {
				$parts[] = (string) $arg;
			} elseif ( is_string( $arg ) ) {
				/* translators: %d: number of characters in a text argument. */
				$parts[] = sprintf( __( 'text (%d characters)', 'axle-staging-guard' ), strlen( $arg ) );
			} elseif ( is_array( $arg ) ) {
				/* translators: %d: number of items in a list argument. */
				$parts[] = sprintf( __( 'list (%d items)', 'axle-staging-guard' ), count( $arg ) );
			} elseif ( is_object( $arg ) ) {
				$parts[] = get_class( $arg );
			} else {
				$parts[] = 'null';
			}
		}

		$summary = $parts ? implode( ', ', $parts ) : '';

		/**
		 * Filters the short description of a held task's arguments.
		 *
		 * @param string $summary Default: numbers as they are, text as its length.
		 * @param string $hook    Hook name.
		 * @param array  $args    The arguments. Do not store them.
		 */
		return substr( (string) apply_filters( 'axle_staging_guard_cron_args_summary', $summary, $hook, $args ), 0, 200 );
	}
}
