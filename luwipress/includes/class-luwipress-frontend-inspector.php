<?php
/**
 * LuwiPress Frontend Inspector
 *
 * Fetch a live URL with cache-bypass and dump structured data across
 * four scopes:
 *
 *   head    → title, canonical, robots, meta description / keywords /
 *             viewport / charset, hreflang chain, OpenGraph and Twitter
 *             card meta.
 *   content → body word count, heading hierarchy (h1..h6), image count
 *             with missing-alt count, internal vs external link counts,
 *             rough text/HTML ratio.
 *   meta    → http_status, response headers (cache layer markers,
 *             X-Robots-Tag, CSP, link Cache-Control), fetched_at, byte
 *             size.
 *   schema  → all <script type="application/ld+json"> blocks, parsed +
 *             summarised by @type. (Composes with Schema Registry's own
 *             diagnostic emitter — same parsing logic, broader payload.)
 *
 * Replaces ~5 chrome-devtools-mcp round-trips per audit with a single
 * MCP / REST call. Designed for daily SEO QA, post-write verification,
 * multilingual render parity probes and GMC pre-export checks.
 *
 * @package LuwiPress
 * @since 3.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Frontend_Inspector {

	private static $instance = null;

	const ALL_SCOPES = array( 'head', 'content', 'meta', 'schema' );

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );
	}

	public function register_rest_endpoints() {
		register_rest_route( 'luwipress/v1', '/frontend/render-dump', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'rest_render_dump' ),
			'permission_callback' => array( $this, 'permission_token' ),
		) );
	}

	public function permission_token( $request ) {
		return LuwiPress_Permission::check_token_or_admin( $request );
	}

	// ─── REST ──────────────────────────────────────────────────────────

	public function rest_render_dump( $request ) {
		$body = $request->get_json_params() ?: array();

		$url      = esc_url_raw( $request->get_param( 'url' )      ?: ( $body['url']      ?? '' ) );
		$post_id  = absint(      $request->get_param( 'post_id' )  ?: ( $body['post_id']  ?? 0 ) );
		$term_id  = absint(      $request->get_param( 'term_id' )  ?: ( $body['term_id']  ?? 0 ) );
		$taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) ?: ( $body['taxonomy'] ?? '' ) );

		$scopes_raw = $request->get_param( 'scopes' );
		if ( null === $scopes_raw ) {
			$scopes_raw = $body['scopes'] ?? null;
		}
		$scopes = $this->normalize_scopes( $scopes_raw );

		if ( ! $url && $post_id ) {
			$url = get_permalink( $post_id );
		}
		if ( ! $url && $term_id && $taxonomy ) {
			$link = get_term_link( $term_id, $taxonomy );
			if ( ! is_wp_error( $link ) ) {
				$url = $link;
			}
		}
		if ( ! $url ) {
			return new WP_Error( 'missing_url', 'url, post_id, or term_id+taxonomy required.', array( 'status' => 400 ) );
		}

		// Cache-bypass GET. Schema Registry uses the same shape — keep aligned.
		$fetch_url = add_query_arg( '_lwp_cb', time(), $url );
		$response  = wp_remote_get( $fetch_url, array(
			'timeout'     => 20,
			'redirection' => 5,
			'headers'     => array(
				'Cache-Control' => 'no-cache',
				'Pragma'        => 'no-cache',
			),
			'user-agent'  => 'LuwiPress-Frontend-Inspector/' . LUWIPRESS_VERSION,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fetch_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$status    = wp_remote_retrieve_response_code( $response );
		$html      = wp_remote_retrieve_body( $response );
		$headers   = wp_remote_retrieve_headers( $response );

		if ( $status >= 400 ) {
			return new WP_Error( 'http_error', 'Fetch returned HTTP ' . $status, array( 'status' => 502 ) );
		}

		$result = array(
			'url'         => $url,
			'fetched_url' => $fetch_url,
			'http_status' => $status,
			'fetched_at'  => current_time( 'mysql' ),
			'byte_size'   => strlen( $html ),
			'scopes'      => $scopes,
		);

		if ( in_array( 'meta', $scopes, true ) ) {
			$result['meta'] = $this->extract_meta( $headers );
		}

		// Parse HTML once if any DOM-dependent scope is requested.
		$dom_needed = array_intersect( $scopes, array( 'head', 'content' ) );
		$dom        = null;
		if ( ! empty( $dom_needed ) ) {
			$dom = $this->parse_html( $html );
		}

		if ( in_array( 'head', $scopes, true ) ) {
			$result['head'] = $dom ? $this->extract_head( $dom ) : $this->head_fallback( $html );
		}

		if ( in_array( 'content', $scopes, true ) ) {
			$result['content'] = $dom ? $this->extract_content( $dom, $url ) : $this->content_fallback( $html );
		}

		if ( in_array( 'schema', $scopes, true ) ) {
			$result['schema'] = $this->extract_schema( $html );
		}

		return rest_ensure_response( $result );
	}

	private function normalize_scopes( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		}
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return self::ALL_SCOPES;
		}
		$out = array();
		foreach ( $raw as $s ) {
			$s = sanitize_key( $s );
			if ( in_array( $s, self::ALL_SCOPES, true ) ) {
				$out[] = $s;
			}
		}
		return $out ?: self::ALL_SCOPES;
	}

	// ─── HTML PARSING ──────────────────────────────────────────────────

	private function parse_html( $html ) {
		if ( '' === $html ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		// loadHTML defaults to ISO-8859-1; force UTF-8 via meta hint prepend.
		$loaded = $dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $loaded ? $dom : null;
	}

	// ─── HEAD SCOPE ────────────────────────────────────────────────────

	private function extract_head( DOMDocument $dom ) {
		$xpath = new DOMXPath( $dom );

		$out = array(
			'title'            => '',
			'canonical'        => '',
			'robots'           => '',
			'meta_description' => '',
			'meta_keywords'    => '',
			'viewport'         => '',
			'charset'          => '',
			'hreflang'         => array(),
			'og'               => array(),
			'twitter'          => array(),
		);

		$title = $xpath->query( '//head/title' )->item( 0 );
		if ( $title ) {
			$out['title'] = trim( $title->textContent );
		}

		$canonical = $xpath->query( '//head/link[@rel="canonical"]' )->item( 0 );
		if ( $canonical instanceof DOMElement ) {
			$out['canonical'] = $canonical->getAttribute( 'href' );
		}

		// Meta name=… (case-insensitive: WordPress emits lowercase, OG uses property=)
		foreach ( $xpath->query( '//head/meta' ) as $meta ) {
			if ( ! $meta instanceof DOMElement ) {
				continue;
			}
			$name     = strtolower( $meta->getAttribute( 'name' ) );
			$property = strtolower( $meta->getAttribute( 'property' ) );
			$content  = $meta->getAttribute( 'content' );
			$charset  = $meta->getAttribute( 'charset' );

			if ( '' !== $charset && '' === $out['charset'] ) {
				$out['charset'] = $charset;
			}

			switch ( $name ) {
				case 'description':
					if ( '' === $out['meta_description'] ) {
						$out['meta_description'] = $content;
					}
					break;
				case 'keywords':
					$out['meta_keywords'] = $content;
					break;
				case 'robots':
					$out['robots'] = $content;
					break;
				case 'viewport':
					$out['viewport'] = $content;
					break;
				default:
					if ( 0 === strpos( $name, 'twitter:' ) ) {
						$out['twitter'][ $name ] = $content;
					}
			}

			if ( 0 === strpos( $property, 'og:' ) ) {
				$out['og'][ $property ] = $content;
			}
		}

		foreach ( $xpath->query( '//head/link[@rel="alternate"][@hreflang]' ) as $link ) {
			if ( ! $link instanceof DOMElement ) {
				continue;
			}
			$out['hreflang'][] = array(
				'hreflang' => $link->getAttribute( 'hreflang' ),
				'href'     => $link->getAttribute( 'href' ),
			);
		}

		return $out;
	}

	private function head_fallback( $html ) {
		// Minimal regex fallback used only if DOM parse fails entirely.
		$title = '';
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			$title = trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		return array(
			'title'            => $title,
			'canonical'        => '',
			'robots'           => '',
			'meta_description' => '',
			'meta_keywords'    => '',
			'viewport'         => '',
			'charset'          => '',
			'hreflang'         => array(),
			'og'               => array(),
			'twitter'          => array(),
			'_fallback'        => true,
		);
	}

	// ─── CONTENT SCOPE ─────────────────────────────────────────────────

	private function extract_content( DOMDocument $dom, $base_url ) {
		$xpath = new DOMXPath( $dom );

		// Heading counts h1..h6.
		$headings = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$nodes        = $xpath->query( '//body//h' . $i );
			$headings[ 'h' . $i ] = $nodes ? $nodes->length : 0;
		}

		// Images + alt presence.
		$img_total       = 0;
		$img_missing_alt = 0;
		foreach ( $xpath->query( '//body//img' ) as $img ) {
			if ( ! $img instanceof DOMElement ) {
				continue;
			}
			$img_total++;
			$alt = $img->getAttribute( 'alt' );
			if ( '' === trim( $alt ) ) {
				$img_missing_alt++;
			}
		}

		// Links internal vs external.
		$host_target  = parse_url( $base_url, PHP_URL_HOST );
		$link_internal = 0;
		$link_external = 0;
		$link_nofollow = 0;
		foreach ( $xpath->query( '//body//a[@href]' ) as $a ) {
			if ( ! $a instanceof DOMElement ) {
				continue;
			}
			$href = $a->getAttribute( 'href' );
			if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'javascript:' ) || 0 === strpos( $href, 'mailto:' ) ) {
				continue;
			}
			$host_link = parse_url( $href, PHP_URL_HOST );
			if ( $host_link && $host_target && strcasecmp( $host_link, $host_target ) !== 0 ) {
				$link_external++;
				$rel = strtolower( $a->getAttribute( 'rel' ) );
				if ( false !== strpos( $rel, 'nofollow' ) ) {
					$link_nofollow++;
				}
			} else {
				$link_internal++;
			}
		}

		// Word count: body text, scripts + styles stripped.
		$body_node = $xpath->query( '//body' )->item( 0 );
		$word_count = 0;
		$text_chars = 0;
		if ( $body_node ) {
			$clone = $body_node->cloneNode( true );
			// Strip script + style + noscript subtrees in the clone.
			$inner_xpath = new DOMXPath( $dom );
			foreach ( $inner_xpath->query( './/script | .//style | .//noscript', $clone ) as $strip ) {
				$strip->parentNode->removeChild( $strip );
			}
			$text       = trim( preg_replace( '/\s+/u', ' ', $clone->textContent ) );
			$text_chars = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
			if ( '' !== $text ) {
				$word_count = preg_match_all( '/[\p{L}\p{N}]+/u', $text, $unused );
			}
		}

		// Rough text:html ratio (visible text chars / total bytes). Useful for
		// content-thin detection; not a perfect metric but a fast signal.
		$html_bytes = strlen( $dom->saveHTML() );
		$ratio = $html_bytes > 0 ? round( $text_chars / $html_bytes, 4 ) : 0;

		return array(
			'word_count'      => (int) $word_count,
			'text_chars'      => (int) $text_chars,
			'headings'        => $headings,
			'images'          => array(
				'total'       => $img_total,
				'missing_alt' => $img_missing_alt,
			),
			'links'           => array(
				'internal' => $link_internal,
				'external' => $link_external,
				'nofollow' => $link_nofollow,
			),
			'text_html_ratio' => $ratio,
		);
	}

	private function content_fallback( $html ) {
		$text  = trim( wp_strip_all_tags( $html ) );
		return array(
			'word_count'      => $text ? str_word_count( $text ) : 0,
			'text_chars'      => function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text ),
			'headings'        => array(),
			'images'          => array( 'total' => 0, 'missing_alt' => 0 ),
			'links'           => array( 'internal' => 0, 'external' => 0, 'nofollow' => 0 ),
			'text_html_ratio' => 0,
			'_fallback'       => true,
		);
	}

	// ─── META SCOPE ────────────────────────────────────────────────────

	/**
	 * Extract response headers that matter for cache / SEO diagnostics.
	 */
	private function extract_meta( $headers ) {
		$collected = array();
		$want      = array(
			'cache-control',
			'x-cache',
			'x-cache-status',
			'x-litespeed-cache',
			'x-litespeed-cache-control',
			'cf-cache-status',
			'x-robots-tag',
			'content-type',
			'content-language',
			'link',
			'age',
			'expires',
			'last-modified',
			'etag',
		);

		// WP_HTTP_Requests_Response Headers implements ArrayAccess + iteration.
		foreach ( $want as $key ) {
			if ( isset( $headers[ $key ] ) ) {
				$val = $headers[ $key ];
				if ( is_array( $val ) ) {
					$val = implode( ', ', $val );
				}
				$collected[ $key ] = $val;
			}
		}

		return $collected;
	}

	// ─── SCHEMA SCOPE ──────────────────────────────────────────────────

	/**
	 * Same parser shape as LuwiPress_Schema_Registry::rest_diagnostic_render
	 * keeps clients that compose both endpoints consistent.
	 */
	private function extract_schema( $html ) {
		$blocks = array();
		if ( preg_match_all( '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches ) ) {
			foreach ( $matches[1] as $idx => $json_str ) {
				$json_str = trim( $json_str );
				if ( '' === $json_str ) {
					continue;
				}
				$decoded  = json_decode( $json_str, true );
				$blocks[] = array(
					'index'     => $idx,
					'valid'     => null !== $decoded,
					'parsed'    => $decoded,
					'byte_size' => strlen( $json_str ),
				);
			}
		}

		$summary = array();
		foreach ( $blocks as $b ) {
			if ( ! $b['valid'] || ! is_array( $b['parsed'] ) ) {
				continue;
			}
			if ( isset( $b['parsed']['@graph'] ) && is_array( $b['parsed']['@graph'] ) ) {
				foreach ( $b['parsed']['@graph'] as $node ) {
					if ( ! empty( $node['@type'] ) ) {
						$t = is_array( $node['@type'] ) ? implode( '|', $node['@type'] ) : $node['@type'];
						$summary[] = $t;
					}
				}
			} elseif ( ! empty( $b['parsed']['@type'] ) ) {
				$t = is_array( $b['parsed']['@type'] ) ? implode( '|', $b['parsed']['@type'] ) : $b['parsed']['@type'];
				$summary[] = $t;
			}
		}

		return array(
			'block_count'  => count( $blocks ),
			'schema_types' => array_values( array_unique( $summary ) ),
			'blocks'       => $blocks,
		);
	}
}
