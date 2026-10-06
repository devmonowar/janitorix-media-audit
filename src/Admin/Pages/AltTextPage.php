<?php
/**
 * Every image's alt text, with a suggestion where one is missing.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\Admin\Pages;

use JanitorixMediaAudit\Admin\Menu;
use JanitorixMediaAudit\AltText\AltDecisions;
use JanitorixMediaAudit\AltText\AltStats;
use JanitorixMediaAudit\AltText\AltUndo;
use JanitorixMediaAudit\AltText\RuleBasedProvider;
use JanitorixMediaAudit\Core\Plugin;
use JanitorixMediaAudit\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * The alt-text audit list.
 *
 * This screen suggests and applies; it never deletes, never scores, and never
 * touches the Confidence or Risk engines. A suggestion is words a provider
 * thought of — applying one is a person's decision, recorded per image with
 * the previous value kept for undo.
 *
 * Single-row actions are GET links carrying a nonce (the WordPress core
 * row-action pattern: Plugins, Posts and Media all act this way). The bulk
 * form stays POST. Both end in the same handler checks: capability, nonce,
 * then act. A bulk selection can never exceed one page (25 rows), which is
 * what keeps a bulk apply from becoming a timeout.
 */
final class AltTextPage {

	private const PER_PAGE = 25;

	/** The statuses this screen filters by. */
	private const FILTERS = array( 'all', 'missing', 'weak', 'decorative' );

	/** Renders the counts, the filters, the list and the bulk form. */
	public function render(): void {
		$stats   = new AltStats();
		$summary = $stats->summary();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Alt Text', 'janitorix-media-audit' ) . '</h1>';

		$this->result_notice();

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: images missing alt, 2: images total, 3: coverage percent */
					__( '%1$d of %2$d images need alt text · %3$d%% covered', 'janitorix-media-audit' ),
					(int) $summary['missing'],
					(int) $summary['total'],
					(int) $summary['coverage']
				)
			)
		);

		echo '<p>' . esc_html__( 'This checks the alt text stored on each image. What a visitor actually hears can differ — a theme or page builder may override it where the image is shown.', 'janitorix-media-audit' ) . '</p>';

		if ( 0 === (int) $summary['total'] ) {
			echo '<p>' . esc_html__( 'No images in the media library yet.', 'janitorix-media-audit' ) . '</p>';
			echo '</div>';

			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filtering of a list view, nothing is written.
		$filter = isset( $_GET['alt_status'] ) ? sanitize_key( wp_unslash( $_GET['alt_status'] ) ) : 'all';
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $filter, self::FILTERS, true ) ) {
			$filter = 'all';
		}

		$this->filters( $summary, $filter );

		$map = $stats->status_map();
		$ids = array_keys( $map );

		if ( 'all' !== $filter ) {
			$ids = array();

			foreach ( $map as $id => $status ) {
				if ( $status === $filter ) {
					$ids[] = $id;
				}
			}
		}

		$total = count( $ids );
		$rows  = array_slice( $ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'Nothing here.', 'janitorix-media-audit' ) . '</p>';
			echo '</div>';

			return;
		}

		// An AI ask lands back on the row it was asked from. Only that row
		// reads a pending suggestion or shows the ask's message — the rest of
		// the page pays no extra queries for a feature one row just used.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; the ask itself was nonce-verified before the redirect that set these.
		$ai_row = isset( $_GET['janitorix_ai_row'] ) ? absint( wp_unslash( $_GET['janitorix_ai_row'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->table( $rows, $stats, $ai_row );

		$this->pagination( $total, $page, $filter );

		echo '</div>';
	}

	/** The notice left after a redirect from a row or bulk action on this page. */
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
	 * Filter links with counts, in the WordPress list-table idiom.
	 *
	 * @param array<string,int> $summary The coverage summary's counts.
	 * @param string            $current The active filter.
	 */
	private function filters( array $summary, string $current ): void {
		$labels = array(
			'all'        => __( 'All', 'janitorix-media-audit' ),
			'missing'    => __( 'Missing', 'janitorix-media-audit' ),
			'weak'       => __( 'Weak', 'janitorix-media-audit' ),
			'decorative' => __( 'Decorative', 'janitorix-media-audit' ),
		);

		$counts = array(
			'all'        => $summary['total'],
			'missing'    => $summary['missing'],
			'weak'       => $summary['weak'],
			'decorative' => $summary['decorative'],
		);

		echo '<ul class="subsubsub">';

		$first = true;

		foreach ( $labels as $filter => $label ) {
			if ( ! $first ) {
				echo ' | ';
			}

			$first = false;

			printf(
				'<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a></li>',
				esc_url( admin_url( 'admin.php?page=' . Menu::SLUG . '-alt&alt_status=' . $filter ) ),
				$filter === $current ? 'current' : '',
				esc_html( $label ),
				(int) $counts[ $filter ]
			);
		}

		echo '</ul><br class="clear">';
	}

	/**
	 * The list and the bulk form around it.
	 *
	 * @param int[]    $rows   Attachment ids on this page.
	 * @param AltStats $stats  The shared counter.
	 * @param int      $ai_row Row an AI ask just landed on (0 when none).
	 */
	private function table( array $rows, AltStats $stats, int $ai_row ): void {
		$details = $this->details( $rows );
		$alts    = $stats->alts_for( $rows );
		$backups = AltUndo::with_backup( $rows );
		$used_in = $this->used_in( $rows );

		$provider = new RuleBasedProvider();

		// The bulk form does NOT wrap the table: each row carries its own small
		// POST form (custom text + Apply), and a form inside a form would be
		// invalid markup that browsers submit as the outer one. The checkboxes
		// join the bulk form below through the HTML `form` attribute instead.
		echo '<table class="widefat striped janitorix-alt-table"><thead><tr>';
		echo '<td class="check-column"><input type="checkbox" id="janitorix-alt-select-all" aria-label="' . esc_attr__( 'Select all images on this page', 'janitorix-media-audit' ) . '"></td>';
		echo '<th>' . esc_html__( 'Image', 'janitorix-media-audit' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'janitorix-media-audit' ) . '</th>';
		echo '<th>' . esc_html__( 'Current alt', 'janitorix-media-audit' ) . '</th>';
		echo '<th>' . esc_html__( 'Suggestion', 'janitorix-media-audit' ) . '</th>';
		echo '<th>' . esc_html__( 'Used in', 'janitorix-media-audit' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'janitorix-media-audit' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $id ) {
			$this->row( (int) $id, $details, $alts, $backups, $used_in, $provider, $ai_row );
		}

		echo '</tbody></table>';

		printf(
			'<form id="janitorix-alt-bulk-form" method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		wp_nonce_field( 'janitorix_alt_bulk' );
		echo '<input type="hidden" name="action" value="janitorix_alt_bulk">';
		echo '<div class="tablenav bottom"><div class="alignleft actions">';
		echo '<select name="janitorix_alt_bulk_action" aria-label="' . esc_attr__( 'Bulk action', 'janitorix-media-audit' ) . '">';
		echo '<option value="">' . esc_html__( 'Bulk actions', 'janitorix-media-audit' ) . '</option>';
		echo '<option value="apply">' . esc_html__( 'Apply suggestions', 'janitorix-media-audit' ) . '</option>';
		echo '<option value="decorative">' . esc_html__( 'Mark decorative', 'janitorix-media-audit' ) . '</option>';
		echo '<option value="clear">' . esc_html__( 'Clear decorative', 'janitorix-media-audit' ) . '</option>';
		echo '</select> ';
		echo '<input type="submit" class="button action" value="' . esc_attr__( 'Apply', 'janitorix-media-audit' ) . '">';
		echo '</div></div>';

		echo '</form>';
	}

	/**
	 * One row: thumbnail, status, current alt, suggestion with its source, and
	 * the row actions.
	 *
	 * @param int                            $id        The attachment id.
	 * @param array<int,array<string,mixed>> $details   Post columns per id.
	 * @param array<int,string>              $alts      Stored alt text per id.
	 * @param array<int,true>                $backups   Ids with an undo waiting.
	 * @param array<int,string[]>            $used_in   Reference labels per id.
	 * @param RuleBasedProvider              $provider  The suggestion source.
	 * @param int                            $ai_row    Row an AI ask just landed on (0 when none).
	 */
	private function row( int $id, array $details, array $alts, array $backups, array $used_in, RuleBasedProvider $provider, int $ai_row ): void {
		$detail = $details[ $id ] ?? array(
			'title'  => '',
			'parent' => 0,
		);

		$alt          = $alts[ $id ] ?? '';
		$is_empty     = '' === trim( $alt );
		$is_deco      = AltDecisions::is_decorative( $id );
		$weak         = ( ! $is_empty && ! $is_deco ) ? $provider->weak_reason( $alt ) : '';
		$suggestion   = ( $is_empty && ! $is_deco ) || '' !== $weak ? $this->suggestion( $id, $detail, $provider ) : null;
		$ai_available = \JanitorixMediaAudit\AltText\Ai\AiSettings::is_available();
		$ai_pending   = false;

		// A parked AI suggestion surfaces exactly once, on the landing after
		// the ask: prefilled into the box with its source, then forgotten so
		// a reload cannot resurrect it.
		if ( $ai_available && $ai_row === $id ) {
			$parked = \JanitorixMediaAudit\AltText\Ai\AiCache::get_pending( get_current_user_id(), $id );

			if ( null !== $parked && '' !== trim( $parked['text'] ) ) {
				\JanitorixMediaAudit\AltText\Ai\AiCache::clear_pending( get_current_user_id(), $id );

				$suggestion = array(
					'text'   => $parked['text'],
					'source' => $parked['source'],
				);

				$ai_pending = true;
			}
		}

		printf(
			'<tr><th scope="row" class="check-column"><input type="checkbox" name="images[]" value="%d" form="janitorix-alt-bulk-form" aria-label="%s"></th>',
			(int) $id,
			esc_attr(
				sprintf(
					/* translators: %s: image filename */
					__( 'Select %s', 'janitorix-media-audit' ),
					$this->filename( $id )
				)
			)
		);

		echo '<td><div style="display:flex;gap:8px;align-items:center;">';
		echo wp_kses_post( wp_get_attachment_image( $id, array( 48, 48 ) ) );
		printf( '<span>%s</span>', esc_html( $this->filename( $id ) ) );
		echo '</div></td>';

		echo '<td>' . esc_html( $this->status_label( $is_empty, $is_deco, $weak ) ) . '</td>';

		printf( '<td>%s</td>', '' === $alt ? '<span aria-hidden="true">—</span>' : esc_html( $alt ) );

		echo '<td>';

		if ( null !== $suggestion ) {
			// The suggestion arrives prefilled and editable: Apply saves
			// whatever is in the box (the suggestion untouched, or the user's
			// own words), never a value that travelled in the request unseen.
			printf(
				'<form method="post" action="%s">',
				esc_url( admin_url( 'admin-post.php' ) )
			);
			wp_nonce_field( 'janitorix_alt_custom' );
			echo '<input type="hidden" name="action" value="janitorix_alt_custom">';
			printf( '<input type="hidden" name="image" value="%d">', (int) $id );
			// Input and button stay on one line whatever the text length:
			// the box shrinks, the button never wraps underneath it.
			echo '<span style="display:inline-flex;gap:6px;align-items:center;max-width:100%">';
			printf(
				'<input type="text" name="janitorix_alt_text" value="%s" maxlength="125" size="30" style="flex:1;min-width:120px" aria-label="%s">',
				esc_attr( $suggestion['text'] ),
				esc_attr__( 'Alt text', 'janitorix-media-audit' )
			);
			printf(
				'<input type="submit" class="%s" style="flex:none" value="%s">',
				$ai_pending ? 'button button-primary' : 'button',
				$ai_pending ? esc_attr__( 'Apply', 'janitorix-media-audit' ) : esc_attr__( 'Save', 'janitorix-media-audit' )
			);
			echo '</span>';
			printf(
				'<br><small class="janitorix-suggest-source">%s</small>',
				esc_html(
					sprintf(
						/* translators: %s: where the suggestion came from */
						__( 'Suggested from %s — edit freely', 'janitorix-media-audit' ),
						$this->source_label( $suggestion['source'] )
					)
				)
			);

			if ( $ai_pending ) {
				printf(
					' <small><a href="%s">%s</a></small>',
					esc_url(
						wp_nonce_url(
							add_query_arg(
								array(
									'action' => 'janitorix_alt_ai_dismiss',
									'image'  => $id,
								),
								admin_url( 'admin-post.php' )
							),
							'janitorix_alt_ai_dismiss'
						)
					),
					esc_html__( 'Dismiss', 'janitorix-media-audit' )
				);
			}

			echo '</form>';
		} elseif ( ! $is_empty && ! $is_deco ) {
			// Good alt, nothing to suggest — but the owner may still want
			// better words. Same form, prefilled with what is there.
			printf(
				'<form method="post" action="%s">',
				esc_url( admin_url( 'admin-post.php' ) )
			);
			wp_nonce_field( 'janitorix_alt_custom' );
			echo '<input type="hidden" name="action" value="janitorix_alt_custom">';
			printf( '<input type="hidden" name="image" value="%d">', (int) $id );
			echo '<span style="display:inline-flex;gap:6px;align-items:center;max-width:100%">';
			printf(
				'<input type="text" name="janitorix_alt_text" value="%s" maxlength="125" size="30" style="flex:1;min-width:120px" aria-label="%s">',
				esc_attr( $alt ),
				esc_attr__( 'Alt text', 'janitorix-media-audit' )
			);
			printf(
				'<input type="submit" class="button" style="flex:none" value="%s">',
				esc_attr__( 'Save', 'janitorix-media-audit' )
			);
			echo '</span>';
			echo '</form>';
		} else {
			echo '<span aria-hidden="true">—</span>';
		}

		echo '</td>';

		echo '<td>';

		if ( empty( $used_in[ $id ] ) ) {
			echo '<span aria-hidden="true">—</span>';
		} else {
			$labels = array_slice( $used_in[ $id ], 0, 3 );
			echo esc_html( implode( ', ', $labels ) );

			if ( count( $used_in[ $id ] ) > 3 ) {
				printf(
					' <small>(%s)</small>',
					esc_html(
						sprintf(
							/* translators: %d: number of further places */
							__( '+%d more', 'janitorix-media-audit' ),
							count( $used_in[ $id ] ) - 3
						)
					)
				);
			}
		}

		echo '</td>';

		echo '<td>';
		$this->row_actions( $id, $is_deco, isset( $backups[ $id ] ), $ai_available, $ai_row === $id );
		echo '</td></tr>';
	}

	/**
	 * The row's Decorative / Undo links, and the AI button where enabled.
	 *
	 * Applying lives in the suggestion box's own form above — one write path
	 * per row, not two. These links only flip flags. The AI button appears
	 * only when the owner enabled AI and saved a key; a disabled site renders
	 * exactly like 1.1.0, down to the markup.
	 *
	 * GET with a nonce, verified in the handler before anything is written —
	 * the same shape as core's own Activate / Trash / Delete row links. The
	 * AI ask itself is a POST form (it spends money); only the dismiss is a
	 * link, because forgetting a transient needs no confirmation.
	 *
	 * @param int  $id           The attachment id.
	 * @param bool $is_deco      Whether it is declared decorative.
	 * @param bool $has_undo     Whether a backup waits.
	 * @param bool $ai_available Whether AI is enabled and keyed.
	 * @param bool $ai_landed    Whether an AI ask just landed on this row.
	 */
	private function row_actions( int $id, bool $is_deco, bool $has_undo, bool $ai_available, bool $ai_landed ): void {
		$links = array();

		$links[] = $is_deco
			? $this->row_link( 'undecorate', $id, __( 'Not decorative', 'janitorix-media-audit' ) )
			: $this->row_link( 'decorative', $id, __( 'Mark decorative', 'janitorix-media-audit' ) );

		if ( $has_undo ) {
			$links[] = $this->row_link( 'undo', $id, __( 'Undo', 'janitorix-media-audit' ) );
		}

		echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every link is escaped at construction in row_link().

		if ( $ai_available ) {
			printf(
				'<form method="post" action="%s" style="margin-top:6px" data-janitorix-once="1" data-janitorix-ai="1" data-janitorix-apply="%s">',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr__( 'Apply', 'janitorix-media-audit' )
			);
			wp_nonce_field( 'janitorix_alt_ai_suggest' );
			echo '<input type="hidden" name="action" value="janitorix_alt_ai_suggest">';
			printf( '<input type="hidden" name="image" value="%d">', (int) $id );
			printf(
				'<input type="submit" class="button" value="%s">',
				esc_attr__( 'Suggest with AI', 'janitorix-media-audit' )
			);
			echo '<br><span class="janitorix-ai-message"></span>';
			echo '</form>';

			if ( $ai_landed ) {
				// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; the ask was nonce-verified before the redirect that set these.
				$message = isset( $_GET['janitorix_message'] ) ? sanitize_text_field( wp_unslash( $_GET['janitorix_message'] ) ) : '';
				// phpcs:enable WordPress.Security.NonceVerification.Recommended

				if ( '' !== $message ) {
					printf( '<p><small>%s</small></p>', esc_html( rawurldecode( $message ) ) );
				}
			}
		}
	}

	/**
	 * One nonce-carrying row-action link.
	 *
	 * @param string $task The action the handler switches on.
	 * @param int    $id   The attachment id.
	 * @param string $text The translated link text.
	 */
	private function row_link( string $task, int $id, string $text ): string {
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'janitorix_alt_row',
							'do'     => $task,
							'image'  => $id,
						),
						admin_url( 'admin-post.php' )
					),
					'janitorix_alt_row'
				)
			),
			esc_html( $text )
		);
	}

	/**
	 * Suggest for one row: filename, title, parent title.
	 *
	 * The suggestion is recomputed here, at render, and again in the handler
	 * at apply time — the posted link carries only the image id, never the
	 * words, so a crafted URL cannot plant arbitrary alt text.
	 *
	 * @param int                 $id       The attachment id.
	 * @param array<string,mixed> $detail   Post columns for the row.
	 * @param RuleBasedProvider   $provider The suggestion source.
	 * @return array{text:string,source:string}|null
	 */
	private function suggestion( int $id, array $detail, RuleBasedProvider $provider ): ?array {
		$file = get_attached_file( $id );

		$parent_title = 0 !== (int) $detail['parent'] ? get_the_title( (int) $detail['parent'] ) : '';

		return $provider->suggest(
			array(
				'filename'     => is_string( $file ) ? wp_basename( $file ) : '',
				'title'        => (string) $detail['title'],
				'parent_title' => is_string( $parent_title ) ? $parent_title : '',
			)
		);
	}

	/**
	 * Post columns for this page's rows, in one query.
	 *
	 * @param int[] $ids Attachment ids on this page.
	 * @return array<int,array<string,mixed>> Id => title and parent id.
	 */
	private function details( array $ids ): array {
		global $wpdb;

		$ids = array_map( 'intval', $ids );
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results(
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a run of '%d' built one per id, and those ids are unpacked into this same call; the sniff counts only placeholders it can see written literally.
			$wpdb->prepare(
				"SELECT ID, post_title, post_parent FROM %i WHERE ID IN ( {$in} )",
				$wpdb->posts,
				...$ids
			)
		);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->ID ] = array(
				'title'  => (string) $row->post_title,
				'parent' => (int) $row->post_parent,
			);
		}

		return $out;
	}

	/**
	 * Where this page's images are referenced, from the latest completed scan.
	 *
	 * No scan, no locations — the alt-text audit does not need a scan, so an
	 * unscanned site gets an empty column rather than a demand to scan first.
	 *
	 * @param int[] $ids Attachment ids on this page.
	 * @return array<int,string[]> Attachment id => location labels.
	 */
	private function used_in( array $ids ): array {
		$scan = Plugin::instance()->controller()->repository()->latest();

		if ( null === $scan ) {
			return array();
		}

		global $wpdb;

		$ids = array_map( 'intval', $ids );
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results(
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a run of '%d' built one per id, and those ids are unpacked into this same call; the sniff counts only placeholders it can see written literally.
			$wpdb->prepare(
				"SELECT attachment_id, location_label
				 FROM %i
				 WHERE scan_id = %d AND attachment_id IN ( {$in} )
				 ORDER BY attachment_id ASC",
				Tables::references(),
				(int) $scan->id,
				...$ids
			)
		);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();

		foreach ( (array) $rows as $row ) {
			$id    = (int) $row->attachment_id;
			$label = trim( (string) $row->location_label );

			if ( '' === $label ) {
				continue;
			}

			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array();
			}

			if ( ! in_array( $label, $out[ $id ], true ) ) {
				$out[ $id ][] = $label;
			}
		}

		return $out;
	}

	/**
	 * The basename of the attached file, for recognition.
	 *
	 * @param int $id The attachment id.
	 */
	private function filename( int $id ): string {
		$file = get_attached_file( $id );

		return is_string( $file ) ? wp_basename( $file ) : '';
	}

	/**
	 * The status word for a row.
	 *
	 * @param bool   $is_empty Whether no alt text is stored.
	 * @param bool   $is_deco  Whether it is declared decorative.
	 * @param string $weak     The weak reason, or ''.
	 */
	private function status_label( bool $is_empty, bool $is_deco, string $weak ): string {
		if ( $is_deco ) {
			return __( 'Decorative', 'janitorix-media-audit' );
		}

		if ( $is_empty ) {
			return __( 'Missing', 'janitorix-media-audit' );
		}

		if ( '' !== $weak ) {
			return __( 'Weak', 'janitorix-media-audit' );
		}

		return __( 'Good', 'janitorix-media-audit' );
	}

	/**
	 * Translate a suggestion source key at render time.
	 *
	 * Delegates to the registry so the reloaded row and the AJAX reply label
	 * a source the same way — one function, two callers.
	 *
	 * @param string $source One of 'filename', 'title', 'parent' — or 'ai:<model>'.
	 */
	private function source_label( string $source ): string {
		return \JanitorixMediaAudit\AltText\SuggestionProviders::source_label( $source );
	}

	/**
	 * Page links that carry the active filter along.
	 *
	 * @param int    $total  The total number of matching images.
	 * @param int    $page   The current page number.
	 * @param string $filter The current filter value, carried into each link.
	 */
	private function pagination( int $total, int $page, string $filter ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages < 2 ) {
			return;
		}

		$base = add_query_arg(
			array(
				'page'       => Menu::SLUG . '-alt',
				'alt_status' => $filter,
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
