<?php
/**
 * Keeps search engines away from non-production copies.
 *
 * @package AryanX\Axle\StagingGuard
 */

namespace AryanX\Axle\StagingGuard\Search;

use AryanX\Axle\StagingGuard\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Noindex header and meta tag, a blocking robots.txt, and no sitemaps.
 *
 * Never touches the "Discourage search engines" option, so a staging
 * database copied back to production cannot de-index the live site.
 */
final class Robots implements Service {

	/**
	 * Adds the search engine hooks.
	 */
	public function register(): void {
		add_filter( 'wp_robots', array( $this, 'robots_meta' ), PHP_INT_MAX );
		// phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.robots_txt -- On VIP the robots.txt output is cached; docs/REFERENCE.md explains how to flush it.
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), PHP_INT_MAX );
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		add_action( 'send_headers', array( $this, 'robots_header' ) );
		add_action( 'admin_notices', array( $this, 'reading_screen_notice' ) );
	}

	/**
	 * Adds noindex and nofollow to the robots meta tag.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public function robots_meta( array $robots ): array {
		unset( $robots['index'], $robots['follow'] );
		$robots['noindex']  = true;
		$robots['nofollow'] = true;

		return $robots;
	}

	/**
	 * Disallows everything in the virtual robots.txt.
	 *
	 * @return string
	 */
	public function robots_txt(): string {
		/**
		 * Filters the robots.txt a staging copy serves.
		 *
		 * @param string $output Default: disallow everything.
		 */
		return (string) apply_filters( 'axle_staging_guard_robots_txt', "# Axle Staging Guard: this is not the live site.\nUser-agent: *\nDisallow: /\n" );
	}

	/**
	 * Sends X-Robots-Tag so feeds and other non-HTML responses are covered too.
	 */
	public function robots_header(): void {
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}

	/**
	 * Explains on Settings > Reading why the search engine setting has no effect here.
	 */
	public function reading_screen_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'options-reading' !== $screen->id ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html__( 'Axle Staging Guard is hiding this copy of the site from search engines, whatever the setting below says. The setting still applies on the live site.', 'axle-staging-guard' )
		);
	}
}
