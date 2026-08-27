<?php
/**
 * Uninstall routine.
 *
 * Drops the mask registry table and removes plugin options/transients on
 * every site of the network.
 *
 * @package JulietJustMask
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes all plugin data for the current site.
 */
function juliet_uninstall_single_site() {
	global $wpdb;

	$table = $wpdb->prefix . 'juliet_masks';

	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	delete_option( 'juliet_db_version' );
	delete_option( 'juliet_flush_required' );

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			'\_transient\_juliet\_%',
			'\_transient\_timeout\_juliet\_%'
		)
	);

	if ( function_exists( 'wp_cache_flush_group' ) ) {
		@wp_cache_flush_group( 'juliet' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	} else {
		wp_cache_delete( 'active_masks', 'juliet' );
	}
}

if ( is_multisite() ) {
	$juliet_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 1000,
		)
	);

	foreach ( $juliet_site_ids as $juliet_site_id ) {
		switch_to_blog( $juliet_site_id );
		juliet_uninstall_single_site();
		restore_current_blog();
	}
} else {
	juliet_uninstall_single_site();
}
