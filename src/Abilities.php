<?php
/**
 * Abilities: environment checks for AI agents and other Abilities API clients.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard;

use AryanX\Axle\StagingGuard\Admin\SettingsPage;
use AryanX\Axle\StagingGuard\Cron\Hold;
use AryanX\Axle\StagingGuard\Gate\Gate;
use AryanX\Axle\StagingGuard\Http\Guard as HttpGuard;
use AryanX\Axle\StagingGuard\Mail\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Axle Staging Guard's abilities on WordPress 6.9 and later.
 *
 * The most useful one for an agent is get-environment: "is this the live site,
 * and what happens to email here?", asked before doing anything risky.
 */
final class Abilities implements Service {

	public const CATEGORY = 'axle-staging-guard';

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
	 * Email handling.
	 *
	 * @var Guard
	 */
	private Guard $mail;

	/**
	 * Outgoing request handling.
	 *
	 * @var HttpGuard
	 */
	private HttpGuard $http;

	/**
	 * The password gate.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Holds scheduled tasks.
	 *
	 * @var Hold
	 */
	private Hold $hold;

	/**
	 * Sets up the abilities.
	 *
	 * @param Settings    $settings    Saved settings.
	 * @param Environment $environment Environment detection.
	 * @param Guard       $mail        Email handling.
	 * @param HttpGuard   $http        Outgoing request handling.
	 * @param Gate        $gate        The password gate.
	 * @param Hold        $hold        Holds scheduled tasks.
	 */
	public function __construct( Settings $settings, Environment $environment, Guard $mail, HttpGuard $http, Gate $gate, Hold $hold ) {
		$this->settings    = $settings;
		$this->environment = $environment;
		$this->mail        = $mail;
		$this->http        = $http;
		$this->gate        = $gate;
		$this->hold        = $hold;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the Axle Staging Guard category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Axle Staging Guard', 'axle-staging-guard' ),
				'description' => __( 'Which environment this copy of the site is, and what it does with email, outgoing requests to other services, search engines, visitors without a password and scheduled tasks.', 'axle-staging-guard' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$can_manage = static function (): bool {
			return current_user_can( SettingsPage::capability() );
		};

		$this->ability(
			'get-environment',
			__( 'Get environment', 'axle-staging-guard' ),
			__( 'Whether this copy of the site is production, staging, development or local, how that was decided, and what happens to email, outgoing API calls and search engines here. Check this before sending email, calling an outside service, importing data or changing settings.', 'axle-staging-guard' ),
			array(),
			array(
				'type'       => 'object',
				'properties' => array(
					'environment'  => array(
						'type' => 'string',
						'enum' => array( 'production', 'staging', 'development', 'local' ),
					),
					'label'        => array( 'type' => 'string' ),
					'is_live_site' => array( 'type' => 'boolean' ),
					'source'       => array(
						'type'        => 'string',
						'description' => 'constant, hosts, lock, default or filter.',
					),
					'explanation'  => array( 'type' => 'string' ),
					'email'        => array(
						'type'        => 'string',
						'description' => 'block, redirect or allow. Always allow on the live site.',
					),
					'email_detail' => array( 'type' => 'string' ),
					'http'         => array(
						'type'        => 'string',
						'description' => 'allow, log or block: what happens to outgoing requests made through the WordPress HTTP API. Always allow on the live site.',
					),
					'noindex'      => array( 'type' => 'boolean' ),
				),
			),
			array( $this, 'get_environment' ),
			static function (): bool {
				return current_user_can( 'edit_posts' );
			},
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'get-mail-log',
			__( 'List stopped email', 'axle-staging-guard' ),
			__( 'Email this copy blocked or redirected, newest first: recipients, subject and what happened. Bodies only when the site keeps them.', 'axle-staging-guard' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'time'      => array( 'type' => 'string' ),
						'to'        => array( 'type' => 'string' ),
						'subject'   => array( 'type' => 'string' ),
						'action'    => array(
							'type' => 'string',
							'enum' => array( 'blocked', 'redirected', 'partly_blocked' ),
						),
						'delivered' => array( 'type' => 'string' ),
						'body'      => array( 'type' => 'string' ),
					),
				),
			),
			array( $this, 'get_mail_log' ),
			$can_manage,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'get-http-log',
			__( 'List stopped outgoing requests', 'axle-staging-guard' ),
			__( 'Outgoing requests to other services this copy blocked or recorded, most recent first: method, host, path without query string, the plugin or theme that made the call and how many times it happened.', 'axle-staging-guard' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'time'       => array( 'type' => 'string' ),
						'first_seen' => array( 'type' => 'string' ),
						'method'     => array( 'type' => 'string' ),
						'host'       => array( 'type' => 'string' ),
						'path'       => array( 'type' => 'string' ),
						'action'     => array(
							'type' => 'string',
							'enum' => array( 'blocked', 'logged' ),
						),
						'caller'     => array(
							'type'        => 'string',
							'description' => 'plugin:slug, mu-plugin:slug or theme:slug. Empty when it could not be found.',
						),
						'count'      => array( 'type' => 'integer' ),
					),
				),
			),
			array( $this, 'get_http_log' ),
			$can_manage,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'test-url',
			__( 'Test a URL', 'axle-staging-guard' ),
			__( 'Says what would happen to an outgoing request to a URL on this copy: allowed, logged or blocked, and why. Nothing is sent and nothing is recorded. Use it before running something that calls an outside service.', 'axle-staging-guard' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'url'    => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'method' => array(
						'type'    => 'string',
						'default' => 'GET',
					),
				),
				'required'             => array( 'url' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'result'      => array(
						'type' => 'string',
						'enum' => array( 'allowed', 'logged', 'blocked' ),
					),
					'reason'      => array( 'type' => 'string' ),
					'host'        => array( 'type' => 'string' ),
					'explanation' => array( 'type' => 'string' ),
				),
			),
			array( $this, 'test_url' ),
			$can_manage,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'send-test-email',
			__( 'Send a test email', 'axle-staging-guard' ),
			__( 'Sends a short test email and reports what Axle Staging Guard did with it: blocked, redirected, partly blocked, sent or failed. On the live site it really sends.', 'axle-staging-guard' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'to' => array(
						'type'   => 'string',
						'format' => 'email',
					),
				),
				'required'             => array( 'to' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'result' => array(
						'type' => 'string',
						'enum' => array( 'blocked', 'redirected', 'partly_blocked', 'sent', 'failed' ),
					),
				),
			),
			array( $this, 'send_test_email' ),
			$can_manage,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			)
		);

		$this->register_gate_abilities( $can_manage );
		$this->register_task_abilities( $can_manage );

		$this->ability(
			'clear-log',
			__( 'Clear a list', 'axle-staging-guard' ),
			__( 'Removes every entry from the stopped email list, the stopped request list, the held task list, or all three. The email, requests and tasks themselves are not affected. It cannot be undone.', 'axle-staging-guard' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'list' => array(
						'type' => 'string',
						'enum' => array( 'mail', 'http', 'held', 'all' ),
					),
				),
				'required'             => array( 'list' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'removed' => array( 'type' => 'integer' ),
				),
			),
			array( $this, 'clear_log' ),
			$can_manage,
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			)
		);
	}

	/**
	 * Registers the password gate abilities.
	 *
	 * @param callable $can_manage Permission callback.
	 */
	private function register_gate_abilities( callable $can_manage ): void {
		$link = array(
			'type'       => 'object',
			'properties' => array(
				'id'        => array( 'type' => 'string' ),
				'name'      => array( 'type' => 'string' ),
				'status'    => array(
					'type' => 'string',
					'enum' => array( 'works', 'expired' ),
				),
				'expires'   => array( 'type' => 'string' ),
				'last_used' => array(
					'type'        => 'string',
					'description' => 'Empty when the link was never used.',
				),
				'visits'    => array( 'type' => 'integer' ),
			),
		);

		$this->ability(
			'get-gate',
			__( 'Get the password gate', 'axle-staging-guard' ),
			__( 'Whether the password gate is on for this copy of the site, whether a password is set, and how many share links work. The gate never runs on the live site.', 'axle-staging-guard' ),
			array(),
			array(
				'type'       => 'object',
				'properties' => array(
					'active'       => array( 'type' => 'boolean' ),
					'switched_on'  => array( 'type' => 'boolean' ),
					'password_set' => array( 'type' => 'boolean' ),
					'allowed_ips'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'open_paths'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'active_links' => array( 'type' => 'integer' ),
				),
			),
			array( $this, 'get_gate' ),
			$can_manage,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'set-gate',
			__( 'Turn the password gate on or off', 'axle-staging-guard' ),
			__( 'Turns the password gate on or off, and can set or change the shared password. Turning it on needs a password. On the live site it is saved but does nothing.', 'axle-staging-guard' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'enabled'  => array( 'type' => 'boolean' ),
					'password' => array(
						'type'        => 'string',
						'description' => 'Optional. Sets or changes the shared password. Stored hashed.',
					),
				),
				'required'             => array( 'enabled' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'active' => array( 'type' => 'boolean' ),
				),
			),
			array( $this, 'set_gate' ),
			$can_manage,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);

		$this->ability(
			'list-gate-links',
			__( 'List share links', 'axle-staging-guard' ),
			__( 'The share links for the password gate, newest first: name, whether it works, when it expires and when it was last used. The address itself is only returned when a link is made.', 'axle-staging-guard' ),
			array(),
			array(
				'type'  => 'array',
				'items' => $link,
			),
			array( $this, 'list_gate_links' ),
			$can_manage,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'create-gate-link',
			__( 'Create a share link', 'axle-staging-guard' ),
			__( 'Makes a named share link that lets one person past the password gate for 1 to 30 days. Returns the address. Anyone who has it can see the site until it expires or is revoked.', 'axle-staging-guard' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'name' => array(
						'type'        => 'string',
						'description' => 'Who it is for, such as "For Priya at the client".',
					),
					'days' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 30,
						'default' => 7,
					),
				),
				'required'             => array( 'name' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'id'      => array( 'type' => 'string' ),
					'url'     => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'expires' => array( 'type' => 'string' ),
				),
			),
			array( $this, 'create_gate_link' ),
			$can_manage,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			)
		);

		$this->ability(
			'revoke-gate-link',
			__( 'Revoke a share link', 'axle-staging-guard' ),
			__( 'Revokes a share link. The person who had it is out on their next page view. It cannot be undone, but a new link can be made.', 'axle-staging-guard' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array( 'type' => 'string' ),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'revoked' => array( 'type' => 'boolean' ),
				),
			),
			array( $this, 'revoke_gate_link' ),
			$can_manage,
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			)
		);
	}

	/**
	 * Registers the scheduled task abilities.
	 *
	 * @param callable $can_manage Permission callback.
	 */
	private function register_task_abilities( callable $can_manage ): void {
		$this->ability(
			'list-held-tasks',
			__( 'List held scheduled tasks', 'axle-staging-guard' ),
			__( 'The scheduled tasks this copy holds instead of running, and the runs that were held recently, newest first. Only the task name, the owner and a summary of its arguments are kept.', 'axle-staging-guard' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'held' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'log'  => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'time'      => array( 'type' => 'string' ),
								'hook'      => array( 'type' => 'string' ),
								'owner'     => array( 'type' => 'string' ),
								'due'       => array( 'type' => 'string' ),
								'arguments' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			array( $this, 'list_held_tasks' ),
			$can_manage,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'hold-task',
			__( 'Hold or release a scheduled task', 'axle-staging-guard' ),
			__( 'Holds a scheduled task on this copy so it does not run, or releases it so it runs normally. WordPress clean-up, privacy and recovery tasks are protected and cannot be held.', 'axle-staging-guard' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'hook' => array( 'type' => 'string' ),
					'held' => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'hook', 'held' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'held' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
			array( $this, 'hold_task' ),
			$can_manage,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);
	}

	/**
	 * Ability: the gate's state.
	 *
	 * @return array
	 */
	public function get_gate(): array {
		return array(
			'active'       => $this->gate->is_active(),
			'switched_on'  => (bool) $this->settings->get( 'gate_enabled' ),
			'password_set' => $this->gate->has_password(),
			'allowed_ips'  => $this->gate->open_ips(),
			'open_paths'   => $this->gate->open_paths(),
			'active_links' => count(
				array_filter(
					$this->gate->links()->all(),
					function ( array $link ): bool {
						return $this->gate->links()->is_active( $link );
					}
				)
			),
		);
	}

	/**
	 * Ability: turn the gate on or off.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function set_gate( $input = null ) {
		$input = (array) $input;

		if ( isset( $input['password'] ) && '' !== (string) $input['password'] ) {
			$result = $this->gate->set_password( (string) $input['password'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( ! empty( $input['enabled'] ) ) {
			$result = $this->gate->enable();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} else {
			$this->gate->disable();
		}

		return array( 'active' => $this->gate->is_active() );
	}

	/**
	 * Ability: the share links.
	 *
	 * @return array[]
	 */
	public function list_gate_links(): array {
		$store = $this->gate->links();

		return array_map(
			static function ( array $link ) use ( $store ): array {
				return array(
					'id'        => $link['id'],
					'name'      => $link['name'],
					'status'    => $store->is_active( $link ) ? 'works' : 'expired',
					'expires'   => gmdate( 'c', $link['expires'] ),
					'last_used' => $link['last_used'] > 0 ? gmdate( 'c', $link['last_used'] ) : '',
					'visits'    => $link['uses'],
				);
			},
			$store->all()
		);
	}

	/**
	 * Ability: make a share link.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function create_gate_link( $input = null ) {
		$input = (array) $input;
		$link  = $this->gate->links()->create( (string) ( $input['name'] ?? '' ), (int) ( $input['days'] ?? 7 ), get_current_user_id() );

		if ( is_wp_error( $link ) ) {
			return $link;
		}

		return array(
			'id'      => $link['id'],
			'url'     => $this->gate->links()->url( $link ),
			'expires' => gmdate( 'c', $link['expires'] ),
		);
	}

	/**
	 * Ability: revoke a share link.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public function revoke_gate_link( $input = null ): array {
		return array( 'revoked' => $this->gate->links()->revoke( sanitize_key( (string) ( ( (array) $input )['id'] ?? '' ) ) ) );
	}

	/**
	 * Ability: held tasks.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public function list_held_tasks( $input = null ): array {
		$limit = max( 1, min( 100, (int) ( ( (array) $input )['limit'] ?? 20 ) ) );

		return array(
			'held' => $this->hold->tasks()->held(),
			'log'  => array_map(
				static function ( array $entry ): array {
					return array(
						'time'      => gmdate( 'c', $entry['time'] ),
						'hook'      => $entry['hook'],
						'owner'     => $entry['owner'],
						'due'       => $entry['due'] > 0 ? gmdate( 'c', $entry['due'] ) : '',
						'arguments' => $entry['args'],
					);
				},
				$this->hold->log()->all( $limit )
			),
		);
	}

	/**
	 * Ability: hold or release a task.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function hold_task( $input = null ) {
		$input = (array) $input;
		$hook  = sanitize_text_field( (string) ( $input['hook'] ?? '' ) );
		$held  = (array) $this->settings->get( 'cron_held' );

		if ( '' === $hook ) {
			return new \WP_Error( 'axle_staging_guard_invalid_hook', __( 'Give the name of a scheduled task.', 'axle-staging-guard' ), array( 'status' => 400 ) );
		}

		if ( ! empty( $input['held'] ) && $this->hold->tasks()->is_protected( $hook ) ) {
			return new \WP_Error( 'axle_staging_guard_protected_hook', __( 'That task is protected: WordPress needs it, so it cannot be held.', 'axle-staging-guard' ), array( 'status' => 400 ) );
		}

		$held = ! empty( $input['held'] ) ? array_merge( $held, array( $hook ) ) : array_diff( $held, array( $hook ) );

		return array( 'held' => $this->hold->save( $held, array( $hook ) ) );
	}

	/**
	 * Ability: clear a list.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function clear_log( $input = null ) {
		$list    = (string) ( ( (array) $input )['list'] ?? '' );
		$removed = 0;

		if ( ! in_array( $list, array( 'mail', 'http', 'held', 'all' ), true ) ) {
			return new \WP_Error( 'axle_staging_guard_invalid_list', __( 'Choose mail, http, held or all.', 'axle-staging-guard' ), array( 'status' => 400 ) );
		}

		if ( 'mail' === $list || 'all' === $list ) {
			$removed += $this->mail->log()->clear();
		}
		if ( 'http' === $list || 'all' === $list ) {
			$removed += $this->http->log()->clear();
		}
		if ( 'held' === $list || 'all' === $list ) {
			$removed += $this->hold->log()->clear();
		}

		return array( 'removed' => $removed );
	}

	/**
	 * Ability: the environment.
	 *
	 * @return array
	 */
	public function get_environment(): array {
		$guarded = $this->environment->is_guarded();
		$policy  = $this->mail->policy();

		return array(
			'environment'  => $this->environment->type(),
			'label'        => $this->environment->label(),
			'is_live_site' => ! $guarded,
			'source'       => $this->environment->source(),
			'explanation'  => $this->environment->explain(),
			'email'        => $guarded ? (string) $policy['mode'] : 'allow',
			// Where redirected email goes is for people who manage Axle Staging Guard only.
			'email_detail' => $guarded && current_user_can( SettingsPage::capability() ) ? $this->mail->describe() : '',
			'http'         => $guarded ? (string) $this->http->policy()['mode'] : 'allow',
			'noindex'      => $guarded && (bool) $this->settings->get( 'noindex' ),
		);
	}

	/**
	 * Ability: stopped email.
	 *
	 * @param array|null $input Ability input.
	 * @return array[]
	 */
	public function get_mail_log( $input = null ): array {
		$limit = max( 1, min( 100, (int) ( ( (array) $input )['limit'] ?? 20 ) ) );

		return array_map(
			static function ( array $entry ): array {
				return array(
					'time'      => gmdate( 'c', (int) $entry['time'] ),
					'to'        => (string) $entry['to'],
					'subject'   => (string) $entry['subject'],
					'action'    => (string) $entry['action'],
					'delivered' => (string) $entry['delivered'],
					'body'      => (string) $entry['body'],
				);
			},
			$this->mail->log()->all( $limit )
		);
	}

	/**
	 * Ability: stopped outgoing requests.
	 *
	 * @param array|null $input Ability input.
	 * @return array[]
	 */
	public function get_http_log( $input = null ): array {
		$limit = max( 1, min( 100, (int) ( ( (array) $input )['limit'] ?? 20 ) ) );

		return array_map(
			static function ( array $entry ): array {
				return array(
					'time'       => gmdate( 'c', (int) $entry['time'] ),
					'first_seen' => gmdate( 'c', (int) $entry['first'] ),
					'method'     => (string) $entry['method'],
					'host'       => (string) $entry['host'],
					'path'       => (string) $entry['path'],
					'action'     => (string) $entry['action'],
					'caller'     => (string) $entry['caller'],
					'count'      => (int) $entry['count'],
				);
			},
			$this->http->log()->all( $limit )
		);
	}

	/**
	 * Ability: what would happen to a URL.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function test_url( $input = null ) {
		$input = (array) $input;
		$url   = trim( (string) ( $input['url'] ?? '' ) );
		if ( ! Http\Rules::is_url( $url ) ) {
			return new \WP_Error( 'axle_staging_guard_invalid_url', __( 'That is not a valid http or https URL.', 'axle-staging-guard' ) );
		}

		$decision = $this->http->evaluate( $url, (string) ( $input['method'] ?? 'GET' ) );
		$results  = array(
			'allow' => 'allowed',
			'log'   => 'logged',
			'block' => 'blocked',
		);

		return array(
			'result'      => $results[ $decision['action'] ],
			'reason'      => $decision['reason'],
			'host'        => $decision['host'],
			'explanation' => $this->http->explain( $decision ),
		);
	}

	/**
	 * Ability: sends a test email.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function send_test_email( $input = null ) {
		$to = sanitize_email( (string) ( ( (array) $input )['to'] ?? '' ) );
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'axle_staging_guard_invalid_email', __( 'That is not a valid email address.', 'axle-staging-guard' ) );
		}

		return array( 'result' => $this->mail->send_test( $to ) );
	}

	/**
	 * Registers one ability in the Axle Staging Guard category.
	 *
	 * @param string   $name        Name without the namespace.
	 * @param string   $label       Label.
	 * @param string   $description What it does, for people and agents.
	 * @param array    $input       Input schema, or empty for none.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Execute callback.
	 * @param callable $permission  Permission callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, callable $permission, array $annotations ): void {
		$args = array(
			'label'               => $label,
			'description'         => $description,
			'category'            => self::CATEGORY,
			'output_schema'       => $output,
			'execute_callback'    => $execute,
			'permission_callback' => $permission,
			'meta'                => array(
				'annotations'  => $annotations,
				'show_in_rest' => true,
				// Clients such as the MCP adapter only list abilities marked public:
				// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
				'public'       => true,
				'mcp'          => array( 'public' => true ),
			),
		);
		if ( $input ) {
			$args['input_schema'] = $input;
		}

		wp_register_ability( self::CATEGORY . '/' . $name, $args );
	}
}
