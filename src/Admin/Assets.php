<?php
/**
 * How a status becomes a palette class and a label.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The mapping half of the status palette.
 *
 * The palette's colours live in `assets/css/admin.css`, and the script that
 * uses these classes in `assets/js/admin.js` — both real files, enqueued by
 * Menu::enqueue(). What stays here is the part that has to be decided in PHP:
 * which class a given level or recommendation earns, and what it is called.
 *
 * Every status also carries text. Colour is never the only signal — this plugin's
 * entire job is telling people what is dangerous, and a red dot means nothing to
 * a colourblind reader.
 */
final class Assets {

	/**
	 * Map a level to its palette class. Meaning first, colour second — the
	 * caller always prints the text too.
	 *
	 * @param string $level A risk or confidence level's name.
	 */
	public static function level_class( string $level ): string {
		switch ( strtolower( $level ) ) {
			case 'very low':
			case 'low':
				return 'janitorix-safe';
			case 'medium':
				return 'janitorix-caution';
			case 'high':
			case 'critical':
				return 'janitorix-danger';
			default:
				return 'janitorix-neutral';
		}
	}

	/**
	 * Map a recommendation to its palette class.
	 *
	 * @param string $action A recommendation action constant.
	 */
	public static function recommendation_class( string $action ): string {
		switch ( $action ) {
			case 'move_to_trash':
				return 'janitorix-safe';
			case 'review':
			case 'rescan':
				return 'janitorix-caution';
			case 'keep':
				return 'janitorix-neutral';
			default:
				return 'janitorix-neutral';
		}
	}

	/**
	 * The human-readable text for a recommendation.
	 *
	 * @param string $action A recommendation action constant.
	 */
	public static function recommendation_label( string $action ): string {
		$labels = array(
			'move_to_trash' => __( 'Move to Trash', 'janitorix-media-audit' ),
			'review'        => __( 'Review', 'janitorix-media-audit' ),
			'keep'          => __( 'Keep', 'janitorix-media-audit' ),
			'rescan'        => __( 'Rescan', 'janitorix-media-audit' ),
			// The three the user chose. Worded so the verdict reads as theirs.
			'ignore'        => __( 'Ignored by you', 'janitorix-media-audit' ),
			'mark_safe'     => __( 'Marked safe by you', 'janitorix-media-audit' ),
			'excluded'      => __( 'Excluded by you', 'janitorix-media-audit' ),
		);

		return $labels[ $action ] ?? ucfirst( str_replace( '_', ' ', $action ) );
	}
}
