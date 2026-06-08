<?php
/**
 * LuwiPress Content Audit
 *
 * Promotional-phrase detection across post titles, meta titles, meta
 * descriptions, excerpts and body content. Built for daily Tapadum-style
 * QA: catch Google Merchant Center disapproval risks BEFORE the feed
 * goes out (GMC bans urgency/sale-pressure language in product titles
 * and descriptions; the body is allowed but still surfaced as a soft
 * finding for editorial cleanup).
 *
 * Severity rubric:
 *   high   → promotional phrase in meta_title or meta_description
 *            (GMC-prohibited; will trigger disapproval).
 *   medium → promotional phrase in post_title or post_excerpt
 *            (commonly syndicated to product feed by product feed plugins).
 *   low    → promotional phrase in post_content body (allowed for CTAs
 *            but flagged so editors can sweep when desired).
 *
 * Multilingual: detection uses Unicode-aware word boundaries so Turkish
 * (ı/ş/ğ), French/Italian/Spanish diacritics and CJK all match cleanly.
 * The phrase bank is per-language; the audit auto-detects the language
 * of each scanned post via WPML/Polylang and applies the matching bank.
 *
 * Extending the phrase bank:
 *     add_filter('luwipress_content_audit_phrases', function($bank, $lang) {
 *         $bank['en'][] = 'fire sale';
 *         return $bank;
 *     }, 10, 2);
 *
 * @package LuwiPress
 * @since 3.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Content_Audit {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );

		// Feed AI-tell + promotional-phrase findings into the KG Action Queue /
		// Next Wins so they're actionable from the Knowledge Graph, not just the
		// standalone Health Audit page. Cached 1h inside inject_kg_candidates().
		add_filter( 'luwipress_kg_action_queue_external_candidates', array( $this, 'inject_kg_candidates' ) );
	}

	public function register_rest_endpoints() {
		register_rest_route( 'luwipress/v1', '/content/promotional-phrase-audit', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'rest_promotional_phrase_audit' ),
			'permission_callback' => array( $this, 'permission_token' ),
		) );

		register_rest_route( 'luwipress/v1', '/content/promotional-phrase-bank', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_phrase_bank' ),
			'permission_callback' => array( $this, 'permission_token' ),
		) );

		// AI-Tell scanner (3.5.4+) — surfaces stock LLM phrasings ("In the
		// world of...", "stands as one of the most...", "In conclusion,"
		// etc.) that Google's 2024+ Helpful Content Update flags as low-
		// quality, machine-generated boilerplate. Same scanning machinery
		// as the promotional-phrase audit, different phrase bank.
		register_rest_route( 'luwipress/v1', '/content/ai-tell-audit', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'rest_ai_tell_audit' ),
			'permission_callback' => array( $this, 'permission_token' ),
		) );

		register_rest_route( 'luwipress/v1', '/content/ai-tell-bank', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_ai_tell_bank' ),
			'permission_callback' => array( $this, 'permission_token' ),
		) );
	}

	public function permission_token( $request ) {
		return LuwiPress_Permission::check_token_or_admin( $request );
	}

	// ─── PHRASE BANK ───────────────────────────────────────────────────

	/**
	 * Returns the canonical promotional-phrase bank, keyed by language.
	 *
	 * Coverage focuses on phrases Google Merchant Center explicitly flags
	 * as disapproval triggers, plus common urgency / sale-pressure idioms
	 * across en/tr/fr/it/es (Tapadum's active markets). Operators extend
	 * via the `luwipress_content_audit_phrases` filter.
	 */
	public function get_phrase_bank() {
		$bank = array(
			'en' => array(
				'free shipping', 'free delivery', 'free returns',
				'click here', 'shop now', 'buy now', 'order now', 'add to cart now',
				'best price', 'cheapest', 'lowest price', 'unbeatable price',
				'limited time', 'limited offer', 'limited stock', 'while supplies last',
				'today only', 'only today', 'last chance', 'last day',
				"don't miss", 'don’t miss', 'must have', 'must-have',
				'act fast', 'act now', 'hurry', 'hurry up',
				'guaranteed', '100% guarantee', '100% guaranteed',
				'huge sale', 'mega sale', 'biggest sale', 'flash sale',
				'on sale now', 'sale ends', 'discount code',
			),
			'tr' => array(
				'ücretsiz kargo', 'kargo bedava', 'iade ücretsiz',
				'tıkla', 'şimdi al', 'hemen al', 'sepete ekle',
				'en ucuz', 'en iyi fiyat', 'kaçırılmaz fiyat',
				'sınırlı süre', 'sınırlı stok', 'son fırsat', 'son gün',
				'sadece bugün', 'bugüne özel', 'kaçırma', 'kaçırmayın',
				'acele edin', 'acele et', 'hemen sipariş',
				'%100 garanti', 'kesin garanti',
				'büyük indirim', 'mega indirim', 'flaş indirim',
				'indirim kodu', 'kampanya bitiyor',
			),
			'fr' => array(
				'livraison gratuite', 'retours gratuits',
				'cliquez ici', 'achetez maintenant', 'commandez maintenant',
				'meilleur prix', 'prix imbattable', 'le moins cher',
				'offre limitée', 'stock limité', 'durée limitée',
				"aujourd'hui seulement", 'aujourd’hui seulement', 'dernière chance',
				'ne ratez pas', 'à ne pas manquer', 'incontournable',
				'dépêchez-vous', 'agissez vite',
				'garanti 100%', '100% garanti',
				'grande vente', 'soldes flash', 'vente flash',
			),
			'it' => array(
				'spedizione gratuita', 'consegna gratuita', 'reso gratuito',
				'clicca qui', 'acquista ora', 'compra ora', 'ordina ora',
				'miglior prezzo', 'prezzo più basso', 'prezzo imbattibile',
				'offerta limitata', 'tempo limitato', 'scorte limitate',
				'solo oggi', 'ultima occasione', 'ultimo giorno',
				'non perdere', 'da non perdere', 'imperdibile',
				'affrettati', 'affrettatevi',
				'garantito al 100%', '100% garantito',
				'grande saldo', 'super saldo', 'saldi lampo',
			),
			'es' => array(
				'envío gratis', 'envío gratuito', 'devolución gratis',
				'haz clic', 'compra ahora', 'pide ahora', 'ordena ahora',
				'mejor precio', 'precio más bajo', 'precio imbatible',
				'oferta limitada', 'tiempo limitado', 'stock limitado',
				'solo hoy', 'última oportunidad', 'último día',
				'no te lo pierdas', 'imperdible',
				'apresúrate', 'date prisa',
				'garantizado 100%', '100% garantizado',
				'gran venta', 'mega oferta', 'oferta flash',
			),
		);

		/**
		 * Filter the promotional-phrase bank.
		 *
		 * @param array  $bank Phrase bank keyed by language code.
		 * @param string $lang Currently-requested language ('*' on full-bank reads).
		 */
		return apply_filters( 'luwipress_content_audit_phrases', $bank, '*' );
	}

	public function rest_phrase_bank( $request ) {
		$bank   = $this->get_phrase_bank();
		$totals = array();
		foreach ( $bank as $lang => $phrases ) {
			$totals[ $lang ] = count( $phrases );
		}
		return rest_ensure_response( array(
			'languages' => array_keys( $bank ),
			'counts'    => $totals,
			'bank'      => $bank,
		) );
	}

	// ─── AUDIT ─────────────────────────────────────────────────────────

	/**
	 * POST /content/promotional-phrase-audit
	 *
	 * Params (all optional):
	 *   post_id        int    — audit a single post only
	 *   post_type      string — filter by post type (default 'product')
	 *   category_id    int    — filter by product_cat term
	 *   lang           string — force language; default = per-post detect
	 *   scope          string — meta | body | all (default 'all')
	 *   limit          int    — max posts to scan (default 50, max 500)
	 *   offset         int    — pagination offset
	 *   only_violations bool  — return only posts with violations (default true)
	 */
	public function rest_promotional_phrase_audit( $request ) {
		$body = $request->get_json_params() ?: array();

		$post_id        = absint( $request->get_param( 'post_id' ) ?: ( $body['post_id'] ?? 0 ) );
		$post_type      = sanitize_key( $request->get_param( 'post_type' ) ?: ( $body['post_type'] ?? 'product' ) );
		$category_id    = absint( $request->get_param( 'category_id' ) ?: ( $body['category_id'] ?? 0 ) );
		$lang_force     = sanitize_key( $request->get_param( 'lang' ) ?: ( $body['lang'] ?? '' ) );
		$scope          = sanitize_key( $request->get_param( 'scope' ) ?: ( $body['scope'] ?? 'all' ) );
		$limit          = absint( $request->get_param( 'limit' ) ?: ( $body['limit'] ?? 50 ) );
		$offset         = absint( $request->get_param( 'offset' ) ?: ( $body['offset'] ?? 0 ) );
		$only_violations_param = $request->get_param( 'only_violations' );
		if ( null === $only_violations_param ) {
			$only_violations_param = $body['only_violations'] ?? true;
		}
		$only_violations = rest_sanitize_boolean( $only_violations_param );

		$limit = max( 1, min( 500, $limit ) );
		if ( ! in_array( $scope, array( 'meta', 'body', 'all' ), true ) ) {
			$scope = 'all';
		}

		// Resolve target post list.
		if ( $post_id ) {
			$posts = array( get_post( $post_id ) );
			$posts = array_filter( $posts );
		} else {
			$args = array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'fields'         => 'all',
			);
			if ( $category_id && 'product' === $post_type ) {
				$args['tax_query'] = array(
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => $category_id,
					),
				);
			}
			$query = new WP_Query( $args );
			$posts = $query->posts;
		}

		$bank = $this->get_phrase_bank();

		$violations         = array();
		$total_findings     = 0;
		$scanned            = 0;
		$with_violations    = 0;

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$scanned++;

			$lang = $lang_force ?: $this->detect_post_language( $post->ID );
			$phrases = isset( $bank[ $lang ] ) ? $bank[ $lang ] : ( $bank['en'] ?? array() );

			$fields = $this->collect_fields( $post, $scope );

			$findings = array();
			foreach ( $fields as $field_name => $field_data ) {
				$matches = $this->find_phrases( $field_data['text'], $phrases );
				foreach ( $matches as $m ) {
					$findings[] = array(
						'phrase'   => $m['phrase'],
						'field'    => $field_name,
						'severity' => $field_data['severity'],
						'context'  => $m['context'],
						'offset'   => $m['offset'],
					);
				}
			}

			if ( empty( $findings ) ) {
				if ( $only_violations ) {
					continue;
				}
			} else {
				$with_violations++;
				$total_findings += count( $findings );
			}

			$violations[] = array(
				'post_id'        => $post->ID,
				'post_title'     => get_the_title( $post ),
				'post_type'      => $post->post_type,
				'permalink'      => get_permalink( $post ),
				'lang'           => $lang,
				'finding_count'  => count( $findings ),
				'findings'       => $findings,
			);
		}

		return rest_ensure_response( array(
			'scope'             => $scope,
			'post_type'         => $post_type,
			'scanned'           => $scanned,
			'with_violations'   => $with_violations,
			'total_findings'    => $total_findings,
			'next_offset'       => $post_id ? null : ( $offset + $scanned ),
			'results'           => $violations,
		) );
	}

	// ─── FIELD COLLECTION ──────────────────────────────────────────────

	/**
	 * Pull text to scan, tagged with severity per GMC sensitivity.
	 * Returns array of field_name => ['text' => string, 'severity' => string].
	 *
	 * Public (3.5.6+) so the Health Score Brand Voice pillar can call it
	 * directly instead of going through ReflectionMethod — sequential
	 * Reflection lookups inside a 200-post loop were measurably slowing
	 * cold compute() (~100x slower than direct call per invocation, with
	 * 3 reflections per post). The method is side-effect-free; promoting
	 * it costs nothing and frees the hot path.
	 */
	public function collect_fields( $post, $scope ) {
		$fields = array();

		if ( 'body' !== $scope ) {
			// META — high severity (GMC-prohibited).
			$meta_title = $this->get_seo_meta_title( $post->ID );
			$meta_desc  = $this->get_seo_meta_description( $post->ID );
			if ( '' !== $meta_title ) {
				$fields['meta_title'] = array( 'text' => $meta_title, 'severity' => 'high' );
			}
			if ( '' !== $meta_desc ) {
				$fields['meta_description'] = array( 'text' => $meta_desc, 'severity' => 'high' );
			}

			// post_title + excerpt — medium (feed-synced fallback when meta missing).
			$fields['post_title'] = array( 'text' => $post->post_title, 'severity' => 'medium' );
			if ( '' !== trim( (string) $post->post_excerpt ) ) {
				$fields['post_excerpt'] = array( 'text' => $post->post_excerpt, 'severity' => 'medium' );
			}
		}

		if ( 'meta' !== $scope ) {
			$body_text = wp_strip_all_tags( (string) $post->post_content );
			$fields['post_content'] = array( 'text' => $body_text, 'severity' => 'low' );
		}

		return $fields;
	}

	private function get_seo_meta_title( $post_id ) {
		$candidates = array(
			'rank_math_title',
			'_yoast_wpseo_title',
			'_aioseo_title',
			'_seopress_titles_title',
		);
		foreach ( $candidates as $key ) {
			$v = get_post_meta( $post_id, $key, true );
			if ( is_string( $v ) && '' !== $v ) {
				return $v;
			}
		}
		return '';
	}

	private function get_seo_meta_description( $post_id ) {
		$candidates = array(
			'rank_math_description',
			'_yoast_wpseo_metadesc',
			'_aioseo_description',
			'_seopress_titles_desc',
		);
		foreach ( $candidates as $key ) {
			$v = get_post_meta( $post_id, $key, true );
			if ( is_string( $v ) && '' !== $v ) {
				return $v;
			}
		}
		return '';
	}

	// ─── MATCHER ───────────────────────────────────────────────────────

	/**
	 * Returns array of matches in $text against $phrases.
	 * Each match: ['phrase' => string, 'context' => string, 'offset' => int].
	 *
	 * Matching is case-insensitive, Unicode-aware. Word boundaries use
	 * negative lookbehind/lookahead on \p{L}\p{N} so Turkish (ı/ş/ğ) and
	 * Latin-with-diacritics match cleanly without false positives inside
	 * compound words.
	 *
	 * Public (3.5.6+) — see note on collect_fields(). Direct method call
	 * from Health Score::measure_brand_voice() replaces a Reflection
	 * round-trip per phrase scan.
	 */
	public function find_phrases( $text, $phrases ) {
		$results = array();
		if ( '' === $text || empty( $phrases ) ) {
			return $results;
		}

		// 3.5.6+: compile a single alternation pattern per unique phrase list
		// and cache it. The previous shape compiled one pattern PER PHRASE
		// PER POST PER FIELD — on a 100-post audit with 140 phrases × 5
		// fields that was ~70k preg_quote+preg_match_all cycles. Now: one
		// compile per unique phrase list (banks are stable per session), one
		// preg_match_all per field, with the matched phrase recovered by
		// case-insensitive lookup against the original phrase array.
		$compiled = $this->compile_phrase_pattern( $phrases );
		if ( null === $compiled ) {
			return $results;
		}

		if ( ! preg_match_all( $compiled['pattern'], $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $results;
		}

		$lookup = $compiled['lookup']; // lowercased-phrase => canonical phrase
		foreach ( $matches[0] as $hit ) {
			$raw     = $hit[0];
			$key     = function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw, 'UTF-8' ) : strtolower( $raw );
			$canon   = $lookup[ $key ] ?? $raw;
			$results[] = array(
				'phrase'  => $canon,
				'context' => $this->build_context( $text, $hit[1], strlen( $raw ) ),
				'offset'  => $hit[1],
			);
			if ( count( $results ) >= 50 ) {
				return $results; // hard cap per field — keep payload sane
			}
		}
		return $results;
	}

	/**
	 * Compile a list of phrases into a single Unicode-aware alternation
	 * pattern with a side-table that maps lowercased matched text back to
	 * the canonical phrase as written in the bank. Cached per-process by
	 * the phrase-list signature so the bank doesn't recompile on every
	 * audit call.
	 *
	 * @param string[] $phrases
	 * @return array{pattern:string,lookup:array<string,string>}|null
	 */
	private function compile_phrase_pattern( $phrases ) {
		static $cache = array();

		// Normalise + dedupe the input first so two callers with the same
		// banks share a cache slot regardless of order.
		$normalised = array();
		foreach ( $phrases as $p ) {
			$p = trim( (string) $p );
			if ( '' === $p ) {
				continue;
			}
			$normalised[] = $p;
		}
		if ( empty( $normalised ) ) {
			return null;
		}
		sort( $normalised );
		$normalised = array_values( array_unique( $normalised ) );

		$signature = md5( implode( '|', $normalised ) );
		if ( isset( $cache[ $signature ] ) ) {
			return $cache[ $signature ];
		}

		// Build a lookup that maps the LOWERCASED phrase back to its
		// canonical (bank-original) form so callers receive the phrase
		// as it appears in the bank, not the casing the source text used.
		$lookup = array();
		$quoted = array();
		foreach ( $normalised as $p ) {
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $p, 'UTF-8' ) : strtolower( $p );
			$lookup[ $key ] = $p;
			$quoted[]       = preg_quote( $p, '/' );
		}

		$pattern = '/(?<![\p{L}\p{N}])(?:' . implode( '|', $quoted ) . ')(?![\p{L}\p{N}])/iu';
		$cache[ $signature ] = array(
			'pattern' => $pattern,
			'lookup'  => $lookup,
		);
		return $cache[ $signature ];
	}

	private function build_context( $text, $offset, $len ) {
		$pad = 40;
		$start = max( 0, $offset - $pad );
		$end   = min( strlen( $text ), $offset + $len + $pad );
		$chunk = substr( $text, $start, $end - $start );
		if ( $start > 0 ) {
			$chunk = '…' . $chunk;
		}
		if ( $end < strlen( $text ) ) {
			$chunk = $chunk . '…';
		}
		return $chunk;
	}

	// ─── LANGUAGE DETECT ───────────────────────────────────────────────

	/**
	 * Resolve a post's language code via WPML, Polylang, or site locale.
	 *
	 * Public (3.5.6+) — see note on collect_fields(). Direct method call
	 * from Health Score::measure_brand_voice() replaces a Reflection
	 * round-trip per scanned post.
	 */
	public function detect_post_language( $post_id ) {
		// WPML
		if ( has_filter( 'wpml_post_language_details' ) ) {
			$info = apply_filters( 'wpml_post_language_details', null, $post_id );
			if ( is_array( $info ) && ! empty( $info['language_code'] ) ) {
				return sanitize_key( $info['language_code'] );
			}
		}
		// Polylang
		if ( function_exists( 'pll_get_post_language' ) ) {
			$code = pll_get_post_language( $post_id, 'slug' );
			if ( is_string( $code ) && '' !== $code ) {
				return sanitize_key( $code );
			}
		}
		// Fallback: site locale.
		$locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		return sanitize_key( substr( $locale, 0, 2 ) );
	}

	// ─── AI-TELL BANK (3.5.4+) ─────────────────────────────────────────
	//
	// "AI-tells" are stock LLM phrasings that 2024+ Helpful Content Update
	// flags as low-quality machine-generated boilerplate. The bank below is
	// the canonical list from the Tapadum SEO writing guide §1.8 ("Yasak
	// Kalıplar — AI-Tell Blacklist"). Operators extend via the
	// `luwipress_content_audit_ai_tells` filter, same shape as the
	// promotional bank.
	//
	// We deliberately keep this separate from the promotional bank so
	// reporting can distinguish "this Trips GMC disapproval" (promo) from
	// "this looks AI-generated" (HCU risk) — same scanner, different
	// downstream UX.

	public function get_ai_tell_bank() {
		$bank = array(
			'en' => array(
				// Opening clichés
				'in the world of',
				'stands as one of the most',
				'welcome to our store',
				"let's delve into",
				"let's explore the world of",
				'unlock the rhythmic possibilities',
				'unlock the magic',
				'discover the magic of',
				// Overused adjective phrases
				'mesmerizing tones',
				'enchanting sounds',
				'mystical sound',
				'magical experience',
				'breathtaking craftsmanship',
				// Conclusion clichés
				'in conclusion,',
				'in summary,',
				'to sum up,',
				'at the end of the day,',
				'all in all,',
				// Promotional fluff (HCU-flagged, not GMC)
				'unleash your creativity',
				'your gateway to',
				'your ultimate',
				'elevate your',
				// Generic transitions
				"it's worth noting that",
				'when it comes to',
				'needless to say',
				'rest assured',
			),
			'tr' => array(
				'müziğin dünyasında',
				'müziğin büyülü dünyasına',
				'en büyüleyici',
				'eşsiz bir deneyim',
				'kendinden geçirici',
				'efsanevi sesler',
				'mistik tınılar',
				'sonuç olarak,',
				'özetle,',
				'kısacası,',
				'hayalinizdeki',
				'mutlaka denemelisiniz',
				'kaçırılmayacak',
				'üst seviyeye taşıyın',
			),
			'fr' => array(
				'dans le monde de',
				"l'un des plus captivants",
				'bienvenue dans notre boutique',
				'plongeons dans',
				'sons envoûtants',
				'tonalités envoûtantes',
				'expérience magique',
				'en conclusion,',
				'pour résumer,',
				'en somme,',
				'libérez votre créativité',
				'à ne pas manquer',
				'élevez votre',
			),
			'it' => array(
				'nel mondo della musica',
				"uno dei più affascinanti",
				'benvenuto nel nostro negozio',
				'immergiamoci',
				'suoni avvolgenti',
				'tonalità ammaliante',
				'esperienza magica',
				'in conclusione,',
				'in sintesi,',
				'in definitiva,',
				'libera la tua creatività',
				'imperdibile esperienza',
				'eleva il tuo',
			),
			'es' => array(
				'en el mundo de la música',
				'uno de los más cautivadores',
				'bienvenido a nuestra tienda',
				'sumerjámonos',
				'sonidos cautivadores',
				'tonalidades envolventes',
				'experiencia mágica',
				'en conclusión,',
				'en resumen,',
				'en definitiva,',
				'libera tu creatividad',
				'eleva tu',
			),
		);

		/**
		 * Filter the AI-tell phrase bank.
		 *
		 * @param array  $bank Phrase bank keyed by language code.
		 * @param string $lang Currently-requested language ('*' on full-bank reads).
		 */
		return apply_filters( 'luwipress_content_audit_ai_tells', $bank, '*' );
	}

	public function rest_ai_tell_bank( $request ) {
		$bank   = $this->get_ai_tell_bank();
		$totals = array();
		foreach ( $bank as $lang => $phrases ) {
			$totals[ $lang ] = count( $phrases );
		}
		return rest_ensure_response( array(
			'kind'      => 'ai_tell',
			'languages' => array_keys( $bank ),
			'counts'    => $totals,
			'bank'      => $bank,
		) );
	}

	/**
	 * REST: scan posts for AI-tell phrasings. Same request shape as the
	 * promotional-phrase audit; switches phrase bank.
	 *
	 * Severity rubric (different from promo): ALL AI-tell matches are
	 * editorial soft-flags ("medium") — they're not GMC-prohibited like
	 * urgency language, they're HCU-risk patterns. Operators decide what
	 * to rewrite based on context.
	 */
	public function rest_ai_tell_audit( $request ) {
		$body = $request->get_json_params() ?: array();

		$post_id        = absint( $request->get_param( 'post_id' ) ?: ( $body['post_id'] ?? 0 ) );
		$post_type      = sanitize_key( $request->get_param( 'post_type' ) ?: ( $body['post_type'] ?? 'post' ) );
		$category_id    = absint( $request->get_param( 'category_id' ) ?: ( $body['category_id'] ?? 0 ) );
		$lang_force     = sanitize_key( $request->get_param( 'lang' ) ?: ( $body['lang'] ?? '' ) );
		$scope          = sanitize_key( $request->get_param( 'scope' ) ?: ( $body['scope'] ?? 'all' ) );
		$limit          = absint( $request->get_param( 'limit' ) ?: ( $body['limit'] ?? 50 ) );
		$offset         = absint( $request->get_param( 'offset' ) ?: ( $body['offset'] ?? 0 ) );
		$only_violations_param = $request->get_param( 'only_violations' );
		if ( null === $only_violations_param ) {
			$only_violations_param = $body['only_violations'] ?? true;
		}
		$only_violations = rest_sanitize_boolean( $only_violations_param );

		$limit = max( 1, min( 500, $limit ) );
		if ( ! in_array( $scope, array( 'meta', 'body', 'all' ), true ) ) {
			$scope = 'all';
		}

		// Resolve target post list (identical pattern to promo audit).
		if ( $post_id ) {
			$posts = array( get_post( $post_id ) );
			$posts = array_filter( $posts );
		} else {
			$args = array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'fields'         => 'all',
			);
			if ( $category_id && 'product' === $post_type ) {
				$args['tax_query'] = array(
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => $category_id,
					),
				);
			}
			$query = new WP_Query( $args );
			$posts = $query->posts;
		}

		$bank = $this->get_ai_tell_bank();

		$violations      = array();
		$total_findings  = 0;
		$scanned         = 0;
		$with_violations = 0;

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$scanned++;

			$lang    = $lang_force ?: $this->detect_post_language( $post->ID );
			$phrases = isset( $bank[ $lang ] ) ? $bank[ $lang ] : ( $bank['en'] ?? array() );

			$fields   = $this->collect_fields( $post, $scope );
			$findings = array();
			foreach ( $fields as $field_name => $field_data ) {
				$matches = $this->find_phrases( $field_data['text'], $phrases );
				foreach ( $matches as $m ) {
					$findings[] = array(
						'phrase'   => $m['phrase'],
						'field'    => $field_name,
						// AI-tells are uniformly editorial — soft-flag everywhere.
						'severity' => 'medium',
						'context'  => $m['context'],
						'offset'   => $m['offset'],
					);
				}
			}

			if ( empty( $findings ) ) {
				if ( $only_violations ) {
					continue;
				}
			} else {
				$with_violations++;
				$total_findings += count( $findings );
			}

			$violations[] = array(
				'post_id'       => $post->ID,
				'post_title'    => get_the_title( $post ),
				'post_type'     => $post->post_type,
				'permalink'     => get_permalink( $post ),
				'lang'          => $lang,
				'finding_count' => count( $findings ),
				'findings'      => $findings,
			);
		}

		return rest_ensure_response( array(
			'kind'             => 'ai_tell',
			'scope'            => $scope,
			'post_type'        => $post_type,
			'scanned'          => $scanned,
			'with_violations'  => $with_violations,
			'total_findings'   => $total_findings,
			'next_offset'      => $post_id ? null : ( $offset + $scanned ),
			'results'          => $violations,
		) );
	}

	// ─── KG Action Queue integration ─────────────────────────────────────

	/**
	 * Feed AI-tell + promotional-phrase findings into the KG Action Queue as
	 * Next Wins candidates. Hooked on `luwipress_kg_action_queue_external_candidates`.
	 *
	 * Cached 1h in `luwipress_content_audit_kg_candidates` so the dashboard never
	 * re-scans content on every Knowledge Graph load. Each candidate carries a
	 * cta_url deep-linking to the matching Health Audit sub-tab for the fix.
	 *
	 * @since 3.7.4
	 * @param array $candidates Existing candidates.
	 * @return array
	 */
	public function inject_kg_candidates( $candidates ) {
		if ( ! is_array( $candidates ) ) {
			$candidates = array();
		}
		$cache_key = 'luwipress_content_audit_kg_candidates';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return array_merge( $candidates, $cached );
		}

		$out      = array();
		$is_wc    = post_type_exists( 'product' );
		$pt       = $is_wc ? 'product' : 'post';
		$pt_label = $is_wc ? __( 'products', 'luwipress' ) : __( 'posts', 'luwipress' );
		$base_cta = admin_url( 'admin.php?page=luwipress-site&tab=audit' );

		// AI-Tell scanner — stock LLM phrasing (Helpful Content Update risk).
		$ai = $this->run_audit_for_kg( 'ai_tell', $pt );
		if ( $ai && ! empty( $ai['with_violations'] ) ) {
			$n      = (int) $ai['with_violations'];
			$find   = (int) ( $ai['total_findings'] ?? $n );
			$scan   = max( 1, (int) ( $ai['scanned'] ?? 1 ) );
			$ratio  = $n / $scan;
			$tier   = $ratio >= 0.4 ? 'high' : ( $ratio >= 0.15 ? 'medium' : 'low' );
			$impact = $ratio >= 0.4 ? 68 : ( $ratio >= 0.15 ? 52 : 38 );
			$out[]  = array(
				'id'         => 'content-audit:ai-tell',
				'type'       => 'content_ai_tell',
				'title'      => __( 'AI-tell phrasing flagged', 'luwipress' ),
				'body'       => sprintf(
					/* translators: 1: flagged count, 2: scanned count, 3: post-type label, 4: total findings */
					__( '%1$d of %2$d scanned %3$s show stock LLM phrasing (%4$d findings). Rewrite for a proprietary voice — Helpful Content risk.', 'luwipress' ),
					$n, $scan, $pt_label, $find
				),
				'detail'     => '',
				'impact'     => $impact,
				'effort_min' => 20,
				'roi'        => $impact / 20,
				'tier'       => $tier,
				'workflow'   => 'content-audit',
				'cta_url'    => add_query_arg( 'sub', 'ai-tell', $base_cta ),
				'why'        => array(
					'primary_signal'      => sprintf( '%d/%d %s flagged for AI-tells', $n, $scan, $pt_label ),
					'supporting_signals'  => array(),
					'baseline_comparison' => null,
				),
			);
		}

		// Promotional phrases — Google Merchant Center disapproval risk.
		$promo = $this->run_audit_for_kg( 'promotional', $pt );
		if ( $promo && ! empty( $promo['with_violations'] ) ) {
			$n     = (int) $promo['with_violations'];
			$find  = (int) ( $promo['total_findings'] ?? $n );
			$scan  = max( 1, (int) ( $promo['scanned'] ?? 1 ) );
			$out[] = array(
				'id'         => 'content-audit:promotional',
				'type'       => 'content_promotional_phrase',
				'title'      => __( 'Promotional phrases (GMC risk)', 'luwipress' ),
				'body'       => sprintf(
					/* translators: 1: flagged count, 2: scanned count, 3: post-type label, 4: total findings */
					__( '%1$d of %2$d scanned %3$s contain promotional / urgency language (%4$d flags) that can trigger Google Merchant Center disapproval.', 'luwipress' ),
					$n, $scan, $pt_label, $find
				),
				'detail'     => '',
				'impact'     => 72,
				'effort_min' => 15,
				'roi'        => 72 / 15,
				'tier'       => 'high',
				'workflow'   => 'content-audit',
				'cta_url'    => add_query_arg( 'sub', 'promotional', $base_cta ),
				'why'        => array(
					'primary_signal'      => sprintf( '%d/%d %s with promotional phrases', $n, $scan, $pt_label ),
					'supporting_signals'  => array(),
					'baseline_comparison' => null,
				),
			);
		}

		set_transient( $cache_key, $out, HOUR_IN_SECONDS );
		return array_merge( $candidates, $out );
	}

	/**
	 * Run one audit scanner internally (no HTTP round-trip) for KG candidate
	 * building. Returns the response data array, or null on failure.
	 *
	 * @since 3.7.4
	 * @param string $kind      'ai_tell' | 'promotional'.
	 * @param string $post_type Post type to sample.
	 * @return array|null
	 */
	private function run_audit_for_kg( $kind, $post_type ) {
		$method = ( 'promotional' === $kind ) ? 'rest_promotional_phrase_audit' : 'rest_ai_tell_audit';
		$route  = ( 'promotional' === $kind )
			? '/luwipress/v1/content/promotional-phrase-audit'
			: '/luwipress/v1/content/ai-tell-audit';
		try {
			$req = new WP_REST_Request( 'POST', $route );
			$req->set_param( 'post_type', $post_type );
			$req->set_param( 'scope', 'all' );
			$req->set_param( 'limit', 100 );
			$req->set_param( 'only_violations', true );
			$res = $this->{$method}( $req );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( is_wp_error( $res ) ) {
			return null;
		}
		return ( $res instanceof WP_REST_Response ) ? (array) $res->get_data() : (array) $res;
	}
}
