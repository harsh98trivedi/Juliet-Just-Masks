<?php
/**
 * Deactivation routine.
 *
 * @package JulietJustMask
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deactivator.
 */
class Juliet_Deactivator {

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		flush_rewrite_rules();
	}
}
