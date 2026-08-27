<?php
/**
 * Stealth Routing Engine.
 *
 * Registers rewrite rules for every active mask, whitelists the custom query
 * vars and hijacks delivery at template_redirect before themes can render.
 *
 * @package JulietJustMask
 */

defined( 'ABSPATH' ) || exit;

/**
 * Router.
 */
class Juliet_Router {

	/**
	 * Mask store.
	 *
	 * @var Juliet_Mask_Store
	 */
	protected $store;

	/**
	 * Query var flagging an active mask route.
	 */
	const FLAG_VAR = 'juliet_mask_active';

	/**
	 * Query var carrying the requested mask slug.
	 */
	const SLUG_VAR = 'j_slug';

	/**
	 * Query var carrying any sub-path beneath the mask root.
	 */
	const PATH_VAR = 'juliet_mask_path';

	public function __construct( Juliet_Mask_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Attaches WordPress hooks.
	 */
	public function register_hooks() {
		// Hook early into parse_request for instant, zero-latency routing
		add_action( 'parse_request', array( $this, 'early_route_match' ), 0 );
		add_action( 'init', array( $this, 'register_rewrite_rules' ), 20 );
		add_filter( 'query_vars', array( $this, 'whitelist_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_proxy' ), 1 );
	}

	/**
	 * Early matching interceptor running directly on parse_request.
	 *
	 * Guarantees masks take effect immediately when created or toggled on/off,
	 * independent of rewrite cache state or permalink structure.
	 *
	 * @param WP $wp Current WordPress environment instance.
	 */
	public function early_route_match( $wp = null ) {
		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$raw_path = '';

		if ( is_object( $wp ) && ! empty( $wp->request ) ) {
			$raw_path = (string) $wp->request;
		} else {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$parsed      = wp_parse_url( $request_uri );
			$path        = isset( $parsed['path'] ) ? $parsed['path'] : '';

			// Strip WordPress home directory if WP is installed in a subfolder
			$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			if ( '/' !== $home_path && '' !== $home_path && 0 === strpos( $path, $home_path ) ) {
				$path = substr( $path, strlen( $home_path ) );
			}

			$raw_path = trim( $path, '/' );
		}

		if ( '' === $raw_path ) {
			return;
		}

		// Split first segment as slug, remainder as subpath
		$parts   = explode( '/', $raw_path, 2 );
		$slug    = strtolower( $parts[0] );
		$subpath = isset( $parts[1] ) ? $parts[1] : '';

		$active_map = $this->store->active_map();

		if ( isset( $active_map[ $slug ] ) ) {
			$mask = $active_map[ $slug ];

			if ( is_object( $wp ) ) {
				$wp->set_query_var( self::FLAG_VAR, '1' );
				$wp->set_query_var( self::SLUG_VAR, $slug );
				$wp->set_query_var( self::PATH_VAR, $subpath );
			}

			$this->dispatch_proxy( $mask, $subpath );
		}
	}

	/**
	 * Dispatches an in-flight request directly to the proxy engine.
	 *
	 * @param object $mask    Active mask row.
	 * @param string $subpath Sub-path.
	 */
	public function dispatch_proxy( $mask, $subpath = '' ) {
		try {
			$proxy = new Juliet_Proxy( $this->store );
			$proxy->handle_mask_request( $mask, $subpath );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( '[Juliet] Proxy failure: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}

			$proxy = new Juliet_Proxy( $this->store );
			$proxy->serve_native_404();
		}
	}

	/**
	 * Registers one rewrite rule per active mask (top priority).
	 *
	 * Supports the mask root plus unlimited sub-paths:
	 *   /marketing-hub            -> target URL as saved
	 *   /marketing-hub/pricing    -> target directory + /pricing
	 */
	public function register_rewrite_rules() {
		if ( get_option( 'juliet_flush_required' ) ) {
			delete_option( 'juliet_flush_required' );
			flush_rewrite_rules( false );
		}

		if ( ! $this->store->table_exists() ) {
			return;
		}

		foreach ( $this->store->active_map() as $slug => $mask ) {
			add_rewrite_rule(
				'^' . $slug . '(?:/(.*))?/?$',
				'index.php?' . self::FLAG_VAR . '=1&' . self::SLUG_VAR . '=' . rawurlencode( $slug ) . '&' . self::PATH_VAR . '=$matches[1]',
				'top'
			);
		}
	}

	/**
	 * Whitelists Juliet's custom query variables.
	 *
	 * @param string[] $vars Registered query vars.
	 * @return string[]
	 */
	public function whitelist_query_vars( array $vars ) {
		$vars[] = self::FLAG_VAR;
		$vars[] = self::SLUG_VAR;
		$vars[] = self::PATH_VAR;

		return $vars;
	}

	/**
	 * Detects a flagged request and hands it to the proxy engine (fallback handler).
	 */
	public function maybe_proxy() {
		if ( ! get_query_var( self::FLAG_VAR ) ) {
			return;
		}

		try {
			$proxy = new Juliet_Proxy( $this->store );
			$proxy->handle_request();
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( '[Juliet] Proxy failure: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}

			$proxy = new Juliet_Proxy( $this->store );
			$proxy->serve_native_404();
		}
	}
}
