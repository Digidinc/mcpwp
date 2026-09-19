<?php
/**
 * Admin chat page methods.
 *
 * Carved verbatim from Mcpwp_Admin (G3 split). Mixed back via trait — same class, same $this.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Admin_Chat_Trait {

	/**
	 * Render the Chat page.
	 */
	public function render_chat_page() {
		include MCPWP_PLUGIN_DIR . 'admin/partials/mcpwp-chat-display.php';
	}

	/**
	 * Build OpenAI-format message array from history + current message.
	 *
	 * @param string $message Current user message.
	 * @param array  $history Prior conversation turns.
	 * @return array{system: string, messages: array}
	 */
	private function build_chat_messages( string $message, array $history ): array {
		$parts = array(
			'Site: ' . get_bloginfo( 'name' ),
			'URL: ' . home_url(),
			'Description: ' . get_bloginfo( 'description' ),
			'Plugin: MCPWP v' . MCPWP_VERSION,
		);
		$site_character = get_option( 'mcpwp_site_context', '' );
		if ( ! empty( $site_character ) ) {
			$parts[] = 'Site Character: ' . wp_trim_words( $site_character, 200 );
		}
		$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 20, 'fields' => 'ids' ) );
		if ( ! empty( $pages ) ) {
			$list = array();
			foreach ( $pages as $pid ) {
				$list[] = sprintf( '%d: %s', $pid, get_the_title( $pid ) );
			}
			$parts[] = 'Published pages: ' . implode( ', ', $list );
		}
		$caps = array();
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$caps[] = 'Elementor ' . ELEMENTOR_VERSION;
		}
		if ( class_exists( 'WooCommerce' ) ) {
			$caps[] = 'WooCommerce';
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			$caps[] = 'Yoast SEO';
		}
		if ( ! empty( $caps ) ) {
			$parts[] = 'Active integrations: ' . implode( ', ', $caps );
		}
		$site_context = implode( "\n", $parts );

		$system = "You are MCPWP, an AI assistant embedded in a WordPress site. Help the user manage their site.\n\n"
			. "When the user asks you to DO something (build, edit, create, delete, update), respond with a JSON tool call:\n"
			. "{\"tool\": \"tool_name\", \"arguments\": {\"key\": \"value\"}}\n\n"
			. "Available tools: wp_build_page, wp_edit_widget, wp_edit_section, wp_add_section, wp_create_page, wp_update_page, "
			. "wp_list_pages, wp_upload_media_from_url, wp_search, wp_get_elementor_summary, wp_regenerate_elementor_css\n\n"
			. "Blueprint types: hero, features, cta, pricing, faq, testimonials, team, portfolio, blog_grid, services, about, "
			. "process_steps, social_proof, product_showcase, before_after, newsletter, stats, gallery, text, map, countdown, logo_grid, video, contact_form\n\n"
			. "If the user asks a QUESTION, respond normally as text.\n\n"
			. "Site context:\n" . $site_context;

		$messages = array( array( 'role' => 'system', 'content' => $system ) );
		foreach ( array_slice( $history, -10 ) as $msg ) {
			if ( isset( $msg['role'], $msg['content'] ) ) {
				$messages[] = array( 'role' => sanitize_key( $msg['role'] ), 'content' => wp_kses_post( $msg['content'] ) );
			}
		}
		$messages[] = array( 'role' => 'user', 'content' => $message );

		return array( 'system' => $system, 'messages' => $messages, 'site_context' => $site_context );
	}

	/**
	 * AJAX proxy for chat — multi-model: OpenAI, Gemini, Workers AI fallback.
	 */
	public function ajax_chat() {
		check_ajax_referer( 'mcpwp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'Chat requires administrator access.' ) );
		}

		$message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';
		// History is a JSON string — wp_unslash prevents magic-quotes corruption; json_decode validates structure.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload; decoded array values are not written to DB without further sanitization.
		$history = isset( $_POST['history'] ) ? json_decode( wp_unslash( $_POST['history'] ), true ) : array();

		if ( empty( $message ) ) {
			wp_send_json_error( array( 'message' => 'Message is required' ) );
		}

		$manager    = Mcpwp_Integration_Manager::get_instance();
		$openai_key = $manager->get_provider_key( 'openai' );
		$gemini_key = $manager->get_provider_key( 'gemini' );
		$pref       = get_option( 'mcpwp_chat_model', 'auto' );

		$built         = $this->build_chat_messages( $message, is_array( $history ) ? $history : array() );
		$messages      = $built['messages'];
		$system        = $built['system'];
		$site_context  = $built['site_context'];

		$ai_response = '';
		$model_used  = '';

		// Decide provider based on preference + key availability.
		$use_openai  = ( 'openai' === $pref && ! empty( $openai_key ) )
			|| ( 'auto' === $pref && ! empty( $openai_key ) );
		$use_gemini  = ( 'gemini' === $pref && ! empty( $gemini_key ) )
			|| ( 'auto' === $pref && empty( $openai_key ) && ! empty( $gemini_key ) );

		if ( $use_openai ) {
			$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $openai_key,
				),
				'body'    => wp_json_encode( array( 'model' => 'gpt-4o-mini', 'messages' => $messages ) ),
			) );
			if ( is_wp_error( $response ) ) {
				wp_send_json_error( array( 'message' => $response->get_error_message() ) );
			}
			$body        = json_decode( wp_remote_retrieve_body( $response ), true );
			$ai_response = $body['choices'][0]['message']['content'] ?? '';
			$model_used  = 'openai/' . ( $body['model'] ?? 'gpt-4o-mini' );

		} elseif ( $use_gemini ) {
			// Build Gemini multi-turn contents (system instruction + history + user turn).
			$contents = array();
			foreach ( array_slice( $messages, 1 ) as $msg ) { // skip system message
				$contents[] = array(
					'role'  => 'user' === $msg['role'] ? 'user' : 'model',
					'parts' => array( array( 'text' => $msg['content'] ) ),
				);
			}
			$gemini_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $gemini_key;
			$response   = wp_remote_post( $gemini_url, array(
				'timeout' => 60,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
					'contents'          => $contents,
				) ),
			) );
			if ( is_wp_error( $response ) ) {
				wp_send_json_error( array( 'message' => $response->get_error_message() ) );
			}
			$body        = json_decode( wp_remote_retrieve_body( $response ), true );
			$ai_response = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
			$model_used  = 'gemini/gemini-2.5-flash';

		} else {
			// Fall back to free Cloudflare Workers AI.
			$chat_endpoint = get_option( 'mcpwp_chat_endpoint', 'https://mcpwp-chat.weathered-scene-2272.workers.dev' );
			$chat_secret   = get_option( 'mcpwp_chat_secret', '' );

			$response = wp_remote_post( $chat_endpoint, array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $chat_secret,
				),
				'body'    => wp_json_encode( array(
					'message'      => $message,
					'history'      => array_slice( is_array( $history ) ? $history : array(), -10 ),
					'site_context' => $site_context,
				) ),
			) );
			if ( is_wp_error( $response ) ) {
				wp_send_json_error( array( 'message' => $response->get_error_message() ) );
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) ) {
				wp_send_json_error( array( 'message' => 'Invalid response from AI' ) );
			}
			wp_send_json_success( array_merge( $body, array( 'model' => 'workers-ai' ) ) );
			return;
		}

		// Parse tool calls from the response.
		$tool_call = null;
		if ( preg_match( '/\{[\s\S]*"tool"\s*:\s*"[\s\S]*\}/', $ai_response, $match ) ) {
			$parsed = json_decode( $match[0], true );
			if ( isset( $parsed['tool'] ) ) {
				$tool_call = $parsed;
			}
		}

		wp_send_json_success( array(
			'response'  => $ai_response,
			'tool_call' => $tool_call,
			'model'     => $model_used,
		) );
	}

	/**
	 * AJAX handler — execute an MCP tool server-side (from chat).
	 */
	public function ajax_chat_execute_tool() {
		check_ajax_referer( 'mcpwp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'Tool execution requires administrator access.' ) );
		}

		$tool = isset( $_POST['tool'] ) ? sanitize_text_field( wp_unslash( $_POST['tool'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload for tool arguments; structure is validated after decode and not written to DB directly.
		$arguments = isset( $_POST['arguments'] ) ? json_decode( wp_unslash( $_POST['arguments'] ), true ) : array();

		if ( empty( $tool ) ) {
			wp_send_json_error( array( 'message' => 'Tool name is required' ) );
		}

		// Gate destructive tools behind explicit confirmation.
		$destructive = array(
			'wp_delete_page', 'wp_delete_post', 'wp_delete_media', 'wp_delete_all_drafts',
			'wp_delete_menu', 'wp_delete_menu_item', 'wp_delete_webhook', 'wp_delete_content',
			'wp_delete_custom_css', 'wp_delete_term', 'wp_revoke_api_key', 'wp_rollback_approval',
		);
		$confirmed = isset( $_POST['confirmed'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['confirmed'] ) );
		if ( in_array( $tool, $destructive, true ) && ! $confirmed ) {
			wp_send_json_success( array( 'needs_confirmation' => true, 'tool' => $tool ) );
		}

		// Execute via internal REST dispatch — no API key needed, runs as current user.
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'jsonrpc' => '2.0',
			'id'      => time(),
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => $tool,
				'arguments' => is_array( $arguments ) ? $arguments : array(),
			),
		) ) );

		// Authenticated via Mcpwp_Api_Auth::is_trusted_admin_ajax_request()
		// inside verify_api_key()'s permission callback for this action —
		// see that method for the admin+DOING_AJAX+action-allowlist+nonce
		// conditions it requires. There used to be an
		// add_filter( 'mcpwp_bypass_api_key_check', '__return_true' ) /
		// remove_filter() pair here for a global apply_filters() bypass that
		// has since been removed everywhere (#607): nothing calls
		// apply_filters( 'mcpwp_bypass_api_key_check', ... ) any more, so
		// that pair was dead weight — worse, it read as if flipping this
		// filter still bypassed auth, which would silently reopen a global
		// bypass for the next person who wired up a consumer for it. Removed
		// rather than left as a no-op.
		$response = rest_do_request( $request );

		$data = rest_get_server()->response_to_data( $response, false );

		if ( isset( $data['result']['content'][0]['text'] ) ) {
			$text = $data['result']['content'][0]['text'];
			$parsed = json_decode( $text, true );
			wp_send_json_success( is_array( $parsed ) ? $parsed : array( 'text' => $text ) );
		} elseif ( isset( $data['error'] ) ) {
			wp_send_json_error( $data['error'] );
		} else {
			wp_send_json_success( $data );
		}
	}

	/**
	 * SSE streaming endpoint — proxies OpenAI token stream to the browser.
	 * Outputs text/event-stream; never calls wp_send_json_*.
	 */
	public function ajax_chat_stream() {
		if ( ! check_ajax_referer( 'mcpwp_admin_nonce', 'nonce', false ) || ! current_user_can( 'activate_plugins' ) ) {
			http_response_code( 403 );
			exit;
		}

		$message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload; wp_unslash prevents magic-quotes corruption.
		$history = isset( $_POST['history'] ) ? json_decode( wp_unslash( $_POST['history'] ), true ) : array();

		if ( empty( $message ) ) {
			http_response_code( 400 );
			exit;
		}

		$manager    = Mcpwp_Integration_Manager::get_instance();
		$openai_key = $manager->get_provider_key( 'openai' );

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );

		if ( ob_get_level() ) {
			ob_end_flush();
		}
		flush();

		if ( empty( $openai_key ) ) {
			echo 'data: ' . wp_json_encode( array( 'error' => 'Streaming requires an OpenAI API key' ) ) . "\n\n";
			flush();
			exit;
		}

		$built    = $this->build_chat_messages( $message, is_array( $history ) ? $history : array() );
		$messages = $built['messages'];

		// SSE token streaming requires direct cURL; wp_remote_get cannot stream chunked responses
		// because WP HTTP API buffers the full response before returning.
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt_array,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_close
		if ( ! function_exists( 'curl_init' ) ) {
			echo 'data: ' . wp_json_encode( array( 'error' => 'cURL not available' ) ) . "\n\n";
			flush();
			exit;
		}

		$ch = curl_init( 'https://api.openai.com/v1/chat/completions' );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( array(
				'model'    => 'gpt-4o-mini',
				'messages' => $messages,
				'stream'   => true,
			) ),
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $openai_key,
			),
			CURLOPT_WRITEFUNCTION  => static function ( $curl, $data ) {
				$lines = explode( "\n", $data );
				foreach ( $lines as $line ) {
					$line = trim( $line );
					if ( '' === $line || 'data: ' !== substr( $line, 0, 6 ) ) {
						continue;
					}
					$payload = substr( $line, 6 );
					if ( '[DONE]' === $payload ) {
						echo "data: [DONE]\n\n";
					} else {
						$chunk = json_decode( $payload, true );
						$token = $chunk['choices'][0]['delta']['content'] ?? null;
						if ( null !== $token ) {
							echo 'data: ' . wp_json_encode( array( 'token' => $token ) ) . "\n\n";
						}
					}
					flush();
				}
				return strlen( $data );
			},
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => 90,
		) );

		curl_exec( $ch );
		curl_close( $ch );
		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt_array,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_close
		exit;
	}

	/**
	 * Save chat conversation history for the current user.
	 */
	public function ajax_chat_save_history() {
		check_ajax_referer( 'mcpwp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload stored per-user only; not rendered in admin without re-escaping.
		$history = isset( $_POST['history'] ) ? json_decode( wp_unslash( $_POST['history'] ), true ) : array();
		update_option( 'mcpwp_chat_history_' . get_current_user_id(), array_slice( (array) $history, -50 ), false );
		wp_send_json_success();
	}

	/**
	 * Clear saved chat history for the current user.
	 */
	public function ajax_chat_clear_history() {
		check_ajax_referer( 'mcpwp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error();
		}

		delete_option( 'mcpwp_chat_history_' . get_current_user_id() );
		wp_send_json_success();
	}
}
