<?php
/**
 * Activation routine.
 *
 * Installs the mask registry schema via dbDelta and primes rewrite rules.
 *
 * @package JulietJustMask
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activator.
 */
class Juliet_Activator {

	/**
	 * Runs on plugin activation.
	 */
	public static function activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		self::install_schema();

		add_option( 'juliet_db_version', JULIET_DB_VERSION, '', false );

		update_option( 'juliet_flush_required', 1, false );
		flush_rewrite_rules();
	}

	/**
	 * Creates or updates wp_juliet_masks.
	 */
	public static function install_schema() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'juliet_masks';
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			mask_slug varchar(255) NOT NULL,
			target_url text NOT NULL,
			enable_base_inject tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY mask_slug (mask_slug)
		) {$collate};";

		dbDelta( $sql );
	}
}
