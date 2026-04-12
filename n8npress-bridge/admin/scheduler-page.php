<?php
/**
 * n8nPress Content Scheduler Page
 *
 * AI-powered content generation and scheduling interface.
 *
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'edit_posts' ) ) {
	wp_die( esc_html__( 'Insufficient permissions.', 'n8npress' ) );
}

$scheduled_items  = N8nPress_Content_Scheduler::get_scheduled_items();
$target_language  = get_option( 'n8npress_target_language', 'en' );
$ai_provider      = get_option( 'n8npress_ai_provider', 'openai' );
$ai_model         = get_option( 'n8npress_ai_model', 'gpt-4o-mini' );
$has_ai_key       = ! empty( get_option( 'n8npress_openai_api_key', '' ) )
                 || ! empty( get_option( 'n8npress_anthropic_api_key', '' ) )
                 || ! empty( get_option( 'n8npress_google_ai_api_key', '' ) );

$provider_labels = array( 'openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'google' => 'Google AI' );

$status_config = array(
	'pending'    => array( 'label' => __( 'Pending', 'n8npress' ),      'color' => '#6b7280', 'icon' => 'clock' ),
	'generating' => array( 'label' => __( 'Generating', 'n8npress' ),   'color' => '#f59e0b', 'icon' => 'update' ),
	'ready'      => array( 'label' => __( 'Ready', 'n8npress' ),        'color' => '#2563eb', 'icon' => 'yes-alt' ),
	'published'  => array( 'label' => __( 'Published', 'n8npress' ),    'color' => '#16a34a', 'icon' => 'admin-post' ),
	'failed'     => array( 'label' => __( 'Failed', 'n8npress' ),       'color' => '#dc2626', 'icon' => 'dismiss' ),
);

$tone_options = array(
	'professional' => __( 'Professional', 'n8npress' ),
	'casual'       => __( 'Casual & Friendly', 'n8npress' ),
	'academic'     => __( 'Academic', 'n8npress' ),
	'creative'     => __( 'Creative & Engaging', 'n8npress' ),
	'persuasive'   => __( 'Persuasive & Sales', 'n8npress' ),
	'informative'  => __( 'Informative & Educational', 'n8npress' ),
);

$language_options = array(
	'en' => 'English', 'tr' => 'Turkce', 'de' => 'Deutsch', 'fr' => 'Francais',
	'ar' => 'Arabic', 'es' => 'Espanol', 'it' => 'Italiano', 'nl' => 'Nederlands',
	'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese', 'pt' => 'Portuguese',
	'ko' => 'Korean', 'hi' => 'Hindi',
);

// Count by status
$counts = array( 'pending' => 0, 'generating' => 0, 'ready' => 0, 'published' => 0, 'failed' => 0 );
foreach ( $scheduled_items as $item ) {
	$s = get_post_meta( $item->ID, '_n8npress_schedule_status', true );
	if ( isset( $counts[ $s ] ) ) {
		$counts[ $s ]++;
	}
}
?>

<div class="wrap n8npress-dashboard">

	<!-- Header -->
	<div class="n8np-header">
		<div class="n8np-header-left">
			<h1 class="n8np-title">
				<span class="dashicons dashicons-calendar-alt" style="color:var(--n8n-primary);"></span>
				<?php esc_html_e( 'Content Scheduler', 'n8npress' ); ?>
			</h1>
		</div>
		<div class="n8np-header-actions">
			<?php if ( $has_ai_key ) : ?>
			<span class="n8np-pill pill-ok">
				<span class="dashicons dashicons-admin-generic"></span>
				<?php echo esc_html( ( $provider_labels[ $ai_provider ] ?? 'AI' ) . ' / ' . $ai_model ); ?>
			</span>
			<?php else : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=n8npress-settings&tab=api-keys' ) ); ?>" class="n8np-pill pill-err">
				<span class="dashicons dashicons-warning"></span>
				<?php esc_html_e( 'No AI key configured', 'n8npress' ); ?>
			</a>
			<?php endif; ?>
		</div>
	</div>

	<!-- Status Cards -->
	<div class="sched-stats">
		<?php foreach ( $status_config as $key => $cfg ) :
			if ( 0 === $counts[ $key ] && in_array( $key, array( 'failed' ), true ) ) continue;
		?>
		<div class="sched-stat-card" style="--accent:<?php echo esc_attr( $cfg['color'] ); ?>">
			<span class="dashicons dashicons-<?php echo esc_attr( $cfg['icon'] ); ?>"></span>
			<strong><?php echo absint( $counts[ $key ] ); ?></strong>
			<span><?php echo esc_html( $cfg['label'] ); ?></span>
		</div>
		<?php endforeach; ?>
	</div>

	<!-- Create Form -->
	<div class="n8np-card sched-form-card">
		<div class="n8np-card-header">
			<h3><span class="dashicons dashicons-edit-large"></span> <?php esc_html_e( 'New Content', 'n8npress' ); ?></h3>
		</div>

		<form id="sched-form">
			<?php wp_nonce_field( 'n8npress_scheduler_nonce' ); ?>

			<div class="sched-form-grid">
				<!-- Main Fields -->
				<div class="sched-form-main">
					<div class="sched-field">
						<label for="sched-topic"><?php esc_html_e( 'Topic', 'n8npress' ); ?> <span style="color:var(--n8n-error);">*</span></label>
						<input type="text" id="sched-topic" name="topic" class="large-text"
						       placeholder="<?php esc_attr_e( 'e.g., Best practices for acoustic guitar maintenance', 'n8npress' ); ?>" required />
					</div>
					<div class="sched-field">
						<label for="sched-keywords"><?php esc_html_e( 'SEO Keywords', 'n8npress' ); ?></label>
						<input type="text" id="sched-keywords" name="keywords" class="large-text"
						       placeholder="<?php esc_attr_e( 'guitar care, string changing, acoustic maintenance', 'n8npress' ); ?>" />
					</div>
					<div class="sched-row-3">
						<div class="sched-field">
							<label for="sched-tone"><?php esc_html_e( 'Tone', 'n8npress' ); ?></label>
							<select id="sched-tone" name="tone">
								<?php foreach ( $tone_options as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="sched-field">
							<label for="sched-words"><?php esc_html_e( 'Words', 'n8npress' ); ?></label>
							<input type="number" id="sched-words" name="word_count" value="1500" min="300" max="5000" />
						</div>
						<div class="sched-field">
							<label for="sched-lang"><?php esc_html_e( 'Language', 'n8npress' ); ?></label>
							<select id="sched-lang" name="language">
								<?php foreach ( $language_options as $code => $name ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $target_language, $code ); ?>>
									<?php echo esc_html( $name ); ?>
								</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</div>

				<!-- Sidebar -->
				<div class="sched-form-side">
					<div class="sched-field">
						<label for="sched-date"><?php esc_html_e( 'Publish Date', 'n8npress' ); ?> <span style="color:var(--n8n-error);">*</span></label>
						<input type="date" id="sched-date" name="publish_date" required
						       min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" />
					</div>
					<div class="sched-field">
						<label for="sched-time"><?php esc_html_e( 'Time', 'n8npress' ); ?></label>
						<input type="time" id="sched-time" name="publish_time" value="09:00" />
					</div>
					<div class="sched-field">
						<label for="sched-type"><?php esc_html_e( 'Post Type', 'n8npress' ); ?></label>
						<select id="sched-type" name="target_post_type">
							<option value="post"><?php esc_html_e( 'Blog Post', 'n8npress' ); ?></option>
							<option value="page"><?php esc_html_e( 'Page', 'n8npress' ); ?></option>
							<?php if ( post_type_exists( 'product' ) ) : ?>
							<option value="product"><?php esc_html_e( 'Product', 'n8npress' ); ?></option>
							<?php endif; ?>
						</select>
					</div>
					<label class="sched-checkbox">
						<input type="checkbox" name="generate_image" value="1" checked />
						<?php esc_html_e( 'Generate featured image', 'n8npress' ); ?>
					</label>
					<button type="submit" class="button button-primary sched-submit" <?php echo $has_ai_key ? '' : 'disabled'; ?>>
						<span class="dashicons dashicons-controls-play"></span>
						<?php esc_html_e( 'Generate & Schedule', 'n8npress' ); ?>
					</button>
				</div>
			</div>
		</form>
		<div id="sched-result"></div>
	</div>

	<!-- Content List -->
	<div class="n8np-card">
		<div class="n8np-card-header">
			<h3><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Scheduled Content', 'n8npress' ); ?></h3>
			<span class="n8np-card-badge"><?php echo absint( count( $scheduled_items ) ); ?> <?php esc_html_e( 'items', 'n8npress' ); ?></span>
		</div>

		<?php if ( empty( $scheduled_items ) ) : ?>
		<div class="sched-empty">
			<span class="dashicons dashicons-calendar-alt"></span>
			<h3><?php esc_html_e( 'No scheduled content yet', 'n8npress' ); ?></h3>
			<p><?php esc_html_e( 'Create your first AI-generated content above.', 'n8npress' ); ?></p>
		</div>
		<?php else : ?>
		<div class="sched-list" id="sched-list">
			<?php foreach ( $scheduled_items as $item ) :
				$status       = get_post_meta( $item->ID, '_n8npress_schedule_status', true );
				$topic        = get_post_meta( $item->ID, '_n8npress_schedule_topic', true );
				$type         = get_post_meta( $item->ID, '_n8npress_schedule_type', true );
				$lang         = get_post_meta( $item->ID, '_n8npress_schedule_language', true );
				$pub_date     = get_post_meta( $item->ID, '_n8npress_schedule_date', true );
				$published_id = get_post_meta( $item->ID, '_n8npress_published_post_id', true );
				$error        = get_post_meta( $item->ID, '_n8npress_schedule_error', true );
				$tone         = get_post_meta( $item->ID, '_n8npress_schedule_tone', true );
				$words        = get_post_meta( $item->ID, '_n8npress_schedule_words', true );
				$cfg          = $status_config[ $status ] ?? $status_config['pending'];
			?>
			<div class="sched-item <?php echo 'generating' === $status ? 'sched-pulse' : ''; ?>" data-id="<?php echo absint( $item->ID ); ?>" data-status="<?php echo esc_attr( $status ); ?>">
				<div class="sched-item-status" style="background:<?php echo esc_attr( $cfg['color'] ); ?>">
					<span class="dashicons dashicons-<?php echo esc_attr( $cfg['icon'] ); ?>"></span>
				</div>
				<div class="sched-item-body">
					<div class="sched-item-title"><?php echo esc_html( $topic ); ?></div>
					<div class="sched-item-meta">
						<span class="sched-tag"><?php echo esc_html( $type ); ?></span>
						<span class="sched-tag"><?php echo esc_html( strtoupper( $lang ) ); ?></span>
						<?php if ( $tone ) : ?><span class="sched-tag"><?php echo esc_html( $tone ); ?></span><?php endif; ?>
						<?php if ( $words ) : ?><span class="sched-tag"><?php echo absint( $words ); ?>w</span><?php endif; ?>
						<?php if ( $pub_date ) : ?>
						<span class="sched-date"><span class="dashicons dashicons-calendar-alt"></span> <?php echo esc_html( wp_date( 'M j, Y H:i', strtotime( $pub_date ) ) ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( $error ) : ?>
					<div class="sched-error"><span class="dashicons dashicons-warning"></span> <?php echo esc_html( $error ); ?></div>
					<?php endif; ?>
				</div>
				<div class="sched-item-actions">
					<?php if ( $published_id ) : ?>
					<a href="<?php echo esc_url( get_edit_post_link( $published_id ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'n8npress' ); ?></a>
					<a href="<?php echo esc_url( get_permalink( $published_id ) ); ?>" class="button button-small" target="_blank"><?php esc_html_e( 'View', 'n8npress' ); ?></a>
					<?php elseif ( 'published' !== $status ) : ?>
					<button type="button" class="button button-small sched-delete" data-id="<?php echo absint( $item->ID ); ?>">
						<span class="dashicons dashicons-trash"></span>
					</button>
					<?php endif; ?>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>

</div>
