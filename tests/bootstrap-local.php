<?php
/**
 * Plugin-specific unit test stand-ins. The shared bootstrap loads this file; the template never overwrites it.
 *
 * @package AryanX\Axle\StagingGuard
 */

// phpcs:ignoreFile

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * Enough of WP_User for the gate's permission filter.
	 */
	class WP_User {
		/** @var int */
		public $ID = 0;

		public function __construct( int $id = 0 ) {
			$this->ID = $id;
		}
	}
}

if ( ! function_exists( '_get_cron_array' ) ) {
	/**
	 * The schedule, taken from a global the tests fill.
	 */
	function _get_cron_array() {
		return $GLOBALS['axle_sg_cron'] ?? array();
	}
}
