<?php
/**
 * Mask Registry admin screens.
 *
 * Provides the "Juliet Just Mask" menu, the mask registry list table and the
 * create/edit form, with nonce verification, capability checks and full
 * input sanitization / output escaping.
 *
 * @package JulietJustMask
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin controller.
 */
class Juliet_Admin {

	/**
	 * Top-level menu slug.
	 */
	const PAGE_SLUG = 'juliet-just-mask';

	/**
	 * Mask store.
	 *
	 * @var Juliet_Mask_Store
	 */
	protected $store;

	public function __construct( Juliet_Mask_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Attaches admin hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_head', array( $this, 'admin_menu_icon_css' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( JULIET_PLUGIN_FILE ), array( $this, 'action_links' ) );
		add_action( 'wp_ajax_juliet_export_masks', array( $this, 'ajax_export_masks' ) );
		add_action( 'wp_ajax_juliet_import_masks', array( $this, 'ajax_import_masks' ) );
		add_action( 'wp_ajax_juliet_toggle_status', array( $this, 'ajax_toggle_status' ) );
		add_action( 'wp_ajax_juliet_check_slug_conflict', array( $this, 'ajax_check_slug_conflict' ) );
	}

	/**
	 * Injects sidebar menu icon CSS to ensure the SVG icon is constrained on all admin screens.
	 */
	public function admin_menu_icon_css() {
		?>
		<style>
			#toplevel_page_juliet-just-mask .wp-menu-image {
				display: block !important;
				float: left !important;
				width: 36px !important;
			}
			#toplevel_page_juliet-just-mask .wp-menu-image img {
				display: inline-block !important;
				width: 20px !important;
				height: 20px !important;
				max-width: 20px !important;
				max-height: 20px !important;
				padding: 7px 0 0 0 !important;
				margin: 0 auto !important;
				border: none !important;
				box-shadow: none !important;
				background: none !important;
				opacity: 0.6;
				transition: opacity 0.15s ease;
			}
			.wp-has-current-submenu#toplevel_page_juliet-just-mask .wp-menu-image img,
			.current-menu-item#toplevel_page_juliet-just-mask .wp-menu-image img,
			#toplevel_page_juliet-just-mask:hover .wp-menu-image img {
				opacity: 1 !important;
			}
		</style>
		<?php
	}

	/**
	 * Adds Settings-style quick link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( array $links ) {
		$custom = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Manage Masks', 'juliet-just-mask' )
		);

		array_unshift( $links, $custom );

		return $links;
	}

	/**
	 * Registers the admin menu.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Juliet Masks', 'juliet-just-mask' ),
			__( 'Juliet Masks', 'juliet-just-mask' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			plugins_url( 'assets/images/icon.svg', JULIET_PLUGIN_FILE ),
			58
		);
	}

	/**
	 * Whether the current request is inside Juliet's admin pages.
	 *
	 * @return bool
	 */
	protected function is_plugin_screen() {
		return isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Loads screen assets.
	 */
	public function enqueue_assets() {
		$css_ver = file_exists( JULIET_PLUGIN_DIR . 'assets/css/juliet-admin.css' ) ? (string) filemtime( JULIET_PLUGIN_DIR . 'assets/css/juliet-admin.css' ) : JULIET_VERSION;
		$js_ver  = file_exists( JULIET_PLUGIN_DIR . 'assets/js/juliet-admin.js' ) ? (string) filemtime( JULIET_PLUGIN_DIR . 'assets/js/juliet-admin.js' ) : JULIET_VERSION;

		wp_enqueue_style(
			'juliet-admin',
			plugins_url( 'assets/css/juliet-admin.css', JULIET_PLUGIN_FILE ),
			array( 'dashicons' ),
			$css_ver
		);

		if ( ! $this->is_plugin_screen() ) {
			return;
		}

		wp_enqueue_script(
			'juliet-admin',
			plugins_url( 'assets/js/juliet-admin.js', JULIET_PLUGIN_FILE ),
			array(),
			$js_ver,
			true
		);

		wp_localize_script(
			'juliet-admin',
			'juliet_vars',
			array(
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'export_nonce' => wp_create_nonce( 'juliet_export_nonce' ),
				'import_nonce' => wp_create_nonce( 'juliet_import_nonce' ),
				'toggle_nonce' => wp_create_nonce( 'juliet_toggle_nonce' ),
				'check_nonce'  => wp_create_nonce( 'juliet_check_nonce' ),
			)
		);
	}

	/**
	 * Checks if a slug conflicts with an existing published Page, Post, Romeo Redirect, or Juliet Mask.
	 *
	 * @param string $slug            Slug to check.
	 * @param int    $exclude_mask_id Optional mask ID to exclude from conflict check.
	 * @return array
	 */
	public function check_slug_conflict( $slug, $exclude_mask_id = 0 ) {
		$slug = $this->store->sanitize_slug( $slug );

		if ( '' === $slug ) {
			return array(
				'has_conflict' => false,
				'type'         => '',
				'title'        => '',
				'url'          => '',
				'message'      => '',
			);
		}

		// 1. Check for another mask in Juliet
		$existing_mask = $this->store->get_by_slug( $slug );
		if ( $existing_mask && (int) $existing_mask->id !== (int) $exclude_mask_id ) {
			return array(
				'has_conflict' => true,
				'type'         => 'mask',
				'title'        => '/' . $existing_mask->mask_slug,
				'url'          => '',
				'message'      => sprintf(
					/* translators: %s: mask slug */
					__( 'Another mask already exists with the path /%s.', 'juliet-just-mask' ),
					esc_html( $slug )
				),
			);
		}

		// 2. Check for Page by path
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $page && in_array( $page->post_status, array( 'publish', 'private', 'draft', 'pending' ), true ) ) {
			return array(
				'has_conflict' => true,
				'type'         => 'page',
				'title'        => get_the_title( $page->ID ),
				'url'          => get_permalink( $page->ID ),
				'message'      => sprintf(
					/* translators: 1: post type, 2: post title */
					__( 'A published %1$s ("%2$s") already exists with this permalink.', 'juliet-just-mask' ),
					__( 'Page', 'juliet-just-mask' ),
					esc_html( get_the_title( $page->ID ) )
				),
			);
		}

		// 3. Check for Post or custom post type
		$posts = get_posts(
			array(
				'name'        => $slug,
				'post_type'   => 'any',
				'post_status' => array( 'publish', 'private' ),
				'numberposts' => 1,
			)
		);
		if ( ! empty( $posts ) ) {
			$post_obj   = $posts[0];
			$post_type  = get_post_type_object( $post_obj->post_type );
			$type_label = $post_type && ! empty( $post_type->labels->singular_name ) ? $post_type->labels->singular_name : __( 'Post', 'juliet-just-mask' );

			return array(
				'has_conflict' => true,
				'type'         => 'post',
				'title'        => get_the_title( $post_obj->ID ),
				'url'          => get_permalink( $post_obj->ID ),
				'message'      => sprintf(
					/* translators: 1: post type, 2: post title */
					__( 'A %1$s ("%2$s") already exists with this permalink.', 'juliet-just-mask' ),
					esc_html( $type_label ),
					esc_html( get_the_title( $post_obj->ID ) )
				),
			);
		}

		// 4. Check for Romeo Redirect
		$romeo_rules = get_option( 'romeo_redirect_manager_rules', array() );
		if ( is_array( $romeo_rules ) ) {
			foreach ( $romeo_rules as $rule ) {
				$r_slug = isset( $rule['slug'] ) ? trim( sanitize_title( $rule['slug'] ), '/' ) : '';
				if ( $r_slug === $slug ) {
					return array(
						'has_conflict' => true,
						'type'         => 'redirect',
						'title'        => '/' . $r_slug,
						'url'          => admin_url( 'admin.php?page=romeo-redirect-manager' ),
						'message'      => sprintf(
							/* translators: %s: redirect slug */
							__( 'A redirect already exists in Romeo Redirect Manager for /%s.', 'juliet-just-mask' ),
							esc_html( $slug )
						),
					);
				}
			}
		}

		return array(
			'has_conflict' => false,
			'type'         => '',
			'title'        => '',
			'url'          => '',
			'message'      => '',
		);
	}

	/**
	 * AJAX endpoint to check slug conflict live on typing.
	 */
	public function ajax_check_slug_conflict() {
		check_ajax_referer( 'juliet_check_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'juliet-just-mask' ) );
		}

		$slug    = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		$mask_id = isset( $_POST['mask_id'] ) ? absint( $_POST['mask_id'] ) : 0;

		$conflict = $this->check_slug_conflict( $slug, $mask_id );

		wp_send_json_success( $conflict );
	}

	/**
	 * AJAX handler for fast active/inactive status toggling.
	 */
	public function ajax_toggle_status() {
		check_ajax_referer( 'juliet_toggle_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'juliet-just-mask' ) );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id < 1 ) {
			wp_send_json_error( __( 'Invalid mask ID.', 'juliet-just-mask' ) );
		}

		$mask = $this->store->get( $id );
		if ( ! $mask ) {
			wp_send_json_error( __( 'Mask not found.', 'juliet-just-mask' ) );
		}

		$new_status = 'active' === $mask->status ? 'inactive' : 'active';
		$result     = $this->store->update( $id, array( 'status' => $new_status ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'id'     => $id,
				'status' => $new_status,
				'label'  => 'active' === $new_status ? __( 'Active Mask', 'juliet-just-mask' ) : __( 'Inactive Mask', 'juliet-just-mask' ),
				'title'  => 'active' === $new_status ? __( 'Deactivate mask', 'juliet-just-mask' ) : __( 'Activate mask', 'juliet-just-mask' ),
			)
		);
	}

	/**
	 * Central action dispatcher (save + row actions), run on admin_init.
	 */
	public function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) || ! $this->is_plugin_screen() ) {
			return;
		}

		if ( isset( $_POST['juliet_form_action'] ) && 'save_mask' === $_POST['juliet_form_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->process_save();
		}

		if ( isset( $_GET['juliet_mask_action'], $_GET['mask'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->process_row_action();
		}
	}

	/**
	 * Redirect helper.
	 *
	 * @param array $extra Query args merged onto the list page URL.
	 */
	protected function redirect( array $extra = array() ) {
		$url = add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $extra ),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handles mask create/update submissions (PRG pattern).
	 */
	protected function process_save() {
		check_admin_referer( 'juliet_save_mask', '_juliet_nonce' );

		$mask_id = isset( $_POST['mask_id'] ) ? absint( $_POST['mask_id'] ) : 0;
		$slug    = isset( $_POST['mask_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['mask_slug'] ) ) : '';
		$target  = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';
		$status  = isset( $_POST['mask_status'] ) ? sanitize_key( wp_unslash( $_POST['mask_status'] ) ) : 'active';
		$base_in = ! empty( $_POST['enable_base_inject'] );

		if ( $mask_id > 0 ) {
			$result = $this->store->update(
				$mask_id,
				array(
					'mask_slug'          => $slug,
					'target_url'         => $target,
					'status'             => $status,
					'enable_base_inject' => $base_in,
				)
			);
		} else {
			$result = $this->store->create(
				$slug,
				$target,
				array(
					'status'             => $status,
					'enable_base_inject' => $base_in,
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			$this->redirect( array( 'juliet_msg' => 'error', 'juliet_error' => rawurlencode( $result->get_error_message() ) ) );
		}

		$conflict_data = $this->check_slug_conflict( $slug, $mask_id );
		$warn_conflict = ( $conflict_data['has_conflict'] && 'mask' !== $conflict_data['type'] ) ? rawurlencode( $conflict_data['message'] ) : '';

		$this->redirect(
			array(
				'juliet_msg'           => $mask_id > 0 ? 'updated' : 'created',
				'juliet_warn_conflict' => $warn_conflict,
			)
		);
	}

	/**
	 * Handles per-row actions: activate, deactivate, delete.
	 */
	protected function process_row_action() {
		if ( ! isset( $_GET['juliet_mask_action'], $_GET['mask'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['juliet_mask_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id     = absint( $_GET['mask'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $action, array( 'activate', 'deactivate', 'delete' ), true ) || $id < 1 ) {
			$this->redirect( array( 'juliet_msg' => 'error', 'juliet_error' => rawurlencode( __( 'Unknown mask action.', 'juliet-just-mask' ) ) ) );
		}

		check_admin_referer( 'juliet_row_' . $action . '_' . $id );

		$result   = null;
		$is_error = false;
		$message  = '';

		switch ( $action ) {
			case 'activate':
			case 'deactivate':
				$result   = $this->store->update( $id, array( 'status' => 'activate' === $action ? 'active' : 'inactive' ) );
				$is_error = is_wp_error( $result );
				$message  = $is_error ? 'error' : ( 'activate' === $action ? 'activated' : 'deactivated' );
				break;

			case 'delete':
			default:
				$is_error = ! $this->store->delete( $id );
				$message  = $is_error ? 'error' : 'deleted';
				break;
		}

		if ( $is_error ) {
			$error_text = is_wp_error( $result ) ? $result->get_error_message() : __( 'The action could not be completed.', 'juliet-just-mask' );
			$this->redirect( array( 'juliet_msg' => 'error', 'juliet_error' => rawurlencode( $error_text ) ) );
		}

		$this->redirect( array( 'juliet_msg' => $message ) );
	}

	/**
	 * Renders an admin notice from a redirect message code.
	 */
	protected function render_notices() {
		$msg      = isset( $_GET['juliet_msg'] ) ? sanitize_key( wp_unslash( $_GET['juliet_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error    = isset( $_GET['juliet_error'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['juliet_error'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$conflict = isset( $_GET['juliet_warn_conflict'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['juliet_warn_conflict'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$success_map = array(
			'created'     => __( 'Mask created. Your stealth route is live.', 'juliet-just-mask' ),
			'updated'     => __( 'Mask updated.', 'juliet-just-mask' ),
			'deleted'     => __( 'Mask deleted.', 'juliet-just-mask' ),
			'activated'   => __( 'Mask activated.', 'juliet-just-mask' ),
			'deactivated' => __( 'Mask deactivated.', 'juliet-just-mask' ),
		);

		if ( '' === $msg && '' === $conflict ) {
			return;
		}

		echo '<div class="jjm-notices-container">';

		if ( 'error' === $msg ) {
			printf(
				'<div class="jjm-notice jjm-notice-error"><span class="dashicons dashicons-warning"></span><span class="jjm-notice-msg">%s</span><button type="button" class="jjm-notice-dismiss" aria-label="%s">&times;</button></div>',
				esc_html( '' !== $error ? $error : __( 'Something went wrong.', 'juliet-just-mask' ) ),
				esc_attr__( 'Dismiss', 'juliet-just-mask' )
			);
		} elseif ( isset( $success_map[ $msg ] ) ) {
			printf(
				'<div class="jjm-notice jjm-notice-success"><span class="dashicons dashicons-yes-alt"></span><span class="jjm-notice-msg">%s</span><button type="button" class="jjm-notice-dismiss" aria-label="%s">&times;</button></div>',
				esc_html( $success_map[ $msg ] ),
				esc_attr__( 'Dismiss', 'juliet-just-mask' )
			);
		}

		if ( '' !== $conflict ) {
			printf(
				'<div class="jjm-notice jjm-notice-warning"><span class="dashicons dashicons-warning"></span><span class="jjm-notice-msg"><strong>%s</strong> %s %s</span><button type="button" class="jjm-notice-dismiss" aria-label="%s">&times;</button></div>',
				esc_html__( 'Permalink Conflict:', 'juliet-just-mask' ),
				esc_html( $conflict ),
				esc_html__( 'The mask rewrite takes priority, hiding that item.', 'juliet-just-mask' ),
				esc_attr__( 'Dismiss', 'juliet-just-mask' )
			);
		}

		echo '</div>';
	}

	/**
	 * Resolves the mask being edited (if any).
	 *
	 * @return object|null
	 */
	protected function editing_mask() {
		if ( ! isset( $_GET['action'], $_GET['mask'] ) || 'edit' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return null;
		}

		return $this->store->get( absint( $_GET['mask'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Whether the create/edit form should start expanded.
	 *
	 * @param object|null $editing Mask being edited.
	 * @return bool
	 */
	protected function form_starts_open( $editing ) {
		return (bool) $editing || 0 === $this->store->count();
	}

	/**
	 * Resolves link URL, title, and target for the companion Romeo Redirect Manager.
	 *
	 * - If active: links directly to Romeo's dashboard in WordPress.
	 * - If installed but inactive: links to activate Romeo.
	 * - If not installed: opens the in-dashboard WordPress installer to install it.
	 *
	 * @return array
	 */
	protected function get_romeo_link_data() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$romeo_file = 'romeo-redirect-manager/romeo-redirect-manager.php';

		if ( is_plugin_active( $romeo_file ) ) {
			return array(
				'url'    => admin_url( 'admin.php?page=romeo-redirect-manager' ),
				'title'  => __( 'Open Romeo Redirect Manager', 'juliet-just-mask' ),
				'target' => '_self',
			);
		}

		$installed_plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
		if ( isset( $installed_plugins[ $romeo_file ] ) ) {
			return array(
				'url'    => wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $romeo_file ) ), 'activate-plugin_' . $romeo_file ),
				'title'  => __( 'Activate Romeo Redirect Manager', 'juliet-just-mask' ),
				'target' => '_self',
			);
		}

		if ( current_user_can( 'install_plugins' ) ) {
			return array(
				'url'    => admin_url( 'plugin-install.php?tab=plugin-information&plugin=romeo-redirect-manager' ),
				'title'  => __( 'Install Romeo Redirect Manager', 'juliet-just-mask' ),
				'target' => '_self',
			);
		}

		return array(
			'url'    => 'https://wordpress.org/plugins/romeo-redirect-manager/',
			'title'  => __( 'View Romeo Redirect Manager on WordPress.org', 'juliet-just-mask' ),
			'target' => '_blank',
		);
	}

	/**
	 * Renders the main admin screen.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'juliet-just-mask' ) );
		}

		$editing = $this->editing_mask();
		$masks   = $this->store->all();
		?>
		<div class="jjm-wrap">

			<div class="jjm-header">
				<div class="jjm-brand">
					<div class="jjm-logo-icon">
						<img src="<?php echo esc_url( plugins_url( 'assets/images/icon.svg', JULIET_PLUGIN_FILE ) ); ?>" alt="<?php esc_attr_e( 'Juliet Just Mask Logo', 'juliet-just-mask' ); ?>" style="width:48px;height:48px;display:block;">
					</div>
					<div>
						<h1 style="color:#247cf4; font-size:24px; font-weight:700; margin:0; line-height:1.2; display:flex; align-items:center; gap:10px;">
							<?php esc_html_e( 'Masking Juliet', 'juliet-just-mask' ); ?>
						</h1>
						<small>
							by <a href="https://harsh98trivedi.github.io/" target="_blank" rel="noopener noreferrer" style="color:#247cf4; text-decoration:none; font-weight:600;"><?php esc_html_e( 'Harsh Trivedi', 'juliet-just-mask' ); ?></a>
						</small>
					</div>
				</div>
				<div class="jjm-header-actions">
					<input type="file" id="jjm-import-file" accept=".json" style="display:none;" />
					<a href="https://wordpress.org/support/plugin/juliet-just-mask/reviews/#new-post" target="_blank" rel="noopener noreferrer" class="jjm-btn jjm-btn-ghost">
						<span class="dashicons dashicons-star-filled" style="color:#fbbf24; font-size:16px; width:16px; height:16px;"></span>
						<span><?php esc_html_e( 'Rate', 'juliet-just-mask' ); ?></span>
					</a>
					<a href="https://buymeacoffee.com/harshtrivedi" target="_blank" rel="noopener noreferrer" class="jjm-btn jjm-btn-ghost">
						<span class="dashicons dashicons-heart" style="color:#ff4d6d; font-size:18px; width:18px; height:18px;"></span>
						<span><?php esc_html_e( 'Donate', 'juliet-just-mask' ); ?></span>
					</a>
					<button type="button" id="jjm-btn-import" class="jjm-btn jjm-btn-ghost">
						<span class="dashicons dashicons-upload" style="font-size:18px; width:18px; height:18px;"></span>
						<span><?php esc_html_e( 'Import', 'juliet-just-mask' ); ?></span>
					</button>
					<button type="button" id="jjm-btn-export" class="jjm-btn jjm-btn-ghost">
						<span class="dashicons dashicons-download" style="font-size:18px; width:18px; height:18px;"></span>
						<span><?php esc_html_e( 'Export', 'juliet-just-mask' ); ?></span>
					</button>
					<button type="button" id="jjm-toggle-form" class="jjm-btn jjm-btn-outline jjm-toggle-form-btn">
						<span class="dashicons dashicons-plus-alt2"></span>
						<span class="jjm-btn-text"><?php echo $editing ? esc_html__( 'Editing Mask…', 'juliet-just-mask' ) : esc_html__( 'Create New Mask', 'juliet-just-mask' ); ?></span>
					</button>
				</div>
			</div>

			<?php $this->render_notices(); ?>

			<div class="jjm-card jjm-form-card" id="jjm-form-card" <?php echo $this->form_starts_open( $editing ) ? '' : 'hidden'; ?>>
				<h3 class="jjm-form-title"><?php echo $editing ? esc_html__( 'Edit Mask', 'juliet-just-mask' ) : esc_html__( 'Create New Mask', 'juliet-just-mask' ); ?></h3>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">
					<?php wp_nonce_field( 'juliet_save_mask', '_juliet_nonce' ); ?>
					<input type="hidden" name="juliet_form_action" value="save_mask" />
					<input type="hidden" name="mask_id" value="<?php echo esc_attr( $editing ? (int) $editing->id : 0 ); ?>" />

					<div class="jjm-form-row">
						<div class="jjm-form-group">
							<label class="jjm-label" for="jjm-slug"><?php esc_html_e( 'Local Path', 'juliet-just-mask' ); ?></label>
							<div class="jjm-input-group">
								<div class="jjm-prefix-box">/</div>
								<input type="text" id="jjm-slug" name="mask_slug" class="jjm-input jjm-slug-input"
									value="<?php echo esc_attr( $editing ? $editing->mask_slug : '' ); ?>"
									placeholder="marketing-hub" required />
							</div>
							<div class="jjm-conflict-alert hidden" id="jjm-slug-conflict-warning">
								<span class="dashicons dashicons-warning"></span>
								<span class="jjm-conflict-msg" id="jjm-conflict-text"></span>
								<a href="#" id="jjm-conflict-link" target="_blank" rel="noopener noreferrer" class="jjm-conflict-link hidden"><?php esc_html_e( 'View &rarr;', 'juliet-just-mask' ); ?></a>
							</div>
							<p class="jjm-help">
								<?php esc_html_e( 'Letters, numbers, dashes and underscores only.', 'juliet-just-mask' ); ?>
								<code class="jjm-home-root"><?php echo esc_html( home_url( '/' ) ); ?></code> / marketing-hub
							</p>
						</div>
						<div class="jjm-form-group">
							<label class="jjm-label" for="jjm-status"><?php esc_html_e( 'Status', 'juliet-just-mask' ); ?></label>
							<select id="jjm-status" name="mask_status" class="jjm-input">
								<option value="active" <?php selected( $editing ? $editing->status : 'active', 'active' ); ?>>
									<?php esc_html_e( 'Active — route is live', 'juliet-just-mask' ); ?>
								</option>
								<option value="inactive" <?php selected( $editing ? $editing->status : '', 'inactive' ); ?>>
									<?php esc_html_e( 'Inactive — routing paused', 'juliet-just-mask' ); ?>
								</option>
							</select>
						</div>
					</div>

					<div class="jjm-form-row jjm-form-row--single">
						<div class="jjm-form-group">
							<label class="jjm-label" for="jjm-target"><?php esc_html_e( 'Remote Target URL', 'juliet-just-mask' ); ?></label>
							<input type="url" id="jjm-target" name="target_url" class="jjm-input"
								value="<?php echo esc_attr( $editing ? $editing->target_url : '' ); ?>"
								placeholder="https://external-landing-page.com/promo-1" required />
							<p class="jjm-help"><?php esc_html_e( 'The remote application to render behind your domain. Must start with http:// or https://.', 'juliet-just-mask' ); ?></p>
						</div>
					</div>

					<div class="jjm-toggle-row">
						<div class="jjm-toggle-copy">
							<div class="jjm-toggle-title-wrap">
								<strong><?php esc_html_e( 'Inject <base> tag (recommended for Single Page Applications / JavaScript SPAs)', 'juliet-just-mask' ); ?></strong>
								<button type="button" class="jjm-info-btn" id="jjm-base-info-btn" title="<?php esc_attr_e( 'Learn more about <base> tag injection', 'juliet-just-mask' ); ?>" aria-label="<?php esc_attr_e( 'Info about base tag', 'juliet-just-mask' ); ?>">
									<span class="dashicons dashicons-info-outline"></span>
								</button>
							</div>
							<p><?php esc_html_e( 'Rewrites every reference into this mask and adds a base href pointing at your local path, so dynamically constructed relative URLs stay routed through the proxy instead of escaping to the remote origin.', 'juliet-just-mask' ); ?></p>

							<!-- Expandable Info Box for <base> tag -->
							<div class="jjm-info-box hidden" id="jjm-base-info-box">
								<div class="jjm-info-box-header">
									<span class="dashicons dashicons-lightbulb"></span>
									<strong><?php esc_html_e( 'Why & When to use <base> tag injection:', 'juliet-just-mask' ); ?></strong>
								</div>
								<div class="jjm-info-box-content">
									<p style="margin:0 0 10px 0;"><strong><?php esc_html_e( 'What it does:', 'juliet-just-mask' ); ?></strong> <?php esc_html_e( 'Injects a `<base href="https://yourdomain.com/your-slug/">` tag into the remote document\'s `<head>`. This instructs the visitor\'s browser to resolve all relative links, scripts, stylesheets, and fetch requests against your local masked path instead of the WordPress root.', 'juliet-just-mask' ); ?></p>
									<ul>
										<li>
											<strong><?php esc_html_e( '⚡ Modern JavaScript SPAs & Web Apps:', 'juliet-just-mask' ); ?></strong>
											<?php esc_html_e( 'Essential for React, Vue, Vite, Next.js, Angular, Nuxt, and Svelte applications that load dynamic code chunks (e.g. `./chunk-xyz.js`) or make relative AJAX requests (e.g. `./api/auth`). Without `<base>`, the browser requests them from your site\'s root (causing 404s). With `<base>`, they route cleanly through Juliet.', 'juliet-just-mask' ); ?>
										</li>
										<li>
											<strong><?php esc_html_e( '🔒 Prevents CORS & Origin Escapes:', 'juliet-just-mask' ); ?></strong>
											<?php esc_html_e( 'Ensures dynamic fetch() / XHR calls stay inside the reverse proxy rather than triggering browser cross-origin blocks or revealing the remote target server origin.', 'juliet-just-mask' ); ?>
										</li>
										<li>
											<strong><?php esc_html_e( '📄 When to leave OFF:', 'juliet-just-mask' ); ?></strong>
											<?php esc_html_e( 'For standard static HTML landing pages or marketing sites without client-side routing or dynamic chunk loading, you can safely leave this turned off.', 'juliet-just-mask' ); ?>
										</li>
									</ul>
								</div>
							</div>
						</div>
						<label class="jjm-switch" for="jjm-base-inject" title="<?php esc_attr_e( 'Toggle base tag injection', 'juliet-just-mask' ); ?>">
							<input type="checkbox" id="jjm-base-inject" name="enable_base_inject" value="1"
								<?php checked( $editing ? (int) $editing->enable_base_inject : 0, 1 ); ?> />
							<span class="jjm-switch-track"><span class="jjm-switch-thumb"></span></span>
						</label>
					</div>

					<div class="jjm-form-actions">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="jjm-btn jjm-btn-cancel">
							<?php esc_html_e( 'Cancel', 'juliet-just-mask' ); ?>
						</a>
						<button type="submit" class="jjm-btn jjm-btn-save"><?php echo $editing ? esc_html__( 'Update Mask', 'juliet-just-mask' ) : esc_html__( 'Save Mask', 'juliet-just-mask' ); ?></button>
					</div>
				</form>
			</div>

			<!-- Search Bar -->
			<div class="jjm-search-container">
				<span class="dashicons dashicons-search jjm-search-icon"></span>
				<input type="text" id="jjm-card-search" class="jjm-search-input" placeholder="<?php esc_attr_e( 'Type to search masks...', 'juliet-just-mask' ); ?>">
			</div>

			<!-- Filter Buttons -->
			<div class="jjm-filters">
				<button type="button" class="jjm-filter-btn active" data-filter="all"><?php esc_html_e( 'All', 'juliet-just-mask' ); ?></button>
				<button type="button" class="jjm-filter-btn" data-filter="active"><?php esc_html_e( 'Active', 'juliet-just-mask' ); ?></button>
				<button type="button" class="jjm-filter-btn" data-filter="inactive"><?php esc_html_e( 'Inactive', 'juliet-just-mask' ); ?></button>
				<button type="button" class="jjm-filter-btn" data-filter="base"><?php esc_html_e( '<base>', 'juliet-just-mask' ); ?></button>
			</div>

			<?php
			$total_masks    = count( $masks );
			$active_count   = 0;
			$inactive_count = 0;
			foreach ( $masks as $m ) {
				if ( 'active' === $m->status ) {
					$active_count++;
				} else {
					$inactive_count++;
				}
			}
			?>

			<!-- Toolbar Row: Summary Left | Sort & View Controls Right -->
			<div class="jjm-toolbar-row">
				<div id="jjm-report-summary" class="jjm-report-summary">
					<span><?php esc_html_e( 'Showing', 'juliet-just-mask' ); ?> <strong id="jjm-showing-count"><?php echo esc_html( $total_masks ); ?></strong> <?php esc_html_e( 'masks', 'juliet-just-mask' ); ?></span>
					<span class="jjm-summary-chips">
						<span class="jjm-summary-chip" style="--chip-color:#247cf4;"><?php esc_html_e( 'Active:', 'juliet-just-mask' ); ?> <strong id="jjm-active-count"><?php echo esc_html( $active_count ); ?></strong></span>
						<span class="jjm-summary-chip" style="--chip-color:#f59e0b;"><?php esc_html_e( 'Inactive:', 'juliet-just-mask' ); ?> <strong id="jjm-inactive-count"><?php echo esc_html( $inactive_count ); ?></strong></span>
					</span>
				</div>
				<div class="jjm-view-toggles">
					<div class="jjm-sort-wrapper">
						<select id="jjm-sort-select" class="jjm-input jjm-sort-select" style="width: 100%; height: 38px; margin: 0; font-size: 13px;">
							<option value="date-desc"><?php esc_html_e( 'Newest First', 'juliet-just-mask' ); ?></option>
							<option value="date-asc"><?php esc_html_e( 'Oldest First', 'juliet-just-mask' ); ?></option>
							<option value="name-asc"><?php esc_html_e( 'Slug (A-Z)', 'juliet-just-mask' ); ?></option>
							<option value="name-desc"><?php esc_html_e( 'Slug (Z-A)', 'juliet-just-mask' ); ?></option>
							<option value="status-active"><?php esc_html_e( 'Active First', 'juliet-just-mask' ); ?></option>
						</select>
					</div>
					<button type="button" class="jjm-view-btn active" data-view="card" title="<?php esc_attr_e( 'Card View', 'juliet-just-mask' ); ?>"><span class="dashicons dashicons-grid-view"></span></button>
					<button type="button" class="jjm-view-btn" data-view="list" title="<?php esc_attr_e( 'List View', 'juliet-just-mask' ); ?>"><span class="dashicons dashicons-list-view"></span></button>
				</div>
			</div>

			<div class="jjm-grid card-view" id="jjm-card-grid">
				<div id="jjm-no-results" class="jjm-empty-state <?php echo empty( $masks ) ? '' : 'hidden'; ?>">
					<span class="dashicons dashicons-search"></span>
					<h3><?php esc_html_e( 'No masks found', 'juliet-just-mask' ); ?></h3>
					<p><?php esc_html_e( 'Try a different search or create your first mask to get started.', 'juliet-just-mask' ); ?></p>
				</div>

				<?php if ( ! empty( $masks ) ) : ?>
					<?php foreach ( $masks as $mask ) : ?>
						<?php
						$is_active   = 'active' === $mask->status;
						$full_source = home_url( user_trailingslashit( $mask->mask_slug ) );
						$added_ts    = ! empty( $mask->created_at ) ? strtotime( $mask->created_at ) : 0;
						$edit_url    = admin_url(
							sprintf(
								'admin.php?page=%s&action=edit&mask=%d',
								rawurlencode( self::PAGE_SLUG ),
								(int) $mask->id
							)
						);
						?>
						<div class="jjm-card" id="card-<?php echo esc_attr( $mask->id ); ?>" data-slug="<?php echo esc_attr( strtolower( $mask->mask_slug ) ); ?>" data-target="<?php echo esc_attr( strtolower( $mask->target_url ) ); ?>" data-status="<?php echo esc_attr( $mask->status ); ?>" data-base="<?php echo (int) $mask->enable_base_inject ? '1' : '0'; ?>" data-added="<?php echo esc_attr( $added_ts ); ?>">
							<!-- Slug row: /slug text + inline copy button -->
							<div class="jjm-card-slug-wrap">
								<span class="jjm-card-slug" title="<?php echo esc_attr( $full_source ); ?>" data-copy="<?php echo esc_url( $full_source ); ?>">
									<span class="slash">/</span><span class="jjm-slug-text"><?php echo esc_html( $mask->mask_slug ); ?></span>
								</span>
								<button type="button" class="jjm-slug-copy jjm-copy-btn" data-copy="<?php echo esc_url( $full_source ); ?>" title="<?php esc_attr_e( 'Copy source URL', 'juliet-just-mask' ); ?>">
									<span class="dashicons dashicons-admin-page"></span>
								</button>
							</div>

							<!-- Info: target URL + copy -->
							<div class="jjm-card-info">
								<div class="jjm-card-info-inner">
									<span class="jjm-info-label"><?php esc_html_e( 'URL MASK TARGET', 'juliet-just-mask' ); ?></span>
									<span class="jjm-info-value" title="<?php echo esc_attr( $mask->target_url ); ?>" data-copy="<?php echo esc_url( $mask->target_url ); ?>"><?php echo esc_html( $mask->target_url ); ?></span>
								</div>
								<button type="button" class="jjm-inline-copy jjm-copy-btn" data-copy="<?php echo esc_url( $mask->target_url ); ?>" title="<?php esc_attr_e( 'Copy target URL', 'juliet-just-mask' ); ?>">
									<span class="dashicons dashicons-admin-page"></span>
								</button>
							</div>

							<!-- Footer: status + date -->
							<div class="jjm-card-footer">
								<div class="jjm-status-block">
									<div class="jjm-status-dot status-<?php echo esc_attr( $is_active ? 'active' : 'inactive' ); ?>"></div>
									<span class="jjm-status-label"><?php echo esc_html( $is_active ? __( 'Active Mask', 'juliet-just-mask' ) : __( 'Inactive Mask', 'juliet-just-mask' ) ); ?></span>
								</div>
								<?php if ( (int) $mask->enable_base_inject ) : ?>
									<button type="button" class="jjm-tag jjm-tag-base jjm-open-base-modal" title="<?php esc_attr_e( 'Click to learn about <base> tag injection and Single Page Applications (SPAs)', 'juliet-just-mask' ); ?>" aria-label="<?php esc_attr_e( 'Learn about base tag injection', 'juliet-just-mask' ); ?>">&lt;base&gt;</button>
								<?php endif; ?>
								<?php if ( $added_ts > 0 ) : ?>
									<div class="jjm-date-badge"><?php echo esc_html( mysql2date( 'M j, Y', $mask->created_at ) ); ?></div>
								<?php endif; ?>
							</div>

							<!-- Bottom bar: [toggle switch LEFT] ......... [open | edit | delete RIGHT] -->
							<div class="jjm-card-bottom">
								<a class="jjm-switch-toggle"
									title="<?php echo esc_attr( $is_active ? __( 'Deactivate mask', 'juliet-just-mask' ) : __( 'Activate mask', 'juliet-just-mask' ) ); ?>"
									href="<?php echo esc_url( $this->row_action_url( $is_active ? 'deactivate' : 'activate', (int) $mask->id ) ); ?>">
									<span class="jjm-switch <?php echo $is_active ? 'is-active' : ''; ?>">
										<span class="jjm-switch-track"><span class="jjm-switch-thumb"></span></span>
									</span>
								</a>
								<div class="jjm-card-actions-group">
									<a href="<?php echo esc_url( $full_source ); ?>" target="_blank" rel="noopener noreferrer" class="jjm-action-btn" title="<?php esc_attr_e( 'Open source URL', 'juliet-just-mask' ); ?>">
										<span class="dashicons dashicons-external"></span>
									</a>
									<a href="<?php echo esc_url( $edit_url ); ?>" class="jjm-action-btn" title="<?php esc_attr_e( 'Edit mask', 'juliet-just-mask' ); ?>">
										<span class="dashicons dashicons-edit"></span>
									</a>
									<a href="<?php echo esc_url( $this->row_action_url( 'delete', (int) $mask->id ) ); ?>" class="jjm-action-btn jjm-delete-action-btn jjm-confirm" title="<?php esc_attr_e( 'Delete mask', 'juliet-just-mask' ); ?>" data-confirm="<?php esc_attr_e( 'Delete this mask? The stealth route will stop working immediately.', 'juliet-just-mask' ); ?>">
										<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
											<path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
										</svg>
									</a>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>

		<!-- Import Modal -->
		<div id="jjm-import-modal" class="jjm-modal-overlay" hidden>
			<div class="jjm-modal-card">
				<div class="jjm-modal-header">
					<h3><?php esc_html_e( 'Import Masks', 'juliet-just-mask' ); ?></h3>
					<button type="button" id="jjm-btn-close-import" class="jjm-modal-close" aria-label="<?php esc_attr_e( 'Close', 'juliet-just-mask' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<p class="jjm-modal-desc"><?php esc_html_e( 'Upload a JSON backup file exported from Juliet Just Mask.', 'juliet-just-mask' ); ?></p>

				<!-- No conflict section -->
				<div id="jjm-import-no-conflict-section">
					<div class="jjm-modal-alert jjm-modal-alert--success">
						<span class="dashicons dashicons-yes-alt"></span>
						<span><?php esc_html_e( 'No conflicts detected. All masks will be added.', 'juliet-just-mask' ); ?></span>
					</div>
					<button type="button" id="jjm-btn-import-all" class="jjm-btn jjm-btn-save" style="width:100%;">
						<span class="dashicons dashicons-upload"></span>
						<span><?php esc_html_e( 'Import All', 'juliet-just-mask' ); ?></span>
					</button>
				</div>

				<!-- Conflict section -->
				<div id="jjm-import-conflict-section" hidden>
					<div class="jjm-modal-alert jjm-modal-alert--warning">
						<span class="dashicons dashicons-warning"></span>
						<div>
							<strong><span id="jjm-import-conflict-count">0</span> <?php esc_html_e( 'conflict(s) detected', 'juliet-just-mask' ); ?></strong>
							<p><?php esc_html_e( 'Some local paths in the file already exist in your registry.', 'juliet-just-mask' ); ?></p>
						</div>
					</div>

					<div class="jjm-modal-option">
						<label>
							<input type="checkbox" id="jjm-import-update" checked />
							<span><?php esc_html_e( 'Update existing masks with matching local path', 'juliet-just-mask' ); ?></span>
						</label>
					</div>

					<div class="jjm-modal-actions">
						<button type="button" id="jjm-btn-merge" class="jjm-btn jjm-btn-save">
							<?php esc_html_e( 'Merge', 'juliet-just-mask' ); ?>
						</button>
						<button type="button" id="jjm-btn-overwrite" class="jjm-btn jjm-btn-danger">
							<?php esc_html_e( 'Overwrite All', 'juliet-just-mask' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>

		<!-- Import Logs Modal -->
		<div id="jjm-import-logs-modal" class="jjm-modal-overlay" hidden>
			<div class="jjm-modal-card jjm-modal-card--wide">
				<div class="jjm-modal-header">
					<h3><?php esc_html_e( 'Import Results', 'juliet-just-mask' ); ?></h3>
					<button type="button" id="jjm-btn-close-logs" class="jjm-modal-close" aria-label="<?php esc_attr_e( 'Close', 'juliet-just-mask' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<div id="jjm-import-status-text" class="jjm-import-status-text"></div>
				<div id="jjm-import-logs-content" class="jjm-logs-box"></div>
				<div class="jjm-modal-footer">
					<button type="button" id="jjm-btn-done-reload" class="jjm-btn jjm-btn-save">
						<?php esc_html_e( 'Done & Refresh', 'juliet-just-mask' ); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- Base Tag & SPA Info Modal -->
		<div id="jjm-base-modal" class="jjm-modal-overlay" hidden>
			<div class="jjm-modal-card jjm-base-modal-card">
				<div class="jjm-modal-header">
					<div style="display:flex; align-items:center; gap:8px;">
						<span class="dashicons dashicons-lightbulb" style="color:#f59e0b; font-size:22px; width:22px; height:22px;"></span>
						<h3 style="margin:0; font-size:18px; font-weight:700;"><?php esc_html_e( 'Understanding <base> Tag Injection & SPAs', 'juliet-just-mask' ); ?></h3>
					</div>
					<button type="button" id="jjm-btn-close-base-modal" class="jjm-modal-close" aria-label="<?php esc_attr_e( 'Close', 'juliet-just-mask' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<div class="jjm-base-modal-body">
					<div class="jjm-modal-highlight-box">
						<strong><?php esc_html_e( 'What is an SPA?', 'juliet-just-mask' ); ?></strong>
						<p><?php esc_html_e( 'SPA stands for Single Page Application — modern web apps built with frameworks like React, Vue, Next.js, Nuxt, Angular, Vite, or Svelte that dynamically update the UI without full browser page reloads.', 'juliet-just-mask' ); ?></p>
					</div>

					<div class="jjm-modal-feature-section">
						<h4><?php esc_html_e( 'Why is <base> Tag Injection Essential for SPAs?', 'juliet-just-mask' ); ?></h4>
						<p><?php esc_html_e( 'When WordPress masks a remote web app under a local subpath (such as https://yoursite.com/my-tool), the visitor\'s browser naturally considers the domain root to be https://yoursite.com/.', 'juliet-just-mask' ); ?></p>
						<p><?php esc_html_e( 'When the SPA dynamically fetches code chunks (e.g. ./chunk-394.js) or sends relative API requests (e.g. ./api/auth), the browser without <base> mistakenly requests https://yoursite.com/chunk-394.js — resulting in a broken 404 error.', 'juliet-just-mask' ); ?></p>
					</div>

					<div class="jjm-modal-feature-section">
						<h4><?php esc_html_e( 'How Juliet Solves This:', 'juliet-just-mask' ); ?></h4>
						<p><?php esc_html_e( 'Enabling <base> tag injection adds <base href="https://yoursite.com/my-tool/"> to the document <head>. This commands the browser to resolve all relative scripts, assets, images, and API fetch calls against your local masked path, allowing Juliet to seamlessly proxy them upstream to the remote server.', 'juliet-just-mask' ); ?></p>
					</div>

					<div class="jjm-modal-feature-section">
						<h4><?php esc_html_e( 'When to use it:', 'juliet-just-mask' ); ?></h4>
						<ul class="jjm-modal-bullets">
							<li><strong><?php esc_html_e( 'Turn ON for:', 'juliet-just-mask' ); ?></strong> <?php esc_html_e( 'React, Vue, Vite, Next.js, Nuxt, Angular, Svelte apps, dashboards, or web apps with dynamic client-side routing.', 'juliet-just-mask' ); ?></li>
							<li><strong><?php esc_html_e( 'Leave OFF for:', 'juliet-just-mask' ); ?></strong> <?php esc_html_e( 'Standard static HTML landing pages, simple marketing sites, or blogs without client-side script chunks.', 'juliet-just-mask' ); ?></li>
						</ul>
					</div>
				</div>
				<div class="jjm-modal-footer">
					<button type="button" id="jjm-btn-got-it-base-modal" class="jjm-btn jjm-btn-save" style="min-width:110px;">
						<?php esc_html_e( 'Got It', 'juliet-just-mask' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Builds a nonce-protected row action URL.
	 *
	 * @param string $action Action key.
	 * @param int    $id     Mask ID.
	 * @return string
	 */
	protected function row_action_url( $action, $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'               => self::PAGE_SLUG,
					'juliet_mask_action' => $action,
					'mask'               => $id,
				),
				admin_url( 'admin.php' )
			),
			'juliet_row_' . $action . '_' . $id
		);
	}

	/**
	 * AJAX handler: exports all masks as JSON.
	 */
	public function ajax_export_masks() {
		check_ajax_referer( 'juliet_export_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'juliet-just-mask' ) );
		}

		$raw_masks = $this->store->all();
		$export    = array();

		foreach ( $raw_masks as $mask ) {
			$export[] = array(
				'mask_slug'          => $mask->mask_slug,
				'target_url'         => $mask->target_url,
				'enable_base_inject' => (int) $mask->enable_base_inject,
				'status'             => $mask->status,
			);
		}

		wp_send_json_success( $export );
	}

	/**
	 * AJAX handler: imports masks from JSON.
	 */
	public function ajax_import_masks() {
		check_ajax_referer( 'juliet_import_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'juliet-just-mask' ) );
		}

		$mode            = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'merge';
		$update_existing = ! empty( $_POST['update_existing'] ) && 'true' === $_POST['update_existing'];
		$json_data       = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Processed via json_decode immediately

		$imported_items = json_decode( $json_data, true );

		if ( ! is_array( $imported_items ) ) {
			wp_send_json_error( __( 'Invalid JSON data.', 'juliet-just-mask' ) );
		}

		$valid_items = array();
		foreach ( $imported_items as $item ) {
			if ( is_array( $item ) && ! empty( $item['mask_slug'] ) && ! empty( $item['target_url'] ) ) {
				$valid_items[] = array(
					'mask_slug'          => $this->store->sanitize_slug( $item['mask_slug'] ),
					'target_url'         => esc_url_raw( $item['target_url'] ),
					'enable_base_inject' => ! empty( $item['enable_base_inject'] ) ? 1 : 0,
					'status'             => isset( $item['status'] ) && in_array( $item['status'], Juliet_Mask_Store::STATUSES, true ) ? $item['status'] : 'active',
				);
			}
		}

		if ( empty( $valid_items ) ) {
			wp_send_json_error( __( 'No valid masks found in file.', 'juliet-just-mask' ) );
		}

		$success_count = 0;
		$failed_count  = 0;
		$logs          = array();

		if ( 'overwrite' === $mode ) {
			$this->store->delete_all();
			foreach ( $valid_items as $item ) {
				$created = $this->store->create(
					$item['mask_slug'],
					$item['target_url'],
					array(
						'status'             => $item['status'],
						'enable_base_inject' => $item['enable_base_inject'],
					)
				);

				if ( ! is_wp_error( $created ) ) {
					$success_count++;
					$logs[] = sprintf( 'Added mask: /%s', $item['mask_slug'] );
				} else {
					$failed_count++;
					$logs[] = sprintf( 'Failed to add /%s: %s', $item['mask_slug'], $created->get_error_message() );
				}
			}
		} else {
			foreach ( $valid_items as $item ) {
				$existing = $this->store->get_by_slug( $item['mask_slug'] );

				if ( $existing ) {
					if ( $update_existing ) {
						$updated = $this->store->update(
							$existing->id,
							array(
								'target_url'         => $item['target_url'],
								'status'             => $item['status'],
								'enable_base_inject' => $item['enable_base_inject'],
							)
						);

						if ( ! is_wp_error( $updated ) ) {
							$success_count++;
							$logs[] = sprintf( 'Updated existing mask: /%s', $item['mask_slug'] );
						} else {
							$failed_count++;
							$logs[] = sprintf( 'Failed to update /%s: %s', $item['mask_slug'], $updated->get_error_message() );
						}
					} else {
						$failed_count++;
						$logs[] = sprintf( 'Skipped duplicate mask: /%s', $item['mask_slug'] );
					}
				} else {
					$created = $this->store->create(
						$item['mask_slug'],
						$item['target_url'],
						array(
							'status'             => $item['status'],
							'enable_base_inject' => $item['enable_base_inject'],
						)
					);

					if ( ! is_wp_error( $created ) ) {
						$success_count++;
						$logs[] = sprintf( 'Added new mask: /%s', $item['mask_slug'] );
					} else {
						$failed_count++;
						$logs[] = sprintf( 'Failed to add /%s: %s', $item['mask_slug'], $created->get_error_message() );
					}
				}
			}
		}

		flush_rewrite_rules();
		delete_option( 'juliet_flush_required' );

		wp_send_json_success(
			array(
				'success_count' => $success_count,
				'failed_count'  => $failed_count,
				'logs'          => $logs,
			)
		);
	}
}
