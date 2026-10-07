<?php
/**
 * Alt text from the user's own AI key — never without one.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\AltText\Ai;

use JanitorixMediaAudit\AltText\Contracts\SuggestionProvider;

defined( 'ABSPATH' ) || exit;

/**
 * The second suggestion provider: the rule-based one stays first, always.
 *
 * This provider never runs unless the site owner enabled AI and saved a key —
 * the screen and the handler both check before constructing it, so its
 * existence in the codebase costs a disabled site nothing. When it runs and
 * fails, it returns null like any provider that has nothing worth saying;
 * the reason travels separately (see last_error()) so the screen can show it
 * beside the button without breaking the rest of the row.
 *
 * The model's words are never trusted: clean_text() strips the predictable
 * wrapper (quotes, "Image of …") and caps the length, and the write path
 * sanitizes again at save time. A suggestion that survives all of that and is
 * still empty is an error, not a suggestion.
 */
final class AiSuggestionProvider implements SuggestionProvider {

	/**
	 * Bumped whenever the prompt changes — the cache key carries it, so an
	 * old answer never poses as a new prompt's.
	 */
	public const PROMPT_VERSION = 1;

	/** Longest side of the image sent to the model, in pixels. */
	public const MAX_SIDE = 1024;

	/**
	 * Source files larger than this are refused before the editor opens them.
	 * Resizing is the memory-hungry part; a guard up front beats a fatal
	 * halfway through.
	 */
	public const MAX_SOURCE_BYTES = 15728640;

	/**
	 * The user's provider and key.
	 *
	 * @var OpenAiCompatibleAdapter
	 */
	private $adapter;

	/**
	 * Model name, for the source label and the cache key.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * Site locale — the language asked for.
	 *
	 * @var string
	 */
	private $locale;

	/**
	 * Test seam: receives an attachment id, answers like prepare_image().
	 *
	 * @var callable|null
	 */
	private $loader;

	/**
	 * Machine error key from the last suggest() that returned null.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Wire the provider to one adapter, model and language.
	 *
	 * @param OpenAiCompatibleAdapter $adapter The user's provider and key.
	 * @param string                  $model   Model name, for labels and cache keys.
	 * @param string                  $locale  Site locale — the language asked for.
	 * @param callable|null           $loader  Test seam (see $loader).
	 */
	public function __construct( OpenAiCompatibleAdapter $adapter, string $model, string $locale, ?callable $loader = null ) {
		$this->adapter = $adapter;
		$this->model   = $model;
		$this->locale  = $locale;
		$this->loader  = $loader;
	}

	/** {@inheritdoc} */
	public function id(): string {
		return 'ai';
	}

	/** {@inheritdoc} */
	public function label(): string {
		return 'ai';
	}

	/**
	 * Why the last suggest() declined, or '' when it did not.
	 *
	 * Either an adapter key ('timeout', 'unauthorized', 'rate-limited',
	 * 'bad-model', 'bad-response', 'transport-error', 'insecure-url',
	 * 'not-configured') or an image-prep key ('missing-image',
	 * 'unsupported-type', 'too-large', 'editor-error', 'empty-reply').
	 */
	public function last_error(): string {
		return $this->last_error;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Needs `attachment_id` in the context beside the usual three fields —
	 * the model looks at the image, not just at its name.
	 *
	 * @param array{filename:string,title:string,parent_title:string} $context What is known about the image.
	 */
	public function suggest( array $context ): ?array {
		$this->last_error = '';

		$id = (int) ( $context['attachment_id'] ?? 0 );

		if ( $id < 1 ) {
			$this->last_error = 'missing-image';

			return null;
		}

		$cached = AiCache::get_result( $id, $this->model, $this->locale, self::file_fingerprint( $id ) );

		if ( null !== $cached ) {
			return array(
				'text'   => $cached,
				'source' => 'ai',
			);
		}

		$image = $this->load_image( $id );

		if ( isset( $image['error'] ) ) {
			$this->last_error = is_string( $image['error'] ) ? $image['error'] : 'editor-error';

			return null;
		}

		$result = $this->adapter->complete( self::build_messages( $context, $image, $this->locale ) );

		if ( ! $result['ok'] ) {
			$this->last_error = $result['error'];

			return null;
		}

		$text = self::clean_text( $result['text'] );

		if ( '' === $text ) {
			$this->last_error = 'empty-reply';

			return null;
		}

		AiCache::save_result( $id, $this->model, $this->locale, self::file_fingerprint( $id ), $text );

		return array(
			'text'   => $text,
			'source' => 'ai',
		);
	}

	/**
	 * Build the chat messages for one image.
	 *
	 * Pure string work — the part the test suite pins. The constraints live
	 * in the prompt because the model, not the code, chooses the words: 125
	 * characters, no "image of" opening, and the site's language.
	 *
	 * @param array{filename:string,title:string,parent_title:string} $context What is known about the image.
	 * @param array{mime:string,base64:string}                        $image   The prepared image.
	 * @param string                                                  $locale  Site locale.
	 * @return array<int,array<string,mixed>>
	 */
	public static function build_messages( array $context, array $image, string $locale ): array {
		$parts = array();

		if ( '' !== trim( (string) ( $context['filename'] ?? '' ) ) ) {
			$parts[] = 'Filename: ' . trim( (string) $context['filename'] );
		}

		if ( '' !== trim( (string) ( $context['parent_title'] ?? '' ) ) ) {
			$parts[] = 'Shown on page: ' . trim( (string) $context['parent_title'] );
		}

		$parts[] = 'Site language: ' . $locale;

		return array(
			array(
				'role'    => 'system',
				'content' => 'Write alternative text for the attached image. At most 125 characters. Never start with "image of", "photo of" or "picture of". Write in the site language given below. Reply with only the alternative text, no quotes, no explanation.',
			),
			array(
				'role'    => 'user',
				'content' => array_merge(
					array(
						array(
							'type' => 'text',
							'text' => implode( "\n", $parts ),
						),
					),
					array(
						array(
							'type'      => 'image_url',
							'image_url' => array(
								'url' => 'data:' . $image['mime'] . ';base64,' . $image['base64'],
							),
						),
					)
				),
			),
		);
	}

	/**
	 * Distrust the model's reply on the way in.
	 *
	 * Quotes, a wasted opening, trailing explanation — stripped. Length is
	 * capped like any suggestion. What survives empty was never a suggestion.
	 * Pure string work, pinned by tests; the write path sanitizes again.
	 *
	 * @param string $text The raw reply.
	 */
	public static function clean_text( string $text ): string {
		$text = trim( $text );

		// A reply wrapped as a sentence about itself, not the text itself.
		if ( 1 === preg_match( '/^["\'](.*)["\']$/s', $text, $m ) ) {
			$text = trim( $m[1] );
		}

		foreach ( array( 'image of', 'photo of', 'picture of', 'a photo of', 'a picture of' ) as $opening ) {
			if ( 0 === stripos( $text, $opening . ' ' ) ) {
				$text = trim( substr( $text, strlen( $opening ) + 1 ) );
				break;
			}
		}

		// Some models explain first and answer second ("Here is the alt
		// text: …"). The answer is the last line, not the lecture.
		if ( false !== strpos( $text, "\n" ) ) {
			$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ) ) );

			if ( ! empty( $lines ) ) {
				$text = end( $lines );
			}
		}

		// Stripping an opening often leaves a lowercase fragment ("Image of
		// a cat" → "a cat"). A suggestion starts capitalized, always.
		if ( '' !== $text ) {
			$text = ucfirst( $text );
		}

		return \JanitorixMediaAudit\AltText\RuleBasedProvider::cap_text( $text );
	}

	/**
	 * Load the model-sized image, from the seam or the library.
	 *
	 * The seam exists so tests never touch the uploads directory; the
	 * normalizer exists so both paths speak one shape afterwards.
	 *
	 * @param int $attachment_id The image to load.
	 * @return array{mime:string,base64:string}|array{error:string}
	 */
	private function load_image( int $attachment_id ): array {
		if ( null === $this->loader ) {
			return $this->prepare_image( $attachment_id );
		}

		$loader = $this->loader;

		return $loader( $attachment_id );
	}

	/**
	 * What version of the file the cache key should bind to.
	 *
	 * The attached file's modification time: replacing the image under the
	 * same attachment ID changes the fingerprint, so a week-old cached
	 * answer about the previous file is never served. Empty when the file
	 * cannot be read — the key then behaves exactly as before.
	 *
	 * @param int $attachment_id The image.
	 */
	private static function file_fingerprint( int $attachment_id ): string {
		if ( ! function_exists( 'get_attached_file' ) ) {
			return '';
		}

		$file = get_attached_file( $attachment_id );

		if ( ! is_string( $file ) || '' === $file || ! file_exists( $file ) ) {
			return '';
		}

		$mtime = filemtime( $file );

		return false === $mtime ? '' : (string) $mtime;
	}

	/**
	 * Shrink one image to model size and encode it.
	 *
	 * @param int $attachment_id The image to prepare.
	 * @return array{mime:string,base64:string}|array{error:string}
	 */
	private function prepare_image( int $attachment_id ): array {
		$file = get_attached_file( $attachment_id );

		if ( ! is_string( $file ) || '' === $file || ! is_readable( $file ) ) {
			return array( 'error' => 'missing-image' );
		}

		$mime = (string) get_post_mime_type( $attachment_id );

		if ( 0 !== strpos( $mime, 'image/' ) || 'image/svg+xml' === $mime ) {
			return array( 'error' => 'unsupported-type' );
		}

		$size = filesize( $file );

		if ( false === $size || $size > self::MAX_SOURCE_BYTES ) {
			return array( 'error' => 'too-large' );
		}

		$editor = wp_get_image_editor( $file );

		if ( is_wp_error( $editor ) ) {
			return array( 'error' => 'editor-error' );
		}

		$editor->resize( self::MAX_SIDE, self::MAX_SIDE, false );

		$temp = wp_tempnam( 'janitorix-alt-' );

		if ( ! is_string( $temp ) ) {
			return array( 'error' => 'editor-error' );
		}

		$saved = $editor->save( $temp, 'image/jpeg' );

		if ( is_wp_error( $saved ) || ! is_array( $saved ) || empty( $saved['path'] ) ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- best-effort cleanup of our own temp file.

			return array( 'error' => 'editor-error' );
		}

		$data = file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local temp file this method created two lines above, not a remote URL; wp_remote_get() cannot read the filesystem.

		@unlink( $saved['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- best-effort cleanup of our own temp file.
		@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- save() may have written beside the temp name instead.

		if ( ! is_string( $data ) || '' === $data ) {
			return array( 'error' => 'editor-error' );
		}

		return array(
			'mime'   => 'image/jpeg',
			'base64' => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API's image_url payload format, not obfuscation; decoded by the provider, never executed.
		);
	}
}
