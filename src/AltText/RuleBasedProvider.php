<?php
/**
 * Alt text from what the site already knows — no AI, no network.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\AltText;

use JanitorixMediaAudit\AltText\Contracts\SuggestionProvider;

defined( 'ABSPATH' ) || exit;

/**
 * The first suggestion provider: filename, title, parent post — in that order.
 *
 * The order is the argument. A filename was chosen for this file; a title
 * defaults to that same filename (WordPress fills it in), so it is only
 * evidence when it differs; a parent post's title describes the page, not the
 * image, so it is the last resort rather than the first guess.
 *
 * Everything here is pure string work — no database, no WordPress — which is
 * why the test suite can pin every rule without a site.
 */
final class RuleBasedProvider implements SuggestionProvider {

	/**
	 * Alt text longer than this is truncated by most screen readers.
	 */
	public const MAX_LENGTH = 125;

	/**
	 * Filenames that carry no meaning: camera counters, screenshots, pasted
	 * images, WhatsApp forwards. A suggestion built from one of these is a
	 * generic alt wearing a specific filename's clothes.
	 */
	private const GENERIC_PATTERNS = array(
		'/^(img|dsc|dcim|pxl|image|photo|picture|screenshot|screen[-_ ]?shot|whatsapp[-_ ]?(image|video)?|pasted[-_ ]?(image|file)?)[-_ ]*\d*$/i',
		'/^(screenshot|screen[-_ ]?shot)\s+\d{4}[-_ ]\d{2}[-_ ]\d{2}/i',
		'/^\d+$/',
	);

	/**
	 * Whole alt texts that say nothing, however sincerely meant.
	 *
	 * @var string[]
	 */
	private const GENERIC_WORDS = array( 'image', 'photo', 'picture', 'img', 'pic' );

	/**
	 * Openings that waste the reader's time — a screen reader already
	 * announces "image", so "image of a cat" is heard as "image, image of a
	 * cat".
	 *
	 * @var string[]
	 */
	private const WASTED_OPENINGS = array( 'image of', 'photo of', 'picture of', 'a photo of', 'a picture of' );

	/** {@inheritdoc} */
	public function id(): string {
		return 'rule-based';
	}

	/** {@inheritdoc} */
	public function label(): string {
		return 'rule-based';
	}

	/**
	 * Suggest alt text for one image, or decline.
	 *
	 * @param array{filename:string,title:string,parent_title:string} $context What is known about the image.
	 * @return array{text:string,source:string}|null The suggestion and its source, or null.
	 */
	public function suggest( array $context ): ?array {
		$filename = (string) ( $context['filename'] ?? '' );
		$title    = (string) ( $context['title'] ?? '' );
		$parent   = (string) ( $context['parent_title'] ?? '' );

		$from_file = $this->humanize( $this->basename( $filename ) );

		if ( '' !== $from_file && '' === $this->weak_reason( $from_file ) ) {
			return array(
				'text'   => $from_file,
				'source' => 'filename',
			);
		}

		// The title defaults to the filename, so a title that humanizes to
		// the same words is not a second opinion — it is the same opinion
		// overheard.
		$clean_title = $this->strip_opening( trim( $title ) );
		$clean_title = '' !== $clean_title ? ucfirst( $clean_title ) : '';

		if ( '' !== $clean_title && $clean_title !== $from_file && '' === $this->weak_reason( $clean_title ) ) {
			return array(
				'text'   => $this->cap( $clean_title ),
				'source' => 'title',
			);
		}

		$clean_parent = $this->strip_opening( trim( $parent ) );
		$clean_parent = '' !== $clean_parent ? ucfirst( $clean_parent ) : '';

		if ( '' !== $clean_parent && '' === $this->weak_reason( $clean_parent ) ) {
			return array(
				'text'   => $this->cap( $clean_parent ),
				'source' => 'parent',
			);
		}

		return null;
	}

	/**
	 * Why an existing alt text is weak, or '' when it is fine.
	 *
	 * The return is a machine key — 'too-long', 'generic' or 'too-short' —
	 * translated at render time. An empty alt is not weak: emptiness is either
	 * missing (the list's whole subject) or decorative (a deliberate choice),
	 * and neither is a quality judgement.
	 *
	 * @param string $alt The alt text as stored.
	 */
	public function weak_reason( string $alt ): string {
		$alt = trim( $alt );

		if ( '' === $alt ) {
			return '';
		}

		if ( $this->length( $alt ) > self::MAX_LENGTH ) {
			return 'too-long';
		}

		if ( $this->length( $alt ) < 3 ) {
			return 'too-short';
		}

		$lower = strtolower( $alt );

		if ( in_array( $lower, self::GENERIC_WORDS, true ) ) {
			return 'generic';
		}

		foreach ( self::GENERIC_PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $alt ) ) {
				return 'generic';
			}
		}

		return '';
	}

	/**
	 * Turn "sunset-over-dhaka.jpg" into "Sunset over dhaka".
	 *
	 * Underscores, dashes and dots become spaces; the extension goes; camera
	 * debris never survives because suggest() asks weak_reason() first.
	 *
	 * @param string $basename The filename without its directory.
	 */
	private function humanize( string $basename ): string {
		// Strip the extension: the last dot onwards, unless the name starts
		// with the dot (a hidden file has no extension to strip).
		$dot = strrpos( $basename, '.' );

		if ( false !== $dot && 0 !== $dot ) {
			$basename = substr( $basename, 0, $dot );
		}

		$text = trim( str_replace( array( '_', '-', '.' ), ' ', $basename ) );
		$text = $this->strip_opening( $text );

		// Collapse runs of spaces the separators above may have left behind.
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		if ( '' === $text ) {
			return '';
		}

		return $this->cap( ucfirst( $text ) );
	}

	/**
	 * The filename without any directory — "2024/10/sunset.jpg" is about
	 * "sunset.jpg"; the folders are where it lives, not what it shows.
	 *
	 * @param string $filename Whatever was stored.
	 */
	private function basename( string $filename ): string {
		$filename = str_replace( '\\', '/', $filename );
		$slash    = strrpos( $filename, '/' );

		return false === $slash ? $filename : substr( $filename, $slash + 1 );
	}

	/**
	 * Remove a wasted opening ("Image of …") where one is present.
	 *
	 * @param string $text The candidate text.
	 */
	private function strip_opening( string $text ): string {
		foreach ( self::WASTED_OPENINGS as $opening ) {
			if ( 0 === stripos( $text, $opening . ' ' ) ) {
				return trim( substr( $text, strlen( $opening ) + 1 ) );
			}
		}

		return $text;
	}

	/**
	 * Hard cap at MAX_LENGTH, on a character boundary.
	 *
	 * Public so the write path enforces the same limit the suggester keeps:
	 * a hand-written alt must not be storable longer than a suggested one.
	 *
	 * @param string $text The candidate text.
	 */
	public static function cap_text( string $text ): string {
		if ( self::length_of( $text ) <= self::MAX_LENGTH ) {
			return $text;
		}

		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, 0, self::MAX_LENGTH );
		}

		return substr( $text, 0, self::MAX_LENGTH );
	}

	/**
	 * Hard cap at MAX_LENGTH, on a character boundary.
	 *
	 * @param string $text The candidate text.
	 */
	private function cap( string $text ): string {
		return self::cap_text( $text );
	}

	/**
	 * Character length, multibyte-aware where possible.
	 *
	 * @param string $text The text to measure.
	 */
	private function length( string $text ): int {
		return self::length_of( $text );
	}

	/**
	 * Character length, multibyte-aware where possible.
	 *
	 * @param string $text The text to measure.
	 */
	private static function length_of( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $text );
		}

		return strlen( $text );
	}
}
