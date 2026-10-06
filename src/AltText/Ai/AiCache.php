<?php
/**
 * AI answers worth keeping, and suggestions waiting for a human.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\AltText\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Two transient drawers, two different lifetimes.
 *
 * Result cache (a week): the same image through the same model in the same
 * language gets the same answer — asking twice pays twice. The key carries
 * the model, the locale and the prompt version, so a changed prompt or a
 * changed language never resurrects a stale answer.
 *
 * Pending suggestions (ten minutes): the AI button POSTs, the handler asks,
 * and the redirect back needs the words. They wait here — never in the URL,
 * which would leak them into histories and logs — until the screen shows
 * them, the user applies them, or dismisses them.
 *
 * Transients, not options: on an external object cache they evaporate on
 * their own, and what uninstall cannot sweep it does not have to — the TTLs
 * stay short enough that a leftover is a brief ghost, not a resident.
 */
final class AiCache {

	/** Result cache lifetime. */
	public const RESULT_TTL = 604800;

	/** Pending suggestion lifetime. */
	public const PENDING_TTL = 600;

	/** Prefix every key below shares, for the uninstall sweep. */
	public const PREFIX = 'jalt_ai_';

	/**
	 * Build the result-cache key. Pure string work, pinned by tests.
	 *
	 * @param int    $attachment_id The image.
	 * @param string $model         The model that answered.
	 * @param string $locale        The language asked for.
	 */
	public static function result_key( int $attachment_id, string $model, string $locale ): string {
		return self::PREFIX . 'r_' . md5( $attachment_id . '|' . strtolower( trim( $model ) ) . '|' . $locale . '|v' . AiSuggestionProvider::PROMPT_VERSION );
	}

	/**
	 * The pending suggestion's key for one person and image.
	 *
	 * @param int $user_id       The person waiting.
	 * @param int $attachment_id The image asked about.
	 */
	public static function pending_key( int $user_id, int $attachment_id ): string {
		return self::PREFIX . 'p_' . $user_id . '_' . $attachment_id;
	}

	/**
	 * A cached answer, if one is still valid.
	 *
	 * @param int    $attachment_id The image.
	 * @param string $model         The model that answered.
	 * @param string $locale        The language asked for.
	 */
	public static function get_result( int $attachment_id, string $model, string $locale ): ?string {
		$cached = get_transient( self::result_key( $attachment_id, $model, $locale ) );

		return is_string( $cached ) && '' !== $cached ? $cached : null;
	}

	/**
	 * Keep an answer for the next identical ask.
	 *
	 * @param int    $attachment_id The image.
	 * @param string $model         The model that answered.
	 * @param string $locale        The language asked for.
	 * @param string $text          The cleaned suggestion.
	 */
	public static function save_result( int $attachment_id, string $model, string $locale, string $text ): void {
		set_transient( self::result_key( $attachment_id, $model, $locale ), $text, self::RESULT_TTL );
	}

	/**
	 * A suggestion waiting to be shown, applied or dismissed.
	 *
	 * @param int $user_id       The person waiting.
	 * @param int $attachment_id The image asked about.
	 * @return array{text:string,source:string}|null
	 */
	public static function get_pending( int $user_id, int $attachment_id ): ?array {
		$pending = get_transient( self::pending_key( $user_id, $attachment_id ) );

		if ( ! is_array( $pending ) || ! isset( $pending['text'], $pending['source'] ) ) {
			return null;
		}

		return array(
			'text'   => (string) $pending['text'],
			'source' => (string) $pending['source'],
		);
	}

	/**
	 * Park a suggestion until the redirect lands.
	 *
	 * @param int    $user_id       The person waiting.
	 * @param int    $attachment_id The image asked about.
	 * @param string $text          The cleaned suggestion.
	 * @param string $source        Who answered: 'ai:<model>' or a provider id.
	 */
	public static function save_pending( int $user_id, int $attachment_id, string $text, string $source ): void {
		set_transient(
			self::pending_key( $user_id, $attachment_id ),
			array(
				'text'   => $text,
				'source' => $source,
			),
			self::PENDING_TTL
		);
	}

	/**
	 * Forget a pending suggestion — shown, applied or dismissed.
	 *
	 * @param int $user_id       The person waiting.
	 * @param int $attachment_id The image asked about.
	 */
	public static function clear_pending( int $user_id, int $attachment_id ): void {
		delete_transient( self::pending_key( $user_id, $attachment_id ) );
	}
}
