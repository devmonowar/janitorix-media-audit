<?php
/**
 * Talks to one OpenAI-compatible chat API — and nothing else.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\AltText\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * A thin, honest HTTP boundary around the AI provider.
 *
 * OpenAI, Groq, OpenRouter and Gemini's OpenAI endpoint all accept the same
 * shape — POST `{base}/chat/completions` with model + messages — so one
 * adapter covers every key the user might bring. A provider with a genuinely
 * different wire format gets its own adapter beside this one, never a flag
 * inside it.
 *
 * Three rules hold everywhere below:
 *
 * - The key travels in exactly one place: the Authorization header of the
 *   outgoing request. It is never logged, never returned, never placed in an
 *   error string — the test suite asserts this, not just the docblock.
 * - Plain HTTP is refused unless the site explicitly allows it
 *   (`janitorix_alt_ai_allow_http` filter). An API key in cleartext is not a
 *   configuration choice, it is a leak with a settings screen.
 * - The transport is injected. Production passes nothing and gets
 *   `wp_remote_post`; tests pass a fake and never touch the network. That is
 *   what keeps the plain-PHP suite able to pin every error mapping below.
 */
final class OpenAiCompatibleAdapter {

	/** Seconds before the request is abandoned. No retry loop. */
	public const TIMEOUT = 30;

	/** Cap on the reply — an alt text never needs more. */
	public const MAX_TOKENS = 300;

	/**
	 * Provider base URL, without trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * The user's own API key. Held, never logged or returned.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Model name, exactly as the user typed it.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * Test seam; null in production.
	 *
	 * @var callable|null
	 */
	private $transport;

	/**
	 * Point the adapter at one provider and key.
	 *
	 * @param string        $base_url  Provider base URL, e.g. https://api.openai.com/v1.
	 * @param string        $key       The user's own API key. Stored by the caller, never here.
	 * @param string        $model     Model name, exactly as the user typed it. Never defaulted.
	 * @param callable|null $transport Test seam: receives ($url, $args), returns what
	 *                                 `wp_remote_post()` would (array) or a WP_Error-shaped
	 *                                 array with an 'error' key. Null in production.
	 */
	public function __construct( string $base_url, string $key, string $model, ?callable $transport = null ) {
		$this->base_url  = rtrim( trim( $base_url ), '/' );
		$this->key       = $key;
		$this->model     = $model;
		$this->transport = $transport;
	}

	/**
	 * Ask the model for one completion.
	 *
	 * @param array<int,array<string,mixed>> $messages OpenAI-shaped messages.
	 * @return array{ok:true,text:string}|array{ok:false,error:string} The reply,
	 *         or a machine error key: 'insecure-url', 'transport-error',
	 *         'timeout', 'unauthorized', 'rate-limited', 'bad-model',
	 *         'bad-response'. The key appears in none of them.
	 */
	public function complete( array $messages ): array {
		if ( '' === $this->key || '' === $this->model || '' === $this->base_url ) {
			return array(
				'ok'    => false,
				'error' => 'not-configured',
			);
		}

		if ( 0 !== stripos( $this->base_url, 'https://' ) && ! apply_filters( 'janitorix_alt_ai_allow_http', false ) ) {
			return array(
				'ok'    => false,
				'error' => 'insecure-url',
			);
		}

		$body = wp_json_encode(
			array(
				'model'       => $this->model,
				'messages'    => array_values( $messages ),
				'max_tokens'  => self::MAX_TOKENS,
				'temperature' => 0.2,
			)
		);

		$args = array(
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->key,
			),
			'body'    => is_string( $body ) ? $body : '{}',
		);

		$response = null !== $this->transport
			? call_user_func( $this->transport, $this->base_url . '/chat/completions', $args )
			: wp_remote_post( $this->base_url . '/chat/completions', $args );

		if ( is_wp_error( $response ) ) {
			$message = strtolower( $response->get_error_message() );

			if ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'curl error 28' ) ) {
				return array(
					'ok'    => false,
					'error' => 'timeout',
				);
			}

			return array(
				'ok'    => false,
				'error' => 'transport-error',
			);
		}

		if ( is_array( $response ) && array_key_exists( 'error', $response ) ) {
			return $this->map_test_error( $response );
		}

		$code = is_array( $response ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;

		if ( 401 === $code ) {
			return array(
				'ok'    => false,
				'error' => 'unauthorized',
			);
		}

		if ( 429 === $code ) {
			return array(
				'ok'    => false,
				'error' => 'rate-limited',
			);
		}

		if ( 200 !== $code ) {
			return array(
				'ok'    => false,
				'error' => $this->is_model_error( $response ) ? 'bad-model' : 'bad-response',
			);
		}

		$text = $this->read_text( $response );

		if ( null === $text ) {
			return array(
				'ok'    => false,
				'error' => 'bad-response',
			);
		}

		return array(
			'ok'   => true,
			'text' => $text,
		);
	}

	/**
	 * What the screen tells the human for a machine error key.
	 *
	 * Translated here, at the boundary — handlers pass these straight to the
	 * redirect notice, and the key itself never reaches the screen. Every
	 * branch below is reachable: complete() is the only producer of these
	 * keys, and the provider adds only image-prep keys of its own.
	 *
	 * @param string $error One of the keys complete() returns.
	 */
	public static function user_message( string $error ): string {
		switch ( $error ) {
			case 'timeout':
				return __( 'The request timed out after 30 seconds. Try again.', 'janitorix-media-audit' );
			case 'unauthorized':
				return __( 'The key was rejected. Check the key and try Test connection.', 'janitorix-media-audit' );
			case 'rate-limited':
				return __( 'Rate limited — the service asked us to slow down. Wait a little and try again.', 'janitorix-media-audit' );
			case 'bad-model':
				return __( 'The model name was not recognized. Check the model name.', 'janitorix-media-audit' );
			case 'transport-error':
				return __( 'The service could not be reached. Check the base URL.', 'janitorix-media-audit' );
			case 'insecure-url':
				return __( 'The base URL must start with https://.', 'janitorix-media-audit' );
			case 'not-configured':
				return __( 'Enable AI and save a key and a model name first.', 'janitorix-media-audit' );
			case 'unsupported-type':
				return __( 'That image type cannot be sent (SVG and non-images are refused).', 'janitorix-media-audit' );
			case 'too-large':
				return __( 'That image is too large to send.', 'janitorix-media-audit' );
			case 'editor-error':
			case 'missing-image':
				return __( 'That image could not be prepared.', 'janitorix-media-audit' );
			case 'empty-reply':
				return __( 'The model answered with nothing usable. Try again.', 'janitorix-media-audit' );
			default:
				return __( 'The service answered unexpectedly. Try again.', 'janitorix-media-audit' );
		}
	}

	/**
	 * Map the test seam's error shape to the same keys production returns.
	 *
	 * Tests speak in `array( 'error' => '<wp-error-code>' )`; the mapping is
	 * identical to the WP_Error branch above, so both paths are pinned by the
	 * same assertions.
	 *
	 * @param array<string,mixed> $response The fake transport's answer.
	 * @return array{ok:false,error:string}
	 */
	private function map_test_error( array $response ): array {
		$code = strtolower( (string) $response['error'] );

		if ( false !== strpos( $code, 'timed out' ) || false !== strpos( $code, 'curl error 28' ) ) {
			$error = 'timeout';
		} elseif ( 'http_401' === $code || 'unauthorized' === $code ) {
			$error = 'unauthorized';
		} elseif ( 'http_429' === $code || 'rate_limited' === $code ) {
			$error = 'rate-limited';
		} else {
			$error = 'transport-error';
		}

		return array(
			'ok'    => false,
			'error' => $error,
		);
	}

	/**
	 * Pull the assistant's text out of a chat-completions reply.
	 *
	 * @param mixed $response What the transport returned.
	 */
	private function read_text( $response ): ?string {
		if ( ! is_array( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( ! is_string( $body ) || '' === $body ) {
			return null;
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$text = $data['choices'][0]['message']['content'] ?? null;

		return is_string( $text ) && '' !== trim( $text ) ? $text : null;
	}

	/**
	 * Is this failure about the model name rather than the request?
	 *
	 * @param mixed $response What the transport returned.
	 */
	private function is_model_error( $response ): bool {
		if ( ! is_array( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( ! is_string( $body ) ) {
			return false;
		}

		$lower = strtolower( $body );

		return false !== strpos( $lower, 'model' ) && (
			false !== strpos( $lower, 'not found' )
			|| false !== strpos( $lower, 'does not exist' )
			|| false !== strpos( $lower, 'invalid' )
		);
	}
}
