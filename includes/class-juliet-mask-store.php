<?php
/**
 * Mask registry data store.
 *
 * All persistence for the `wp_juliet_masks` micro-table lives here.
 *
 * @package JulietJustMask
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRUD + validation for stealth-route masks.
 */
class Juliet_Mask_Store {

	/**
	 * Fully prefixed table name.
	 *
	 * @var string
	 */
	protected $table;

	/**
	 * Allowed status values.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'active', 'inactive' );

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'juliet_masks';
	}

	/**
	 * Table name accessor.
	 *
	 * @return string
	 */
	public function table() {
		return $this->table;
	}

	/**
	 * Whether the schema has been installed.
	 *
	 * @return bool
	 */
	public function table_exists() {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) ) === $this->table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Normalizes a local path slug to a safe, single rewrite segment.
	 *
	 * @param string $slug Raw slug.
	 * @return string
	 */
	public function sanitize_slug( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		$slug = str_replace( array( ' ', '/' ), '-', $slug );
		$slug = preg_replace( '/[^a-z0-9_\-]/', '', $slug );
		$slug = preg_replace( '/-{2,}/', '-', $slug );
		$slug = trim( $slug, '-_' );

		return substr( $slug, 0, 100 );
	}

	/**
	 * Reserved paths masks must never hijack.
	 *
	 * @return string[]
	 */
	public function reserved_slugs() {
		$reserved = array(
			'wp-admin',
			'wp-login.php',
			'wp-json',
			'wp-content',
			'wp-includes',
			'wp-cron.php',
			'xmlrpc.php',
			'robots.txt',
			'favicon.ico',
			'sitemap',
			'sitemap.xml',
			'sitemap_index.xml',
			'feed',
			'embed',
			'search',
			'author',
			'page',
			'comments',
		);

		/**
		 * Filters slugs that may not be registered as masks.
		 *
		 * @param string[] $reserved Reserved path segments.
		 */
		return apply_filters( 'juliet_reserved_slugs', $reserved );
	}

	/**
	 * Whether a slug collides with a reserved WordPress path.
	 *
	 * @param string $slug Sanitized slug.
	 * @return bool
	 */
	public function is_reserved_slug( $slug ) {
		return in_array( $slug, $this->reserved_slugs(), true );
	}

	/**
	 * Validates and normalizes a remote target URL.
	 *
	 * @param string $url Raw target URL.
	 * @return string|WP_Error Absolute URL or error.
	 */
	public function validate_target_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return new WP_Error( 'juliet_empty_target', __( 'A Remote Target URL is required.', 'juliet-just-mask' ) );
		}

		$url = esc_url_raw( $url, array( 'http', 'https' ) );

		if ( ! $url ) {
			return new WP_Error( 'juliet_invalid_target', __( 'The Remote Target URL is invalid. Only http:// and https:// targets are allowed.', 'juliet-just-mask' ) );
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'juliet_invalid_protocol', __( 'Invalid target protocol. The URL must start with http:// or https://.', 'juliet-just-mask' ) );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'juliet_credentials_in_url', __( 'Target URLs containing embedded credentials are not allowed.', 'juliet-just-mask' ) );
		}

		return $url;
	}

	/**
	 * Inserts a new mask.
	 *
	 * @param string $slug       Local path slug.
	 * @param string $target_url Remote target URL.
	 * @param array  $args       Optional: enable_base_inject (bool), status (string).
	 * @return int|WP_Error Mask ID on success.
	 */
	public function create( $slug, $target_url, array $args = array() ) {
		global $wpdb;

		$slug = $this->sanitize_slug( $slug );

		if ( '' === $slug ) {
			return new WP_Error( 'juliet_invalid_slug', __( 'A Local Path is required.', 'juliet-just-mask' ) );
		}

		if ( ! preg_match( '/^[a-z0-9][a-z0-9_\-]{0,99}$/', $slug ) ) {
			return new WP_Error( 'juliet_invalid_slug', __( 'The Local Path must be 1–100 characters using letters, numbers, dashes and underscores, starting with a letter or number.', 'juliet-just-mask' ) );
		}

		if ( $this->is_reserved_slug( $slug ) ) {
			return new WP_Error( 'juliet_reserved_slug', __( 'That Local Path is reserved by WordPress and cannot be masked.', 'juliet-just-mask' ) );
		}

		$validated = $this->validate_target_url( $target_url );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( $this->get_by_slug( $slug ) ) {
			return new WP_Error( 'juliet_duplicate_slug', __( 'A mask with that Local Path already exists.', 'juliet-just-mask' ) );
		}

		$status = isset( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ? $args['status'] : 'active';

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table,
			array(
				'mask_slug'          => $slug,
				'target_url'         => $validated,
				'enable_base_inject' => empty( $args['enable_base_inject'] ) ? 0 : 1,
				'status'             => $status,
			),
			array( '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'juliet_db_error', __( 'Could not save the mask. Please try again.', 'juliet-just-mask' ) );
		}

		$this->invalidate_caches();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates an existing mask.
	 *
	 * @param int   $id   Mask ID.
	 * @param array $data Fields: mask_slug, target_url, enable_base_inject, status.
	 * @return true|WP_Error
	 */
	public function update( $id, array $data ) {
		global $wpdb;

		$id    = absint( $id );
		$existing = $this->get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'juliet_not_found', __( 'Mask not found.', 'juliet-just-mask' ) );
		}

		$fields = array();
		$format = array();

		if ( isset( $data['mask_slug'] ) ) {
			$slug = $this->sanitize_slug( $data['mask_slug'] );

			if ( ! preg_match( '/^[a-z0-9][a-z0-9_\-]{0,99}$/', $slug ) ) {
				return new WP_Error( 'juliet_invalid_slug', __( 'The Local Path must be 1–100 characters using letters, numbers, dashes and underscores.', 'juliet-just-mask' ) );
			}

			if ( $this->is_reserved_slug( $slug ) ) {
				return new WP_Error( 'juliet_reserved_slug', __( 'That Local Path is reserved by WordPress and cannot be masked.', 'juliet-just-mask' ) );
			}

			$clash = $this->get_by_slug( $slug );
			if ( $clash && (int) $clash->id !== $id ) {
				return new WP_Error( 'juliet_duplicate_slug', __( 'A mask with that Local Path already exists.', 'juliet-just-mask' ) );
			}

			$fields['mask_slug'] = $slug;
			$format[]            = '%s';
		}

		if ( isset( $data['target_url'] ) ) {
			$validated = $this->validate_target_url( $data['target_url'] );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			$fields['target_url'] = $validated;
			$format[]             = '%s';
		}

		if ( isset( $data['enable_base_inject'] ) ) {
			$fields['enable_base_inject'] = empty( $data['enable_base_inject'] ) ? 0 : 1;
			$format[]                     = '%d';
		}

		if ( isset( $data['status'] ) ) {
			if ( ! in_array( $data['status'], self::STATUSES, true ) ) {
				return new WP_Error( 'juliet_invalid_status', __( 'Invalid mask status.', 'juliet-just-mask' ) );
			}

			$fields['status'] = $data['status'];
			$format[]         = '%s';
		}

		if ( empty( $fields ) ) {
			return true;
		}

		$updated = $wpdb->update( $this->table, $fields, array( 'id' => $id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false === $updated ) {
			return new WP_Error( 'juliet_db_error', __( 'Could not update the mask. Please try again.', 'juliet-just-mask' ) );
		}

		$this->invalidate_caches();

		return true;
	}

	/**
	 * Deletes a mask.
	 *
	 * @param int $id Mask ID.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		$deleted = $wpdb->delete( $this->table, array( 'id' => absint( $id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->invalidate_caches();

		return false !== $deleted;
	}

	/**
	 * Deletes all masks from the registry.
	 *
	 * @return bool
	 */
	public function delete_all() {
		global $wpdb;

		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->invalidate_caches();

		return false !== $deleted;
	}

	/**
	 * Fetches one mask by ID.
	 *
	 * @param int $id Mask ID.
	 * @return object|null
	 */
	public function get( $id ) {
		global $wpdb;

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, absint( $id ) )
		);
	}

	/**
	 * Fetches one mask by its local slug.
	 *
	 * @param string $slug Local path slug.
	 * @return object|null
	 */
	public function get_by_slug( $slug ) {
		global $wpdb;

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE mask_slug = %s', $this->table, sanitize_text_field( (string) $slug ) )
		);
	}

	/**
	 * Fetches every mask, newest first.
	 *
	 * @return object[]
	 */
	public function all() {
		global $wpdb;

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC, id DESC', $this->table )
		);
	}

	/**
	 * Active masks keyed by slug — hot path for the router.
	 *
	 * @return array<string, object>
	 */
	public function active_map() {
		static $memory_cache = null;

		if ( null !== $memory_cache ) {
			return $memory_cache;
		}

		$cached = wp_cache_get( 'active_masks_map', 'juliet' );

		if ( is_array( $cached ) ) {
			$memory_cache = $cached;
			return $cached;
		}

		global $wpdb;

		$map = array();

		if ( $this->table_exists() ) {
			$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT * FROM %i WHERE status = %s', $this->table, 'active' )
			);

			foreach ( $rows as $row ) {
				if ( ! empty( $row->mask_slug ) ) {
					$map[ strtolower( $row->mask_slug ) ] = $row;
				}
			}
		}

		wp_cache_set( 'active_masks_map', $map, 'juliet' );
		$memory_cache = $map;

		return $map;
	}

	/**
	 * Total number of registered masks.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return 0;
		}

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table )
		);
	}

	/**
	 * Clears cached lookups and schedules a rewrite-rule flush.
	 */
	public function invalidate_caches() {
		wp_cache_delete( 'active_masks', 'juliet' );
		wp_cache_delete( 'active_masks_map', 'juliet' );

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			@wp_cache_flush_group( 'juliet' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		update_option( 'juliet_flush_required', 1, false );
	}
}
