<?php
/**
 * Image Handler — DALL-E generation + WordPress Media Library integration.
 *
 * @package LuwiPress
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Image_Handler {

	/**
	 * Generate an image via DALL-E and attach it to a post.
	 *
	 * @param string $prompt  Image generation prompt.
	 * @param int    $post_id WordPress post ID to attach to.
	 * @param array  $options Optional: set_featured, size, quality, filename.
	 * @return int|WP_Error   Attachment ID or WP_Error.
	 */
	public static function generate_and_attach( $prompt, $post_id, array $options = array() ) {
		$provider = LuwiPress_AI_Engine::get_provider( 'openai' );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		if ( ! ( $provider instanceof LuwiPress_Provider_OpenAI ) ) {
			return new WP_Error( 'luwipress_wrong_provider', __( 'Image generation requires OpenAI provider.', 'luwipress' ) );
		}

		// Generate image.
		$image_result = $provider->generate_image( $prompt, array(
			'size'    => $options['size'] ?? '1792x1024',
			'quality' => $options['quality'] ?? 'standard',
		) );

		if ( is_wp_error( $image_result ) ) {
			return $image_result;
		}

		// Download and attach.
		$attachment_id = self::sideload_from_url(
			$image_result['url'],
			$post_id,
			$options['filename'] ?? '',
			$options['description'] ?? $prompt
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Set as featured image if requested.
		if ( ! empty( $options['set_featured'] ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		// Record token usage for image generation.
		if ( class_exists( 'LuwiPress_Token_Tracker' ) ) {
			LuwiPress_Token_Tracker::record( array(
				'workflow'      => 'image-generation',
				'provider'      => 'openai',
				'model'         => $options['model'] ?? 'dall-e-3',
				'input_tokens'  => 0,
				'output_tokens' => 0,
				'execution_id'  => 'img-' . wp_generate_uuid4(),
			) );
		}

		return $attachment_id;
	}

	/**
	 * Download an image from URL and add to WordPress Media Library.
	 *
	 * @param string $url         Remote image URL.
	 * @param int    $post_id     Post to attach to.
	 * @param string $filename    Desired filename (optional).
	 * @param string $description Image description for alt text.
	 * @return int|WP_Error       Attachment ID or WP_Error.
	 */
	public static function sideload_from_url( $url, $post_id = 0, $filename = '', $description = '' ) {
		// Require WordPress admin functions for media handling.
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Download to temp file.
		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error(
				'luwipress_download_failed',
				sprintf( __( 'Failed to download image: %s', 'luwipress' ), $tmp->get_error_message() )
			);
		}

		// Determine filename.
		if ( empty( $filename ) ) {
			$filename = 'luwipress-' . wp_generate_uuid4() . '.png';
		}
		if ( ! preg_match( '/\.\w{3,4}$/', $filename ) ) {
			$filename .= '.png';
		}

		$file_array = array(
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $tmp,
		);

		// Sideload into media library.
		$attachment_id = media_handle_sideload( $file_array, $post_id, $description );

		// Clean up temp file on error.
		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $attachment_id;
		}

		// Set alt text.
		if ( ! empty( $description ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $description ) );
		}

		return $attachment_id;
	}
}
