<?php
/**
 * LuwiPress FAQ Editor — metabox template
 *
 * Renders the FAQ row repeater inside the LuwiPress FAQ metabox. Expects
 * the following variables in scope (set by
 * LuwiPress_FAQ_Editor::render_metabox()):
 *
 *   - $post_id        int    Current post ID
 *   - $rows           array  Stored FAQ rows (canonical [{question, answer}, ...])
 *   - $status         array  { status, label, updated }
 *   - $is_wc_product  bool   Whether AI generation is available
 *   - $rest_base      string Localized REST namespace URL
 *   - $rest_nonce     string wp_rest nonce
 *   - $schema_preview string Admin URL of the Schema Preview page
 *
 * Save flow: standard WP form submission. Editor JS posts the rows back
 * as two parallel-indexed arrays (`luwipress_faq_q[]`, `luwipress_faq_a[]`)
 * which the save_metabox handler reassembles into canonical pairs.
 *
 * @package LuwiPress
 * @since   3.5.5
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Defensive defaults — PHPStan can't introspect render_metabox()'s
// extract()-style variable provisioning, so we restate the contract here.
// At runtime LuwiPress_FAQ_Editor::render_metabox() always provides these.
$post_id        = isset( $post_id )        ? (int) $post_id        : 0;
$rows           = isset( $rows ) && is_array( $rows ) ? $rows       : array();
$status         = isset( $status ) && is_array( $status ) ? $status : array( 'status' => 'empty', 'label' => '', 'updated' => '' );
$is_wc_product  = ! empty( $is_wc_product );
$schema_preview = isset( $schema_preview ) ? (string) $schema_preview : '';

$status_color = array(
	'empty'      => '#999',
	'manual'     => '#2c7a2c',
	'completed'  => '#2c7a2c',
	'processing' => '#a86b00',
	'failed'     => '#c33',
);
$pill_bg = $status_color[ $status['status'] ] ?? '#666';
?>
<div class="lwp-faq-editor" data-post-id="<?php echo (int) $post_id; ?>" data-is-product="<?php echo $is_wc_product ? '1' : '0'; ?>">

	<div class="lwp-faq-editor__header">
		<span class="lwp-faq-editor__pill" style="background-color:<?php echo esc_attr( $pill_bg ); ?>;">
			<?php echo esc_html( $status['label'] ); ?>
			<?php if ( ! empty( $status['updated'] ) ) : ?>
				<small style="opacity:.85;margin-left:6px;">
					<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['updated'] ) ); ?>
				</small>
			<?php endif; ?>
		</span>
		<span class="lwp-faq-editor__count" data-bind="row-count">
			<?php
			/* translators: %d = current row count */
			printf( esc_html( _n( '%d question', '%d questions', count( $rows ), 'luwipress' ) ), count( $rows ) );
			?>
		</span>
	</div>

	<p class="description">
		<?php
		esc_html_e(
			'Each question + answer pair is emitted as a single FAQPage entry in this post\'s JSON-LD. Aim for 3-5 entries with answers in the 50-80 word range — that band tracks closely with the Tapadum SEO writing guide §2.5 and the AEO citability standard.',
			'luwipress'
		);
		?>
	</p>

	<div class="lwp-faq-editor__rows" data-bind="rows">
		<?php
		// Empty-state row — always render one even when zero stored, so the
		// editor has something to grab. Hidden via CSS when stored rows
		// exist (the JS removes it on first add).
		$render_rows = ! empty( $rows ) ? $rows : array( array( 'question' => '', 'answer' => '' ) );
		foreach ( $render_rows as $i => $row ) :
			$q = isset( $row['question'] ) ? (string) $row['question'] : '';
			$a = isset( $row['answer'] )   ? (string) $row['answer']   : '';
		?>
			<div class="lwp-faq-editor__row" data-bind="row">
				<div class="lwp-faq-editor__row-head">
					<span class="lwp-faq-editor__row-num" data-bind="row-num"><?php echo (int) ( $i + 1 ); ?></span>
					<div class="lwp-faq-editor__row-actions">
						<button type="button" class="button button-small" data-bind="move-up" title="<?php esc_attr_e( 'Move up', 'luwipress' ); ?>">
							<span class="dashicons dashicons-arrow-up-alt2" style="font-size:14px;line-height:1.6;"></span>
						</button>
						<button type="button" class="button button-small" data-bind="move-down" title="<?php esc_attr_e( 'Move down', 'luwipress' ); ?>">
							<span class="dashicons dashicons-arrow-down-alt2" style="font-size:14px;line-height:1.6;"></span>
						</button>
						<button type="button" class="button button-small button-link-delete" data-bind="remove" title="<?php esc_attr_e( 'Remove question', 'luwipress' ); ?>">
							<span class="dashicons dashicons-trash" style="font-size:14px;line-height:1.6;"></span>
						</button>
					</div>
				</div>
				<label class="screen-reader-text" for="lwp-faq-q-<?php echo (int) $i; ?>"><?php esc_html_e( 'Question', 'luwipress' ); ?></label>
				<input type="text"
					id="lwp-faq-q-<?php echo (int) $i; ?>"
					name="luwipress_faq_q[]"
					value="<?php echo esc_attr( $q ); ?>"
					class="lwp-faq-editor__question regular-text"
					placeholder="<?php esc_attr_e( 'What materials are used to build this oud?', 'luwipress' ); ?>"
					data-bind="question" />
				<label class="screen-reader-text" for="lwp-faq-a-<?php echo (int) $i; ?>"><?php esc_html_e( 'Answer', 'luwipress' ); ?></label>
				<textarea
					id="lwp-faq-a-<?php echo (int) $i; ?>"
					name="luwipress_faq_a[]"
					rows="3"
					class="lwp-faq-editor__answer"
					placeholder="<?php esc_attr_e( '2-4 sentences, 50-80 words. Lead with the direct answer; cite a fact or workshop observation if you can.', 'luwipress' ); ?>"
					data-bind="answer"><?php echo esc_textarea( $a ); ?></textarea>
				<div class="lwp-faq-editor__row-meta">
					<span class="lwp-faq-editor__wc" data-bind="word-count">—</span>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="lwp-faq-editor__actions">
		<button type="button" class="button" data-bind="add-row">
			<span class="dashicons dashicons-plus-alt2" style="font-size:16px;line-height:1.5;"></span>
			<?php esc_html_e( 'Add question', 'luwipress' ); ?>
		</button>

		<?php if ( $is_wc_product ) : ?>
			<button type="button" class="button button-primary lwp-faq-editor__ai" data-bind="ai-generate">
				<span class="dashicons dashicons-superhero" style="font-size:16px;line-height:1.5;"></span>
				<?php esc_html_e( 'Generate with AI', 'luwipress' ); ?>
			</button>
		<?php endif; ?>

		<a class="button button-link" href="<?php echo esc_url( $schema_preview ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Open Schema Preview to verify FAQPage emission on the live URL.', 'luwipress' ); ?>">
			<span class="dashicons dashicons-code-standards" style="font-size:14px;line-height:1.6;"></span>
			<?php esc_html_e( 'Preview schema', 'luwipress' ); ?>
		</a>
	</div>

	<div class="lwp-faq-editor__status" data-bind="ai-status" style="margin-top:8px;display:none;"></div>
</div>
