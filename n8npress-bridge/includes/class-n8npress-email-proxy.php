<?php
/**
 * n8nPress Email Proxy
 *
 * REST endpoint that lets n8n workflows send email through WordPress's
 * wp_mail() function. This means whatever SMTP/mail plugin the site uses
 * (WP Mail SMTP, FluentSMTP, Post SMTP, etc.) will handle delivery.
 *
 * n8n workflows no longer need their own SMTP credentials.
 *
 * Endpoint: POST /wp-json/n8npress/v1/send-email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class N8nPress_Email_Proxy {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	public function register_endpoints() {
		register_rest_route( 'n8npress/v1', '/send-email', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'send_email' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'to' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => array( $this, 'validate_email_list' ),
				),
				'subject' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'body' => array(
					'required' => true,
					'type'     => 'string',
				),
				'html' => array(
					'default' => true,
					'type'    => 'boolean',
				),
				'cc' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'bcc' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'reply_to' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
				),
				'template' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'enum'              => array( 'plain', 'woocommerce', 'n8npress' ),
					'default'           => 'plain',
				),
			),
		) );
	}

	public function check_permission( $request ) {
		$stored = get_option( 'n8npress_seo_api_token', '' );

		$auth_header = $request->get_header( 'authorization' );
		if ( ! empty( $auth_header ) ) {
			$token = str_replace( 'Bearer ', '', $auth_header );
			if ( ! empty( $stored ) && hash_equals( $stored, $token ) ) {
				return true;
			}
			if ( class_exists( 'N8nPress_Auth' ) ) {
				$user = N8nPress_Auth::validate_token( $token );
				if ( ! is_wp_error( $user ) ) {
					return true;
				}
			}
		}

		$api_token = $request->get_header( 'x-n8npress-token' );
		if ( ! empty( $api_token ) && ! empty( $stored ) && hash_equals( $stored, $api_token ) ) {
			return true;
		}

		return new WP_Error( 'unauthorized', 'Authentication required', array( 'status' => 401 ) );
	}

	public function validate_email_list( $value, $request, $param ) {
		$emails = array_map( 'trim', explode( ',', $value ) );
		foreach ( $emails as $email ) {
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'invalid_email', sprintf( 'Invalid email address: %s', $email ) );
			}
		}
		return true;
	}

	// ------------------------------------------------------------------
	// POST /send-email
	// ------------------------------------------------------------------

	public function send_email( $request ) {
		$to       = $request->get_param( 'to' );
		$subject  = $request->get_param( 'subject' );
		$body     = $request->get_param( 'body' );
		$is_html  = $request->get_param( 'html' );
		$template = $request->get_param( 'template' );

		// Build headers
		$headers = array();

		if ( $is_html ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}

		// CC
		$cc = $request->get_param( 'cc' );
		if ( ! empty( $cc ) ) {
			$headers[] = 'Cc: ' . $cc;
		}

		// BCC
		$bcc = $request->get_param( 'bcc' );
		if ( ! empty( $bcc ) ) {
			$headers[] = 'Bcc: ' . $bcc;
		}

		// Reply-To
		$reply_to = $request->get_param( 'reply_to' );
		if ( ! empty( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		// Wrap body in template if requested
		if ( $is_html ) {
			$body = $this->apply_template( $body, $subject, $template );
		}

		// Sanitize body — allow safe HTML
		$body = wp_kses_post( $body );

		// Send via wp_mail()
		$sent = wp_mail( $to, $subject, $body, $headers );

		if ( ! $sent ) {
			N8nPress_Logger::log( 'Email send failed', 'error', array(
				'to'      => $to,
				'subject' => $subject,
			) );

			return new WP_Error( 'email_failed', 'Failed to send email via wp_mail()', array( 'status' => 500 ) );
		}

		N8nPress_Logger::log( 'Email sent via proxy', 'info', array(
			'to'       => $to,
			'subject'  => $subject,
			'template' => $template,
		) );

		return rest_ensure_response( array(
			'success' => true,
			'to'      => $to,
			'subject' => $subject,
			'method'  => $this->get_mail_method(),
		) );
	}

	/**
	 * Wrap email body in a template.
	 */
	private function apply_template( $body, $subject, $template ) {
		switch ( $template ) {
			case 'woocommerce':
				return $this->woocommerce_template( $body, $subject );

			case 'n8npress':
				return $this->n8npress_template( $body, $subject );

			case 'plain':
			default:
				return $body;
		}
	}

	/**
	 * Use WooCommerce's email template wrapper if available.
	 */
	private function woocommerce_template( $body, $subject ) {
		if ( ! function_exists( 'wc_get_template_html' ) ) {
			return $body;
		}

		ob_start();
		do_action( 'woocommerce_email_header', $subject );
		echo $body; // Already sanitized by wp_kses_post in caller
		do_action( 'woocommerce_email_footer' );
		return ob_get_clean();
	}

	/**
	 * Simple n8npress-branded wrapper.
	 */
	private function n8npress_template( $body, $subject ) {
		$site_name = get_bloginfo( 'name' );
		$site_url  = get_site_url();

		return sprintf(
			'<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:600px;margin:0 auto;padding:20px;">
			<div style="border-bottom:2px solid #2563eb;padding-bottom:10px;margin-bottom:20px;">
				<strong style="font-size:18px;">%s</strong>
			</div>
			<div>%s</div>
			<div style="border-top:1px solid #e5e7eb;margin-top:30px;padding-top:15px;font-size:12px;color:#6b7280;">
				<a href="%s">%s</a> &middot; Powered by n8nPress
			</div>
			</body></html>',
			esc_html( $subject ),
			$body,
			esc_url( $site_url ),
			esc_html( $site_name )
		);
	}

	/**
	 * Report which mail method the site is using (for n8n workflow logs).
	 */
	private function get_mail_method() {
		$detector = N8nPress_Plugin_Detector::get_instance();
		$email    = $detector->detect_email();
		return $email['plugin'] . ':' . $email['method'];
	}
}
