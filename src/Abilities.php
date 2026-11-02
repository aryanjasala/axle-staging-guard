<?php
/**
 * Abilities: environment checks for AI agents and other Abilities API clients.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard;

use AryanX\Axle\StagingGuard\Admin\SettingsPage;
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
	 * Sets up the abilities.
	 *
	 * @param Settings    $settings    Saved settings.
	 * @param Environment $environment Environment detection.
	 * @param Guard       $mail        Email handling.
	 */
	public function __construct( Settings $settings, Environment $environment, Guard $mail ) {
		$this->settings    = $settings;
		$this->environment = $environment;
		$this->mail        = $mail;
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
				'description' => __( 'Which environment this copy of the site is, and what it does with email and search engines.', 'axle-staging-guard' ),
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
			__( 'Whether this copy of the site is production, staging, development or local, how that was decided, and what happens to email and search engines here. Check this before sending email, importing data or changing settings.', 'axle-staging-guard' ),
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
