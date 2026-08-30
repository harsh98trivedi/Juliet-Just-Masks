<?php
/**
 * The Proxy Fetch Engine.
 *
 * Fetches remote payloads with visitor-identifying headers, guards against
 * SSRF, caches responses and mirrors remote status/content-type before
 * terminating WordPress execution.
 *
 * @package JulietJustMask
 */

defined( 'ABSPATH' ) || exit;

/**
 * Proxy engine.
 */
class Juliet_Proxy {

	/**
	 * Mask store.
	 *
	 * @var Juliet_Mask_Store
	 */
	protected $store;

	/**
	 * HTML patcher.
	 *
	 * @var Juliet_HTML_Patcher
	 */
	protected $patcher;

	/**
	 * Resolved mask for the current request.
	 *
	 * @var object|null
	 */
	protected $mask;

	/**
	 * Final outbound URL for the current request.
	 *
	 * @var string
	 */
	protected $target_url = '';

	/**
	 * Normalized sub-path beneath the mask slug for the current request.
	 *
	 * @var string
	 */
	protected $subpath = '';

	public function __construct( Juliet_Mask_Store $store ) {
		$this->store   = $store;
		$this->patcher = new Juliet_HTML_Patcher();
	}

	/**
	 * Direct execution entrypoint when resolved by the early router.
	 *
	 * @param object $mask    Resolved mask row.
	 * @param string $subpath Sub-path beneath the mask slug.
	 */
	public function handle_mask_request( $mask, $subpath = '' ) {
		if ( $this->is_proxy_loop() ) {
			$this->serve_native_404();
		}

		$this->mask = $mask;

		if ( ! $this->mask || 'active' !== $this->mask->status ) {
			$this->serve_native_404();
		}

		$method    = $this->request_method();
		$subpath   = $this->normalize_subpath( $subpath );
		$target    = $this->build_target_url( $this->mask, $subpath );
		$validated = $this->validate_outbound_url( $target );

		$this->subpath = $subpath;

		if ( is_wp_error( $validated ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( '[Juliet] Blocked outbound request for mask "%s": %s', $this->mask->mask_slug, $validated->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}

			$this->serve_native_404();
		}

		$this->target_url = $validated;

		// Handle OPTIONS preflight
		if ( 'OPTIONS' === $method ) {
			$this->deliver_options();
		}

		if ( in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			$cached = $this->cache_get( $validated );

			if ( is_array( $cached ) ) {
				$this->deliver( $cached['code'], $cached['type'], $cached['body'], $cached['headers'], $method );
			}
		}

		$response = $this->fetch( $validated, $method );

		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( '[Juliet] Remote fetch failed for "%s" → %s: %s', $this->mask->mask_slug, $validated, $response->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}

			$this->serve_native_404();
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$type    = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body    = (string) wp_remote_retrieve_body( $response );
		$headers = array(
			'location'      => (string) wp_remote_retrieve_header( $response, 'location' ),
			'disposition'   => (string) wp_remote_retrieve_header( $response, 'content-disposition' ),
			'set-cookie'    => (string) wp_remote_retrieve_header( $response, 'set-cookie' ),
			'etag'          => (string) wp_remote_retrieve_header( $response, 'etag' ),
			'last-modified' => (string) wp_remote_retrieve_header( $response, 'last-modified' ),
		);

		if ( '' === trim( $type ) ) {
			$type = $this->infer_content_type( $validated );
		}

		if ( $this->cache_ttl() > 0 && in_array( $method, array( 'GET', 'HEAD' ), true ) && $code >= 200 && $code < 400 && '' === $headers['set-cookie'] && strlen( $body ) <= $this->max_cache_bytes() ) {
			$this->cache_set( $validated, compact( 'code', 'type', 'body', 'headers' ) );
		}

		$this->deliver( $code, $type, $body, $headers, $method );
	}

	/**
	 * Full proxy lifecycle: resolve, build, validate, fetch, patch, deliver.
	 */
	public function handle_request() {
		$mask = $this->resolve_mask();
		$this->handle_mask_request( $mask, (string) get_query_var( Juliet_Router::PATH_VAR ) );
	}

	/**
	 * Blocks requests that already carry our proxy signature (loop guard).
	 *
	 * @return bool
	 */
	protected function is_proxy_loop() {
		$incoming = isset( $_SERVER['HTTP_X_JULIET_PROXY'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_JULIET_PROXY'] ) ) : '';

		/**
		 * Filters whether the inbound proxy-loop guard is honored.
		 *
		 * @param bool $honor Default true.
		 */
		return (bool) apply_filters( 'juliet_honor_loop_guard', '' !== $incoming );
	}

	/**
	 * Resolves the active mask for the current request.
	 *
	 * @return object|null
	 */
	protected function resolve_mask() {
		$slug = sanitize_text_field( (string) get_query_var( Juliet_Router::SLUG_VAR ) );

		if ( '' === $slug ) {
			return null;
		}

		foreach ( $this->store->active_map() as $mask ) {
			if ( hash_equals( $mask->mask_slug, $slug ) ) {
				return $mask;
			}
		}

		return null;
	}

	/**
	 * Allowed HTTP methods for pass-through.
	 *
	 * @return string
	 */
	protected function request_method() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( ! in_array( $method, array( 'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ), true ) ) {
			$method = 'GET';
		}

		return $method;
	}

	/**
	 * Normalizes a sub-path: strips dot segments and re-encodes each segment.
	 *
	 * @param string $path Raw sub-path from the rewrite match.
	 * @return string Path without leading/trailing slashes (may be empty).
	 */
	protected function normalize_subpath( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$segs = array();

		foreach ( explode( '/', $path ) as $segment ) {
			$segment = trim( rawurldecode( $segment ) );

			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $segs );
				continue;
			}

			$segment = preg_replace( '/[\x00-\x1F\x7F]/', '', $segment );

			if ( '' !== $segment ) {
				$segs[] = rawurlencode( $segment );
			}
		}

		return implode( '/', $segs );
	}

	/**
	 * Builds the final outbound URL for the mask and requested sub-path.
	 *
	 * Root requests hit the saved target verbatim (query args merged, local
	 * wins). Sub-path requests are appended to the target's base directory.
	 *
	 * @param object $mask    Active mask.
	 * @param string $subpath Normalized sub-path ('' for the mask root).
	 * @return string
	 */
	protected function build_target_url( $mask, $subpath ) {
		$parts = wp_parse_url( $mask->target_url );

		$scheme   = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host     = isset( $parts['host'] ) ? $parts['host'] : '';
		$port     = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$t_path   = isset( $parts['path'] ) ? $parts['path'] : '/';
		$t_query  = isset( $parts['query'] ) ? $parts['query'] : '';

		$origin = $scheme . '://' . $host . $port;

		parse_str( $t_query, $target_args );

		$local_args = stripslashes_deep( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $local_args[ Juliet_Router::FLAG_VAR ], $local_args[ Juliet_Router::SLUG_VAR ], $local_args[ Juliet_Router::PATH_VAR ] );

		if ( '' === $subpath ) {
			$url = $origin . $t_path;

			$merged = array_merge( $target_args, $local_args );

			if ( ! empty( $merged ) ) {
				$url .= '?' . http_build_query( $merged );
			}
		} else {
			$base_dir = rtrim( preg_replace( '~[^/]*$~', '', rtrim( $t_path, '/' ) ), '/' );

			$url = $origin . $base_dir . '/' . $subpath;

			if ( ! empty( $local_args ) ) {
				$url .= '?' . http_build_query( $local_args );
			}
		}

		/**
		 * Filters the final outbound URL before it is fetched.
		 *
		 * @param string     $url     Outbound URL.
		 * @param object     $mask    Active mask row.
		 * @param string     $subpath Normalized sub-path.
		 */
		return esc_url_raw( apply_filters( 'juliet_target_url', $url, $mask, $subpath ), array( 'http', 'https' ) );
	}

	/**
	 * SSRF guard: protocol, credentials, host sanity and private-range blocking.
	 *
	 * @param string $url Final outbound URL.
	 * @return string|WP_Error The URL when safe.
	 */
	protected function validate_outbound_url( $url ) {
		if ( '' === $url || 0 !== stripos( $url, 'http' ) ) {
			return new WP_Error( 'juliet_invalid_protocol', __( 'Invalid target protocol.', 'juliet-just-masks' ) );
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'juliet_invalid_target', __( 'Invalid target URL.', 'juliet-just-masks' ) );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'juliet_credentials_in_url', __( 'Embedded credentials are not allowed in target URLs.', 'juliet-just-masks' ) );
		}

		$host = strtolower( $parts['host'] );

		/**
		 * Allows proxying to private/internal network targets.
		 *
		 * Automatically enabled in local / development environments.
		 *
		 * @param bool $allow Default based on environment.
		 */
		$is_local_env = ( function_exists( 'wp_get_environment_type' ) && in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) )
			|| ( defined( 'WP_DEBUG' ) && WP_DEBUG )
			|| ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) )
			|| ( preg_match( '/\.(local|test|ddev\.site|internal|localhost)$/i', $host ) );

		if ( apply_filters( 'juliet_allow_private_targets', $is_local_env ) ) {
			return $url;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( ! $this->ip_is_public( $host ) ) {
				return new WP_Error( 'juliet_private_target', __( 'Target resolves to a private or reserved IP address.', 'juliet-just-masks' ) );
			}

			return $url;
		}

		foreach ( $this->resolve_host_ips( $host ) as $ip ) {
			if ( ! $this->ip_is_public( $ip ) ) {
				return new WP_Error( 'juliet_private_target', __( 'Target resolves to a private or reserved IP address.', 'juliet-just-masks' ) );
			}
		}

		return $url;
	}

	/**
	 * Collects A/AAAA records for a hostname.
	 *
	 * @param string $host Hostname.
	 * @return string[] Resolved IPs (empty when resolution fails).
	 */
	protected function resolve_host_ips( $host ) {
		$ips = array();

		$a_records = dns_get_record( $host, DNS_A );
		if ( is_array( $a_records ) ) {
			foreach ( $a_records as $record ) {
				if ( ! empty( $record['ip'] ) && filter_var( $record['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
					$ips[] = $record['ip'];
				}
			}
		}

		$aaaa_records = dns_get_record( $host, DNS_AAAA );
		if ( is_array( $aaaa_records ) ) {
			foreach ( $aaaa_records as $record ) {
				if ( ! empty( $record['ipv6'] ) && filter_var( $record['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		if ( empty( $ips ) ) {
			$fallback = gethostbyname( $host );

			if ( $fallback !== $host && filter_var( $fallback, FILTER_VALIDATE_IP ) ) {
				$ips[] = $fallback;
			}
		}

		return $ips;
	}

	/**
	 * Whether an IP is globally routable (not private/reserved).
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	protected function ip_is_public( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return ! $this->ipv4_in_reserved_range( $ip );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return ! $this->ipv6_in_reserved_range( $ip );
		}

		return false;
	}

	/**
	 * Reserved IPv4 range check.
	 *
	 * @param string $ip IPv4 address.
	 * @return bool
	 */
	protected function ipv4_in_reserved_range( $ip ) {
		$long = ip2long( $ip );

		if ( false === $long ) {
			return true;
		}

		$ranges = array(
			'0.0.0.0/8',
			'10.0.0.0/8',
			'100.64.0.0/10',
			'127.0.0.0/8',
			'169.254.0.0/16',
			'172.16.0.0/12',
			'192.0.0.0/24',
			'192.0.2.0/24',
			'192.168.0.0/16',
			'198.18.0.0/15',
			'198.51.100.0/24',
			'203.0.113.0/24',
			'224.0.0.0/4',
			'240.0.0.0/4',
		);

		foreach ( $ranges as $cidr ) {
			list( $subnet, $bits ) = explode( '/', $cidr );
			$subnet_long           = ip2long( $subnet );
			$mask                  = -1 << ( 32 - (int) $bits );

			if ( ( $long & $mask ) === ( $subnet_long & $mask ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reserved IPv6 range check (prefix based).
	 *
	 * @param string $ip IPv6 address.
	 * @return bool
	 */
	protected function ipv6_in_reserved_range( $ip ) {
		$ranges = array(
			'::/128',
			'::1/128',
			'::ffff:0:0/96',
			'fc00::/7',
			'fe80::/10',
			'ff00::/8',
			'2001:db8::/32',
		);

		foreach ( $ranges as $cidr ) {
			if ( $this->ipv6_in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * CIDR membership test for IPv6.
	 *
	 * @param string $ip   IPv6 address.
	 * @param string $cidr IPv6 CIDR.
	 * @return bool
	 */
	protected function ipv6_in_cidr( $ip, $cidr ) {
		list( $subnet, $bits ) = explode( '/', $cidr );

		$ip_bin   = @inet_pton( $ip );
		$net_bin  = @inet_pton( $subnet );
		$bits     = (int) $bits;

		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== 16 || strlen( $net_bin ) !== 16 ) {
			return false;
		}

		$full_bytes = intdiv( $bits, 8 );
		$rem_bits   = $bits % 8;

		if ( $full_bytes > 0 && substr( $ip_bin, 0, $full_bytes ) !== substr( $net_bin, 0, $full_bytes ) ) {
			return false;
		}

		if ( $rem_bits > 0 ) {
			$mask = ( 0xFF << ( 8 - $rem_bits ) ) & 0xFF;

			if ( ( ( ord( $ip_bin[ $full_bytes ] ) ^ ord( $net_bin[ $full_bytes ] ) ) & $mask ) !== 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Assembles outbound request headers that identify the real visitor.
	 *
	 * @param object $mask Active mask.
	 * @param string $url Target URL.
	 * @return array
	 */
	protected function request_headers( $mask, $url ) {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '';
		$remote_ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$xff        = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';

		$chain = '' !== $xff ? $xff . ', ' . $remote_ip : $remote_ip;

		$headers = array_filter(
			array(
				'User-Agent'         => '' !== $user_agent ? $user_agent : 'Juliet-Just-Masks/' . JULIET_VERSION . ' (WordPress reverse proxy)',
				'Accept'             => isset( $_SERVER['HTTP_ACCEPT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ), 0, 500 ) : '',
				'Accept-Language'    => isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ), 0, 200 ) : '',
				'X-Forwarded-For'    => $chain,
				'X-Forwarded-Host'   => isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '',
				'X-Forwarded-Proto'  => is_ssl() ? 'https' : 'http',
				'X-Juliet-Proxy'     => 'active',
			)
		);

		/**
		 * Controls whether the visitor's Cookie header is forwarded upstream.
		 *
		 * Disabled by default: forwarding cookies leaks this site's auth
		 * cookies to the remote server. Enable only for trusted targets.
		 *
		 * @param bool $forward Default false.
		 */
		if ( apply_filters( 'juliet_forward_cookies', false ) && ! empty( $_SERVER['HTTP_COOKIE'] ) ) {
			$headers['Cookie'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_COOKIE'] ) );
		}

		/**
		 * Filters the outbound proxy headers.
		 *
		 * @param array  $headers Outbound headers.
		 * @param object $mask    Active mask row.
		 * @param string $url     Target URL.
		 */
		return apply_filters( 'juliet_proxy_headers', $headers, $mask, $url );
	}

	/**
	 * Performs the remote request.
	 *
	 * @param string $url    Target URL.
	 * @param string $method HTTP method.
	 * @return array|WP_Error
	 */
	protected function fetch( $url, $method ) {
		$args = array(
			'timeout'     => $this->timeout(),
			'redirection' => 5,
			'blocking'    => true,
			'sslverify'   => true,
			'headers'     => $this->request_headers( $this->mask, $url ),
			'method'      => $method,
			'data_format' => 'body',
		);

		if ( ! in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			$body = (string) file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( '' !== $body ) {
				$args['body'] = $body;
			}

			if ( ! empty( $_SERVER['CONTENT_TYPE'] ) ) {
				$args['headers']['Content-Type'] = sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) );
			}
		}

		/**
		 * Filters the wp_remote_request() arguments.
		 *
		 * @param array  $args   Request args.
		 * @param string $url    Target URL.
		 * @param object $mask   Active mask row.
		 */
		$args = apply_filters( 'juliet_request_args', $args, $url, $this->mask );

		return wp_remote_request( $url, $args );
	}

	/**
	 * Handles CORS OPTIONS preflight requests.
	 */
	protected function deliver_options() {
		status_header( 204 );
		header( 'X-Juliet-Proxy: active' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD' );
		header( 'Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-Forwarded-For, X-Juliet-Proxy' );
		header( 'Access-Control-Max-Age: 86400' );
		exit;
	}

	/**
	 * Infers standard MIME content type from target URL extension.
	 *
	 * @param string $url Target URL.
	 * @return string
	 */
	public function infer_content_type( $url ) {
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$ext  = pathinfo( $path, PATHINFO_EXTENSION );

		$map = array(
			'html'  => 'text/html; charset=utf-8',
			'htm'   => 'text/html; charset=utf-8',
			'js'    => 'application/javascript; charset=utf-8',
			'mjs'   => 'application/javascript; charset=utf-8',
			'css'   => 'text/css; charset=utf-8',
			'json'  => 'application/json; charset=utf-8',
			'png'   => 'image/png',
			'jpg'   => 'image/jpeg',
			'jpeg'  => 'image/jpeg',
			'gif'   => 'image/gif',
			'svg'   => 'image/svg+xml',
			'webp'  => 'image/webp',
			'avif'  => 'image/avif',
			'ico'   => 'image/x-icon',
			'woff2' => 'font/woff2',
			'woff'  => 'font/woff',
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
			'eot'   => 'application/vnd.ms-fontobject',
			'xml'   => 'application/xml; charset=utf-8',
			'txt'   => 'text/plain; charset=utf-8',
			'pdf'   => 'application/pdf',
		);

		return isset( $map[ $ext ] ) ? $map[ $ext ] : 'text/html; charset=utf-8';
	}

	/**
	 * Mirrors the remote response and terminates execution.
	 *
	 * @param int      $code     Remote status code.
	 * @param string   $type     Remote content type.
	 * @param string   $body     Remote body.
	 * @param array    $headers  Selected remote headers.
	 * @param string   $method   Request method.
	 */
	protected function deliver( $code, $type, $body, array $headers = array(), $method = 'GET' ) {
		$code = ( $code >= 100 && $code <= 599 ) ? $code : 200;

		$sanitized_type = $this->sanitize_content_type( $type );

		status_header( $code );
		header( 'Content-Type: ' . $sanitized_type );
		header( 'X-Juliet-Proxy: active' );
		header( 'Access-Control-Allow-Origin: *' );

		if ( $code >= 300 && $code < 400 && '' !== $headers['location'] ) {
			header( 'Location: ' . $this->strip_crlf( $this->absolutize_location( $headers['location'] ) ) );
		}

		if ( '' !== $headers['disposition'] ) {
			header( 'Content-Disposition: ' . $this->strip_crlf( $headers['disposition'] ) );
		}

		$is_html  = false !== stripos( $sanitized_type, 'text/html' ) || ( '' === trim( $type ) && preg_match( '/\.(html?|php)$/i', $this->target_url ) );
		$is_css   = false !== stripos( $sanitized_type, 'text/css' ) || preg_match( '/\.css(\?.*)?$/i', $this->target_url );
		$is_get   = in_array( $method, array( 'GET', 'HEAD' ), true );
		$ok_code  = $code >= 200 && $code < 400;

		if ( $is_html ) {
			nocache_headers();
		} else {
			header( 'Cache-Control: public, max-age=3600' );
			if ( ! empty( $headers['etag'] ) ) {
				header( 'ETag: ' . $this->strip_crlf( $headers['etag'] ) );
			}
			if ( ! empty( $headers['last-modified'] ) ) {
				header( 'Last-Modified: ' . $this->strip_crlf( $headers['last-modified'] ) );
			}
		}

		if ( $is_html && $ok_code && $is_get ) {
			$body = $this->patcher->patch( $body, $this->current_target_url(), $this->mask, $this->current_subpath() );
		} elseif ( $is_css && $ok_code && $is_get ) {
			$prefix = ! empty( $this->mask->enable_base_inject ) ? home_url( '/' . trim( $this->mask->mask_slug, '/' ) ) : $this->patcher->origin_of( $this->target_url );
			$body   = $this->patcher->patch_standalone_css( $body, $prefix );
		}

		if ( 'HEAD' !== $method ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		exit;
	}

	/**
	 * The outbound URL for the in-flight request (used as patch base).
	 *
	 * @return string
	 */
	protected function current_target_url() {
		return $this->target_url;
	}

	/**
	 * The normalized sub-path for the in-flight request (used for the local
	 * document base when injecting <base>).
	 *
	 * @return string
	 */
	protected function current_subpath() {
		return $this->subpath;
	}

	/**
	 * Sanitizes a remote content type for re-emission.
	 *
	 * @param string $type Raw content type.
	 * @return string
	 */
	protected function sanitize_content_type( $type ) {
		$type = $this->strip_crlf( trim( (string) $type ) );

		if ( '' === $type ) {
			return 'text/html; charset=utf-8';
		}

		$mime    = trim( preg_split( '/\s*;/', $type, 2 )[0] );
		$charset = '';

		if ( preg_match( '/charset\s*=\s*"?([A-Za-z0-9_\-]+)"?/i', $type, $m ) ) {
			$charset = '; charset=' . strtoupper( $m[1] );
		}

		if ( ! preg_match( '~^[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*/[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*$~', $mime ) ) {
			return 'text/html; charset=utf-8';
		}

		return $mime . $charset;
	}

	/**
	 * Removes CR/LF from header values (header injection guard).
	 *
	 * @param string $value Header value.
	 * @return string
	 */
	protected function strip_crlf( $value ) {
		return str_replace( array( "\r", "\n", "\0" ), '', (string) $value );
	}

	/**
	 * Converts a relative Location header into an absolute URL.
	 *
	 * @param string $location Location header value.
	 * @return string
	 */
	protected function absolutize_location( $location ) {
		$location = trim( $location );
		$parts    = wp_parse_url( $this->mask->target_url );

		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$origin = $scheme . '://' . $host . $port;

		if ( '' !== $location && '/' === $location[0] ) {
			return $origin . $location;
		}

		$t_path = isset( $parts['path'] ) ? preg_replace( '~[^/]*$~', '', $parts['path'] ) : '/';

		return $origin . $t_path . $location;
	}

	/**
	 * Graceful fallback: renders the active theme's native 404 template.
	 */
	public function serve_native_404() {
		global $wp_query;

		status_header( 404 );
		nocache_headers();

		if ( isset( $wp_query ) ) {
			$wp_query->set_404();
		}

		$template = get_404_template();

		if ( $template ) {
			load_template( $template );
		} else {
			wp_die(
				esc_html__( 'Page not found.', 'juliet-just-masks' ),
				'',
				array( 'response' => 404 )
			);
		}

		exit;
	}

	/**
	 * Remote fetch timeout in seconds.
	 *
	 * @return int
	 */
	protected function timeout() {
		/**
		 * Filters the remote fetch timeout (seconds).
		 *
		 * @param int $timeout Default 15.
		 */
		return max( 1, (int) apply_filters( 'juliet_timeout', 15 ) );
	}

	/**
	 * Server-side response cache TTL in seconds (0 disables caching).
	 *
	 * @return int
	 */
	protected function cache_ttl() {
		/**
		 * Filters the response cache TTL in seconds. Return 0 to disable.
		 *
		 * @param int   $ttl  Default 300.
		 * @param object $mask Active mask row.
		 */
		return max( 0, (int) apply_filters( 'juliet_cache_ttl', 300, $this->mask ) );
	}

	/**
	 * Maximum body size eligible for caching.
	 *
	 * @return int
	 */
	protected function max_cache_bytes() {
		/**
		 * Filters the maximum cacheable response body size in bytes.
		 *
		 * @param int $bytes Default 2 MB.
		 */
		return (int) apply_filters( 'juliet_max_cache_bytes', 2 * MB_IN_BYTES );
	}

	/**
	 * Cache key for an outbound URL.
	 *
	 * @param string $url Target URL.
	 * @return string
	 */
	protected function cache_key( $url ) {
		return 'juliet_resp_' . md5( $url );
	}

	/**
	 * Reads a cached remote response.
	 *
	 * @param string $url Target URL.
	 * @return array|null
	 */
	protected function cache_get( $url ) {
		$hit = get_transient( $this->cache_key( $url ) );

		return is_array( $hit ) ? $hit : null;
	}

	/**
	 * Persists a remote response.
	 *
	 * @param string $url     Target URL.
	 * @param array  $payload Compact response payload.
	 */
	protected function cache_set( $url, array $payload ) {
		set_transient( $this->cache_key( $url ), $payload, $this->cache_ttl() );
	}
}
