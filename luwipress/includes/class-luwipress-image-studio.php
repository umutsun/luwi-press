<?php
/**
 * Image Studio — one-click AI retouch of a post's image, in the editor.
 *
 * Adds a post-editor side meta box that takes the post's CURRENT featured image
 * as the reference and regenerates a polished, standardised version of it (via
 * the OpenAI gpt-image-1 edits endpoint, wrapped by LuwiPress_Image_Handler).
 * The original is never destroyed — the retouch lands as a new Media Library
 * attachment and is optionally set as the featured image.
 *
 * User-facing copy is Luwi-branded (no third-party model names).
 *
 * @package LuwiPress
 * @since   3.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Image_Studio {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Post types that support a featured image — that's where retouch is useful.
	 *
	 * @return string[]
	 */
	private function target_post_types() {
		$types = get_post_types( array( 'show_ui' => true ), 'names' );
		$out   = array();
		foreach ( $types as $pt ) {
			if ( 'attachment' === $pt ) {
				continue;
			}
			if ( post_type_supports( $pt, 'thumbnail' ) ) {
				$out[] = $pt;
			}
		}
		/** Filter the post types that get the Image Studio meta box. */
		return apply_filters( 'luwipress_image_studio_post_types', $out );
	}

	public function register_meta_box() {
		foreach ( $this->target_post_types() as $pt ) {
			add_meta_box(
				'luwipress-image-studio',
				__( 'Luwi Image Studio', 'luwipress' ),
				array( $this, 'render_meta_box' ),
				$pt,
				'side',
				'low'
			);
		}
	}

	public function render_meta_box( $post ) {
		$thumb_id  = (int) get_post_thumbnail_id( $post->ID );
		$thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';
		$has_key   = (bool) get_option( 'luwipress_openai_api_key', '' );
		$rest      = esc_url_raw( rest_url( 'luwipress/v1/image/retouch' ) );
		$nonce     = wp_create_nonce( 'wp_rest' );
		?>
		<div class="lwp-studio" data-lwp-studio data-post="<?php echo (int) $post->ID; ?>" data-thumb="<?php echo $thumb_id; ?>" data-rest="<?php echo esc_attr( $rest ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php if ( ! $has_key ) : ?>
				<p class="lwp-studio-note"><?php esc_html_e( 'Add your AI API key in LuwiPress → Settings to use Image Studio.', 'luwipress' ); ?></p>
			<?php endif; ?>

			<div class="lwp-studio-preview" data-lwp-studio-preview>
				<?php if ( $thumb_url ) : ?>
					<img src="<?php echo esc_url( $thumb_url ); ?>" alt="">
				<?php else : ?>
					<p class="lwp-studio-note" data-lwp-studio-empty><?php esc_html_e( 'Set a featured image first — Image Studio retouches it.', 'luwipress' ); ?></p>
				<?php endif; ?>
			</div>

			<p class="lwp-studio-row">
				<label><?php esc_html_e( 'Style', 'luwipress' ); ?></label>
				<select data-lwp-studio-style>
					<option value="clean"><?php esc_html_e( 'Clean & professional', 'luwipress' ); ?></option>
					<option value="luxury"><?php esc_html_e( 'Luxury / editorial', 'luwipress' ); ?></option>
					<option value="ecommerce"><?php esc_html_e( 'E-commerce', 'luwipress' ); ?></option>
					<option value="vivid"><?php esc_html_e( 'Vivid', 'luwipress' ); ?></option>
					<option value="bw"><?php esc_html_e( 'Black & white', 'luwipress' ); ?></option>
				</select>
			</p>
			<p class="lwp-studio-row">
				<label><?php esc_html_e( 'Size', 'luwipress' ); ?></label>
				<select data-lwp-studio-size>
					<option value="auto"><?php esc_html_e( 'Match original', 'luwipress' ); ?></option>
					<option value="1024x1024"><?php esc_html_e( 'Square', 'luwipress' ); ?></option>
					<option value="1536x1024"><?php esc_html_e( 'Landscape', 'luwipress' ); ?></option>
					<option value="1024x1536"><?php esc_html_e( 'Portrait', 'luwipress' ); ?></option>
				</select>
			</p>
			<p class="lwp-studio-row">
				<input type="text" data-lwp-studio-extra placeholder="<?php esc_attr_e( 'Optional: extra instruction', 'luwipress' ); ?>">
			</p>
			<p class="lwp-studio-row">
				<label><input type="checkbox" data-lwp-studio-feat checked> <?php esc_html_e( 'Set result as featured image', 'luwipress' ); ?></label>
			</p>

			<button type="button" class="button button-primary lwp-studio-go" data-lwp-studio-go <?php disabled( ! $thumb_id ); ?>><?php esc_html_e( 'Retouch image', 'luwipress' ); ?></button>
			<span class="spinner lwp-studio-spin" style="float:none;margin:0 0 0 6px"></span>
			<p class="lwp-studio-msg" data-lwp-studio-msg></p>
		</div>

		<style>
			.lwp-studio-preview{margin:0 0 10px;min-height:40px}
			.lwp-studio-preview img{max-width:100%;height:auto;border:1px solid #dcdcde;border-radius:3px;display:block}
			.lwp-studio-row{margin:0 0 8px}
			.lwp-studio-row label{display:block;font-weight:600;margin-bottom:3px;font-size:12px}
			.lwp-studio-row select,.lwp-studio-row input[type=text]{width:100%}
			.lwp-studio-note{color:#646970;font-size:12px;margin:0 0 8px}
			.lwp-studio-msg{font-size:12px;margin:8px 0 0}
			.lwp-studio-msg.err{color:#b32d2e}.lwp-studio-msg.ok{color:#2271b1}
			.lwp-studio-spin.is-active{visibility:visible}
		</style>
		<script>
		(function(){
			var box=document.querySelector('[data-lwp-studio]'); if(!box) return;
			var go=box.querySelector('[data-lwp-studio-go]'),
				spin=box.querySelector('.lwp-studio-spin'),
				msg=box.querySelector('[data-lwp-studio-msg]'),
				prev=box.querySelector('[data-lwp-studio-preview]');
			function thumbId(){
				// Prefer the live featured-image value (classic editor) if present.
				var f=document.getElementById('_thumbnail_id');
				return (f && f.value && f.value!=='-1') ? parseInt(f.value,10) : parseInt(box.getAttribute('data-thumb'),10)||0;
			}
			go && go.addEventListener('click',function(){
				var tid=thumbId();
				if(!tid){ msg.className='lwp-studio-msg err'; msg.textContent='Set a featured image first.'; return; }
				go.disabled=true; spin.classList.add('is-active'); msg.className='lwp-studio-msg'; msg.textContent='Retouching…';
				fetch(box.getAttribute('data-rest'),{
					method:'POST',
					headers:{'Content-Type':'application/json','X-WP-Nonce':box.getAttribute('data-nonce')},
					body:JSON.stringify({
						attachment_id:tid,
						post_id:parseInt(box.getAttribute('data-post'),10),
						style:box.querySelector('[data-lwp-studio-style]').value,
						size:box.querySelector('[data-lwp-studio-size]').value,
						extra:box.querySelector('[data-lwp-studio-extra]').value,
						set_featured:box.querySelector('[data-lwp-studio-feat]').checked?1:0
					})
				}).then(function(r){return r.json();}).then(function(d){
					go.disabled=false; spin.classList.remove('is-active');
					if(!d || !d.url){ msg.className='lwp-studio-msg err'; msg.textContent=(d&&d.message)?d.message:'Retouch failed.'; return; }
					prev.innerHTML='<img src="'+d.url+'" alt="">';
					box.setAttribute('data-thumb', d.attachment_id);
					msg.className='lwp-studio-msg ok'; msg.textContent='Done — new image added to the Media Library.';
					if(d.set_featured && window.wp && wp.media && wp.media.featuredImage){ try{ wp.media.featuredImage.set(d.attachment_id); }catch(e){} }
				}).catch(function(){ go.disabled=false; spin.classList.remove('is-active'); msg.className='lwp-studio-msg err'; msg.textContent='Network error.'; });
			});
		})();
		</script>
		<?php
	}

	public function register_routes() {
		register_rest_route( 'luwipress/v1', '/image/retouch', array(
			'methods'             => 'POST',
			'permission_callback' => function ( WP_REST_Request $req ) {
				$pid = (int) $req->get_param( 'post_id' );
				return $pid ? current_user_can( 'edit_post', $pid ) : current_user_can( 'upload_files' );
			},
			'args'                => array(
				'attachment_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				'post_id'       => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
				'style'         => array( 'type' => 'string', 'default' => 'clean', 'sanitize_callback' => 'sanitize_key' ),
				'size'          => array( 'type' => 'string', 'default' => 'auto', 'sanitize_callback' => 'sanitize_text_field' ),
				'extra'         => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'set_featured'  => array( 'type' => 'boolean', 'default' => false ),
			),
			'callback'            => function ( WP_REST_Request $req ) {
				if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 0 ); }
				$new = LuwiPress_Image_Handler::retouch_attachment( (int) $req->get_param( 'attachment_id' ), array(
					'post_id'      => (int) $req->get_param( 'post_id' ),
					'style'        => (string) $req->get_param( 'style' ),
					'size'         => (string) $req->get_param( 'size' ),
					'extra'        => (string) $req->get_param( 'extra' ),
					'set_featured' => (bool) $req->get_param( 'set_featured' ),
				) );
				if ( is_wp_error( $new ) ) {
					return new WP_Error( $new->get_error_code(), $new->get_error_message(), array( 'status' => 400 ) );
				}
				return rest_ensure_response( array(
					'attachment_id' => (int) $new,
					'url'           => wp_get_attachment_image_url( (int) $new, 'medium' ),
					'full'          => wp_get_attachment_url( (int) $new ),
					'set_featured'  => (bool) $req->get_param( 'set_featured' ),
				) );
			},
		) );
	}
}
