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

		// Generate image (defaults to gpt-image-1, which returns base64).
		$image_result = $provider->generate_image( $prompt, array(
			'size'    => $options['size'] ?? '1536x1024',
			'quality' => $options['quality'] ?? 'high',
		) );

		if ( is_wp_error( $image_result ) ) {
			return $image_result;
		}

		// Attach — gpt-image-1 returns base64; dall-e legacy returns a url.
		if ( ! empty( $image_result['url'] ) ) {
			$attachment_id = self::sideload_from_url(
				$image_result['url'],
				$post_id,
				$options['filename'] ?? '',
				$options['description'] ?? $prompt
			);
		} elseif ( ! empty( $image_result['b64'] ) ) {
			$attachment_id = self::sideload_from_b64(
				$image_result['b64'],
				$post_id,
				$options['filename'] ?? '',
				$options['description'] ?? $prompt
			);
		} else {
			return new WP_Error( 'luwipress_empty_response', __( 'Image generation returned no image.', 'luwipress' ) );
		}

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

	/**
	 * Decode a base64 image (gpt-image-1) and add it to the Media Library.
	 *
	 * @param string $b64         Base64-encoded image data.
	 * @param int    $post_id     Post to attach to.
	 * @param string $filename    Desired filename (optional).
	 * @param string $description Image description for alt text.
	 * @return int|WP_Error       Attachment ID or WP_Error.
	 */
	public static function sideload_from_b64( $b64, $post_id = 0, $filename = '', $description = '' ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$bytes = base64_decode( $b64, true );
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'luwipress_b64_decode', __( 'Generated image could not be decoded.', 'luwipress' ) );
		}

		if ( empty( $filename ) ) {
			$filename = 'luwipress-' . wp_generate_uuid4() . '.png';
		}
		if ( ! preg_match( '/\.\w{3,4}$/', $filename ) ) {
			$filename .= '.png';
		}

		$tmp = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return new WP_Error( 'luwipress_tmp_failed', __( 'Could not create a temp file for the image.', 'luwipress' ) );
		}
		file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$file_array    = array( 'name' => sanitize_file_name( $filename ), 'tmp_name' => $tmp );
		$attachment_id = media_handle_sideload( $file_array, $post_id, $description );

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $attachment_id;
		}

		if ( ! empty( $description ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $description ) );
		}

		return $attachment_id;
	}

	/**
	 * Retouch an existing attachment with AI, using it as the reference image,
	 * and add the polished result to the Media Library (a NEW attachment, so the
	 * original is never destroyed).
	 *
	 * @param int   $attachment_id Source image attachment ID.
	 * @param array $options       style, size, quality, prompt, post_id, set_featured, description.
	 * @return int|WP_Error        New attachment ID, or WP_Error.
	 */
	public static function retouch_attachment( $attachment_id, array $options = array() ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'luwipress_bad_attachment', __( 'No source image to retouch.', 'luwipress' ) );
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'luwipress_file_missing', __( 'The source image file could not be found.', 'luwipress' ) );
		}
		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) {
			return new WP_Error( 'luwipress_bad_mime', __( 'Only PNG, JPEG or WebP images can be retouched.', 'luwipress' ) );
		}
		$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'luwipress_read_failed', __( 'The source image could not be read.', 'luwipress' ) );
		}

		$provider = LuwiPress_AI_Engine::get_provider( 'openai' );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		if ( ! ( $provider instanceof LuwiPress_Provider_OpenAI ) || ! method_exists( $provider, 'edit_image' ) ) {
			return new WP_Error( 'luwipress_wrong_provider', __( 'Image retouch requires the OpenAI provider.', 'luwipress' ) );
		}

		$prompt = ! empty( $options['prompt'] )
			? (string) $options['prompt']
			: self::build_retouch_prompt( $options['style'] ?? 'clean', $options['extra'] ?? '' );

		$result = $provider->edit_image( $bytes, $mime, basename( $file ), $prompt, array(
			'size'    => $options['size'] ?? '1536x1024',
			'quality' => $options['quality'] ?? 'high',
		) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = (int) ( $options['post_id'] ?? 0 );
		if ( ! $post_id ) {
			$parent  = wp_get_post_parent_id( $attachment_id );
			$post_id = $parent ? (int) $parent : 0;
		}
		$desc     = $options['description'] ?? get_the_title( $attachment_id );
		$filename = 'luwi-retouch-' . $attachment_id . '-' . wp_generate_password( 6, false ) . '.png';

		if ( ! empty( $result['b64'] ) ) {
			$new_id = self::sideload_from_b64( $result['b64'], $post_id, $filename, $desc );
		} elseif ( ! empty( $result['url'] ) ) {
			$new_id = self::sideload_from_url( $result['url'], $post_id, $filename, $desc );
		} else {
			return new WP_Error( 'luwipress_empty_response', __( 'Retouch returned no image.', 'luwipress' ) );
		}
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Remember the source so the UI can show before/after.
		update_post_meta( $new_id, '_luwipress_retouch_source', $attachment_id );

		if ( ! empty( $options['set_featured'] ) && $post_id ) {
			set_post_thumbnail( $post_id, $new_id );
		}

		if ( class_exists( 'LuwiPress_Token_Tracker' ) ) {
			LuwiPress_Token_Tracker::record( array(
				'workflow'      => 'image-retouch',
				'provider'      => 'openai',
				'model'         => 'gpt-image-1',
				'input_tokens'  => 0,
				'output_tokens' => 0,
				'execution_id'  => 'imgedit-' . wp_generate_uuid4(),
			) );
		}

		return $new_id;
	}

	/**
	 * Build a retouch prompt from a style preset (+ optional operator note).
	 *
	 * @param string $style clean|luxury|ecommerce|vivid|bw
	 * @param string $extra Optional extra instruction.
	 * @return string
	 */
	public static function build_retouch_prompt( $style = 'clean', $extra = '' ) {
		$styles = array(
			'clean'     => 'a clean, professional, brightly and evenly lit photograph',
			'luxury'    => 'a refined, premium, editorial photograph with elegant soft lighting and rich tones',
			'ecommerce' => 'a crisp e-commerce product photo on a clean, uncluttered neutral background',
			'vivid'     => 'a vivid, vibrant, high-contrast photograph with punchy colour',
			'bw'        => 'an elegant black-and-white photograph with deep contrast',
		);
		$style = strtolower( (string) $style );
		$desc  = $styles[ $style ] ?? $styles['clean'];
		$base  = 'Retouch and enhance this image into ' . $desc . '. '
			. 'Improve lighting, sharpness, colour balance and overall composition while staying faithful '
			. 'to the original subject, scene and proportions. Remove visual noise and distractions. '
			. 'No added text, no watermark, no logo.';
		$extra = trim( (string) $extra );
		if ( '' !== $extra ) {
			$base .= ' ' . wp_strip_all_tags( $extra );
		}
		return $base;
	}
}
