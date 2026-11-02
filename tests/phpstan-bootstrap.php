<?php
/**
 * Constants PHPStan needs to know about. Each one is optional and set in
 * wp-config.php, so the code only reads it behind defined().
 *
 * @package AryanX\Axle\StagingGuard
 */

defined( 'AXLE_STAGING_GUARD_MAIL_MODE' ) || define( 'AXLE_STAGING_GUARD_MAIL_MODE', 'block' );
defined( 'AXLE_STAGING_GUARD_REDIRECT_TO' ) || define( 'AXLE_STAGING_GUARD_REDIRECT_TO', '' );
defined( 'AXLE_STAGING_GUARD_ALLOWLIST' ) || define( 'AXLE_STAGING_GUARD_ALLOWLIST', '' );
defined( 'AXLE_STAGING_GUARD_KEEP_BODIES' ) || define( 'AXLE_STAGING_GUARD_KEEP_BODIES', false );
defined( 'AXLE_STAGING_GUARD_NOINDEX' ) || define( 'AXLE_STAGING_GUARD_NOINDEX', true );
defined( 'AXLE_STAGING_GUARD_LOG_DAYS' ) || define( 'AXLE_STAGING_GUARD_LOG_DAYS', 7 );
defined( 'AXLE_STAGING_GUARD_PRODUCTION_HOSTS' ) || define( 'AXLE_STAGING_GUARD_PRODUCTION_HOSTS', '' );

// Core constant set in wp-settings.php, which the WordPress stubs do not declare.
defined( 'WPINC' ) || define( 'WPINC', 'wp-includes' );
