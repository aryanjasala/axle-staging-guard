<?php
/**
 * Removes Axle Staging Guard's settings (including the gate password hash and share links), email, request and held-task lists, schedule and temporary data, on every site.
 *
 * @package StagingGuard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Axle Staging Guard data from the current site.
 */
function axle_staging_guard_uninstall_site(): void {
	wp_clear_scheduled_hook( 'axle_staging_guard_purge_log' );
	delete_option( 'axle_staging_guard_settings' );
	delete_option( 'axle_staging_guard_mail_log' );
	delete_transient( 'axle_sg_held_tick' );

	// Counters for wrong gate passwords and the short-lived "Undo" and "test a URL" states.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall only: transients with a variable name cannot be listed another way.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_axle_sg_' ) . '%', $wpdb->esc_like( '_transient_timeout_axle_sg_' ) . '%' ) );

	do {
		$axle_staging_guard_entries = get_posts(
			array(
				'post_type'   => array( 'axle_sg_mail', 'axle_sg_http', 'axle_sg_held' ),
				'post_status' => 'any',
				'numberposts' => 100,
				'fields'      => 'ids',
			)
		);

		foreach ( $axle_staging_guard_entries as $axle_staging_guard_entry ) {
			wp_delete_post( $axle_staging_guard_entry, true );
		}
	} while ( $axle_staging_guard_entries );
}

if ( is_multisite() ) {
	$axle_staging_guard_offset = 0;
	do {
		$axle_staging_guard_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $axle_staging_guard_offset,
			)
		);

		foreach ( $axle_staging_guard_sites as $axle_staging_guard_site ) {
			switch_to_blog( $axle_staging_guard_site );
			axle_staging_guard_uninstall_site();
			restore_current_blog();
		}

		$axle_staging_guard_offset += 100;
	} while ( $axle_staging_guard_sites );
} else {
	axle_staging_guard_uninstall_site();
}

// The notice dismissals are user meta, which is shared across a network.
delete_metadata( 'user', 0, 'axle_staging_guard_lock_notice_dismissed', '', true );
delete_metadata( 'user', 0, 'axle_staging_guard_steps_dismissed', '', true );
