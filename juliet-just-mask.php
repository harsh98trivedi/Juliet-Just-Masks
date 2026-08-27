<?php
/**
 * Plugin Name:       Juliet Just Mask
 * Plugin URI:        https://wordpress.org/plugins/juliet-just-mask/
 * Description:       URL masking, mask manager, and stealth reverse proxy companion for Romeo Redirect Manager. Maps local paths to remote applications and renders them natively — no iframes, no Nginx rules.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Harsh Trivedi
 * Author URI:        https://harsh98trivedi.github.io/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       juliet-just-mask
 * Domain Path:       /languages
 *
 * @package           Juliet_Just_Mask
 */

defined( 'ABSPATH' ) || exit;

define( 'JULIET_VERSION', '1.0.0' );
define( 'JULIET_PLUGIN_FILE', __FILE__ );
define( 'JULIET_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JULIET_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JULIET_DB_VERSION', '1.0.0' );

require_once JULIET_PLUGIN_DIR . 'includes/class-juliet-mask-store.php';
require_once JULIET_PLUGIN_DIR . 'includes/class-juliet-html-patcher.php';
require_once JULIET_PLUGIN_DIR . 'includes/class-juliet-proxy.php';
require_once JULIET_PLUGIN_DIR . 'includes/class-juliet-router.php';
require_once JULIET_PLUGIN_DIR . 'includes/class-juliet-admin.php';
require_once JULIET_PLUGIN_DIR . 'includes/class-juliet-activator.php';
require_once JULIET_PLUGIN_DIR . 'includes/class-juliet-deactivator.php';

/**
 * Main plugin container. Boots every subsystem once on plugins_loaded.
 */
final class Juliet_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Juliet_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Mask registry data store.
	 *
	 * @var Juliet_Mask_Store
	 */
	public $store;

	/**
	 * Router (rewrite rules + template hijack).
	 *
	 * @var Juliet_Router
	 */
	public $router;

	/**
	 * Admin screens.
	 *
	 * @var Juliet_Admin
	 */
	public $admin;

	/**
	 * Boots the plugin.
	 *
	 * @return Juliet_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Wires subsystems and hooks.
	 */
	private function boot() {
		$this->store  = new Juliet_Mask_Store();
		$this->router = new Juliet_Router( $this->store );
		$this->admin  = new Juliet_Admin( $this->store );

		if ( function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
			wp_cache_add_non_persistent_groups( array( 'juliet' ) );
		}

		$this->router->register_hooks();
		$this->admin->register_hooks();
	}
}

/**
 * Access the shared plugin instance.
 *
 * @return Juliet_Plugin
 */
function juliet() {
	return Juliet_Plugin::instance();
}

add_action( 'plugins_loaded', 'juliet', 5 );

register_activation_hook( __FILE__, array( 'Juliet_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Juliet_Deactivator', 'deactivate' ) );
