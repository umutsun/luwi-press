<?php
/**
 * HTTP agent adapter
 *
 * Single generic adapter class that bridges to any external agent runtime
 * speaking the LuwiPress Agentic wire format. Two instances ship by default
 * (registered from the plugin bootstrap):
 *
 *   - Open Claw → https://oc.luwi.dev/agent   (option key: luwipress_agent_open_claw)
 *   - Hermes    → https://hermes.luwi.dev/agent (option key: luwipress_agent_hermes)
 *
 * Adding a third backend is a one-line `register()` call from any plugin
 * hooking `luwipress_agent_register` — no new class needed.
 *
 * Wire format — request (POST JSON):
 *   {
 *     "messages": [ { "role": "user"|"assistant", "content": "..." }, ... ],
 *     "context":  { "site_name": "...", "products": 123, ... },
 *     "tools":    [ ... ]
 *   }
 * response (HTTP 200, JSON):
 *   {
 *     "response":   "assistant reply text",
 *     "tool_calls": [ { "action": "...", "params": { ... } }, ... ]
 *   }
 *
 * Per-instance config option shape:
 *   [ 'endpoint' => '...', 'token' => '...', 'timeout' => 60 ]
 *
 * @package LuwiPress\Agent
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LuwiPress_Agent_Adapter_HTTP implements LuwiPress_Agent_Adapter_Interface {

	/** @var string */
	private $id;
	/** @var string */
	private $label;
	/** @var string */
	private $option_key;
	/** @var string */
	private $default_endpoint;

	/**
	 * @param string $id               Stable machine id, e.g. `open-claw`, `hermes`.
	 * @param string $label            Human-readable name (display only).
	 * @param string $option_key       WP option holding endpoint/token overrides.
	 * @param string $default_endpoint Hosted endpoint used when no override is set.
	 */
	public function __construct( $id, $label, $option_key, $default_endpoint ) {
		$this->id               = (string) $id;
		$this->label            = (string) $label;
		$this->option_key       = (string) $option_key;
		$this->default_endpoint = (string) $default_endpoint;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_label() {
		return $this->label;
	}

	public function is_configured() {
		$cfg = $this->get_config();
		return ! empty( $cfg['endpoint'] ) && ! empty( $cfg['token'] );
	}

	public function dispatch( $messages, $context, $tools ) {
		$cfg = $this->get_config();
		if ( empty( $cfg['endpoint'] ) || empty( $cfg['token'] ) ) {
			return new WP_Error(
				'agentic_not_configured',
				sprintf(
					/* translators: 1: adapter label, 2: default endpoint */
					__( '%1$s runtime not configured. Add an access token in LuwiPress Agentic settings (default endpoint: %2$s).', 'luwipress-agentic' ),
					$this->label,
					$this->default_endpoint
				)
			);
		}

		// Pass through user/assistant/system AND tool messages. Tool messages
		// carry the result of a previous tool_call back to the LLM. Assistant
		// messages may include tool_calls metadata so the model sees what it
		// asked for last turn.
		$out_messages = array();
		foreach ( (array) $messages as $m ) {
			$role = isset( $m['role'] ) ? (string) $m['role'] : '';
			if ( ! in_array( $role, array( 'user', 'assistant', 'system', 'tool' ), true ) ) {
				continue;
			}
			$entry = array( 'role' => $role, 'content' => isset( $m['content'] ) ? (string) $m['content'] : '' );
			if ( 'tool' === $role && ! empty( $m['tool_call_id'] ) ) {
				$entry['tool_call_id'] = (string) $m['tool_call_id'];
			}
			if ( 'assistant' === $role && ! empty( $m['tool_calls'] ) && is_array( $m['tool_calls'] ) ) {
				$entry['tool_calls'] = $m['tool_calls'];
			}
			$out_messages[] = $entry;
		}

		$body = array(
			'messages' => $out_messages,
			'context'  => is_array( $context ) ? $context : array(),
			'tools'    => is_array( $tools ) ? $tools : array(),
		);

		$response = wp_remote_post(
			esc_url_raw( $cfg['endpoint'] ),
			array(
				'timeout' => isset( $cfg['timeout'] ) ? (int) $cfg['timeout'] : 60,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $cfg['token'],
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$snippet = wp_remote_retrieve_body( $response );
			$snippet = is_string( $snippet ) ? substr( $snippet, 0, 200 ) : '';
			return new WP_Error(
				'agentic_http_' . $code,
				sprintf( '%s returned HTTP %d. %s', $this->label, $code, $snippet )
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'agentic_bad_json',
				sprintf( '%s returned invalid JSON.', $this->label )
			);
		}

		$tool_calls = isset( $data['tool_calls'] ) && is_array( $data['tool_calls'] )
			? array_values( array_filter(
				$data['tool_calls'],
				static function ( $tc ) {
					return is_array( $tc ) && ! empty( $tc['action'] );
				}
			) )
			: array();

		return array(
			'response'   => isset( $data['response'] ) ? (string) $data['response'] : '',
			'tool_calls' => $tool_calls,
		);
	}

	private function get_config() {
		$cfg = get_option( $this->option_key, array() );
		$cfg = is_array( $cfg ) ? $cfg : array();
		if ( empty( $cfg['endpoint'] ) ) {
			$cfg['endpoint'] = $this->default_endpoint;
		}
		$cfg['endpoint'] = self::normalize_endpoint( $cfg['endpoint'] );
		return $cfg;
	}

	/**
	 * Defensive endpoint normalizer.
	 *
	 * Operators frequently paste a bare host (`https://hermes.luwi.dev` or
	 * `https://hermes.luwi.dev/`) into the Endpoint field, expecting the
	 * plugin to add the wire-format path. Without normalization the request
	 * lands on the host root and is answered by whatever else serves that
	 * vhost (typically a 404 from a Next.js / paperclip frontend).
	 *
	 * Rules:
	 *   - Trim whitespace.
	 *   - Strip trailing slash unless the path itself is `/`.
	 *   - When the URL has NO path or just `/`, append `/agent` — preserving
	 *     custom self-hosted endpoints (e.g. `https://my.host/api/v1/chat`)
	 *     that intentionally use a different path.
	 *   - Invalid URLs pass through untouched so error handling stays explicit.
	 *
	 * @since 1.1.1
	 */
	public static function normalize_endpoint( $endpoint ) {
		$endpoint = trim( (string) $endpoint );
		if ( '' === $endpoint ) {
			return $endpoint;
		}
		$parts = wp_parse_url( $endpoint );
		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return $endpoint;
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( '' === $path || '/' === $path ) {
			$path = '/agent';
		} elseif ( '/' === substr( $path, -1 ) ) {
			$path = rtrim( $path, '/' );
		}
		$rebuilt = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$rebuilt .= ':' . (int) $parts['port'];
		}
		$rebuilt .= $path;
		if ( ! empty( $parts['query'] ) ) {
			$rebuilt .= '?' . $parts['query'];
		}
		return $rebuilt;
	}
}
