<?php
/**
 * Alt-text coverage for the whole library, in bulk queries.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\AltText;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth every surface reads: the admin screen, the
 * Dashboard card, and the WP-CLI stats command all call summary() and render
 * what it returns. Two definitions of "missing" would drift into two
 * different numbers on two different screens, and a coverage figure the user
 * cannot reconcile is a figure they stop trusting.
 *
 * Bulk is the point, the same as MediaFacts: one query for the ids, one for
 * the stored alt texts, one for the decorative flags. No per-image queries,
 * no scan required — attachment meta exists whether or not a scan has run.
 */
final class AltStats {

	/**
	 * Count the library's alt-text state.
	 *
	 * An image is exactly one of: decorative (declared), missing (empty and
	 * not declared), weak (present but flagged), or good. Coverage is the
	 * share that needs nothing: good plus decorative.
	 *
	 * @return array{total:int,good:int,weak:int,missing:int,decorative:int,coverage:int}
	 */
	public function summary(): array {
		$data = $this->load();

		$total = count( $data['ids'] );

		if ( 0 === $total ) {
			return array(
				'total'      => 0,
				'good'       => 0,
				'weak'       => 0,
				'missing'    => 0,
				'decorative' => 0,
				'coverage'   => 100,
			);
		}

		$counts = array(
			'total'      => $total,
			'good'       => 0,
			'weak'       => 0,
			'missing'    => 0,
			'decorative' => 0,
		);

		foreach ( $this->status_map() as $status ) {
			++$counts[ $status ];
		}

		$counts['coverage'] = (int) round( 100 * ( $counts['good'] + $counts['decorative'] ) / $total );

		return $counts;
	}

	/**
	 * Every image's alt-text status, for filtered lists.
	 *
	 * The screen filters before it paginates, so it needs the status of the
	 * whole library, not just one page. Same three queries as summary() —
	 * asking in bulk twice is still cheaper than asking per image once.
	 *
	 * @return array<int,string> Attachment id => 'good', 'weak', 'missing' or 'decorative'.
	 */
	public function status_map(): array {
		$data     = $this->load();
		$provider = new RuleBasedProvider();

		$out = array();

		foreach ( $data['ids'] as $id ) {
			if ( isset( $data['decoratives'][ $id ] ) ) {
				$out[ $id ] = 'decorative';
				continue;
			}

			$alt = $data['alts'][ $id ] ?? '';

			if ( '' === trim( $alt ) ) {
				$out[ $id ] = 'missing';
				continue;
			}

			$out[ $id ] = '' !== $provider->weak_reason( $alt ) ? 'weak' : 'good';
		}

		return $out;
	}

	/**
	 * The stored alt text for a set of images, in one query.
	 *
	 * The screen lists one page at a time; this is that page's worth of alt
	 * texts without a query per row.
	 *
	 * @param int[] $ids Attachment ids on this page.
	 * @return array<int,string> Attachment id => stored alt text ('' when none).
	 */
	public function alts_for( array $ids ): array {
		$ids = array_map( 'intval', $ids );

		if ( empty( $ids ) ) {
			return array();
		}

		return $this->stored_alts( $ids );
	}

	/**
	 * The three bulk answers every public method reasons from.
	 *
	 * @return array{ids:int[],alts:array<int,string>,decoratives:array<int,true>}
	 */
	private function load(): array {
		$ids = $this->image_ids();

		if ( empty( $ids ) ) {
			return array(
				'ids'         => array(),
				'alts'        => array(),
				'decoratives' => array(),
			);
		}

		return array(
			'ids'         => $ids,
			'alts'        => $this->stored_alts( $ids ),
			'decoratives' => AltDecisions::map(),
		);
	}

	/**
	 * Every image attachment outside the trash.
	 *
	 * Trashed attachments are not audited: they are already on their way out,
	 * and demanding alt text for them would be bookkeeping for its own sake.
	 *
	 * @return int[]
	 */
	private function image_ids(): array {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM %i WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND post_status != 'trash' ORDER BY ID DESC",
				$wpdb->posts,
				$wpdb->esc_like( 'image/' ) . '%'
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * The stored alt text per image, in one query.
	 *
	 * Alt text is unique meta — one row per attachment in practice — but a
	 * duplicated key must not double-count an image, so the first row wins and
	 * the rest are ignored.
	 *
	 * @param int[] $ids Every image attachment id.
	 * @return array<int,string> Attachment id => stored alt text.
	 */
	private function stored_alts( array $ids ): array {
		global $wpdb;

		$ids = array_map( 'intval', $ids );
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results(
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a run of '%d' built one per id, and those ids are unpacked into this same call; the sniff counts only placeholders it can see written literally.
			$wpdb->prepare(
				"SELECT post_id, meta_value
				 FROM %i
				 WHERE post_id IN ( {$in} )
				   AND meta_key = '_wp_attachment_image_alt'",
				$wpdb->postmeta,
				...$ids
			)
		);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();

		foreach ( (array) $rows as $row ) {
			$id = (int) $row->post_id;

			if ( ! array_key_exists( $id, $out ) ) {
				$out[ $id ] = (string) $row->meta_value;
			}
		}

		return $out;
	}
}
