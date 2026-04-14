<?php
/**
 * LuwiPress Content Scheduler Page
 *
 * AI-powered content generation and scheduling interface.
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
$target_language  = get_option( 'luwipress_target_language', 'en' );
$ai_provider      = get_option( 'luwipress_ai_provider', 'openai' );
$ai_model         = get_option( 'luwipress_ai_model', 'gpt-4o-mini' );
$has_ai_key       = ! empty( get_option( 'luwipress_openai_api_key', '' ) )
                 || ! empty( get_option( 'luwipress_anthropic_api_key', '' ) )
                 || ! empty( get_option( 'luwipress_google_ai_api_key', '' ) );

$provider_labels = array( 'openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'google' => 'Google AI' );

$status_config = array(
	'pending'    => array( 'label' => __( 'Pending', 'luwipress' ),      'color' => '#6b7280', 'icon' => 'clock' ),
	'generating' => array( 'label' => __( 'Generating', 'luwipress' ),   'color' => '#f59e0b', 'icon' => 'update' ),
	'ready'      => array( 'label' => __( 'Ready', 'luwipress' ),        'color' => '#2563eb', 'icon' => 'yes-alt' ),
	'published'  => array( 'label' => __( 'Published', 'luwipress' ),    'color' => '#16a34a', 'icon' => 'admin-post' ),
	'failed'     => array( 'label' => __( 'Failed', 'luwipress' ),       'color' => '#dc2626', 'icon' => 'dismiss' ),
);

$tone_options = array(
	'professional' => __( 'Professional', 'luwipress' ),
	'casual'       => __( 'Casual & Friendly', 'luwipress' ),
	'academic'     => __( 'Academic', 'luwipress' ),
	'creative'     => __( 'Creative & Engaging', 'luwipress' ),
	'persuasive'   => __( 'Persuasive & Sales', 'luwipress' ),
	'informative'  => __( 'Informative & Educational', 'luwipress' ),
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
	$s = get_post_meta( $item->ID, '_luwipress_schedule_status', true );
	if ( isset( $counts[ $s ] ) ) {
		$counts[ $s ]++;
	}
}
?>

<div class="wrap luwipress-dashboard">

	<!-- Header -->
	<div class="lp-header">
		<div class="lp-header-left">
			<h1 class="lp-title">
				<span class="dashicons dashicons-calendar-alt" style="color:var(--lp-primary);"></span>
				<?php esc_html_e( 'Content Scheduler', 'luwipress' ); ?>
			</h1>
		</div>
		<div class="lp-header-actions">
			<?php if ( $has_ai_key ) : ?>
			<span class="lp-pill pill-ok">
				<span class="dashicons dashicons-admin-generic"></span>
				<?php echo esc_html( ( $provider_labels[ $ai_provider ] ?? 'AI' ) . ' / ' . $ai_model ); ?>
			</span>
			<?php else : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=luwipress-settings&tab=api-keys' ) ); ?>" class="lp-pill pill-err">
				<span class="dashicons dashicons-warning"></span>
				<?php esc_html_e( 'No AI key configured', 'luwipress' ); ?>
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
	<div class="lp-card sched-form-card">
		<div class="lp-card-header">
			<h3><span class="dashicons dashicons-edit-large"></span> <?php esc_html_e( 'New Content', 'luwipress' ); ?></h3>
		</div>

		<form id="sched-form">
			<?php wp_nonce_field( 'luwipress_scheduler_nonce' ); ?>

			<div class="sched-form-grid">
				<!-- Main Fields -->
				<div class="sched-form-main">
					<div class="sched-field">
						<label for="sched-topic"><?php esc_html_e( 'Topic', 'luwipress' ); ?> <span style="color:var(--lp-error);">*</span></label>
						<input type="text" id="sched-topic" name="topic" class="large-text"
						       placeholder="<?php esc_attr_e( 'e.g., Best practices for acoustic guitar maintenance', 'luwipress' ); ?>" required />
					</div>
					<div class="sched-field">
						<label for="sched-keywords"><?php esc_html_e( 'SEO Keywords', 'luwipress' ); ?></label>
						<input type="text" id="sched-keywords" name="keywords" class="large-text"
						       placeholder="<?php esc_attr_e( 'guitar care, string changing, acoustic maintenance', 'luwipress' ); ?>" />
					</div>
					<div class="sched-row-3">
						<div class="sched-field">
							<label for="sched-tone"><?php esc_html_e( 'Tone', 'luwipress' ); ?></label>
							<select id="sched-tone" name="tone">
								<?php foreach ( $tone_options as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="sched-field">
							<label for="sched-words"><?php esc_html_e( 'Words', 'luwipress' ); ?></label>
							<input type="number" id="sched-words" name="word_count" value="1500" min="300" max="5000" />
						</div>
						<div class="sched-field">
							<label for="sched-lang"><?php esc_html_e( 'Language', 'luwipress' ); ?></label>
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
						<label for="sched-date"><?php esc_html_e( 'Publish Date', 'luwipress' ); ?> <span style="color:var(--lp-error);">*</span></label>
						<input type="date" id="sched-date" name="publish_date" required
						       min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" />
					</div>
					<div class="sched-field">
						<label for="sched-time"><?php esc_html_e( 'Time', 'luwipress' ); ?></label>
						<input type="time" id="sched-time" name="publish_time" value="09:00" />
					</div>
					<div class="sched-field">
						<label for="sched-type"><?php esc_html_e( 'Post Type', 'luwipress' ); ?></label>
						<select id="sched-type" name="target_post_type">
							<option value="post"><?php esc_html_e( 'Blog Post', 'luwipress' ); ?></option>
							<option value="page"><?php esc_html_e( 'Page', 'luwipress' ); ?></option>
							<?php if ( post_type_exists( 'product' ) ) : ?>
							<option value="product"><?php esc_html_e( 'Product', 'luwipress' ); ?></option>
							<?php endif; ?>
						</select>
					</div>
					<label class="sched-checkbox">
						<input type="checkbox" name="generate_image" value="1" checked />
						<?php esc_html_e( 'Generate featured image', 'luwipress' ); ?>
					</label>
					<button type="submit" class="button button-primary sched-submit" <?php echo $has_ai_key ? '' : 'disabled'; ?>>
						<span class="dashicons dashicons-controls-play"></span>
						<?php esc_html_e( 'Generate & Schedule', 'luwipress' ); ?>
					</button>
				</div>
			</div>
		</form>
		<div id="sched-result"></div>
	</div>

	<!-- Content List -->
	<div class="lp-card">
		<div class="lp-card-header">
			<h3><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Scheduled Content', 'luwipress' ); ?></h3>
			<span class="lp-card-badge"><?php echo absint( count( $scheduled_items ) ); ?> <?php esc_html_e( 'items', 'luwipress' ); ?></span>
		</div>

		<?php if ( empty( $scheduled_items ) ) : ?>
		<div class="sched-empty">
			<span class="dashicons dashicons-calendar-alt"></span>
			<h3><?php esc_html_e( 'No scheduled content yet', 'luwipress' ); ?></h3>
			<p><?php esc_html_e( 'Create your first AI-generated content above.', 'luwipress' ); ?></p>
		</div>
		<?php else : ?>
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
					<a href="<?php echo esc_url( get_edit_post_link( $published_id ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'luwipress' ); ?></a>
					<a href="<?php echo esc_url( get_permalink( $published_id ) ); ?>" class="button button-small" target="_blank"><?php esc_html_e( 'View', 'luwipress' ); ?></a>
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
