<?php
/**
 * Plugin Name:       Axle Staging Guard
 * Plugin URI:        https://axlewp.com/plugins/axle-staging-guard/
 * Description:       Keeps staging and local copies of a site from emailing real people or getting indexed by search engines, and colours the admin bar so nobody mistakes them for the live site.
 * Version:           2.1.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Aryan Jasala
 * Author URI:        https://axlewp.com/about/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       axle-staging-guard
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard;

defined( 'ABSPATH' ) || exit;

const VERSION     = '2.1.0';
const PLUGIN_FILE = __FILE__;

// PSR-4: AryanX\Axle\StagingGuard\Foo\Bar lives in src/Foo/Bar.php.
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
