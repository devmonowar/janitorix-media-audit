<?php
/**
 * Mission control.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\Admin\Pages;

use JanitorixMediaAudit\Admin\Assets;
use JanitorixMediaAudit\Admin\Menu;
use JanitorixMediaAudit\Confidence\ConfidenceEngine;
use JanitorixMediaAudit\Core\Plugin;
use JanitorixMediaAudit\Reports\DashboardReport;

defined( 'ABSPATH' ) || exit;

/**
 * Opens with a verdict and one obvious next action, not a wall of numbers.
 *
 * Every figure below was computed by the DashboardReport. This file divides
 * nothing, counts nothing, and decides nothing — and it never triggers a scan
 * on page load.
 */
final class DashboardPage {

	/** Render the whole Dashboard screen. */
	public function render(): void {
		$controller = Plugin::instance()->controller();
		$data       = ( new DashboardReport( $controller->repository() ) )->build( null !== $controller->cached() );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Janitorix Media Audit', 'janitorix-media-audit' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, presence-checked, value never read.
		if ( isset( $_GET['janitorix_scanned'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Scan complete.', 'janitorix-media-audit' ) . '</p></div>';
		}

		if ( empty( $data['has_scan'] ) ) {
			$this->empty_state();
			echo '</div>';

			return;
		}

		foreach ( $data['notices'] as $notice ) {
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['text'] )
			);
		}

		$this->hero( $data );
		$this->cards( $data );

		echo '</div>';
	}

	/**
	 * No scan yet: a welcome and one button. Never empty charts or zeroed
	 * statistics.
	 */
	private function empty_state(): void {
		echo '<div class="janitorix-empty">';
		echo '<h2>' . esc_html__( 'Nothing scanned yet', 'janitorix-media-audit' ) . '</h2>';
		echo '<p>' . esc_html__( 'Run a scan to find out which images your site is not using — and how sure we are about each one.', 'janitorix-media-audit' ) . '</p>';
		echo '<p>' . esc_html__( 'Nothing is deleted. The plugin reports what it would recommend.', 'janitorix-media-audit' ) . '</p>';
		$this->scan_button( __( 'Start the first scan', 'janitorix-media-audit' ) );
		echo '</div>';
	}

	/**
	 * The health score, the score's reasons, and the headline figures.
	 *
	 * @param array<string,mixed> $data The dashboard report's built data.
	 */
	private function hero( array $data ): void {
		$health = $data['health'];
		$scan   = $data['scan'];

		echo '<div class="janitorix-hero">';
		echo '<h2>' . esc_html__( 'Media health', 'janitorix-media-audit' ) . '</h2>';

		printf(
			'<div><span class="janitorix-score">%d%%</span><span class="janitorix-label">%s</span></div>',
			(int) $health['score'],
			esc_html( $health['label'] )
		);

		// Never a number without a reason.
		echo '<ul class="janitorix-reasons">';
		foreach ( $health['reasons'] as $reason ) {
			echo '<li>' . esc_html( $reason ) . '</li>';
		}
		echo '</ul>';

		echo '<div class="janitorix-figures">';
		$this->figure( __( 'Images', 'janitorix-media-audit' ), (int) $scan->images_total );
		$this->figure( __( 'Unused', 'janitorix-media-audit' ), (int) $scan->images_unused );
		$this->figure( __( 'Need review', 'janitorix-media-audit' ), (int) $data['risk']['needs_review'] );
		$this->figure( __( 'Coverage', 'janitorix-media-audit' ), (int) $data['coverage'], '%' );
		echo '</div>';

		$this->scan_button(
			$data['stale']
				? __( 'Rescan — the site has changed', 'janitorix-media-audit' )
				: __( 'Run a new scan', 'janitorix-media-audit' )
		);

		printf(
			' <a class="button" href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . Menu::SLUG . '-images' ) ),
			esc_html__( 'Review images', 'janitorix-media-audit' )
		);

		echo '</div>';
	}

	/**
	 * The nine summary cards.
	 *
	 * @param array<string,mixed> $data The dashboard report's built data.
	 */
	private function cards( array $data ): void {
		$scan = $data['scan'];

		echo '<div class="janitorix-cards">';

		// Quick Actions — always visible.
		echo '<div class="janitorix-card"><h3>' . esc_html__( 'Quick Actions', 'janitorix-media-audit' ) . '</h3>';
		echo '<div class="janitorix-actions">';
		$this->scan_button( __( 'Rescan', 'janitorix-media-audit' ) );
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . Menu::SLUG . '-images' ) ),
			esc_html__( 'Review Images', 'janitorix-media-audit' )
		);
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . Menu::SLUG . '-settings' ) ),
			esc_html__( 'Open Settings', 'janitorix-media-audit' )
		);
		echo '</div></div>';

		// Scanner Status — one of six states, plus modules completed.
		echo '<div class="janitorix-card"><h3>' . esc_html__( 'Scanner Status', 'janitorix-media-audit' ) . '</h3>';
		printf(
			'<p><span class="janitorix-pill %s">%s</span></p>',
			esc_attr( $data['stale'] || 'completed' !== $scan->status ? 'janitorix-caution' : 'janitorix-safe' ),
			esc_html( (string) $data['state'] )
		);
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: completed scanners, 2: total scanners */
					__( '%1$d of %2$d modules completed', 'janitorix-media-audit' ),
					$data['scanners']['completed'],
					$data['scanners']['total']
				)
			)
		);
		echo '</div>';

		$this->coverage_card( $data );

		// Confidence — library-wide.
		echo '<div class="janitorix-card"><h3>' . esc_html__( 'Confidence', 'janitorix-media-audit' ) . '</h3>';
		printf( '<div class="janitorix-big">%d%%</div>', (int) $data['confidence'] );
		echo '<p>' . esc_html__( 'that an image with no references really has none — the ceiling is 99%, never 100.', 'janitorix-media-audit' ) . '</p>';
		echo '</div>';

		// Risk — the user must know instantly whether action is required.
		echo '<div class="janitorix-card"><h3>' . esc_html__( 'Risk Summary', 'janitorix-media-audit' ) . '</h3>';
		printf( '<div class="janitorix-big">%d</div>', (int) $data['risk']['unused_at_risk'] );
		echo '<p>' . esc_html__( 'unused images at High or Critical risk — these need a human.', 'janitorix-media-audit' ) . '</p>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: count needing review, 2: count at low risk */
					__( '%1$d held for review · %2$d at Low risk or below', 'janitorix-media-audit' ),
					$data['risk']['needs_review'],
					$data['risk']['safe']
				)
			)
		);
		echo '</div>';

		$this->storage_card( $data );

		// Recommendations — each count links to the Images screen, pre-filtered.
		echo '<div class="janitorix-card"><h3>' . esc_html__( 'Recommendations', 'janitorix-media-audit' ) . '</h3>';

		if ( empty( $data['recommendations'] ) ) {
			echo '<p>' . esc_html__( 'None yet.', 'janitorix-media-audit' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $data['recommendations'] as $action => $count ) {
				printf(
					'<li><a href="%s">%s</a> — %d</li>',
					esc_url( admin_url( 'admin.php?page=' . Menu::SLUG . '-images&recommendation=' . rawurlencode( (string) $action ) ) ),
					esc_html( Assets::recommendation_label( (string) $action ) ),
					(int) $count
				);
			}
			echo '</ul>';
		}
		echo '</div>';

		// Recent Activity.
		echo '<div class="janitorix-card"><h3>' . esc_html__( 'Recent Activity', 'janitorix-media-audit' ) . '</h3>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: human-readable time difference */
					__( '%s ago', 'janitorix-media-audit' ),
					human_time_diff( strtotime( $scan->completed_at . ' UTC' ) )
				)
			)
		);
		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . Menu::SLUG . '-history' ) ),
			esc_html__( 'View scan history', 'janitorix-media-audit' )
		);
		echo '</div>';

		// System status — silent unless something is wrong.
		if ( ! empty( $data['scanners']['missing'] ) || ! empty( $data['scanners']['failed'] ) ) {
			echo '<div class="janitorix-card"><h3>' . esc_html__( 'System', 'janitorix-media-audit' ) . '</h3>';

			if ( ! empty( $data['scanners']['failed'] ) ) {
				echo '<p><span class="janitorix-pill janitorix-danger">' . esc_html__( 'Failed', 'janitorix-media-audit' ) . '</span> ' . esc_html( implode( ', ', $data['scanners']['failed'] ) ) . '</p>';
			}

			if ( ! empty( $data['scanners']['missing'] ) ) {
				echo '<p><span class="janitorix-pill janitorix-caution">' . esc_html__( 'Not built yet', 'janitorix-media-audit' ) . '</span> ' . esc_html( implode( ', ', $data['scanners']['missing'] ) ) . '</p>';
			}

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Coverage, and how loudly to say it is short.
	 *
	 * @param array<string,mixed> $data The dashboard report's built data.
	 */
	private function coverage_card( array $data ): void {
		echo '<div class="janitorix-card"><h3>' . esc_html__( 'Coverage', 'janitorix-media-audit' ) . '</h3>';
		printf( '<div class="janitorix-big">%d%%</div>', (int) $data['coverage'] );
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: completed scanners, 2: applicable scanners, 3: checks performed */
					__( '%1$d of %2$d modules completed · %3$d checks', 'janitorix-media-audit' ),
					$data['scanners']['completed'],
					$data['scanners']['total'],
					$data['checks']
				)
			)
		);

		printf(
			'<p class="janitorix-t">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: human-readable time difference */
					__( 'Last full scan %s ago.', 'janitorix-media-audit' ),
					human_time_diff( strtotime( $data['scan']->completed_at . ' UTC' ) )
				)
			)
		);

		if ( $data['coverage_floor'] ) {
			printf(
				'<p><span class="janitorix-pill janitorix-danger">%s</span></p>',
				esc_html(
					sprintf(
						/* translators: %d: the coverage floor percentage */
						__( 'Below the %d%% floor — no deletion is recommended at any confidence', 'janitorix-media-audit' ),
						ConfidenceEngine::COVERAGE_FLOOR
					)
				)
			);
		}

		echo '</div>';
	}

	/**
	 * What the library weighs, and what could be handed back.
	 *
	 * The saving quoted is what the plugin would actually offer to remove, not
	 * everything unused — an image held for review is not space the user can
	 * have, and promising it would be a promise the Safety Engine then refuses.
	 *
	 * @param array<string,mixed> $data The dashboard report's built data.
	 */
	private function storage_card( array $data ): void {
		$scan = $data['scan'];

		echo '<div class="janitorix-card"><h3>' . esc_html__( 'Storage', 'janitorix-media-audit' ) . '</h3>';
		printf( '<div class="janitorix-big">%d</div>', (int) $scan->images_total );
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: used count, 2: possibly used count, 3: unused count */
					__( '%1$d used · %2$d possibly used · %3$d unused', 'janitorix-media-audit' ),
					$scan->images_used,
					$scan->images_possibly_used,
					$scan->images_unused
				)
			)
		);

		// Never render a zeroed statistic. Where WordPress recorded no file
		// sizes there is nothing honest to say, so nothing is said.
		if ( $data['storage']['total_bytes'] > 0 ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: formatted size, e.g. "1.4 GB" */
						__( '%s in the library', 'janitorix-media-audit' ),
						size_format( $data['storage']['total_bytes'] )
					)
				)
			);
		}

		if ( $data['storage']['recoverable_bytes'] > 0 ) {
			printf(
				'<p><strong>%s</strong></p>',
				esc_html(
					sprintf(
						/* translators: %s: formatted size, e.g. "220 MB" */
						__( '%s recoverable', 'janitorix-media-audit' ),
						size_format( $data['storage']['recoverable_bytes'] )
					)
				)
			);
		}

		echo '</div>';
	}

	/**
	 * One number-and-label figure.
	 *
	 * @param string $label  What the number counts.
	 * @param int    $value  The number.
	 * @param string $suffix E.g. '%'.
	 */
	private function figure( string $label, int $value, string $suffix = '' ): void {
		printf(
			'<div class="janitorix-figure"><span class="janitorix-n">%d%s</span><span class="janitorix-t">%s</span></div>',
			absint( $value ),
			esc_html( $suffix ),
			esc_html( $label )
		);
	}

	/**
	 * The button that POSTs to admin_post_janitorix_start_scan.
	 *
	 * @param string $label The button's text.
	 */
	private function scan_button( string $label ): void {
		printf(
			'<form method="post" action="%s" style="display:inline">%s<input type="hidden" name="action" value="janitorix_start_scan"><button type="submit" class="button button-primary">%s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'janitorix_start_scan', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() already returns escaped HTML.
			esc_html( $label )
		);
	}
}
