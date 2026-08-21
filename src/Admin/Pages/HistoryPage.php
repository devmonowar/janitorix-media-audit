<?php
/**
 * What we concluded, and when.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\Admin\Pages;

use JanitorixMediaAudit\Admin\Menu;
use JanitorixMediaAudit\Core\Plugin;
use JanitorixMediaAudit\Database\ScanRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Not a log — an audit trail.
 *
 * Every row is a frozen snapshot: the counts, the coverage, the scanner states,
 * and the confidence breakdown exactly as they were when the scan finished. A
 * later scan never rewrites an earlier one, which is what makes "the plugin told
 * me this was unused last month" a question anyone can actually answer.
 */
final class HistoryPage {

	/** Render either the report for one scan, or the listing of every scan. */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$requested = isset( $_GET['scan'] ) ? absint( wp_unslash( $_GET['scan'] ) ) : 0;

		if ( $requested > 0 ) {
			$this->report( $requested );

			return;
		}

		$this->listing();
	}

	/** Every recorded scan, newest first. */
	private function listing(): void {
		$repository = Plugin::instance()->controller()->repository();
		$scans      = $repository->history( 50 );
		$recovered  = $repository->recoverable_bytes( wp_list_pluck( $scans, 'id' ) );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Scan History', 'janitorix-media-audit' ) . '</h1>';

		if ( empty( $scans ) ) {
			echo '<div class="janitorix-empty"><p>' . esc_html__( 'No scans recorded yet.', 'janitorix-media-audit' ) . '</p></div></div>';

			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th style="width:70px">' . esc_html__( 'Scan', 'janitorix-media-audit' ) . '</th>';
		echo '<th>' . esc_html__( 'When', 'janitorix-media-audit' ) . '</th>';
		echo '<th style="width:100px">' . esc_html__( 'Result', 'janitorix-media-audit' ) . '</th>';
		echo '<th style="width:90px">' . esc_html__( 'Images', 'janitorix-media-audit' ) . '</th>';
		echo '<th style="width:90px">' . esc_html__( 'Unused', 'janitorix-media-audit' ) . '</th>';
		echo '<th style="width:110px">' . esc_html__( 'Storage Saved', 'janitorix-media-audit' ) . '</th>';
		echo '<th style="width:90px">' . esc_html__( 'Coverage', 'janitorix-media-audit' ) . '</th>';
		echo '<th style="width:90px">' . esc_html__( 'Duration', 'janitorix-media-audit' ) . '</th>';
		echo '<th>' . esc_html__( 'Scanners', 'janitorix-media-audit' ) . '</th>';
		echo '<th style="width:100px"></th>';
		echo '</tr></thead><tbody>';

		foreach ( $scans as $scan ) {
			$states    = json_decode( (string) $scan->scanner_states, true ) ?? array();
			$completed = 0;

			foreach ( $states as $state ) {
				if ( in_array( $state, array( 'success', 'warning' ), true ) ) {
					++$completed;
				}
			}

			echo '<tr>';
			printf( '<td>#%d</td>', (int) $scan->id );
			printf(
				'<td>%s</td>',
				esc_html(
					$scan->completed_at
						? sprintf(
							/* translators: %s: human-readable time difference */
							__( '%s ago', 'janitorix-media-audit' ),
							human_time_diff( strtotime( $scan->completed_at . ' UTC' ) )
						)
						: __( 'in progress', 'janitorix-media-audit' )
				)
			);
			printf( '<td>%s</td>', esc_html( ScanRepository::result_of( $scan ) ) );
			printf( '<td>%s</td>', esc_html( (string) ( $scan->images_total ?? '—' ) ) );
			printf( '<td>%s</td>', esc_html( (string) ( $scan->images_unused ?? '—' ) ) );
			printf(
				'<td>%s</td>',
				empty( $recovered[ (int) $scan->id ] ) ? '—' : esc_html( size_format( $recovered[ (int) $scan->id ] ) )
			);
			printf( '<td>%s</td>', null === $scan->coverage ? '—' : esc_html( $scan->coverage . '%' ) );
			printf( '<td>%s</td>', $scan->duration_ms ? esc_html( round( $scan->duration_ms / 1000, 1 ) . 's' ) : '—' );
			printf(
				'<td>%s</td>',
				esc_html(
					sprintf(
						/* translators: 1: completed scanners, 2: total scanners */
						__( '%1$d of %2$d completed', 'janitorix-media-audit' ),
						$completed,
						count( $states )
					)
				)
			);
			printf(
				'<td><a href="%s">%s</a></td>',
				esc_url(
					add_query_arg(
						array(
							'page' => Menu::SLUG . '-history',
							'scan' => (int) $scan->id,
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'View report', 'janitorix-media-audit' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p class="janitorix-t">' . esc_html__( 'Snapshots are immutable — a later scan never rewrites an earlier one.', 'janitorix-media-audit' ) . '</p>';
		echo '</div>';
	}

	/**
	 * One archived scan, exactly as it was when it finished.
	 *
	 * Nothing here is recalculated. Every figure is read from the row the scan
	 * wrote, which is the whole point of keeping them: "the plugin told me this
	 * was unused last month" is only answerable if last month's answer, and the
	 * reasoning under it, are still on disk.
	 *
	 * That includes the parts that went wrong. A scan where two scanners failed
	 * is a scan whose conclusions were narrower than they looked, and the report
	 * has to say so months later just as plainly as it did on the day.
	 *
	 * @param int $scan_id The scan to show.
	 */
	private function report( int $scan_id ): void {
		$repo = Plugin::instance()->controller()->repository();
		$scan = $repo->find( $scan_id );

		echo '<div class="wrap">';

		printf(
			'<h1>%s</h1>',
			esc_html(
				sprintf(
					/* translators: %d: scan id */
					__( 'Scan #%d', 'janitorix-media-audit' ),
					$scan_id
				)
			)
		);

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( add_query_arg( 'page', Menu::SLUG . '-history', admin_url( 'admin.php' ) ) ),
			esc_html__( '← All scans', 'janitorix-media-audit' )
		);

		if ( null === $scan ) {
			echo '<div class="janitorix-empty"><p>' . esc_html__( 'That scan is no longer stored. Only the 50 most recent are kept.', 'janitorix-media-audit' ) . '</p></div></div>';

			return;
		}

		$this->actions( $scan_id );
		$this->summary( $scan, $repo->recommendation_counts( $scan_id ) );
		$this->scanners( $scan );
		$this->broken( $scan );

		echo '<p class="janitorix-t">' . esc_html__( 'This is a frozen record. Nothing on this page is recalculated from the site as it is now.', 'janitorix-media-audit' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Export, and delete the record.
	 *
	 * The delete button says what it does not do, because that is the part
	 * people hesitate over. The specification puts it plainly — deleting a
	 * history record never deletes a media file — and a button that carries the
	 * reassurance is worth more than a warning buried in documentation.
	 *
	 * @param int $scan_id The scan these actions apply to.
	 */
	private function actions( int $scan_id ): void {
		$export = add_query_arg(
			array(
				'action' => 'janitorix_export_scan',
				'scan'   => $scan_id,
			),
			admin_url( 'admin-post.php' )
		);

		echo '<p>';

		foreach ( array(
			'csv'  => __( 'Export CSV', 'janitorix-media-audit' ),
			'json' => __( 'Export JSON', 'janitorix-media-audit' ),
		) as $format => $label ) {
			printf(
				'<a class="button" href="%s">%s</a> ',
				esc_url( wp_nonce_url( add_query_arg( 'format', $format, $export ), 'janitorix_export_scan' ) ),
				esc_html( $label )
			);
		}

		// The prompt is carried as a data attribute and bound by the enqueued
		// script, so this screen emits no JavaScript of its own.
		printf(
			'<form method="post" action="%s" style="display:inline" data-janitorix-confirm="%s">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr__( 'Delete this scan record? Your images are not touched — only the record of what this scan concluded.', 'janitorix-media-audit' )
		);

		wp_nonce_field( 'janitorix_forget_scan' );
		echo '<input type="hidden" name="action" value="janitorix_forget_scan">';
		printf( '<input type="hidden" name="scan" value="%d">', absint( $scan_id ) );
		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Delete record', 'janitorix-media-audit' ) );
		echo '</form>';

		echo '</p>';
		echo '<p class="janitorix-t">' . esc_html__( 'Deleting a record removes what the plugin concluded. It never removes an image.', 'janitorix-media-audit' ) . '</p>';
	}

	/**
	 * The scan's headline figures.
	 *
	 * @param object            $scan            The archived scan row.
	 * @param array<string,int> $recommendations Counts by recommendation.
	 */
	private function summary( object $scan, array $recommendations ): void {
		echo '<h2>' . esc_html__( 'What this scan concluded', 'janitorix-media-audit' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';

		$this->row(
			__( 'Finished', 'janitorix-media-audit' ),
			$scan->completed_at
				? sprintf(
					/* translators: %s: human-readable time difference */
					__( '%s ago', 'janitorix-media-audit' ),
					human_time_diff( strtotime( $scan->completed_at . ' UTC' ) )
				)
				: __( 'never — this scan did not finish', 'janitorix-media-audit' )
		);

		$this->row( __( 'Result', 'janitorix-media-audit' ), ScanRepository::result_of( $scan ) );
		$this->row( __( 'Plugin version', 'janitorix-media-audit' ), (string) $scan->plugin_version );
		$this->row( __( 'Images examined', 'janitorix-media-audit' ), (string) ( $scan->images_total ?? '—' ) );

		$this->row(
			__( 'Coverage', 'janitorix-media-audit' ),
			null === $scan->coverage
				? '—'
				: sprintf(
					/* translators: 1: coverage percentage, 2: number of checks */
					__( '%1$d%% · %2$d checks', 'janitorix-media-audit' ),
					(int) $scan->coverage,
					(int) $scan->checks
				)
		);

		$this->row(
			__( 'Confidence in absence', 'janitorix-media-audit' ),
			null === $scan->absence_confidence ? '—' : $scan->absence_confidence . '%'
		);

		$this->row(
			__( 'Verdicts', 'janitorix-media-audit' ),
			sprintf(
				/* translators: 1: used count, 2: possibly used count, 3: unused count */
				__( '%1$d used · %2$d possibly used · %3$d unused', 'janitorix-media-audit' ),
				(int) $scan->images_used,
				(int) $scan->images_possibly_used,
				(int) $scan->images_unused
			)
		);

		$this->row(
			__( 'Recommended', 'janitorix-media-audit' ),
			sprintf(
				/* translators: 1: trash count, 2: review count, 3: keep count, 4: rescan count */
				__( '%1$d to Trash · %2$d for Review · %3$d Keep · %4$d Rescan', 'janitorix-media-audit' ),
				(int) ( $recommendations['move_to_trash'] ?? 0 ),
				(int) ( $recommendations['review'] ?? 0 ),
				(int) ( $recommendations['keep'] ?? 0 ),
				(int) ( $recommendations['rescan'] ?? 0 )
			)
		);

		echo '</tbody></table>';
	}

	/**
	 * Which scanners ran, how far each got, and what each one was worth.
	 *
	 * @param object $scan The archived scan row.
	 */
	private function scanners( object $scan ): void {
		$breakdown = json_decode( (string) ( $scan->confidence_breakdown ?? '' ), true );

		if ( ! is_array( $breakdown ) || empty( $breakdown ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Where this scan looked', 'janitorix-media-audit' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Scanner', 'janitorix-media-audit' ) );
		printf( '<th style="width:130px">%s</th>', esc_html__( 'Result', 'janitorix-media-audit' ) );
		printf( '<th style="width:110px">%s</th>', esc_html__( 'Examined', 'janitorix-media-audit' ) );
		printf( '<th style="width:90px">%s</th>', esc_html__( 'Weight', 'janitorix-media-audit' ) );
		printf( '<th style="width:110px">%s</th>', esc_html__( 'Contributed', 'janitorix-media-audit' ) );
		echo '</tr></thead><tbody>';

		foreach ( $breakdown as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$state    = (string) ( $entry['state'] ?? '' );
			$examined = (int) ( $entry['examined'] ?? 0 );
			$gap      = in_array( $state, array( 'failed', 'not_implemented' ), true );

			printf(
				'<tr><td>%s</td><td><span class="janitorix-pill %s">%s</span></td><td>%s</td><td>%d</td><td>%s</td></tr>',
				esc_html( ucwords( str_replace( '_', ' ', (string) ( $entry['scanner'] ?? '' ) ) ) ),
				esc_attr( $gap ? 'janitorix-danger' : ( 'skipped' === $state ? 'janitorix-neutral' : 'janitorix-safe' ) ),
				esc_html( str_replace( '_', ' ', $state ) ),
				$examined > 0 ? esc_html( number_format_i18n( $examined ) ) : '—',
				(int) ( $entry['weight'] ?? 0 ),
				esc_html( (string) ( $entry['contribution'] ?? 0 ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * The findings that point the other way — places already showing a gap.
	 *
	 * @param object $scan The archived scan row.
	 */
	private function broken( object $scan ): void {
		$broken = json_decode( (string) ( $scan->broken_references ?? '' ), true );

		if ( ! is_array( $broken ) || empty( $broken ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'References that resolved to nothing', 'janitorix-media-audit' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><thead><tr>';
		printf( '<th style="width:150px">%s</th>', esc_html__( 'Kind', 'janitorix-media-audit' ) );
		printf( '<th style="width:130px">%s</th>', esc_html__( 'Found by', 'janitorix-media-audit' ) );
		printf( '<th>%s</th>', esc_html__( 'Where', 'janitorix-media-audit' ) );
		printf( '<th>%s</th>', esc_html__( 'What', 'janitorix-media-audit' ) );
		echo '</tr></thead><tbody>';

		foreach ( array_slice( $broken, 0, 200 ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
				esc_html(
					'missing_attachment' === ( $entry['kind'] ?? '' )
						? __( 'Deleted image', 'janitorix-media-audit' )
						: __( 'Unresolvable', 'janitorix-media-audit' )
				),
				esc_html( (string) ( $entry['scanner'] ?? '' ) ),
				esc_html( (string) ( $entry['location'] ?? '' ) ),
				esc_html( (string) ( $entry['raw'] ?? '' ) )
			);
		}

		if ( count( $broken ) > 200 ) {
			printf(
				'<tr><td colspan="4">%s</td></tr>',
				esc_html(
					sprintf(
						/* translators: %d: number of further findings not shown */
						__( 'and %d more — not shown, so this page stays readable.', 'janitorix-media-audit' ),
						count( $broken ) - 200
					)
				)
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * One label/value summary row.
	 *
	 * @param string $label The row's label.
	 * @param string $value Its value.
	 */
	private function row( string $label, string $value ): void {
		printf(
			'<tr><th scope="row" style="width:220px;text-align:left">%s</th><td>%s</td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}
}
