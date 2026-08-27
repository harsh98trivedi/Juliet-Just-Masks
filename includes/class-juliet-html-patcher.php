<?php
/**
 * Asset Dependency Patcher.
 *
 * Two-pronged patching per the TRD: fast regex rewriting of relative asset
 * paths plus an optional <base> injection for JS-heavy applications. A third
 * pass rewrites absolute links pointing at the remote origin back into the
 * masked slug namespace so navigation never leaves the local domain. With
 * base injection enabled every reference resolves inside the local mask
 * (never at the remote origin), keeping SPAs fully proxied.
 *
 * @package JulietJustMask
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTML patcher.
 */
class Juliet_HTML_Patcher {

	/**
	 * Applies all enabled patches to a fetched HTML document.
	 *
	 * When base injection is enabled the whole document is funneled through
	 * the local slug namespace and a <base href> pointing at the masked
	 * directory keeps runtime-relative URLs (SPA fetch/XHR, relative links)
	 * inside the proxy. Otherwise assets resolve straight at the remote
	 * origin as before.
	 *
	 * @param string $html       Raw remote HTML.
	 * @param string $source_url The outbound URL the HTML was fetched from.
	 * @param object $mask       Active mask row.
	 * @param string $subpath    Normalized sub-path beneath the mask slug ('' for the root).
	 * @return string
	 */
	public function patch( $html, $source_url, $mask, $subpath = '' ) {
		$html = (string) $html;

		if ( '' === $html || ! $this->looks_like_html( $html ) ) {
			return $html;
		}

		$origin = $this->origin_of( $source_url );

		if ( '' === $origin ) {
			return $html;
		}

		if ( ! empty( $mask->enable_base_inject ) ) {
			$resolution = $this->local_mount_prefix( $mask );

			$html = $this->rewrite_root_relative_urls( $html, $resolution );
			$html = $this->rewrite_srcset( $html, $resolution );
			$html = $this->rewrite_inline_css_urls( $html, $resolution );
			$html = $this->inject_base_tag( $html, $this->local_doc_base( $mask ) );
		} else {
			$html = $this->rewrite_root_relative_urls( $html, $origin );
			$html = $this->rewrite_srcset( $html, $origin );
			$html = $this->rewrite_inline_css_urls( $html, $origin );
		}

		return $this->mask_remote_links( $html, $origin, $mask, $source_url );
	}

	/**
	 * Local mount point used to rewrite root-relative references when base
	 * injection is enabled (scheme + host + slug path, no trailing slash).
	 *
	 * @param object $mask Active mask row.
	 * @return string
	 */
	protected function local_mount_prefix( $mask ) {
		$mount = trim( (string) $mask->mask_slug, '/' );

		return home_url( '' !== $mount ? '/' . $mount : '/' );
	}

	/**
	 * The document-level <base href> pointing to the root mount point of the mask.
	 *
	 * Always trailing-slashed so runtime document-relative URLs, SPA bundles,
	 * and async chunk requests resolve cleanly inside the proxy.
	 *
	 * @param object $mask Active mask row.
	 * @return string
	 */
	protected function local_doc_base( $mask ) {
		$mount = trim( (string) $mask->mask_slug, '/' );

		return home_url( '/' . $mount . '/' );
	}

	/**
	 * Loose structural check that a payload is an HTML document.
	 *
	 * @param string $html Payload.
	 * @return bool
	 */
	protected function looks_like_html( $html ) {
		return (bool) preg_match(
			'~<(?:!doctype\s+html|html|head|body|div|section|main|article|header|footer|nav|aside|span|p|a|h[1-6]|ul|ol|li|table|form|input|button|img|picture|video|audio|source|iframe|script|style|link|meta|title)\b~i',
			substr( $html, 0, 8192 )
		);
	}

	/**
	 * Scheme + host (+ port) of a URL.
	 *
	 * @param string $url URL.
	 * @return string Origin or '' on failure.
	 */
	public function origin_of( $url ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$port   = isset( $parts['port'] ) && ! $this->is_default_port( $scheme, $parts['port'] ) ? ':' . $parts['port'] : '';

		return $scheme . '://' . strtolower( $parts['host'] ) . $port;
	}

	/**
	 * Whether a port is the scheme default (and thus omittable).
	 *
	 * @param string $scheme Scheme.
	 * @param int    $port   Port.
	 * @return bool
	 */
	protected function is_default_port( $scheme, $port ) {
		return ( 'https' === $scheme && 443 === (int) $port ) || ( 'http' === $scheme && 80 === (int) $port );
	}

	/**
	 * Rewrites root-relative asset URLs to absolute URLs under a prefix.
	 *
	 * The prefix is the remote origin by default, or the local slug mount
	 * when base injection is enabled. Covers all standard and modern HTML attributes.
	 *
	 * @param string $html   HTML.
	 * @param string $prefix Resolution prefix (no trailing slash).
	 * @return string
	 */
	protected function rewrite_root_relative_urls( $html, $prefix ) {
		$out = preg_replace_callback(
			'~\s(src|href|action|poster|data-src|data-href|data-url|data-original|icon|manifest|background)(\s*=\s*)(["\'])(/(?!/)[^"\']*)\3~i',
			function ( $m ) use ( $prefix ) {
				$attr  = strtolower( $m[1] );
				$value = $this->decode_attr( $m[4] );

				if ( '#' === substr( $value, 0, 1 ) ) {
					return $m[0];
				}

				$absolute = esc_url( $prefix . $value );

				if ( '' === $absolute ) {
					return $m[0];
				}

				return ' ' . $attr . $m[2] . $m[3] . $absolute . $m[3];
			},
			$html
		);

		return is_string( $out ) ? $out : $html;
	}

	/**
	 * Converts HTML entities in an extracted attribute value back to literals.
	 *
	 * @param string $value Raw attribute substring.
	 * @return string
	 */
	protected function decode_attr( $value ) {
		return html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Rewrites each candidate inside a srcset attribute under a prefix.
	 *
	 * @param string $html   HTML.
	 * @param string $prefix Resolution prefix (no trailing slash).
	 * @return string
	 */
	public function rewrite_srcset( $html, $prefix ) {
		$out = preg_replace_callback(
			'~\s(srcset|data-srcset)(\s*=\s*)(["\'])([^"\']+)\3~i',
			function ( $m ) use ( $prefix ) {
				$candidates = explode( ',', $m[4] );
				$rebuilt    = array();

				foreach ( $candidates as $candidate ) {
					$parts = preg_split( '~\s+~', trim( html_entity_decode( $candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 2 );

					if ( empty( $parts[0] ) ) {
						continue;
					}

					$url = trim( $parts[0] );

					if ( '/' === substr( $url, 0, 1 ) && '//' !== substr( $url, 0, 2 ) ) {
						$url = esc_url( $prefix . $url );
					}

					$rebuilt[] = $url . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' );
				}

				if ( empty( $rebuilt ) ) {
					return $m[0];
				}

				return ' ' . strtolower( $m[1] ) . $m[2] . $m[3] . esc_url( implode( ', ', $rebuilt ) ) . $m[3];
			},
			$html
		);

		return is_string( $out ) ? $out : $html;
	}

	/**
	 * Rewrites root-relative url() references inside inline styles/style
	 * blocks under a prefix.
	 *
	 * @param string $html   HTML.
	 * @param string $prefix Resolution prefix (no trailing slash).
	 * @return string
	 */
	protected function rewrite_inline_css_urls( $html, $prefix ) {
		$out = preg_replace_callback(
			'~url\(\s*([\'"]?)/(?!/)([^)\'"]+)\1\s*\)~i',
			function ( $m ) use ( $prefix ) {
				return 'url(' . $m[1] . esc_attr( $prefix . '/' . ltrim( $this->decode_attr( $m[2] ), '/' ) ) . $m[1] . ')';
			},
			$html
		);

		return is_string( $out ) ? $out : $html;
	}

	/**
	 * Patches standalone CSS files by rewriting root-relative url() and @import paths.
	 *
	 * @param string $css    Raw CSS body.
	 * @param string $prefix Resolution prefix (no trailing slash).
	 * @return string
	 */
	public function patch_standalone_css( $css, $prefix ) {
		$css = (string) $css;

		if ( '' === $css ) {
			return $css;
		}

		$out = preg_replace_callback(
			'~url\(\s*([\'"]?)/(?!/)([^)\'"]+)\1\s*\)~i',
			function ( $m ) use ( $prefix ) {
				return 'url(' . $m[1] . esc_attr( $prefix . '/' . ltrim( $this->decode_attr( $m[2] ), '/' ) ) . $m[1] . ')';
			},
			$css
		);

		if ( is_string( $out ) ) {
			$out = preg_replace_callback(
				'~@import\s+([\'"])/(?!/)([^"\']+)\1~i',
				function ( $m ) use ( $prefix ) {
					return '@import ' . $m[1] . esc_attr( $prefix . '/' . ltrim( $this->decode_attr( $m[2] ), '/' ) ) . $m[1];
				},
				$out
			);
		}

		return is_string( $out ) ? $out : $css;
	}

	/**
	 * Phase 2 mitigation: rewrites absolute links targeting the remote origin
	 * back into the mask's local namespace, keeping visitors on this domain.
	 *
	 * @param string $html       HTML.
	 * @param string $origin     Remote origin.
	 * @param object $mask       Active mask row.
	 * @param string $source_url Outbound URL actually fetched.
	 * @return string
	 */
	protected function mask_remote_links( $html, $origin, $mask, $source_url ) {

		/**
		 * Filters whether same-origin remote links are rewritten into the
		 * local slug namespace (default true).
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'juliet_apply_link_masking', true ) ) {
			return $html;
		}

		$local_origin = $this->origin_of( home_url() );

		if ( '' !== $local_origin && strcasecmp( $local_origin, $origin ) === 0 ) {
			return $html;
		}

		$slug     = trim( (string) $mask->mask_slug, '/' );
		$t_parts  = wp_parse_url( $source_url );
		$base_dir = isset( $t_parts['path'] ) ? rtrim( preg_replace( '~[^/]*$~', '', rtrim( $t_parts['path'], '/' ) ), '/' ) : '';

		$origin_host = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		$origin_port = (string) wp_parse_url( $origin, PHP_URL_PORT );

		$out = preg_replace_callback(
			'~(<a\b[^>]*\shref)(\s*=\s*)(["\'])(https?://[^"\']+)\3~i',
			function ( $m ) use ( $origin_host, $origin_port, $origin, $slug, $base_dir ) {
				$href = $this->decode_attr( $m[4] );
				$p    = wp_parse_url( $href );

				if ( empty( $p['host'] ) || strtolower( $p['host'] ) !== $origin_host ) {
					return $m[0];
				}

				$href_port = isset( $p['port'] ) ? (string) $p['port'] : '';
				$scheme    = isset( $p['scheme'] ) ? strtolower( $p['scheme'] ) : 'http';
				$eff_port  = '' !== $href_port && ! ( ( 'https' === $scheme && '443' === $href_port ) || ( 'http' === $scheme && '80' === $href_port ) ) ? $href_port : '';

				if ( $eff_port !== $origin_port ) {
					return $m[0];
				}

				$path = isset( $p['path'] ) ? $p['path'] : '/';

				if ( '' !== $base_dir && 0 === strpos( $path . '/', $base_dir . '/' ) ) {
					$path = substr( $path, strlen( $base_dir ) );
				}

				$query    = isset( $p['query'] ) ? '?' . $p['query'] : '';
				$fragment = isset( $p['fragment'] ) ? '#' . $p['fragment'] : '';

				$local = home_url( '/' . $slug . '/' . ltrim( $path, '/' ) . $query . $fragment );

				return $m[1] . $m[2] . $m[3] . esc_url( $local ) . $m[3];
			},
			$html
		);

		return is_string( $out ) ? $out : $html;
	}

	/**
	 * Injects a <base href> tag inside <head>.
	 *
	 * Deliberately avoids a DOMDocument round-trip to preserve SPA code integrity.
	 * The tag is inserted by byte offset so every other byte of the document survives
	 * untouched. Any <base> tag shipped by the remote document is removed
	 * first (browsers honor only the first one).
	 *
	 * @param string $html      HTML.
	 * @param string $base_href Absolute URL for the base href.
	 * @return string
	 */
	public function inject_base_tag( $html, $base_href ) {
		$href = esc_url( $base_href );

		if ( '' === $href ) {
			return $html;
		}

		$original = $html;
		$html     = preg_replace( '~<base\b[^>]*>~i', '', $html );

		if ( ! is_string( $html ) ) {
			$html = $original;
		}

		$tag = '<base href="' . $href . '">';

		if ( preg_match( '~<head\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos = $m[0][1] + strlen( $m[0][0] );
			return substr_replace( $html, "\n\t" . $tag, $pos, 0 );
		}

		if ( preg_match( '~<html\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos = $m[0][1] + strlen( $m[0][0] );
			return substr_replace( $html, "\n<head>\n\t" . $tag . "\n</head>", $pos, 0 );
		}

		return "<head>\n\t" . $tag . "\n</head>\n" . $html;
	}
}
