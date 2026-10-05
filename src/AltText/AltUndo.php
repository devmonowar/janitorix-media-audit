<?php
/**
 * The previous alt text, kept so an apply can be taken back.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\AltText;

defined( 'ABSPATH' ) || exit;

/**
 * Applying a suggestion overwrites the stored alt — a destructive write in
 * miniature. The undo is not a trash bin (alt text has no trash); it is the
 * exact previous value, kept beside the image until the next apply replaces
 * it or the user clears it.
 *
 * One backup per image is enough. A stack of them would answer a question
 * nobody asks ("what was the alt three applies ago?") at the cost of postmeta
 * rows nobody prunes.
 */
final class AltUndo {

	private const META_KEY = '_janitorix_alt_backup';

	/**
	 * Remember the alt text that is about to be replaced.
	 *
	 * An empty previous alt is still worth keeping: undoing back to empty is
	 * a real destination, and "no backup" must mean "nothing was ever
	 * applied", not "the alt used to be empty".
	 *
	 * @param int    $attachment_id The image being changed.
	 * @param string $previous_alt  The alt text being replaced, possibly ''.
	 */
	public static function save( int $attachment_id, string $previous_alt ): void {
		update_post_meta(
			$attachment_id,
			self::META_KEY,
			wp_json_encode(
				array(
					'text' => $previous_alt,
					'time' => time(),
				)
			)
		);
	}

	/**
	 * The remembered alt text, if any.
	 *
	 * @param int $attachment_id The image to check.
	 * @return array{text:string,time:int}|null The backup, or null when no apply has happened.
	 */
	public static function peek( int $attachment_id ): ?array {
		$raw = get_post_meta( $attachment_id, self::META_KEY, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$backup = json_decode( $raw, true );

		if ( ! is_array( $backup ) || ! array_key_exists( 'text', $backup ) ) {
			return null;
		}

		return array(
			'text' => (string) $backup['text'],
			'time' => (int) ( $backup['time'] ?? 0 ),
		);
	}

	/**
	 * Forget the backup — after an undo, or when the user dismisses it.
	 *
	 * @param int $attachment_id The image to forget.
	 */
	public static function clear( int $attachment_id ): void {
		delete_post_meta( $attachment_id, self::META_KEY );
	}

	/**
	 * Which of these images have a backup waiting, in one query.
	 *
	 * The list shows an Undo button per row; this is how it knows which rows
	 * earn one without asking per row.
	 *
	 * @param int[] $ids Attachment ids on this page.
	 * @return array<int,true> Ids with a stored backup.
	 */
	public static function with_backup( array $ids ): array {
		global $wpdb;

		$ids = array_map( 'intval', $ids );

		if ( empty( $ids ) ) {
			return array();
		}

		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_col(
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a run of '%d' built one per id, and those ids are unpacked into this same call; the sniff counts only placeholders it can see written literally.
			$wpdb->prepare(
				"SELECT post_id
				 FROM %i
				 WHERE meta_key = %s
				   AND post_id IN ( {$in} )",
				$wpdb->postmeta,
				self::META_KEY,
				...$ids
			)
		);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();

		foreach ( (array) $rows as $id ) {
			$out[ (int) $id ] = true;
		}

		return $out;
	}

	/**
	 * The meta key, for the uninstaller.
	 *
	 * Exposed rather than duplicated: a cleanup routine that hardcodes the key
	 * is a cleanup routine that stops matching the day the key changes.
	 */
	public static function meta_key(): string {
		return self::META_KEY;
	}
}
