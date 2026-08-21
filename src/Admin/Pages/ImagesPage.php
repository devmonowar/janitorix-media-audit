<?php
/**
 * The filtered list every Dashboard count links into.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\Admin\Pages;

use JanitorixMediaAudit\Admin\Assets;
use JanitorixMediaAudit\Admin\Menu;
use JanitorixMediaAudit\Confidence\ScannerWeights;
use JanitorixMediaAudit\Core\Plugin;
use JanitorixMediaAudit\Core\UserDecisions;
use JanitorixMediaAudit\Safety\SafetyEngine;

defined( 'ABSPATH' ) || exit;

/**
 * The list, and the bulk work that happens on it.
 *
 * Two intentions share one form because they act on the same ticked boxes, and
 * they are kept apart everywhere else. Trashing is destructive, reversible, and
 * passes every image through the Safety Engine. Recording a decision — Ignore,
 * Mark Safe, Exclude Forever — destroys nothing and never reaches the Cleanup
 * Engine; it only changes what future scans are willing to suggest.
 *
 * Rescan is not offered here. It is not a per-image action: a scan covers the
 * whole library, and a button implying otherwise would promise something the
 * scanner layer does not do.
 *
 * **Bulk is not a bypass.** The checkbox is only rendered for a row the Safety
 * Engine already approves, and the handler then re-evaluates every image
 * individually — two hundred images is two hundred separate permissions, not
 * one. A row that is refused is reported with its reason rather than silently
 * skipped.
 */
final class ImagesPage {

	private const PER_PAGE = 25;

	/** Renders the filtered, paginated list and the bulk-action form around it. */
	public function render(): void {
		$controller = Plugin::instance()->controller();
		$repo       = $controller->repository();
		$scan       = $repo->latest();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Images', 'janitorix-media-audit' ) . '</h1>';

		$this->result_notice();

		if ( null === $scan ) {
			echo '<div class="janitorix-empty"><p>' . esc_html__( 'No scan yet.', 'janitorix-media-audit' ) . '</p>';
			printf(
				'<a class="button button-primary" href="%s">%s</a></div></div>',
				esc_url( admin_url( 'admin.php?page=' . Menu::SLUG ) ),
				esc_html__( 'Go to the dashboard', 'janitorix-media-audit' )
			);

			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filtering of a list view, nothing is written.
		$filters = array(
			'recommendation' => isset( $_GET['recommendation'] ) ? sanitize_key( wp_unslash( $_GET['recommendation'] ) ) : '',
			'status'         => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'confidence'     => isset( $_GET['confidence'] ) ? sanitize_text_field( wp_unslash( $_GET['confidence'] ) ) : '',
			'risk_level'     => isset( $_GET['risk_level'] ) ? sanitize_text_field( wp_unslash( $_GET['risk_level'] ) ) : '',
			'scanner'        => isset( $_GET['scanner'] ) ? sanitize_key( wp_unslash( $_GET['scanner'] ) ) : '',
			'from'           => $this->date( isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '' ),
			'to'             => $this->date( isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '' ),
			'search'         => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);

		$page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $repo->browse( (int) $scan->id, $filters, $page, self::PER_PAGE );

		$this->filters( $filters );

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: number of images, 2: scan id */
					__( '%1$d image(s) — from scan #%2$d', 'janitorix-media-audit' ),
					$result['total'],
					$scan->id
				)
			)
		);

		// The bulk form wraps the table so the checkboxes belong to it. The
		// confirmation is bound by the enqueued script through this id rather
		// than an inline handler.
		printf(
			'<form id="janitorix-bulk-form" method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		wp_nonce_field( 'janitorix_bulk' );
		echo '<input type="hidden" name="action" value="janitorix_bulk">';

		$this->table( $result['rows'] );
		$this->bulk_bar();

		echo '</form>';

		$this->pagination( $result['total'], $page, $filters );

		echo '</div>';
	}

	/** The notice left after a redirect from a bulk action on this page. */
	private function result_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result; the action itself was nonce-verified before the redirect that set these.
		if ( empty( $_GET['janitorix_result'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'ok' === $_GET['janitorix_result'] ? 'success' : 'warning',
			esc_html( rawurldecode( isset( $_GET['janitorix_message'] ) ? sanitize_text_field( wp_unslash( $_GET['janitorix_message'] ) ) : '' ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * One bulk action, and it is the reversible one.
	 *
	 * There is no bulk permanent deletion. Destroying hundreds of files on a
	 * single click is not a feature — and every selected image still passes
	 * through the Safety Engine individually, so a protected image inside a
	 * selection is refused on its own merits rather than swept along.
	 */
	private function bulk_bar(): void {
		echo '<div class="janitorix-bulk-bar" style="max-width:960px">';

		echo '<div class="tablenav bottom janitorix-bulk-row"><div class="alignleft actions">';

		printf(
			'<button type="submit" class="button">%s</button> ',
			esc_html__( 'Move selected to Trash', 'janitorix-media-audit' )
		);

		echo '<span class="janitorix-t">' . esc_html__( 'Reversible. Each image is checked individually — protected ones are held back and reported.', 'janitorix-media-audit' ) . '</span>';

		echo '</div></div>';

		// The decisions share this form because they act on the same ticked
		// boxes, but they are a different intention: nothing here is destructive
		// and none of it passes the Safety Engine. The handler tells them apart
		// by which button was pressed, so the trash confirmation never appears
		// in front of "Ignore".
		echo '<div class="tablenav bottom janitorix-bulk-row"><div class="alignleft actions">';
		echo '<span class="janitorix-t">' . esc_html__( 'Or mark the ticked images as:', 'janitorix-media-audit' ) . '</span> ';

		foreach ( $this->decision_labels() as $value => $label ) {
			printf(
				'<button type="submit" class="button" name="decision" value="%s" formnovalidate>%s</button> ',
				esc_attr( $value ),
				esc_html( $label )
			);
		}

		echo '</div></div>';

		echo '</div>';

		// The confirmation itself lives in the enqueued script
		// (assets/js/admin.js), bound to this form's id. Nothing is printed
		// inline here — not even the script itself: WordPress
		// wants JavaScript registered through wp_enqueue_script(), and a screen
		// that prints its own <script> tag cannot be deferred, cached or
		// dequeued by a site owner.
	}

	/**
	 * The filter form above the table.
	 *
	 * @param array<string,string> $filters The current filter values.
	 */
	private function filters( array $filters ): void {
		echo '<form method="get" style="margin:12px 0">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( Menu::SLUG . '-images' ) );

		$this->select(
			'recommendation',
			$filters['recommendation'],
			array(
				''              => __( 'Any recommendation', 'janitorix-media-audit' ),
				'move_to_trash' => __( 'Move to Trash', 'janitorix-media-audit' ),
				'review'        => __( 'Review', 'janitorix-media-audit' ),
				'keep'          => __( 'Keep', 'janitorix-media-audit' ),
				'rescan'        => __( 'Rescan', 'janitorix-media-audit' ),
				'ignore'        => __( 'Ignored', 'janitorix-media-audit' ),
				'mark_safe'     => __( 'Marked safe', 'janitorix-media-audit' ),
				'excluded'      => __( 'Excluded', 'janitorix-media-audit' ),
			),
			__( 'Filter by recommendation', 'janitorix-media-audit' )
		);

		$this->select(
			'status',
			$filters['status'],
			array(
				''              => __( 'Any status', 'janitorix-media-audit' ),
				'used'          => __( 'Used', 'janitorix-media-audit' ),
				'possibly_used' => __( 'Possibly used', 'janitorix-media-audit' ),
				'unused'        => __( 'Unused', 'janitorix-media-audit' ),
			),
			__( 'Filter by status', 'janitorix-media-audit' )
		);

		$this->select(
			'confidence',
			$filters['confidence'],
			array(
				''          => __( 'Any confidence', 'janitorix-media-audit' ),
				'Very High' => __( 'Very High', 'janitorix-media-audit' ),
				'High'      => __( 'High', 'janitorix-media-audit' ),
				'Medium'    => __( 'Medium', 'janitorix-media-audit' ),
				'Low'       => __( 'Low', 'janitorix-media-audit' ),
				'Very Low'  => __( 'Very Low', 'janitorix-media-audit' ),
			),
			__( 'Filter by confidence level', 'janitorix-media-audit' )
		);

		$this->select(
			'risk_level',
			$filters['risk_level'],
			array(
				''         => __( 'Any risk', 'janitorix-media-audit' ),
				'Very Low' => __( 'Very Low', 'janitorix-media-audit' ),
				'Low'      => __( 'Low', 'janitorix-media-audit' ),
				'Medium'   => __( 'Medium', 'janitorix-media-audit' ),
				'High'     => __( 'High', 'janitorix-media-audit' ),
				'Critical' => __( 'Critical', 'janitorix-media-audit' ),
			),
			__( 'Filter by risk level', 'janitorix-media-audit' )
		);

		$scanners = array( '' => __( 'Any scanner', 'janitorix-media-audit' ) );

		foreach ( ScannerWeights::all() as $id ) {
			$scanners[ $id ] = ucwords( str_replace( '_', ' ', $id ) );
		}

		$this->select( 'scanner', $filters['scanner'], $scanners, __( 'Filter by the scanner that found it', 'janitorix-media-audit' ) );

		// Uploaded between. The only date that differs per image — every row in
		// one scan shares its scan time, so a range over that would select
		// either everything or nothing.
		printf(
			'<input type="date" name="from" value="%s" aria-label="%s"> ',
			esc_attr( $filters['from'] ),
			esc_attr__( 'Uploaded on or after', 'janitorix-media-audit' )
		);
		printf(
			'<input type="date" name="to" value="%s" aria-label="%s"> ',
			esc_attr( $filters['to'] ),
			esc_attr__( 'Uploaded on or before', 'janitorix-media-audit' )
		);

		printf(
			'<input type="search" name="s" value="%s" placeholder="%s"> ',
			esc_attr( $filters['search'] ),
			esc_attr__( 'Filename…', 'janitorix-media-audit' )
		);

		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'janitorix-media-audit' ) . '</button>';
		echo '</form>';
	}

	/**
	 * The three decisions, plus the way back out of any of them.
	 *
	 * Clearing is offered beside the others rather than hidden somewhere. A
	 * decision the user cannot walk back is a trap, and "Exclude Forever" is
	 * exactly the label that needs an obvious undo next to it.
	 *
	 * @return array<string,string>
	 */
	private function decision_labels(): array {
		return array(
			UserDecisions::IGNORE   => __( 'Ignore', 'janitorix-media-audit' ),
			UserDecisions::SAFE     => __( 'Mark Safe', 'janitorix-media-audit' ),
			UserDecisions::EXCLUDED => __( 'Exclude Forever', 'janitorix-media-audit' ),
			'clear'                 => __( 'Clear decision', 'janitorix-media-audit' ),
		);
	}

	/**
	 * A date from the query string, or an empty string.
	 *
	 * Anything that is not a plain Y-m-d is discarded rather than passed on and
	 * escaped, because a half-valid date silently selects the wrong rows and the
	 * user has no way to tell.
	 *
	 * @param mixed $value The raw, already-unslashed query value.
	 */
	private function date( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * A filter control that announces itself.
	 *
	 * The visible cue for these is the option text, which a sighted user reads
	 * as a heading and a screen reader reads as "combo box, Any status" — no
	 * indication of what it filters. An `aria-label` is the whole fix, and the
	 * doc's rule is not decorative: status is never communicated by appearance
	 * alone anywhere else in this plugin.
	 *
	 * @param string               $name     The select element's name attribute.
	 * @param string               $selected The currently selected value.
	 * @param array<string,string> $options  Value => label pairs.
	 * @param string               $label    An aria-label; falls back to $name.
	 */
	private function select( string $name, string $selected, array $options, string $label = '' ): void {
		printf(
			'<select name="%s" aria-label="%s">',
			esc_attr( $name ),
			esc_attr( '' !== $label ? $label : $name )
		);

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				selected( $selected, (string) $value, false ),
				esc_html( $label )
			);
		}

		echo '</select> ';
	}

	/**
	 * The list table itself, one row per image.
	 *
	 * @param object[] $rows The page of rows to render.
	 */
	private function table( array $rows ): void {
		$safety   = new SafetyEngine();
		$verdicts = array();
		$held     = array();

		// Evaluated once per row, up front, so the same verdict can both decide
		// the checkbox and explain itself. The Safety Engine was already being
		// asked this question per row; the only change is that its answer is
		// kept rather than reduced to a yes/no and thrown away.
		foreach ( $rows as $row ) {
			$id              = (int) $row->attachment_id;
			$verdicts[ $id ] = $safety->evaluate( SafetyEngine::ACTION_TRASH, $id );

			if ( $verdicts[ $id ]->allowed() ) {
				continue;
			}

			$reason = $verdicts[ $id ]->reason_line();

			if ( '' !== $reason ) {
				$held[ $reason ] = ( $held[ $reason ] ?? 0 ) + 1;
			}
		}

		$this->held_back_notice( $held );

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		// `title` is a tooltip, not a label — several screen readers ignore it
		// entirely, and this checkbox selects every image on the page for a
		// destructive action. It says what it does out loud.
		// The table is `fixed`, so every width here is taken literally and File
		// is the only column left to absorb what remains. Adding a tenth column
		// without trimming the other nine squeezed File past its narrowest
		// character and broke every filename into a vertical stack of letters.
		// Keep the total of the fixed widths under roughly 830px.
		printf(
			'<th style="width:92px"><input type="checkbox" id="janitorix-check-all" aria-label="%s"></th>',
			esc_attr__( 'Select every image on this page that may be trashed', 'janitorix-media-audit' )
		);
		echo '<th style="width:60px">' . esc_html__( 'Preview', 'janitorix-media-audit' ) . '</th>';
		echo '<th>' . esc_html__( 'File', 'janitorix-media-audit' ) . '</th>';
		echo '<th style="width:84px">' . esc_html__( 'Status', 'janitorix-media-audit' ) . '</th>';
		echo '<th style="width:84px">' . esc_html__( 'Confidence', 'janitorix-media-audit' ) . '</th>';
		echo '<th style="width:96px">' . esc_html__( 'Risk', 'janitorix-media-audit' ) . '</th>';
		echo '<th style="width:120px">' . esc_html__( 'Recommendation', 'janitorix-media-audit' ) . '</th>';
		echo '<th style="width:72px">' . esc_html__( 'Size', 'janitorix-media-audit' ) . '</th>';
		// Next to "Last scanned" so the two dates read together, and because it
		// is what the date filters above the table actually filter on — those
		// two inputs were previously the only place upload date appeared at all.
		echo '<th style="width:104px">' . esc_html__( 'Uploaded', 'janitorix-media-audit' ) . '</th>';
		echo '<th style="width:104px">' . esc_html__( 'Last scanned', 'janitorix-media-audit' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="10">' . esc_html__( 'No images match these filters.', 'janitorix-media-audit' ) . '</td></tr>';
		}

		// Every row on this page came from the same scan, so "last scanned" is
		// one value, worked out once rather than per row.
		$scan    = Plugin::instance()->controller()->repository()->latest();
		$scanned = $scan && $scan->completed_at
			? sprintf(
				/* translators: %s: human-readable time difference */
				__( '%s ago', 'janitorix-media-audit' ),
				human_time_diff( strtotime( $scan->completed_at . ' UTC' ) )
			)
			: __( 'unknown', 'janitorix-media-audit' );

		foreach ( $rows as $row ) {
			$details = admin_url( 'admin.php?page=' . Menu::SLUG . '-image&id=' . (int) $row->attachment_id );

			echo '<tr>';

			// A checkbox appears only where the action would actually be
			// permitted. Offering one that will be refused is a promise the
			// screen cannot keep — and the user only finds out afterwards.
			$verdict = $verdicts[ (int) $row->attachment_id ];

			if ( $verdict->allowed() ) {
				printf(
					'<td><input type="checkbox" name="images[]" value="%d" aria-label="%s"></td>',
					(int) $row->attachment_id,
					esc_attr(
						sprintf(
							/* translators: %s: image filename */
							__( 'Select %s for trashing', 'janitorix-media-audit' ),
							$row->filename
						)
					)
				);
			} else {
				// Not a blank cell — the plugin declined to offer a checkbox
				// here, and that decision needs to read as deliberate, not as
				// something that failed to render.
				//
				// The reason travels with it. A tooltip alone would not do:
				// several screen readers ignore `title`, and a user who never
				// hovers reads "Protected" as the plugin failing rather than
				// working. So the same sentence goes in an aria-label too, and
				// the notice above the table states it without any interaction
				// at all.
				$reason = $verdict->reason_line();

				if ( '' === $reason ) {
					$reason = __( 'A safety rule is holding this image back — open it to see which one.', 'janitorix-media-audit' );
				}

				printf(
					'<td><span class="janitorix-protected" title="%1$s" aria-label="%2$s"><span class="dashicons dashicons-lock" aria-hidden="true"></span>%3$s</span></td>',
					esc_attr( $reason ),
					esc_attr(
						sprintf(
							/* translators: %s: the safety rule's reason */
							__( 'Protected: %s', 'janitorix-media-audit' ),
							$reason
						)
					),
					esc_html__( 'Protected', 'janitorix-media-audit' )
				);
			}

			printf( '<td>%s</td>', wp_get_attachment_image( (int) $row->attachment_id, array( 50, 50 ) ) );
			printf(
				'<td><a href="%s"><strong>%s</strong></a><br><span class="janitorix-t">#%d</span></td>',
				esc_url( $details ),
				esc_html( $row->filename ),
				(int) $row->attachment_id
			);
			printf( '<td>%s</td>', esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) ) );
			printf( '<td>%d%%</td>', (int) $row->confidence );
			printf(
				'<td><span class="janitorix-pill %s">%s</span></td>',
				esc_attr( Assets::level_class( (string) $row->risk_level ) ),
				esc_html( $row->risk_level )
			);
			printf(
				'<td><span class="janitorix-pill %s">%s</span></td>',
				esc_attr( Assets::recommendation_class( (string) $row->recommendation ) ),
				esc_html( Assets::recommendation_label( (string) $row->recommendation ) )
			);

			// A dash rather than "0 B" where WordPress recorded no size. Zero is
			// a claim about the file; a dash is a claim about our record of it.
			printf(
				'<td>%s</td>',
				(int) $row->filesize > 0 ? esc_html( size_format( (int) $row->filesize ) ) : '—'
			);

			// Upload date. Already selected by browse() as `uploaded`, so this
			// costs no extra query. The date is the visible value because it is
			// what the filters match on and what scans consistently; the exact
			// time and the age go in the tooltip, where they answer "why is this
			// one still protected?" without adding a column nobody asked for.
			$this->uploaded_cell( $row->uploaded ?? null );

			printf( '<td>%s</td>', esc_html( $scanned ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The Uploaded cell: a date to scan down, an exact time and an age on hover.
	 *
	 * A dash rather than a guess where WordPress recorded no date — an
	 * attachment deleted between the scan and this request leaves the LEFT JOIN
	 * with nothing, and inventing "today" for it would be a claim we cannot make.
	 *
	 * Prints the cell rather than returning it: the escaping sniff cannot follow
	 * a string of markup back through a return value, and silencing it is how
	 * this plugin got two review rounds' worth of findings. Escaping where the
	 * output happens is both provable and honest.
	 *
	 * @param string|null $gmt The attachment's post_date_gmt, as stored.
	 */
	private function uploaded_cell( ?string $gmt ): void {
		$timestamp = empty( $gmt ) || '0000-00-00 00:00:00' === $gmt ? false : strtotime( $gmt . ' UTC' );

		if ( ! $timestamp ) {
			echo '<td>&mdash;</td>';
			return;
		}

		printf(
			'<td><span title="%1$s">%2$s</span></td>',
			esc_attr(
				sprintf(
					/* translators: 1: full local date and time, 2: human-readable age, e.g. "3 hours". */
					__( '%1$s — %2$s ago', 'janitorix-media-audit' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ),
					human_time_diff( $timestamp )
				)
			),
			esc_html( wp_date( get_option( 'date_format' ), $timestamp ) )
		);
	}

	/**
	 * Say out loud why some rows offer no checkbox, before anyone has to ask.
	 *
	 * A row marked only "Protected" reads, to someone who has just uploaded an
	 * image specifically to try the plugin out, as the plugin failing to find
	 * it. That is the most common first experience this screen can give, and it
	 * is the wrong one: the scan did find the image, the recommendation does
	 * stand, and a safety rule is refusing the action on purpose.
	 *
	 * So the reason is stated above the table, in plain sight, with no hover
	 * and no click required. Reasons are grouped rather than repeated per row —
	 * twenty images held back by one rule is one sentence, not twenty.
	 *
	 * @param array<string,int> $held Reason sentence => how many rows it holds back.
	 */
	private function held_back_notice( array $held ): void {
		if ( empty( $held ) ) {
			return;
		}

		echo '<div class="notice notice-info inline janitorix-held"><p><strong>';
		echo esc_html__( 'Some images on this page are held back on purpose.', 'janitorix-media-audit' );
		echo '</strong></p><ul class="janitorix-reasons">';

		foreach ( $held as $reason => $count ) {
			printf(
				'<li>%s — %s</li>',
				esc_html(
					sprintf(
						/* translators: %d: number of images */
						_n( '%d image', '%d images', $count, 'janitorix-media-audit' ),
						$count
					)
				),
				esc_html( $reason )
			);
		}

		echo '</ul><p>';
		echo esc_html__( 'The scan found these images and its verdict still stands — a safety rule is refusing the action, so no checkbox is offered. Open an image to see its full reasoning.', 'janitorix-media-audit' );
		echo '</p></div>';
	}

	/**
	 * Page links that carry every active filter along.
	 *
	 * @param int                  $total   The total number of matching images.
	 * @param int                  $page    The current page number.
	 * @param array<string,string> $filters The current filter values, carried into each link.
	 */
	private function pagination( int $total, int $page, array $filters ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages < 2 ) {
			return;
		}

		// Carry every filter, not a chosen few. A page-two link that drops one
		// shows a different set of images under the same heading, and the user
		// has no way to see that it happened.
		$carried = $filters;
		unset( $carried['search'] );

		$base = add_query_arg(
			array_filter(
				array_merge(
					array( 'page' => Menu::SLUG . '-images' ),
					$carried,
					array( 's' => $filters['search'] )
				)
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="tablenav"><div class="tablenav-pages">';

		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => $base . '%_%',
					'format'    => '&paged=%#%',
					'current'   => $page,
					'total'     => $pages,
					'prev_text' => '‹',
					'next_text' => '›',
				)
			)
		);

		echo '</div></div>';
	}
}
