<?php
/**
 * A signature of what a scan depends on.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\Core;

use JanitorixMediaAudit\Scanner\ScannerRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * A stored scan is only valid while the world it described still holds. When it
 * does not, the result is not merely old — it is wrong, and a wrong answer
 * presented as current is a false positive waiting to happen.
 *
 * The fingerprint captures everything that, if changed, invalidates the scan:
 *
 *   - the plugin version (scanner logic may have changed)
 *   - which builder plugins are active (a new one is a new hiding place)
 *   - the active theme (its templates are a search space)
 *   - the media library's own signature (a new upload is a new candidate)
 *   - the content and the OPTION VALUES the scanners search (a reference can be
 *     added or taken away without any of the above moving at all)
 *
 * Two scans with the same fingerprint would reach the same conclusion, so the
 * cache can serve one for the other. Two with different fingerprints cannot be
 * compared, and the newer must win.
 */
final class ScanFingerprint {

	/**
	 * How many option rows to hash per query.
	 */
	private const OPTION_BATCH = 500;

	/**
	 * Fingerprints already computed this request, keyed by environment signature.
	 *
	 * @var array<string,string>
	 */
	private static $memo = array();

	/**
	 * A signature of the environment a scan runs against.
	 *
	 * @param ScannerRegistry $registry The registry, for each scanner's own version.
	 */
	public static function environment( ScannerRegistry $registry ): string {
		$parts = array(
			'plugin'   => JANITORIX_VERSION,
			'theme'    => get_option( 'stylesheet' ) . '|' . get_option( 'template' ),
			'active'   => self::active_extensions( $registry ),
			// A scanner that would now answer differently invalidates the scans
			// it produced. The plugin version cannot stand in for this: it moves
			// on release, and a scanner can change several times between two —
			// or not at all while the version moves for unrelated reasons.
			'scanners' => $registry->versions(),
		);

		return substr( md5( wp_json_encode( $parts ) ), 0, 32 );
	}

	/**
	 * A signature of the media library itself.
	 *
	 * Cheap to compute — a count and the newest modification time — and enough
	 * to notice an upload, an edit, or a deletion since the last scan without
	 * hashing every attachment.
	 */
	public static function library(): string {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- 'image/%%' is a literal MIME prefix, no variable involved; %% is prepare()'s own escaping for one literal percent sign.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total, MAX(post_modified_gmt) AS newest
				 FROM %i
				 WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%'",
				$wpdb->posts
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery

		return substr( md5( ( $row->total ?? '0' ) . '|' . ( $row->newest ?? '' ) ), 0, 16 );
	}

	/**
	 * A signature of the content the scanners actually search.
	 *
	 * The library signature alone is not enough, and the gap is not academic:
	 * editing a page to ADD an image changes no attachment row, so without this
	 * the fingerprint would still match, the stored scan would still be served,
	 * the Safety Engine's "the site has changed" gate would still pass — and an
	 * image that is now on a live page would still read Unused and stay
	 * trashable. A scan is only valid for the content it was run against.
	 *
	 * Counted over the statuses the Content Scanner reads, so a passing
	 * auto-draft does not churn the cache.
	 */
	public static function content(): string {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total, MAX(post_modified_gmt) AS newest
				 FROM %i
				 WHERE post_type NOT IN ( 'attachment', 'revision' )
				   AND post_status NOT IN ( 'auto-draft' )",
				$wpdb->posts
			)
		);

		return substr(
			md5( ( $row->total ?? '0' ) . '|' . ( $row->newest ?? '' ) . '|' . self::options() ),
			0,
			16
		);
	}

	/**
	 * A signature of the OPTION VALUES the scanners search.
	 *
	 * Counting `theme_mods_*` rows — which is all this used to do — sees an
	 * option appear or disappear and nothing else. Every value change was
	 * invisible: dropping an image into a sidebar widget, pointing a Customizer
	 * logo at a different attachment, editing a theme framework blob. So an
	 * image referenced ONLY from a widget could gain that reference after a
	 * scan, leave the fingerprint untouched, pass the Safety Engine's live
	 * gate, and be trashed while genuinely in use. That is the one thing this
	 * whole class exists to prevent.
	 *
	 * The patterns below are the same search space the Widget, Theme Options
	 * and Generic Fallback scanners read, and that is the point: the
	 * fingerprint has to cover exactly what a scan looked at, or a stored
	 * verdict can outlive the thing it was a verdict about. Values are hashed
	 * one row at a time so a large option store costs no more memory than a
	 * small one, and transients are excluded because they churn on their own
	 * and would force a rescan every few minutes.
	 */
	public static function options(): string {
		global $wpdb;

		$digest = hash_init( 'md5' );
		$offset = 0;

		do {
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- every LIKE pattern here is a literal, no variable involved; %% is prepare()'s own escaping for one literal percent sign.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value
					 FROM %i
					 WHERE ( option_name LIKE 'theme_mods_%%'
					      OR option_name LIKE 'widget_%%'
					      OR option_name = 'sidebars_widgets'
					      OR option_name LIKE '%%_theme_options'
					      OR option_name LIKE '%%_theme_settings'
					      OR option_name LIKE 'theme_%%_options'
					      OR option_name LIKE '%%_options'
					      OR option_name LIKE '%%_settings'
					      OR option_value LIKE '%%/uploads/%%' )
					   AND option_name NOT LIKE '\_transient%%'
					   AND option_name NOT LIKE '\_site\_transient%%'
					 ORDER BY option_name
					 LIMIT %d OFFSET %d",
					$wpdb->options,
					self::OPTION_BATCH,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery

			// A database error here must not read as "no options changed" — that
			// is the silent direction, and the silent direction is the one that
			// trashes a used image. An unusable signature is better than a
			// confident wrong one: it stops matching, and the gate asks for a
			// rescan.
			if ( null === $rows ) {
				return 'unreadable';
			}

			foreach ( $rows as $row ) {
				hash_update( $digest, $row->option_name . '=' . md5( (string) $row->option_value ) . "\n" );
			}

			$batch_count = count( $rows );
			$offset     += self::OPTION_BATCH;
		} while ( self::OPTION_BATCH === $batch_count );

		return substr( hash_final( $digest ), 0, 16 );
	}

	/**
	 * Full fingerprint: environment, library, and the content searched.
	 *
	 * All three must be in it. Each answers a different way the site can change
	 * underneath a stored scan: a plugin or theme switch changes where images can
	 * hide, an upload or deletion changes what there is to find, and a content
	 * edit changes what references them.
	 *
	 * Memoised for the request, because the Images screen asks the Safety
	 * Engine for a verdict on every row and each verdict re-checks the live
	 * gate — roughly 75 rebuilds, each one several aggregate scans, to answer
	 * a question whose answer cannot change between two rows of one page.
	 *
	 * Keyed by the environment signature rather than held in a single static,
	 * so a second registry cannot be handed the first one's answer.
	 *
	 * @param ScannerRegistry $registry The registry, for each scanner's own version.
	 */
	public static function full( ScannerRegistry $registry ): string {
		$environment = self::environment( $registry );

		if ( ! isset( self::$memo[ $environment ] ) ) {
			self::$memo[ $environment ] = $environment . '-' . self::library() . '-' . self::content();
		}

		return self::$memo[ $environment ];
	}

	/**
	 * Forget the memoised fingerprint.
	 *
	 * Anything that changes the site inside this request has to call this
	 * before asking for the fingerprint again, or it gets the signature of the
	 * site as it was BEFORE the change. Trashing an image moves
	 * `post_modified_gmt`, which moves the library signature, so a re-stamp
	 * that skipped this would write a fingerprint that was already wrong —
	 * and every later request would read the scan as stale and demand a
	 * rescan that nothing had actually invalidated.
	 */
	public static function flush(): void {
		self::$memo = array();
	}

	/**
	 * Which extension scanners' plugins are active, in a stable order.
	 *
	 * Only scanners we have BUILT are visible here, which is a real limitation:
	 * activating a builder nobody has written a scanner for does not change this
	 * signature. What catches that case instead is the content signature — an
	 * unrecognised builder still writes its data into posts or options, and both
	 * are hashed. Neither is a substitute for the other.
	 *
	 * @param ScannerRegistry $registry The registry, for which builders exist.
	 * @return string[]
	 */
	private static function active_extensions( ScannerRegistry $registry ): array {
		$active = array();

		foreach ( $registry->implemented() as $id => $scanner ) {
			if ( $scanner->is_applicable() ) {
				$active[] = $id;
			}
		}

		sort( $active );

		return $active;
	}
}
