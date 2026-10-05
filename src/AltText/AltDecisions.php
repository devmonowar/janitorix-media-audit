<?php
/**
 * Which images the user has declared decorative.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\AltText;

defined( 'ABSPATH' ) || exit;

/**
 * A decorative image is not a missing alt — it is a deliberate empty one.
 *
 * This lives beside the image (postmeta), not in a scan table, for the same
 * reason UserDecisions does: a scan is a snapshot and gets pruned, while a
 * person's judgement is meant to outlive every scan that follows it.
 *
 * It is deliberately NOT a fourth UserDecisions value. Those three govern the
 * unused flow and are read by the Recommendation Engine; a decorative flag
 * governs the alt-text list and is read by nothing else. Mixing the two would
 * let an alt-text judgement leak into a deletion recommendation.
 */
final class AltDecisions {

	private const META_KEY = '_janitorix_alt_decorative';

	/** The only value ever stored — presence is the decision. */
	public const DECORATIVE = 'decorative';

	/**
	 * Is this image declared decorative?
	 *
	 * @param int $attachment_id The image to check.
	 */
	public static function is_decorative( int $attachment_id ): bool {
		return self::DECORATIVE === get_post_meta( $attachment_id, self::META_KEY, true );
	}

	/**
	 * Declare an image decorative, or clear the declaration.
	 *
	 * @param int  $attachment_id The image decided about.
	 * @param bool $decorative    True to declare, false to clear.
	 */
	public static function set( int $attachment_id, bool $decorative ): void {
		if ( ! $decorative ) {
			delete_post_meta( $attachment_id, self::META_KEY );

			return;
		}

		update_post_meta( $attachment_id, self::META_KEY, self::DECORATIVE );
	}

	/**
	 * Every decorative image on the site, in one query.
	 *
	 * Asked once before the alt-text list renders rather than once per row.
	 *
	 * @return array<int,true> Decorative attachment ids.
	 */
	public static function map(): array {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT post_id FROM %i WHERE meta_key = %s',
				$wpdb->postmeta,
				self::META_KEY
			)
		);

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
