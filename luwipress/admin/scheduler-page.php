<?php
/**
 * LuwiPress Content Scheduler Page
 *
 * AI-powered content generation and scheduling interface.
 * Hybrid tab layout: Queue · Plans · Create new (sidebar wizard).
 *
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'edit_posts' ) ) {
	wp_die( esc_html__( 'Insufficient permissions.', 'luwipress' ) );
}

$scheduled_items  = LuwiPress_Content_Scheduler::get_scheduled_items();
$recurring_plans  = LuwiPress_Content_Scheduler::get_recurring_plans();
$target_language  = get_option( 'luwipress_target_language', 'en' );
$ai_provider      = get_option( 'luwipress_ai_provider', 'openai' );
$ai_model         = get_option( 'luwipress_ai_model', 'gpt-4o-mini' );
$has_ai_key       = ! empty( get_option( 'luwipress_openai_api_key', '' ) )
                 || ! empty( get_option( 'luwipress_anthropic_api_key', '' ) )
                 || ! empty( get_option( 'luwipress_google_ai_api_key', '' ) );

$provider_labels = array( 'openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'google' => 'Google AI' );

$brand_voice_default = (string) get_option( 'luwipress_brand_voice_card', '' );
$can_save_voice      = current_user_can( 'manage_options' );

$translation_info    = class_exists( 'LuwiPress_Plugin_Detector' )
	? LuwiPress_Plugin_Detector::get_instance()->detect_translation()
	: array( 'plugin' => 'none', 'active_languages' => array() );
$translation_plugin  = $translation_info['plugin'] ?? 'none';
$translation_langs   = is_array( $translation_info['active_languages'] ?? null )
	? array_values( array_unique( array_filter( array_map( 'strval', $translation_info['active_languages'] ) ) ) )
	: array();
$multilingual_ready  = in_array( $translation_plugin, array( 'wpml', 'polylang' ), true ) && count( $translation_langs ) > 1;

$status_config = array(
	'pending'         => array( 'label' => __( 'Pending', 'luwipress' ),        'accent' => 'muted',   'icon' => 'clock' ),
	'generating'      => array( 'label' => __( 'Generating', 'luwipress' ),     'accent' => 'warning', 'icon' => 'update' ),
	'outline_pending' => array( 'label' => __( 'Outline review', 'luwipress' ), 'accent' => 'info',    'icon' => 'welcome-write-blog' ),
	'ready'           => array( 'label' => __( 'Ready', 'luwipress' ),          'accent' => 'info',    'icon' => 'yes-alt' ),
	'published'       => array( 'label' => __( 'Published', 'luwipress' ),      'accent' => 'success', 'icon' => 'admin-post' ),
	'failed'          => array( 'label' => __( 'Failed', 'luwipress' ),         'accent' => 'error',   'icon' => 'dismiss' ),
);

$tone_options = array(
	'professional' => __( 'Professional', 'luwipress' ),
	'casual'       => __( 'Casual & Friendly', 'luwipress' ),
	'academic'     => __( 'Academic', 'luwipress' ),
	'creative'     => __( 'Creative & Engaging', 'luwipress' ),
	'persuasive'   => __( 'Persuasive & Sales', 'luwipress' ),
	'informative'  => __( 'Informative & Educational', 'luwipress' ),
);

$depth_options = array(
	'standard'  => array(
		'label' => __( 'Standard', 'luwipress' ),
		'desc'  => __( '800–1500 words · balanced SEO article', 'luwipress' ),
		'hint'  => __( 'Fast daily posts, product-focused content', 'luwipress' ),
	),
	'deep'      => array(
		'label' => __( 'Deep', 'luwipress' ),
		'desc'  => __( '1500–3000 words · research frame, citations, FAQ', 'luwipress' ),
		'hint'  => __( 'Explainers that need depth (history, theory, tech)', 'luwipress' ),
	),
	'editorial' => array(
		'label' => __( 'Editorial', 'luwipress' ),
		'desc'  => __( '2000–3500+ words · strong voice, anecdotes, essay arc', 'luwipress' ),
		'hint'  => __( 'Flagship pieces that carry brand voice', 'luwipress' ),
	),
);

$language_options = array(
	'en' => 'English', 'tr' => 'Turkce', 'de' => 'Deutsch', 'fr' => 'Francais',
	'ar' => 'Arabic', 'es' => 'Espanol', 'it' => 'Italiano', 'nl' => 'Nederlands',
	'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese', 'pt' => 'Portuguese',
	'ko' => 'Korean', 'hi' => 'Hindi',
);

// Count by status
$counts = array( 'pending' => 0, 'generating' => 0, 'outline_pending' => 0, 'ready' => 0, 'published' => 0, 'failed' => 0 );
foreach ( $scheduled_items as $item ) {
	$s = get_post_meta( $item->ID, '_luwipress_schedule_status', true );
	if ( isset( $counts[ $s ] ) ) {
		$counts[ $s ]++;
	}
}
$total_items = count( $scheduled_items );

// Default tab: queue if there are items, else create; plans tab has its own badge
$default_tab = $total_items > 0 ? 'queue' : 'create';
?>

<div class="wrap luwipress-dashboard sched-shell" data-default-tab="<?php echo esc_attr( $default_tab ); ?>">

	<!-- ─── Page header ─────────────────────────────────────── -->
	<div class="lp-header sched-header">
		<div class="lp-header-left">
			<h1 class="lp-title">
				<span class="dashicons dashicons-calendar-alt lp-title-icon"></span>
				<?php esc_html_e( 'Content Scheduler', 'luwipress' ); ?>
			</h1>
			<p class="sched-subtitle"><?php esc_html_e( 'AI-powered editorial pipeline — queue topics, review outlines, publish on schedule.', 'luwipress' ); ?></p>
		</div>
		<div class="lp-header-actions">
			<?php if ( $has_ai_key ) : ?>
			<span class="lp-pill pill-ok" title="<?php esc_attr_e( 'Active AI provider', 'luwipress' ); ?>">
				<span class="dashicons dashicons-admin-generic"></span>
				<?php echo esc_html( ( $provider_labels[ $ai_provider ] ?? 'AI' ) . ' · ' . $ai_model ); ?>
			</span>
			<?php else : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-settings&tab=api-keys' ) ); ?>" class="lp-pill pill-err">
				<span class="dashicons dashicons-warning"></span>
				<?php esc_html_e( 'No AI key configured', 'luwipress' ); ?>
			</a>
			<?php endif; ?>
		</div>
	</div>

	<!-- ─── Main tabs ───────────────────────────────────────── -->
	<nav class="sched-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Content Scheduler sections', 'luwipress' ); ?>">
		<button type="button" class="sched-tab" role="tab" data-tab="queue" aria-selected="false" aria-controls="sched-tabpanel-queue" id="sched-tab-queue">
			<span class="dashicons dashicons-list-view"></span>
			<span class="sched-tab-label"><?php esc_html_e( 'Queue', 'luwipress' ); ?></span>
			<span class="sched-tab-count"><?php echo absint( $total_items ); ?></span>
		</button>
		<button type="button" class="sched-tab" role="tab" data-tab="plans" aria-selected="false" aria-controls="sched-tabpanel-plans" id="sched-tab-plans">
			<span class="dashicons dashicons-backup"></span>
			<span class="sched-tab-label"><?php esc_html_e( 'Plans', 'luwipress' ); ?></span>
			<span class="sched-tab-count"><?php echo absint( count( $recurring_plans ) ); ?></span>
		</button>
		<button type="button" class="sched-tab sched-tab--cta" role="tab" data-tab="create" aria-selected="false" aria-controls="sched-tabpanel-create" id="sched-tab-create">
			<span class="dashicons dashicons-plus-alt2"></span>
			<span class="sched-tab-label"><?php esc_html_e( 'Create new', 'luwipress' ); ?></span>
		</button>
	</nav>

	<!-- ═══════════════════════════════════════════════════════
	     TAB: QUEUE
	     ═══════════════════════════════════════════════════════ -->
	<section class="sched-tabpanel" id="sched-tabpanel-queue" role="tabpanel" aria-labelledby="sched-tab-queue" hidden>

		<!-- Status summary (compact) -->
		<?php if ( $total_items > 0 ) : ?>
		<div class="sched-summary-bar">
			<?php foreach ( $status_config as $key => $cfg ) :
				if ( 0 === $counts[ $key ] ) continue;
			?>
			<div class="sched-summary-chip sched-summary-chip--<?php echo esc_attr( $cfg['accent'] ); ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $cfg['icon'] ); ?>"></span>
				<strong><?php echo absint( $counts[ $key ] ); ?></strong>
				<span><?php echo esc_html( $cfg['label'] ); ?></span>
			</div>
			<?php endforeach; ?>
			<?php if ( $counts['pending'] > 0 ) : ?>
			<button type="button" class="button button-secondary sched-run-now" id="sched-run-now">
				<span class="dashicons dashicons-controls-play"></span>
				<?php printf( esc_html__( 'Run %d pending now', 'luwipress' ), absint( $counts['pending'] ) ); ?>
			</button>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php if ( empty( $scheduled_items ) ) : ?>
		<!-- Empty state -->
		<div class="lp-card sched-empty-card">
			<div class="sched-empty">
				<span class="dashicons dashicons-calendar-alt"></span>
				<h3><?php esc_html_e( 'No scheduled content yet', 'luwipress' ); ?></h3>
				<p><?php esc_html_e( 'Queue your first batch of AI-generated posts in just a few steps.', 'luwipress' ); ?></p>
				<button type="button" class="button button-primary sched-empty-cta" data-goto-tab="create">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php esc_html_e( 'Create your first batch', 'luwipress' ); ?>
				</button>
			</div>
		</div>
		<?php else : ?>

		<!-- Status sub-tabs -->
		<?php
		$filters = array(
			'all'             => __( 'All', 'luwipress' ),
			'pending'         => __( 'Pending', 'luwipress' ),
			'generating'      => __( 'Generating', 'luwipress' ),
			'outline_pending' => __( 'Outline review', 'luwipress' ),
			'ready'           => __( 'Ready', 'luwipress' ),
			'published'       => __( 'Published', 'luwipress' ),
			'failed'          => __( 'Failed', 'luwipress' ),
		);
		?>
		<div class="sched-subtabs" id="sched-queue-filters" role="tablist">
			<?php foreach ( $filters as $key => $label ) :
				if ( 'all' !== $key && 0 === $counts[ $key ] ) continue;
				$n = 'all' === $key ? $total_items : $counts[ $key ];
				$accent = 'all' === $key ? '' : $status_config[ $key ]['accent'];
			?>
			<button type="button" class="sched-subtab <?php echo 'all' === $key ? 'is-active' : ''; ?> <?php echo $accent ? 'sched-subtab--' . esc_attr( $accent ) : ''; ?>" data-filter="<?php echo esc_attr( $key ); ?>" role="tab" aria-selected="<?php echo 'all' === $key ? 'true' : 'false'; ?>">
				<?php echo esc_html( $label ); ?>
				<span class="sched-subtab-count"><?php echo absint( $n ); ?></span>
			</button>
			<?php endforeach; ?>
		</div>

		<!-- Bulk toolbar -->
		<div class="sched-bulk-toolbar" id="sched-bulk-toolbar" hidden>
			<label class="sched-bulk-toolbar-all">
				<input type="checkbox" id="sched-bulk-all" />
				<span class="sched-bulk-toolbar-count" id="sched-bulk-toolbar-count">0 selected</span>
			</label>
			<div class="sched-bulk-toolbar-actions">
				<button type="button" class="button button-primary sched-bulk-run" data-bulk="publish">
					<span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Publish selected', 'luwipress' ); ?>
				</button>
				<button type="button" class="button sched-bulk-run" data-bulk="retry">
					<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Retry', 'luwipress' ); ?>
				</button>
				<button type="button" class="button sched-bulk-run sched-bulk-danger" data-bulk="delete">
					<span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Delete', 'luwipress' ); ?>
				</button>
			</div>
		</div>

		<!-- Queue list -->
		<div class="sched-list" id="sched-list">
			<?php foreach ( $scheduled_items as $item ) :
				$status       = get_post_meta( $item->ID, '_luwipress_schedule_status', true );
				$topic        = get_post_meta( $item->ID, '_luwipress_schedule_topic', true );
				$type         = get_post_meta( $item->ID, '_luwipress_schedule_type', true );
				$lang         = get_post_meta( $item->ID, '_luwipress_schedule_language', true );
				$pub_date     = get_post_meta( $item->ID, '_luwipress_schedule_date', true );
				$published_id = get_post_meta( $item->ID, '_luwipress_published_post_id', true );
				$error        = get_post_meta( $item->ID, '_luwipress_schedule_error', true );
				$tone         = get_post_meta( $item->ID, '_luwipress_schedule_tone', true );
				$words        = get_post_meta( $item->ID, '_luwipress_schedule_words', true );
				$depth        = get_post_meta( $item->ID, '_luwipress_schedule_depth', true );
				$mode         = get_post_meta( $item->ID, '_luwipress_schedule_publish_mode', true ) ?: 'auto';
				$cfg          = $status_config[ $status ] ?? $status_config['pending'];
				$is_draft     = ( $published_id && 'draft' === $mode ) ? ( 'draft' === get_post_status( $published_id ) ) : false;
			?>
			<div class="sched-item <?php echo 'generating' === $status ? 'sched-pulse' : ''; ?>" data-id="<?php echo absint( $item->ID ); ?>" data-status="<?php echo esc_attr( $status ); ?>" data-post-status="<?php echo esc_attr( $published_id && $is_draft ? 'draft' : ( $published_id ? 'publish' : '' ) ); ?>">
				<label class="sched-item-pick">
					<input type="checkbox" class="sched-item-check" data-id="<?php echo absint( $item->ID ); ?>" />
				</label>
				<div class="sched-item-status sched-item-status--<?php echo esc_attr( $cfg['accent'] ); ?>" title="<?php echo esc_attr( $cfg['label'] ); ?>">
					<span class="dashicons dashicons-<?php echo esc_attr( $cfg['icon'] ); ?>"></span>
				</div>
				<div class="sched-item-body">
					<div class="sched-item-title"><?php echo esc_html( $topic ); ?></div>
					<div class="sched-item-meta">
						<span class="sched-tag sched-tag--mode sched-tag--mode-<?php echo esc_attr( $mode ); ?>">
							<?php echo 'draft' === $mode ? esc_html__( 'Draft', 'luwipress' ) : esc_html__( 'Auto', 'luwipress' ); ?>
						</span>
						<span class="sched-tag"><?php echo esc_html( $type ); ?></span>
						<span class="sched-tag sched-tag--lang"><?php echo esc_html( strtoupper( $lang ) ); ?></span>
						<?php if ( $depth ) : ?><span class="sched-tag sched-tag--depth"><?php echo esc_html( $depth ); ?></span><?php endif; ?>
						<?php if ( $tone ) : ?><span class="sched-tag"><?php echo esc_html( $tone ); ?></span><?php endif; ?>
						<?php if ( $words ) : ?><span class="sched-tag"><?php echo absint( $words ); ?>w</span><?php endif; ?>
						<?php if ( $pub_date ) : ?>
						<span class="sched-date" title="<?php echo esc_attr( wp_date( 'M j, Y H:i', strtotime( $pub_date ) ) ); ?>"><span class="dashicons dashicons-calendar-alt"></span> <?php echo esc_html( wp_date( 'M j, Y', strtotime( $pub_date ) ) ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( $error ) : ?>
					<div class="sched-error"><span class="dashicons dashicons-warning"></span> <?php echo esc_html( $error ); ?></div>
					<?php endif; ?>
				</div>
				<div class="sched-item-actions">
					<?php if ( 'outline_pending' === $status ) : ?>
					<button type="button" class="button button-small button-primary sched-outline-open" data-id="<?php echo absint( $item->ID ); ?>">
						<span class="dashicons dashicons-welcome-write-blog"></span> <?php esc_html_e( 'Review outline', 'luwipress' ); ?>
					</button>
					<button type="button" class="button button-small sched-delete" data-id="<?php echo absint( $item->ID ); ?>" aria-label="<?php esc_attr_e( 'Discard', 'luwipress' ); ?>">
						<span class="dashicons dashicons-trash"></span>
					</button>
					<?php elseif ( $published_id && $is_draft ) : ?>
					<button type="button" class="button button-small sched-enrich" data-id="<?php echo absint( $item->ID ); ?>" title="<?php esc_attr_e( 'Resolve internal links + suggest taxonomy from existing terms', 'luwipress' ); ?>">
						<span class="dashicons dashicons-admin-customizer"></span> <?php esc_html_e( 'Enrich', 'luwipress' ); ?>
					</button>
					<a href="<?php echo esc_url( get_edit_post_link( $published_id ) ); ?>" class="button button-small button-primary">
						<span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Review & publish', 'luwipress' ); ?>
					</a>
					<?php elseif ( $published_id ) : ?>
					<a href="<?php echo esc_url( get_edit_post_link( $published_id ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'luwipress' ); ?></a>
					<a href="<?php echo esc_url( get_permalink( $published_id ) ); ?>" class="button button-small" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'luwipress' ); ?></a>
					<?php else : ?>
						<?php if ( 'failed' === $status ) : ?>
						<button type="button" class="button button-small sched-retry" data-id="<?php echo absint( $item->ID ); ?>">
							<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Retry', 'luwipress' ); ?>
						</button>
						<?php endif; ?>
						<?php if ( 'published' !== $status ) : ?>
						<button type="button" class="button button-small sched-delete" data-id="<?php echo absint( $item->ID ); ?>" aria-label="<?php esc_attr_e( 'Delete', 'luwipress' ); ?>">
							<span class="dashicons dashicons-trash"></span>
						</button>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</section>

	<!-- ═══════════════════════════════════════════════════════
	     TAB: PLANS
	     ═══════════════════════════════════════════════════════ -->
	<section class="sched-tabpanel" id="sched-tabpanel-plans" role="tabpanel" aria-labelledby="sched-tab-plans" hidden>

		<div class="sched-plans-header">
			<div class="sched-plans-intro">
				<h2><?php esc_html_e( 'Recurring plans', 'luwipress' ); ?></h2>
				<p><?php esc_html_e( "Set a theme and a cadence. LuwiPress brainstorms fresh topics for you on schedule, filtering against your recent posts so you don't get duplicates. Each run respects your daily AI budget.", 'luwipress' ); ?></p>
			</div>
			<button type="button" class="button button-primary sched-plan-new" id="sched-plan-new">
				<span class="dashicons dashicons-plus-alt2"></span>
				<?php esc_html_e( 'New plan', 'luwipress' ); ?>
			</button>
		</div>

		<?php if ( empty( $recurring_plans ) ) : ?>
		<div class="lp-card sched-empty-card">
			<div class="sched-empty">
				<span class="dashicons dashicons-backup"></span>
				<h3><?php esc_html_e( 'No recurring plans yet', 'luwipress' ); ?></h3>
				<p><?php esc_html_e( 'Create one to keep your editorial calendar filling itself on schedule.', 'luwipress' ); ?></p>
				<button type="button" class="button button-primary" id="sched-plan-new-empty">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php esc_html_e( 'Create a plan', 'luwipress' ); ?>
				</button>
			</div>
		</div>
		<?php else : ?>
		<div class="sched-plans-list" id="sched-plans-list">
			<?php foreach ( $recurring_plans as $plan ) :
				$next   = ! empty( $plan['next_run_at'] ) ? wp_date( 'M j, Y H:i', (int) $plan['next_run_at'] ) : '—';
				$last   = ! empty( $plan['last_run_at'] ) ? wp_date( 'M j, Y H:i', (int) $plan['last_run_at'] ) : __( 'never', 'luwipress' );
				$paused = ! empty( $plan['paused'] );
			?>
			<div class="sched-plan-row <?php echo $paused ? 'is-paused' : ''; ?>" data-id="<?php echo esc_attr( $plan['id'] ); ?>">
				<div class="sched-plan-main">
					<div class="sched-plan-title">
						<strong><?php echo esc_html( $plan['name'] ); ?></strong>
						<?php if ( $paused ) : ?><span class="sched-plan-pill sched-plan-pill--paused"><?php esc_html_e( 'Paused', 'luwipress' ); ?></span><?php endif; ?>
					</div>
					<div class="sched-plan-meta">
						<span><span class="dashicons dashicons-tag"></span> <?php echo esc_html( $plan['theme'] ); ?></span>
						<span><span class="dashicons dashicons-clock"></span> <?php echo esc_html( $plan['cadence'] ); ?> · <?php echo (int) $plan['count']; ?> <?php esc_html_e( 'posts', 'luwipress' ); ?></span>
						<span><span class="dashicons dashicons-admin-generic"></span> <?php echo esc_html( $plan['depth'] ); ?> · <?php echo (int) $plan['word_count']; ?>w · <?php echo esc_html( strtoupper( $plan['language'] ) ); ?></span>
					</div>
					<div class="sched-plan-dates">
						<span><strong><?php esc_html_e( 'Next:', 'luwipress' ); ?></strong> <?php echo esc_html( $next ); ?></span>
						<span><strong><?php esc_html_e( 'Last:', 'luwipress' ); ?></strong> <?php echo esc_html( $last ); ?></span>
						<?php if ( ! empty( $plan['last_error'] ) ) : ?>
						<span class="sched-plan-err"><span class="dashicons dashicons-warning"></span> <?php echo esc_html( $plan['last_error'] ); ?></span>
						<?php endif; ?>
					</div>
				</div>
				<div class="sched-plan-actions">
					<button type="button" class="button button-small sched-plan-toggle" data-id="<?php echo esc_attr( $plan['id'] ); ?>">
						<span class="dashicons dashicons-<?php echo $paused ? 'controls-play' : 'controls-pause'; ?>"></span>
						<?php echo $paused ? esc_html__( 'Resume', 'luwipress' ) : esc_html__( 'Pause', 'luwipress' ); ?>
					</button>
					<button type="button" class="button button-small sched-plan-edit" data-id="<?php echo esc_attr( $plan['id'] ); ?>" data-plan='<?php echo esc_attr( wp_json_encode( $plan ) ); ?>'>
						<span class="dashicons dashicons-edit"></span>
					</button>
					<button type="button" class="button button-small sched-plan-delete" data-id="<?php echo esc_attr( $plan['id'] ); ?>" aria-label="<?php esc_attr_e( 'Delete plan', 'luwipress' ); ?>">
						<span class="dashicons dashicons-trash"></span>
					</button>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</section>

	<!-- ═══════════════════════════════════════════════════════
	     TAB: CREATE NEW (sidebar wizard)
	     ═══════════════════════════════════════════════════════ -->
	<section class="sched-tabpanel" id="sched-tabpanel-create" role="tabpanel" aria-labelledby="sched-tab-create" hidden>

		<div class="lp-card sched-wizard-card">
			<form id="sched-wizard-form" class="sched-wizard-layout" autocomplete="off">
				<?php wp_nonce_field( 'luwipress_scheduler_nonce' ); ?>
				<input type="hidden" name="action" value="luwipress_bulk_schedule_content">

				<!-- Left: step sidebar -->
				<aside class="sched-wiz-sidebar" aria-label="<?php esc_attr_e( 'Wizard steps', 'luwipress' ); ?>">
					<ol class="sched-wiz-steps" id="sched-steps" role="list">
						<li class="sched-wiz-step is-active" data-step="1" role="button" tabindex="0">
							<span class="sched-wiz-step-num">1</span>
							<span class="sched-wiz-step-body">
								<span class="sched-wiz-step-lbl"><?php esc_html_e( 'Topics', 'luwipress' ); ?></span>
								<span class="sched-wiz-step-hint"><?php esc_html_e( 'What to write about', 'luwipress' ); ?></span>
							</span>
						</li>
						<li class="sched-wiz-step" data-step="2" role="button" tabindex="0">
							<span class="sched-wiz-step-num">2</span>
							<span class="sched-wiz-step-body">
								<span class="sched-wiz-step-lbl"><?php esc_html_e( 'Style', 'luwipress' ); ?></span>
								<span class="sched-wiz-step-hint"><?php esc_html_e( 'Voice, depth, language', 'luwipress' ); ?></span>
							</span>
						</li>
						<li class="sched-wiz-step" data-step="3" role="button" tabindex="0">
							<span class="sched-wiz-step-num">3</span>
							<span class="sched-wiz-step-body">
								<span class="sched-wiz-step-lbl"><?php esc_html_e( 'Schedule', 'luwipress' ); ?></span>
								<span class="sched-wiz-step-hint"><?php esc_html_e( 'Dates & cadence', 'luwipress' ); ?></span>
							</span>
						</li>
						<li class="sched-wiz-step" data-step="4" role="button" tabindex="0">
							<span class="sched-wiz-step-num">4</span>
							<span class="sched-wiz-step-body">
								<span class="sched-wiz-step-lbl"><?php esc_html_e( 'Review', 'luwipress' ); ?></span>
								<span class="sched-wiz-step-hint"><?php esc_html_e( 'Confirm & queue', 'luwipress' ); ?></span>
							</span>
						</li>
					</ol>
					<div class="sched-wiz-progress-wrap" aria-hidden="true">
						<div class="sched-wiz-progress-bar" style="width:25%"></div>
					</div>
				</aside>

				<!-- Right: active step panel -->
				<div class="sched-wiz-main">

					<!-- ── Step 1: Topics ── -->
					<section class="sched-wiz-panel is-active" data-panel="1">
						<header class="sched-wiz-head">
							<h2><?php esc_html_e( 'What do you want to publish?', 'luwipress' ); ?></h2>
							<p><?php esc_html_e( 'Add one topic per line, up to 50 per batch. Optionally add comma-separated keywords after a pipe.', 'luwipress' ); ?></p>
						</header>

						<div class="sched-field">
							<div class="sched-field-head">
								<label for="sched-wiz-topics"><?php esc_html_e( 'Topics', 'luwipress' ); ?> <span class="sched-req" aria-hidden="true">*</span></label>
								<span class="sched-field-head-actions">
									<button type="button" class="button button-small sched-brainstorm-toggle" id="sched-brainstorm-toggle">
										<span class="dashicons dashicons-lightbulb"></span> <?php esc_html_e( 'Brainstorm with AI', 'luwipress' ); ?>
									</button>
									<span class="sched-bulk-count" id="sched-wiz-count">0 / 50</span>
								</span>
							</div>
							<textarea id="sched-wiz-topics" name="topics" rows="10" class="large-text sched-wiz-topics" placeholder="<?php esc_attr_e( "One topic per line.\nAdvanced — pipe overrides per row:\nSpecific topic title | keywords here, more keywords\nAnother topic | depth=editorial | words=3000\nShort topic | depth=standard | words=900 | image=0", 'luwipress' ); ?>" required></textarea>
						</div>

						<!-- AI Brainstorm -->
						<div class="sched-brainstorm" id="sched-brainstorm" hidden>
							<div class="sched-brainstorm-head">
								<h4><span class="dashicons dashicons-lightbulb"></span> <?php esc_html_e( 'AI topic brainstorm', 'luwipress' ); ?></h4>
								<p><?php esc_html_e( 'Give the AI a theme. It proposes specific, publishable titles — filtering against your last 30 post titles so suggestions stay fresh.', 'luwipress' ); ?></p>
							</div>
							<div class="sched-brainstorm-form">
								<div class="sched-brainstorm-fields">
									<div class="sched-field">
										<label for="sched-brainstorm-theme"><?php esc_html_e( 'Theme', 'luwipress' ); ?></label>
										<input type="text" id="sched-brainstorm-theme" placeholder="<?php esc_attr_e( 'e.g. product care basics, buyer guides, craft stories', 'luwipress' ); ?>" />
									</div>
									<div class="sched-field sched-brainstorm-small">
										<label for="sched-brainstorm-count"><?php esc_html_e( 'Count', 'luwipress' ); ?></label>
										<input type="number" id="sched-brainstorm-count" value="10" min="1" max="20" />
									</div>
									<div class="sched-field">
										<label for="sched-brainstorm-style"><?php esc_html_e( 'Style hint (optional)', 'luwipress' ); ?></label>
										<input type="text" id="sched-brainstorm-style" placeholder="<?php esc_attr_e( 'e.g. story-driven, technique-focused, comparison', 'luwipress' ); ?>" />
									</div>
								</div>
								<button type="button" class="button button-primary sched-brainstorm-run" id="sched-brainstorm-run">
									<span class="dashicons dashicons-admin-customizer"></span> <?php esc_html_e( 'Generate ideas', 'luwipress' ); ?>
								</button>
							</div>
							<div class="sched-brainstorm-results" id="sched-brainstorm-results"></div>
						</div>

						<details class="sched-wiz-syntax">
							<summary><?php esc_html_e( 'Advanced: per-topic overrides', 'luwipress' ); ?></summary>
							<div class="sched-wiz-syntax-body">
								<p><?php esc_html_e( 'Override batch defaults for individual rows using pipe-separated key=value segments:', 'luwipress' ); ?></p>
								<pre class="sched-wiz-code">Long flagship topic | depth=editorial | words=3000
Short product care | depth=standard | words=900 | image=0
Topic with SEO | keywords=primary term, secondary | tone=creative</pre>
								<p class="sched-wiz-syntax-keys"><?php esc_html_e( 'Keys:', 'luwipress' ); ?> <code>keywords</code> · <code>depth</code> · <code>words</code> · <code>tone</code> · <code>lang</code> · <code>image</code> · <code>type</code></p>
							</div>
						</details>
					</section>

					<!-- ── Step 2: Style ── -->
					<section class="sched-wiz-panel" data-panel="2" hidden>
						<header class="sched-wiz-head">
							<h2><?php esc_html_e( 'Pick the voice and depth', 'luwipress' ); ?></h2>
							<p><?php esc_html_e( 'Depth controls article length and the AI system prompt. Editorial costs the most per article — reserve for flagship content.', 'luwipress' ); ?></p>
						</header>

						<div class="sched-field">
							<label class="sched-field-label"><?php esc_html_e( 'Content depth', 'luwipress' ); ?></label>
							<div class="sched-depth-grid">
								<?php $first = true; foreach ( $depth_options as $val => $meta ) : ?>
								<label class="sched-depth-card">
									<input type="radio" name="depth" value="<?php echo esc_attr( $val ); ?>" <?php checked( $first ); ?> />
									<div class="sched-depth-inner">
										<strong><?php echo esc_html( $meta['label'] ); ?></strong>
										<small class="sched-depth-desc"><?php echo esc_html( $meta['desc'] ); ?></small>
										<small class="sched-depth-hint"><?php echo esc_html( $meta['hint'] ); ?></small>
									</div>
								</label>
								<?php $first = false; endforeach; ?>
							</div>
						</div>

						<div class="sched-row-3">
							<div class="sched-field">
								<label for="sched-wiz-tone"><?php esc_html_e( 'Tone', 'luwipress' ); ?></label>
								<select id="sched-wiz-tone" name="tone">
									<?php foreach ( $tone_options as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, 'informative' ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="sched-field">
								<label for="sched-wiz-words"><?php esc_html_e( 'Target words', 'luwipress' ); ?></label>
								<input type="number" id="sched-wiz-words" name="word_count" value="1500" min="300" max="5000" step="100" />
							</div>
							<div class="sched-field">
								<label for="sched-wiz-lang"><?php esc_html_e( 'Language', 'luwipress' ); ?></label>
								<select id="sched-wiz-lang" name="language">
									<?php foreach ( $language_options as $code => $name ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $target_language, $code ); ?>><?php echo esc_html( $name ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>

						<div class="sched-row-2">
							<div class="sched-field">
								<label for="sched-wiz-type"><?php esc_html_e( 'Post type', 'luwipress' ); ?></label>
								<select id="sched-wiz-type" name="target_post_type">
									<option value="post"><?php esc_html_e( 'Blog Post', 'luwipress' ); ?></option>
									<option value="page"><?php esc_html_e( 'Page', 'luwipress' ); ?></option>
									<?php if ( post_type_exists( 'product' ) ) : ?>
									<option value="product"><?php esc_html_e( 'Product', 'luwipress' ); ?></option>
									<?php endif; ?>
								</select>
							</div>
							<div class="sched-field">
								<label class="sched-checkbox sched-checkbox--inline">
									<input type="checkbox" name="generate_image" value="1" checked />
									<span><?php esc_html_e( 'Generate a featured image for each post', 'luwipress' ); ?></span>
								</label>
							</div>
						</div>

						<label class="sched-checkbox sched-outline-gate" id="sched-outline-gate">
							<input type="checkbox" name="use_outline_approval" value="1" />
							<span>
								<strong><?php esc_html_e( 'Review outline before writing', 'luwipress' ); ?></strong>
								<small><?php esc_html_e( 'AI drafts a structural outline first and waits for your edits. Only applies to deep/editorial depth — standard skips this step.', 'luwipress' ); ?></small>
							</span>
						</label>

						<?php if ( $multilingual_ready ) : ?>
						<div class="sched-field sched-i18n-field" id="sched-i18n-field">
							<div class="sched-i18n-head">
								<label><?php esc_html_e( 'Also generate in additional languages', 'luwipress' ); ?></label>
								<span class="sched-i18n-plugin"><?php echo esc_html( strtoupper( $translation_plugin ) ); ?></span>
							</div>
							<p class="sched-i18n-help"><?php esc_html_e( 'Each selected language gets its own article written natively (not a machine translation) and linked to the primary via the translation plugin. AI cost multiplies per language.', 'luwipress' ); ?></p>
							<div class="sched-i18n-chips" id="sched-i18n-chips">
								<?php foreach ( $translation_langs as $lang_code ) : ?>
								<label class="sched-i18n-chip" data-lang="<?php echo esc_attr( $lang_code ); ?>">
									<input type="checkbox" name="additional_languages[]" value="<?php echo esc_attr( $lang_code ); ?>" />
									<span class="sched-i18n-chip-code"><?php echo esc_html( strtoupper( $lang_code ) ); ?></span>
								</label>
								<?php endforeach; ?>
							</div>
						</div>
						<?php endif; ?>

						<details class="sched-wiz-voice" <?php echo '' !== $brand_voice_default ? 'open' : ''; ?>>
							<summary>
								<span class="dashicons dashicons-microphone"></span>
								<?php esc_html_e( 'Brand voice card', 'luwipress' ); ?>
								<?php if ( '' !== $brand_voice_default ) : ?>
								<span class="sched-wiz-voice-pill"><?php esc_html_e( 'site default active', 'luwipress' ); ?></span>
								<?php else : ?>
								<span class="sched-wiz-voice-pill sched-wiz-voice-pill--empty"><?php esc_html_e( 'not set', 'luwipress' ); ?></span>
								<?php endif; ?>
							</summary>
							<div class="sched-wiz-voice-body">
								<p class="sched-wiz-voice-help">
									<?php esc_html_e( "Describe how this site's content should feel — audience, taboos, signature phrases, cultural context, things to never say. This text layers on top of the depth rules for every article in the batch.", 'luwipress' ); ?>
								</p>
								<textarea name="brand_voice" rows="6" class="large-text sched-wiz-voice-text" placeholder="<?php esc_attr_e( "Example:\n• Audience profile.\n• Desired register — curator, teacher, storyteller.\n• Forbidden openers / clichés.\n• Preferred opening style (anecdote, contrast, fact).\n• Cultural context to weave in.\n• Words, claims, or competitors to avoid.", 'luwipress' ); ?>"><?php echo esc_textarea( $brand_voice_default ); ?></textarea>
								<?php if ( $can_save_voice ) : ?>
								<label class="sched-checkbox sched-wiz-voice-save">
									<input type="checkbox" name="save_brand_voice_as_default" value="1" />
									<span><?php esc_html_e( 'Save this as the site default after queuing', 'luwipress' ); ?></span>
								</label>
								<?php endif; ?>
							</div>
						</details>
					</section>

					<!-- ── Step 3: Schedule ── -->
					<section class="sched-wiz-panel" data-panel="3" hidden>
						<header class="sched-wiz-head">
							<h2><?php esc_html_e( 'When and how should these publish?', 'luwipress' ); ?></h2>
							<p><?php esc_html_e( 'Pick a start date. With more than one topic, cadence spreads them out. AI stagger keeps generation runs from all firing at once.', 'luwipress' ); ?></p>
						</header>

						<div class="sched-field">
							<label class="sched-field-label"><?php esc_html_e( 'After AI generates each article', 'luwipress' ); ?></label>
							<div class="sched-mode-grid">
								<label class="sched-mode-card">
									<input type="radio" name="publish_mode" value="draft" checked />
									<div class="sched-mode-inner">
										<strong><?php esc_html_e( 'Save as draft for review', 'luwipress' ); ?></strong>
										<small><?php esc_html_e( 'Ships into WP drafts with the target publish date baked in. Review, enrich, then publish yourself.', 'luwipress' ); ?></small>
									</div>
								</label>
								<label class="sched-mode-card">
									<input type="radio" name="publish_mode" value="auto" />
									<div class="sched-mode-inner">
										<strong><?php esc_html_e( 'Auto-publish on schedule', 'luwipress' ); ?></strong>
										<small><?php esc_html_e( 'Goes live at the publish date with no manual step. Best for high-volume batches where voice already works.', 'luwipress' ); ?></small>
									</div>
								</label>
							</div>
						</div>

						<div class="sched-row-2">
							<div class="sched-field">
								<label for="sched-wiz-date"><?php esc_html_e( 'Start date', 'luwipress' ); ?> <span class="sched-req" aria-hidden="true">*</span></label>
								<input type="date" id="sched-wiz-date" name="start_date" min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" value="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( '+1 day' ) ) ); ?>" required />
							</div>
							<div class="sched-field">
								<label for="sched-wiz-time"><?php esc_html_e( 'Publish time', 'luwipress' ); ?></label>
								<input type="time" id="sched-wiz-time" name="start_time" value="09:00" />
							</div>
						</div>

						<div class="sched-row-2 sched-bulk-only" hidden>
							<div class="sched-field">
								<label><?php esc_html_e( 'Cadence', 'luwipress' ); ?></label>
								<div class="sched-bulk-interval">
									<input type="number" name="interval_value" value="1" min="1" max="30" />
									<select name="interval_unit">
										<option value="day"><?php esc_html_e( 'day(s) between posts', 'luwipress' ); ?></option>
										<option value="hour"><?php esc_html_e( 'hour(s) between posts', 'luwipress' ); ?></option>
									</select>
								</div>
							</div>
							<div class="sched-field">
								<label for="sched-wiz-stagger"><?php esc_html_e( 'AI stagger (minutes between runs)', 'luwipress' ); ?></label>
								<input type="number" id="sched-wiz-stagger" name="generate_offset" value="10" min="0" max="60" />
							</div>
						</div>
					</section>

					<!-- ── Step 4: Review ── -->
					<section class="sched-wiz-panel" data-panel="4" hidden>
						<header class="sched-wiz-head">
							<h2><?php esc_html_e( 'Ready to queue?', 'luwipress' ); ?></h2>
							<p><?php esc_html_e( 'Double-check the summary below. Queuing does not spend AI budget immediately — generation runs on schedule and defers automatically if your daily cap fills up.', 'luwipress' ); ?></p>
						</header>
						<div class="sched-wiz-summary" id="sched-wiz-summary"></div>
						<div class="sched-wiz-budget" id="sched-wiz-budget" hidden>
							<div class="sched-wiz-budget-head">
								<span class="dashicons dashicons-chart-line"></span>
								<strong><?php esc_html_e( 'Estimated AI cost', 'luwipress' ); ?></strong>
								<span class="sched-wiz-budget-provider" id="sched-wiz-budget-provider"></span>
							</div>
							<div class="sched-wiz-budget-body" id="sched-wiz-budget-body">
								<span class="sched-wiz-budget-loading"><?php esc_html_e( 'Calculating…', 'luwipress' ); ?></span>
							</div>
							<p class="sched-wiz-budget-disclaimer"><?php esc_html_e( 'Estimate based on current provider pricing and per-article token heuristics. Actual cost may vary ±20%. Image cost approximate (DALL·E 3 std).', 'luwipress' ); ?></p>
						</div>
					</section>

					<!-- Navigation -->
					<div class="sched-wiz-nav">
						<button type="button" class="button sched-wiz-back" disabled>
							<span class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e( 'Back', 'luwipress' ); ?>
						</button>
						<div class="sched-wiz-nav-right">
							<button type="button" class="button button-primary sched-wiz-next">
								<?php esc_html_e( 'Next', 'luwipress' ); ?> <span class="dashicons dashicons-arrow-right-alt2"></span>
							</button>
							<button type="submit" class="button button-primary sched-wiz-submit" hidden <?php disabled( ! $has_ai_key ); ?>>
								<span class="dashicons dashicons-controls-play"></span>
								<?php esc_html_e( 'Queue all topics', 'luwipress' ); ?>
							</button>
						</div>
					</div>

					<div id="sched-wiz-result" aria-live="polite"></div>
				</div>
			</form>
		</div>
	</section>

	<!-- ─── Recurring plan modal ─────────────────────────── -->
	<div class="sched-modal" id="sched-plan-modal" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="sched-plan-modal-title">
		<div class="sched-modal-backdrop" data-close="1"></div>
		<div class="sched-modal-panel" role="document">
			<header class="sched-modal-head">
				<div>
					<h3 id="sched-plan-modal-title"><?php esc_html_e( 'Recurring plan', 'luwipress' ); ?></h3>
					<p class="sched-modal-sub"><?php esc_html_e( 'AI auto-brainstorms and queues new topics on your cadence.', 'luwipress' ); ?></p>
				</div>
				<button type="button" class="sched-modal-close" data-close="1" aria-label="<?php esc_attr_e( 'Close', 'luwipress' ); ?>">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</header>
			<div class="sched-modal-body">
				<form id="sched-plan-form" autocomplete="off">
					<input type="hidden" name="plan_id" value="" />
					<div class="sched-row-2">
						<div class="sched-field">
							<label><?php esc_html_e( 'Plan name', 'luwipress' ); ?> <span class="sched-req" aria-hidden="true">*</span></label>
							<input type="text" name="name" placeholder="<?php esc_attr_e( 'e.g. Weekly how-to calendar', 'luwipress' ); ?>" required />
						</div>
						<div class="sched-field">
							<label><?php esc_html_e( 'Cadence', 'luwipress' ); ?></label>
							<select name="cadence">
								<option value="daily"><?php esc_html_e( 'Daily', 'luwipress' ); ?></option>
								<option value="weekly" selected><?php esc_html_e( 'Weekly', 'luwipress' ); ?></option>
								<option value="biweekly"><?php esc_html_e( 'Every 2 weeks', 'luwipress' ); ?></option>
								<option value="monthly"><?php esc_html_e( 'Monthly', 'luwipress' ); ?></option>
							</select>
						</div>
					</div>
					<div class="sched-field">
						<label><?php esc_html_e( 'Theme', 'luwipress' ); ?> <span class="sched-req" aria-hidden="true">*</span></label>
						<input type="text" name="theme" placeholder="<?php esc_attr_e( 'What should these posts be about? Be specific.', 'luwipress' ); ?>" required />
					</div>
					<div class="sched-field">
						<label><?php esc_html_e( 'Style hint (optional)', 'luwipress' ); ?></label>
						<input type="text" name="style_hint" placeholder="<?php esc_attr_e( 'e.g. story-driven, technique-focused, buyer-journey', 'luwipress' ); ?>" />
					</div>
					<div class="sched-row-3">
						<div class="sched-field">
							<label><?php esc_html_e( 'Posts per run', 'luwipress' ); ?></label>
							<input type="number" name="count" value="3" min="1" max="10" />
						</div>
						<div class="sched-field">
							<label><?php esc_html_e( 'Depth', 'luwipress' ); ?></label>
							<select name="depth">
								<option value="standard"><?php esc_html_e( 'Standard', 'luwipress' ); ?></option>
								<option value="deep"><?php esc_html_e( 'Deep', 'luwipress' ); ?></option>
								<option value="editorial"><?php esc_html_e( 'Editorial', 'luwipress' ); ?></option>
							</select>
						</div>
						<div class="sched-field">
							<label><?php esc_html_e( 'Words', 'luwipress' ); ?></label>
							<input type="number" name="word_count" value="1500" min="300" max="5000" step="100" />
						</div>
					</div>
					<div class="sched-row-3">
						<div class="sched-field">
							<label><?php esc_html_e( 'Tone', 'luwipress' ); ?></label>
							<select name="tone">
								<?php foreach ( $tone_options as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, 'informative' ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="sched-field">
							<label><?php esc_html_e( 'Language', 'luwipress' ); ?></label>
							<select name="language">
								<?php foreach ( $language_options as $code => $name ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $target_language, $code ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="sched-field">
							<label><?php esc_html_e( 'Post type', 'luwipress' ); ?></label>
							<select name="target_post_type">
								<option value="post"><?php esc_html_e( 'Blog Post', 'luwipress' ); ?></option>
								<option value="page"><?php esc_html_e( 'Page', 'luwipress' ); ?></option>
							</select>
						</div>
					</div>
					<div class="sched-row-2">
						<div class="sched-field">
							<label><?php esc_html_e( 'After generation', 'luwipress' ); ?></label>
							<select name="publish_mode">
								<option value="draft"><?php esc_html_e( 'Save as draft for review', 'luwipress' ); ?></option>
								<option value="auto"><?php esc_html_e( 'Auto-publish', 'luwipress' ); ?></option>
							</select>
						</div>
						<div class="sched-field">
							<label class="sched-checkbox sched-checkbox--inline">
								<input type="checkbox" name="generate_image" value="1" checked />
								<span><?php esc_html_e( 'Generate a featured image', 'luwipress' ); ?></span>
							</label>
						</div>
					</div>
				</form>
			</div>
			<footer class="sched-modal-foot">
				<div></div>
				<div class="sched-modal-foot-right">
					<button type="button" class="button" data-close="1"><?php esc_html_e( 'Cancel', 'luwipress' ); ?></button>
					<button type="button" class="button button-primary sched-plan-save"><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Save plan', 'luwipress' ); ?></button>
				</div>
			</footer>
		</div>
	</div>

	<!-- ─── Outline review modal ─────────────────────────── -->
	<div class="sched-modal" id="sched-outline-modal" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="sched-outline-modal-title">
		<div class="sched-modal-backdrop" data-close="1"></div>
		<div class="sched-modal-panel" role="document">
			<header class="sched-modal-head">
				<div>
					<h3 id="sched-outline-modal-title"><?php esc_html_e( 'Review outline', 'luwipress' ); ?></h3>
					<p class="sched-modal-sub" id="sched-outline-modal-topic"></p>
				</div>
				<button type="button" class="sched-modal-close" data-close="1" aria-label="<?php esc_attr_e( 'Close', 'luwipress' ); ?>">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</header>
			<div class="sched-modal-body" id="sched-outline-modal-body">
				<div class="sched-outline-loading"><span class="dashicons dashicons-update spin"></span> <?php esc_html_e( 'Loading outline…', 'luwipress' ); ?></div>
			</div>
			<footer class="sched-modal-foot">
				<button type="button" class="button sched-outline-regen"><span class="dashicons dashicons-image-rotate"></span> <?php esc_html_e( 'Regenerate outline', 'luwipress' ); ?></button>
				<div class="sched-modal-foot-right">
					<button type="button" class="button" data-close="1"><?php esc_html_e( 'Cancel', 'luwipress' ); ?></button>
					<button type="button" class="button button-primary sched-outline-approve">
						<span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Approve & generate article', 'luwipress' ); ?>
					</button>
				</div>
			</footer>
		</div>
	</div>

	<!-- ─── Confirm modal (replaces native window.confirm) ─── -->
	<div class="sched-modal sched-confirm-modal" id="sched-confirm-modal" hidden aria-hidden="true" role="alertdialog" aria-modal="true" aria-labelledby="sched-confirm-title" aria-describedby="sched-confirm-message">
		<div class="sched-modal-backdrop" data-close="1"></div>
		<div class="sched-modal-panel sched-confirm-panel" role="document">
			<header class="sched-modal-head">
				<div class="sched-confirm-head-icon" id="sched-confirm-icon" aria-hidden="true">
					<span class="dashicons dashicons-warning"></span>
				</div>
				<div>
					<h3 id="sched-confirm-title"><?php esc_html_e( 'Are you sure?', 'luwipress' ); ?></h3>
					<p class="sched-modal-sub" id="sched-confirm-message"></p>
				</div>
				<button type="button" class="sched-modal-close" data-close="1" aria-label="<?php esc_attr_e( 'Close', 'luwipress' ); ?>">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</header>
			<footer class="sched-modal-foot">
				<div></div>
				<div class="sched-modal-foot-right">
					<button type="button" class="button" data-close="1" id="sched-confirm-cancel"><?php esc_html_e( 'Cancel', 'luwipress' ); ?></button>
					<button type="button" class="button button-primary" id="sched-confirm-ok"><?php esc_html_e( 'Confirm', 'luwipress' ); ?></button>
				</div>
			</footer>
		</div>
	</div>

</div>
