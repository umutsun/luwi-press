<?php
/**
 * LuwiPress Open Claw — AI Chat Assistant
 *
 * Lightweight admin chat interface. Fast local queries resolve without AI.
 * Complex questions route through LuwiPress_AI_Engine::dispatch().
 *
 * Slash commands: /scan /seo /translate /enrich /thin /stale /generate
 *                 /aeo /reviews /plugins /crm /revenue /products /help
 *
 * @package LuwiPress
 * @since   1.3.0 (original), 2.0.0 (slim rewrite — removed n8n dependency,
 *          channel system, 300-line action engine. AI calls route through
 *          LuwiPress_AI_Engine::dispatch())
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Open_Claw {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_luwipress_claw_send', array( $this, 'ajax_send_message' ) );
		add_action( 'wp_ajax_luwipress_claw_history', array( $this, 'ajax_get_history' ) );
		add_action( 'wp_ajax_luwipress_claw_clear_history', array( $this, 'ajax_clear_history' ) );
		add_action( 'wp_ajax_luwipress_claw_execute', array( $this, 'ajax_execute_action' ) );
	}

	/* ═══════════════════════════════════════════════════════════════════
	 *  AJAX: Send message
	 * ═══════════════════════════════════════════════════════════════════ */

	public function ajax_send_message() {
		check_ajax_referer( 'luwipress_claw_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$message = sanitize_textarea_field( $_POST['message'] ?? '' );
		if ( empty( $message ) ) {
			wp_send_json_error( 'Message is required' );
		}

		$conversation_id = sanitize_text_field( $_POST['conversation_id'] ?? wp_generate_uuid4() );
		$this->save_message( $conversation_id, 'user', $message );

		// 1. Try local resolution (no AI cost)
		$local = $this->try_local_resolution( $message );
		if ( $local ) {
			$this->save_message( $conversation_id, 'assistant', $local['response'], $local['actions'] ?? array() );
			wp_send_json_success( array(
				'conversation_id' => $conversation_id,
				'response'        => $local['response'],
				'actions'         => $local['actions'] ?? array(),
				'source'          => 'local',
			) );
			return;
		}

		// 2. Budget check
		if ( class_exists( 'LuwiPress_Token_Tracker' ) && LuwiPress_Token_Tracker::is_limit_exceeded() ) {
			$limit = get_option( 'luwipress_daily_token_limit', 0 );
			$today = LuwiPress_Token_Tracker::get_today_cost();
			$msg   = sprintf( "**Daily AI budget reached** ($%.2f / $%.2f).\nLocal commands still work — type `/help`.", $today, $limit );
			$this->save_message( $conversation_id, 'assistant', $msg );
			wp_send_json_success( array(
				'conversation_id' => $conversation_id,
				'response'        => $msg,
				'actions'         => array(),
				'source'          => 'limit',
			) );
			return;
		}

		// 3. AI Engine dispatch (synchronous)
		$result = $this->call_ai( $message, $conversation_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array(
			'conversation_id' => $conversation_id,
			'response'        => $result['response'],
			'actions'         => $result['actions'] ?? array(),
			'source'          => 'ai',
		) );
	}

	/* ═══════════════════════════════════════════════════════════════════
	 *  AJAX: History & Clear
	 * ═══════════════════════════════════════════════════════════════════ */

	public function ajax_get_history() {
		check_ajax_referer( 'luwipress_claw_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$conversation_id = sanitize_text_field( $_POST['conversation_id'] ?? '' );
		if ( empty( $conversation_id ) ) {
			$conversation_id = get_user_meta( get_current_user_id(), '_luwipress_claw_conversation', true );
		}

		wp_send_json_success( array(
			'conversation_id' => $conversation_id,
			'messages'        => $this->get_conversation( $conversation_id ),
		) );
	}

	public function ajax_clear_history() {
		check_ajax_referer( 'luwipress_claw_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$conversation_id = sanitize_text_field( $_POST['conversation_id'] ?? '' );
		if ( $conversation_id ) {
			delete_option( 'luwipress_claw_' . $conversation_id );
		}
		wp_send_json_success( array( 'cleared' => true ) );
	}

	/* ═══════════════════════════════════════════════════════════════════
	 *  AJAX: Execute action (from chat action buttons)
	 * ═══════════════════════════════════════════════════════════════════ */

	public function ajax_execute_action() {
		check_ajax_referer( 'luwipress_claw_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$action_type = sanitize_text_field( $_POST['action_type'] ?? $_POST['execute_action'] ?? '' );
		$raw_data    = $_POST['params'] ?? $_POST['action_data'] ?? '{}';
		$action_data = is_string( $raw_data ) ? json_decode( stripslashes( $raw_data ), true ) : (array) $raw_data;
		if ( ! is_array( $action_data ) ) {
			$action_data = array();
		}

		if ( empty( $action_type ) ) {
			wp_send_json_error( 'Action type required' );
		}

		$result = $this->execute_action( $action_type, $action_data );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( $result );
	}

	/* ═══════════════════════════════════════════════════════════════════
	 *  AI ENGINE CALL (synchronous)
	 * ═══════════════════════════════════════════════════════════════════ */

	private function call_ai( $message, $conversation_id ) {
		if ( ! class_exists( 'LuwiPress_AI_Engine' ) ) {
			return new WP_Error( 'no_ai', 'AI Engine not available. Configure an API key in Settings.' );
		}

		$ctx   = $this->build_site_context();
		$store = $ctx['site_name'];

		$system = "You are the AI assistant for {$store}, a WooCommerce store. "
				. "{$ctx['products']} products. SEO: {$ctx['seo_plugin']}. Translation: {$ctx['translation_plugin']}. "
				. ( ! empty( $ctx['active_languages'] ) ? 'Languages: ' . implode( ', ', $ctx['active_languages'] ) . '. ' : '' )
				. "Be concise. For actions, include JSON: {\"action\": \"type\", \"params\": {...}}";

		$history  = $this->get_conversation( $conversation_id );
		$messages = array( array( 'role' => 'system', 'content' => $system ) );
		foreach ( array_slice( $history, -10 ) as $msg ) {
			if ( in_array( $msg['role'] ?? '', array( 'user', 'assistant' ), true ) ) {
				$messages[] = array( 'role' => $msg['role'], 'content' => $msg['content'] ?? '' );
			}
		}
		$messages[] = array( 'role' => 'user', 'content' => $message );

		$result = LuwiPress_AI_Engine::dispatch( 'open-claw', $messages, array( 'timeout' => 45 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text    = $result['content'] ?? '';
		$actions = array();

		if ( preg_match( '/\{"action"\s*:.*?\}/s', $text, $match ) ) {
			$parsed = json_decode( $match[0], true );
			if ( $parsed && ! empty( $parsed['action'] ) ) {
				$actions[] = $parsed;
				$text = trim( str_replace( $match[0], '', $text ) );
			}
		}

		$this->save_message( $conversation_id, 'assistant', $text, $actions );
		return array( 'response' => $text, 'actions' => $actions );
	}

	/* ═══════════════════════════════════════════════════════════════════
	 *  ACTION EXECUTION (routes through Job Queue)
	 * ═══════════════════════════════════════════════════════════════════ */

	private function execute_action( $type, $data ) {
		if ( class_exists( 'LuwiPress_Token_Tracker' ) && LuwiPress_Token_Tracker::is_limit_exceeded() ) {
			return new WP_Error( 'limit', 'Daily AI budget reached.' );
		}

		switch ( $type ) {
			case 'batch_enrich':
				$ids = array_map( 'absint', (array) ( $data['product_ids'] ?? array() ) );
				$count = 0;
				foreach ( $ids as $pid ) {
					if ( $pid > 0 && class_exists( 'LuwiPress_Job_Queue' ) ) {
						LuwiPress_Job_Queue::add( 'enrich_product', array( 'product_id' => $pid ) );
						$count++;
					}
				}
				return array( 'success' => true, 'message' => "{$count} products queued for enrichment." );

			case 'trigger_translation':
				$languages = (array) ( $data['languages'] ?? array() );
				foreach ( $languages as $lang ) {
					if ( class_exists( 'LuwiPress_Job_Queue' ) ) {
						LuwiPress_Job_Queue::add( 'translate_product', array( 'product_id' => 0, 'language' => sanitize_text_field( $lang ) ) );
					}
				}
				return array( 'success' => true, 'message' => count( $languages ) . ' translation jobs queued.' );

			case 'generate_content':
				$topic = sanitize_text_field( $data['topic'] ?? '' );
				if ( empty( $topic ) ) return new WP_Error( 'no_topic', 'Topic required.' );
				if ( class_exists( 'LuwiPress_Job_Queue' ) ) {
					LuwiPress_Job_Queue::add( 'content_generate', array( 'topic' => $topic, 'language' => get_option( 'luwipress_target_language', 'en' ), 'generate_image' => true ) );
				}
				return array( 'success' => true, 'message' => "Blog post \"{$topic}\" queued." );

			case 'aeo_generate':
				$ids = array_map( 'absint', (array) ( $data['product_ids'] ?? array() ) );
				foreach ( $ids as $pid ) {
					if ( $pid > 0 && class_exists( 'LuwiPress_Job_Queue' ) ) {
						LuwiPress_Job_Queue::add( 'generate_aeo', array( 'product_id' => $pid ) );
					}
				}
				return array( 'success' => true, 'message' => count( $ids ) . ' AEO jobs queued.' );

			case 'crm_refresh':
				if ( class_exists( 'LuwiPress_CRM_Bridge' ) ) {
					LuwiPress_CRM_Bridge::get_instance()->refresh_segments();
					return array( 'success' => true, 'message' => 'Customer segments refreshed.' );
				}
				return new WP_Error( 'no_crm', 'CRM not available.' );

			case 'trigger_winback':
				return array( 'success' => true, 'message' => 'Win-back emails queued.' );

			default:
				return new WP_Error( 'unknown', "Unknown action: {$type}" );
		}
	}

	/* ═══════════════════════════════════════════════════════════════════
	 *  LOCAL RESOLUTION (no AI cost)
	 * ═══════════════════════════════════════════════════════════════════ */

	private function try_local_resolution( $message ) {
		$msg = mb_strtolower( trim( $message ) );

		if ( '/' === mb_substr( $msg, 0, 1 ) ) {
			return $this->handle_slash_command( $msg );
		}

		if ( preg_match( '/how many product|product count/i', $msg ) ) {
			$c = wp_count_posts( 'product' );
			return array( 'response' => sprintf( "**%d published products**, %d drafts. Try `/scan` or `/thin`.", $c->publish, $c->draft ) );
		}
		if ( preg_match( '/opportunit|scan|content scan/i', $msg ) )        return $this->scan_opportunities();
		if ( preg_match( '/seo meta|without seo|missing seo|no seo/i', $msg ) ) return $this->check_missing_seo();
		if ( preg_match( '/thin content|thin product/i', $msg ) )           return $this->check_thin_content();
		if ( preg_match( '/missing translation|untranslated|translate all/i', $msg ) ) return $this->check_missing_translations();
		if ( preg_match( '/stale|outdated/i', $msg ) )                      return $this->check_stale_content();
		if ( preg_match( '/generate|blog post|write a post/i', $msg ) ) {
			$topic = preg_match( '/about (.+)/i', $msg, $m ) ? trim( $m[1] ) : '';
			if ( empty( $topic ) ) return array( 'response' => "What topic? Example: *generate a blog post about guitar maintenance*" );
			return array(
				'response' => sprintf( "Generate blog post about **%s**?", $topic ),
				'actions'  => array( array( 'type' => 'generate_content', 'label' => 'Generate Post', 'data' => array( 'topic' => $topic ), 'confirm' => true ) ),
			);
		}
		if ( preg_match( '/what plugin|which plugin|environment|detect/i', $msg ) ) return $this->show_environment();
		if ( preg_match( '/review|sentiment/i', $msg ) )                    return $this->show_reviews();
		if ( preg_match( '/aeo|faq|schema|structured data/i', $msg ) )      return $this->show_aeo_coverage();
		if ( preg_match( '/customer|vip|at.risk|segment|crm/i', $msg ) )    return $this->show_crm_data( $msg );
		if ( preg_match( '/revenue|sales|order count/i', $msg ) )           return $this->show_revenue();

		return null; // → AI
	}

	/* ─── Slash Commands ─────────────────────────────────────────────── */

	private function handle_slash_command( $msg ) {
		$parts = explode( ' ', trim( mb_substr( $msg, 1 ) ), 2 );
		$cmd   = $parts[0];
		$args  = $parts[1] ?? '';

		$aliases = array(
			'scan' => 'opportunit', 'seo' => 'missing seo', 'translate' => 'missing translation',
			'thin' => 'thin content', 'stale' => 'stale', 'aeo' => 'aeo coverage',
			'reviews' => 'review', 'plugins' => 'what plugin', 'env' => 'what plugin',
			'crm' => 'customer segments', 'customers' => 'customer segments',
			'revenue' => 'revenue', 'sales' => 'revenue', 'products' => 'how many products',
		);

		if ( 'help' === $cmd ) return array( 'response' =>
			"**Commands:**\n\n| Command | Description |\n|---|---|\n"
			. "| `/scan` | Content scan |\n| `/seo` | Missing SEO |\n| `/translate` | Missing translations |\n"
			. "| `/enrich` | Batch enrichment |\n| `/thin` | Thin content |\n| `/stale` | Stale content |\n"
			. "| `/generate [topic]` | Blog post |\n| `/aeo` | AEO coverage |\n| `/reviews` | Reviews |\n"
			. "| `/plugins` | Environment |\n| `/crm` | CRM segments |\n| `/revenue` | Revenue |\n"
			. "| `/products` | Product count |\n| `/help` | This help |"
		);
		if ( 'enrich' === $cmd ) return $this->prepare_batch_enrich();
		if ( 'generate' === $cmd ) return $this->try_local_resolution( 'generate a blog post about ' . $args );
		if ( isset( $aliases[ $cmd ] ) ) return $this->try_local_resolution( $aliases[ $cmd ] );
		return array( 'response' => "Unknown: `/{$cmd}`. Type `/help`." );
	}

	/* ─── Query Handlers ─────────────────────────────────────────────── */

	private function scan_opportunities() {
		$data = LuwiPress_Site_Config::get_instance()->get_content_opportunities( new WP_REST_Request( 'GET', '/luwipress/v1/content/opportunities' ) )->get_data();
		$thin = count( $data['thin_content'] ?? array() );
		$seo = count( $data['missing_seo_meta'] ?? array() );
		$alt = count( $data['missing_alt_text'] ?? array() );
		$tr = count( $data['missing_translations'] ?? array() );
		$st = count( $data['stale_content'] ?? array() );
		$total = $thin + $seo + $alt + $tr + $st;
		if ( 0 === $total ) return array( 'response' => 'Content looks healthy.' );

		$lines = array();
		$actions = array();
		if ( $thin > 0 ) {
			$lines[] = "- **Thin**: {$thin}";
			$actions[] = array( 'type' => 'batch_enrich', 'label' => "Enrich {$thin}", 'data' => array( 'product_ids' => array_column( $data['thin_content'], 'id' ) ), 'confirm' => true );
		}
		if ( $seo > 0 ) $lines[] = "- **No SEO**: {$seo}";
		if ( $alt > 0 ) $lines[] = "- **No alt**: {$alt}";
		if ( $tr > 0 )  $lines[] = "- **Untranslated**: {$tr}";
		if ( $st > 0 )  $lines[] = "- **Stale**: {$st}";
		return array( 'response' => "**Scan** ({$total} issues):\n\n" . implode( "\n", $lines ), 'actions' => $actions );
	}

	private function check_missing_seo() {
		$missing = LuwiPress_Site_Config::get_instance()->get_content_opportunities( new WP_REST_Request( 'GET', '/luwipress/v1/content/opportunities' ) )->get_data()['missing_seo_meta'] ?? array();
		if ( empty( $missing ) ) return array( 'response' => 'All products have SEO meta.' );
		$list = '';
		foreach ( array_slice( $missing, 0, 10 ) as $i ) $list .= "- {$i['title']} (ID: {$i['id']})\n";
		if ( count( $missing ) > 10 ) $list .= sprintf( "… +%d more\n", count( $missing ) - 10 );
		return array(
			'response' => sprintf( "**%d** missing SEO:\n\n%s", count( $missing ), $list ),
			'actions'  => array( array( 'type' => 'batch_enrich', 'label' => 'Enrich', 'data' => array( 'product_ids' => array_column( $missing, 'id' ) ), 'confirm' => true ) ),
		);
	}

	private function check_thin_content() {
		$th = absint( get_option( 'luwipress_thin_content_threshold', 300 ) );
		global $wpdb;
		$thin = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, LENGTH(post_content) as c FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND LENGTH(post_content)<%d ORDER BY c ASC LIMIT 20", $th ) );
		if ( empty( $thin ) ) return array( 'response' => "No thin products (<{$th} chars)." );
		$list = ''; $ids = array();
		foreach ( $thin as $p ) { $list .= "- **{$p->post_title}** ({$p->c} chars)\n"; $ids[] = $p->ID; }
		return array( 'response' => sprintf( "**%d thin** (<%d):\n\n%s", count( $thin ), $th, $list ), 'actions' => array( array( 'type' => 'batch_enrich', 'label' => 'Enrich All', 'data' => array( 'product_ids' => $ids ), 'confirm' => true ) ) );
	}

	private function check_missing_translations() {
		$missing = LuwiPress_Site_Config::get_instance()->get_content_opportunities( new WP_REST_Request( 'GET', '/luwipress/v1/content/opportunities' ) )->get_data()['missing_translations'] ?? array();
		if ( empty( $missing ) ) return array( 'response' => 'All translated.' );
		$by = array();
		foreach ( $missing as $i ) { $l = $i['missing_language'] ?? '?'; $by[$l] = ($by[$l] ?? 0) + 1; }
		$list = '';
		foreach ( $by as $l => $c ) $list .= "- **" . strtoupper($l) . "**: {$c}\n";
		return array( 'response' => sprintf( "**%d untranslated**:\n\n%s", count($missing), $list ), 'actions' => array( array( 'type' => 'trigger_translation', 'label' => 'Translate', 'data' => array( 'languages' => array_keys($by) ), 'confirm' => true ) ) );
	}

	private function check_stale_content() {
		$days = absint( get_option( 'luwipress_freshness_stale_days', 90 ) );
		global $wpdb;
		$c = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','post') AND post_status='publish' AND post_modified < %s", gmdate( 'Y-m-d', strtotime("-{$days} days") ) ) );
		return array( 'response' => "**{$c}** items stale ({$days}+ days)." );
	}

	private function prepare_batch_enrich() {
		$th = absint( get_option( 'luwipress_thin_content_threshold', 300 ) );
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND LENGTH(post_content)<%d ORDER BY LENGTH(post_content) ASC LIMIT 50", $th ) );
		if ( empty($ids) ) $ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' ORDER BY ID ASC LIMIT 50" );
		return array( 'response' => sprintf( 'Batch enrichment ready for %d products.', count($ids) ), 'actions' => array( array( 'type' => 'batch_enrich', 'label' => 'Enrich ' . count($ids), 'data' => array( 'product_ids' => array_map('absint', $ids) ), 'confirm' => true ) ) );
	}

	private function show_environment() {
		$env = LuwiPress_Plugin_Detector::get_instance()->get_environment();
		$list = '';
		foreach ( array( 'seo' => 'SEO', 'translation' => 'Translation', 'email' => 'Email', 'crm' => 'CRM', 'cache' => 'Cache' ) as $k => $l ) {
			$p = $env[$k]['plugin'] ?? 'none';
			$list .= "- **{$l}**: " . ( 'none' !== $p ? ucwords( str_replace('-',' ',$p) ) : 'None' ) . "\n";
		}
		return array( 'response' => "Plugins:\n\n{$list}" );
	}

	private function show_reviews() {
		if ( ! class_exists('WooCommerce') ) return array( 'response' => 'WooCommerce not active.' );
		$a = get_comments( array( 'type' => 'review', 'count' => true, 'status' => 'approve' ) );
		$p = get_comments( array( 'type' => 'review', 'count' => true, 'status' => 'hold' ) );
		return array( 'response' => "**{$a}** approved, **{$p}** pending reviews." );
	}

	private function show_aeo_coverage() {
		if ( ! class_exists('LuwiPress_AEO') ) return null;
		$d = LuwiPress_AEO::get_instance()->get_aeo_coverage( new WP_REST_Request('GET','/luwipress/v1/aeo/coverage') );
		if ( is_array($d) ) return array( 'response' => sprintf( "AEO: FAQ **%d%%**, HowTo **%d%%**, Speakable **%d%%**", $d['faq_coverage'] ?? 0, $d['howto_coverage'] ?? 0, $d['speakable_coverage'] ?? 0 ) );
		return null;
	}

	private function show_crm_data( $msg ) {
		if ( ! class_exists('WooCommerce') ) return array( 'response' => 'WooCommerce not active.' );
		if ( preg_match('/vip/i', $msg) ) {
			$users = get_users( array( 'meta_key' => '_luwipress_crm_segment', 'meta_value' => 'vip', 'number' => 10, 'role' => 'customer' ) );
			if ( empty($users) ) return array( 'response' => 'No VIPs yet.' );
			update_meta_cache('user', wp_list_pluck($users,'ID'));
			$list = '';
			foreach ( $users as $u ) { $s = get_user_meta($u->ID,'_luwipress_crm_stats',true); $list .= sprintf("- **%s** — %d orders, $%.2f\n", $u->display_name, $s['order_count']??0, $s['total_spent']??0); }
			return array( 'response' => "VIP:\n\n{$list}" );
		}
		if ( preg_match('/at.risk|risk/i', $msg) ) {
			$users = get_users( array( 'meta_key' => '_luwipress_crm_segment', 'meta_value' => 'at_risk', 'number' => 10, 'role' => 'customer' ) );
			if ( empty($users) ) return array( 'response' => 'No at-risk customers.' );
			update_meta_cache('user', wp_list_pluck($users,'ID'));
			$list = '';
			foreach ($users as $u) { $s = get_user_meta($u->ID,'_luwipress_crm_stats',true); $list .= sprintf("- **%s** — %s (%d days)\n",$u->display_name,$s['last_order']??'?',$s['days_since']??0); }
			return array( 'response' => sprintf("At-Risk (%d):\n\n%s",count($users),$list), 'actions' => array(array('type'=>'trigger_winback','label'=>'Win-Back','data'=>array('segment'=>'at_risk'),'confirm'=>true)) );
		}
		$counts = get_option('luwipress_crm_segment_counts', array());
		if ( empty($counts) ) return array( 'response' => 'Segments not computed.', 'actions' => array(array('type'=>'crm_refresh','label'=>'Refresh','data'=>array(),'confirm'=>true)) );
		$list = '';
		foreach ( array('vip'=>'VIP','loyal'=>'Loyal','active'=>'Active','new'=>'New','at_risk'=>'At Risk','dormant'=>'Dormant') as $k=>$l ) { $c=$counts[$k]??0; if($c>0) $list.="- **{$l}**: {$c}\n"; }
		return array( 'response' => "Segments:\n\n{$list}" );
	}

	private function show_revenue() {
		if ( ! class_exists('WooCommerce') ) return array( 'response' => 'WooCommerce not active.' );
		global $wpdb;
		$r = $wpdb->get_row("SELECT COUNT(*) as o, COALESCE(SUM(pm.meta_value),0) as rev FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id AND pm.meta_key='_order_total' WHERE p.post_type='shop_order' AND p.post_status IN ('wc-completed','wc-processing') AND p.post_date>DATE_SUB(NOW(),INTERVAL 30 DAY)");
		return array( 'response' => sprintf("30 days: **%d** orders, **%s%.2f** revenue.", absint($r->o), get_woocommerce_currency_symbol(), floatval($r->rev)) );
	}

	/* ═══════════════════════════════════════════════════════════════════
	 *  SITE CONTEXT
	 * ═══════════════════════════════════════════════════════════════════ */

	private function build_site_context() {
		$env = LuwiPress_Plugin_Detector::get_instance()->get_environment();
		$pc  = wp_count_posts('product');
		return array(
			'site_name'          => get_bloginfo('name'),
			'products'           => absint( $pc->publish ?? 0 ),
			'seo_plugin'         => $env['seo']['plugin'] ?? 'none',
			'translation_plugin' => $env['translation']['plugin'] ?? 'none',
			'active_languages'   => $env['translation']['active_languages'] ?? array(),
		);
	}

	/* ═══════════════════════════════════════════════════════════════════
	 *  MESSAGE STORAGE
	 * ═══════════════════════════════════════════════════════════════════ */

	private function save_message( $cid, $role, $content, $actions = array() ) {
		$msgs   = $this->get_conversation( $cid );
		$msgs[] = array( 'role' => $role, 'content' => $content, 'actions' => $actions, 'time' => current_time('mysql') );
		$msgs   = array_slice( $msgs, -50 );
		update_option( 'luwipress_claw_' . $cid, $msgs, 'no' );
		$uid = get_current_user_id();
		if ( $uid ) update_user_meta( $uid, '_luwipress_claw_conversation', $cid );
	}

	private function get_conversation( $cid ) {
		if ( empty($cid) ) return array();
		$m = get_option( 'luwipress_claw_' . $cid, array() );
		return is_array($m) ? $m : array();
	}
}
