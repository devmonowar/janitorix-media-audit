<?php
/**
 * The page most users never open.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\Admin\Pages;

use JanitorixMediaAudit\Core\Settings;
use JanitorixMediaAudit\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin works correctly on defaults, so this screen exists to explain them
 * rather than to demand decisions.
 *
 * Most of the settings the spec lists — trash retention, permanent-delete
 * behaviour, confirmation dialogs — are not yet adjustable. What this screen
 * must never do is misdescribe what the plugin can actually do: a screen that
 * tells the user deletion is unavailable, on a build that deletes, is worse
 * than no screen at all. Every row below states the behaviour that is actually
 * compiled in.
 */
final class SettingsPage {

	/** Render the whole Settings screen. */
	public function render(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Settings', 'janitorix-media-audit' ) . '</h1>';

		echo '<p>' . esc_html__( 'The plugin is designed to work correctly without changing anything here. Most people should scan and clean without opening this page at all.', 'janitorix-media-audit' ) . '</p>';

		$this->result_notice();
		$this->form();

		echo '<h2>' . esc_html__( 'Behaviour you cannot change', 'janitorix-media-audit' ) . '</h2>';
		echo '<p>' . esc_html__( 'These are stated rather than offered. Some the safety model rests on; others govern a capability that is not built yet. A switch that does nothing would be worse than either.', 'janitorix-media-audit' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:760px">';
		echo '<tbody>';

		$this->row(
			__( 'Scan mode', 'janitorix-media-audit' ),
			__( 'Full — every applicable scanner runs.', 'janitorix-media-audit' )
		);
		$this->row(
			__( 'Deletion', 'janitorix-media-audit' ),
			__( 'Move to Trash only, and always reversible. Permanent deletion is offered afterwards, from the Trash, and never as a single step. Every removal is checked by the Safety Engine first — including bulk actions, which are checked one image at a time.', 'janitorix-media-audit' )
		);
		$this->row(
			__( 'Confidence gate', 'janitorix-media-audit' ),
			__( 'Below 80% confidence, removal is not recommended — the image is held for review instead.', 'janitorix-media-audit' )
		);
		$this->row(
			__( 'Coverage floor', 'janitorix-media-audit' ),
			__( 'Below 70% coverage, no deletion is recommended at any confidence.', 'janitorix-media-audit' )
		);
		$this->row(
			__( 'Risk ceiling', 'janitorix-media-audit' ),
			__( 'At Medium risk or above, the strongest recommendation offered is Review — regardless of confidence.', 'janitorix-media-audit' )
		);
		$this->row(
			__( 'On uninstall', 'janitorix-media-audit' ),
			__( 'Every table and option this plugin created is removed. Your media is never touched.', 'janitorix-media-audit' )
		);

		echo '</tbody></table>';

		if ( Settings::is_on( 'debug_mode' ) ) {
			$this->diagnostics();
		}

		echo '</div>';
	}

	/**
	 * The settings that actually govern something.
	 *
	 * Basic first, Advanced collapsed, as the specification lays out. What
	 * decides which tier a setting belongs to is the specification's own line —
	 * "nothing in Advanced is required for a correct, safe result".
	 */
	private function form(): void {
		$settings = Settings::all();

		printf(
			'<form method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( 'janitorix_save_settings' );
		echo '<input type="hidden" name="action" value="janitorix_save_settings">';

		echo '<h2>' . esc_html__( 'Basic', 'janitorix-media-audit' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->toggle(
			'cache_results',
			__( 'Cache scan results', 'janitorix-media-audit' ),
			__( 'Reuse a completed scan until the site changes. Turning this off rescans on every request, which is slow and almost never necessary — the cache already invalidates itself when an image, plugin, theme or page changes.', 'janitorix-media-audit' ),
			$settings['cache_results']
		);

		$this->toggle(
			'confirm_actions',
			__( 'Confirm before trashing', 'janitorix-media-audit' ),
			__( 'Ask before moving images to Trash, including in bulk. The Safety Engine still checks every image either way — this only removes the extra click.', 'janitorix-media-audit' ),
			$settings['confirm_actions']
		);

		echo '</tbody></table>';

		echo '<details><summary>' . esc_html__( 'Show advanced settings', 'janitorix-media-audit' ) . '</summary>';
		echo '<p class="janitorix-t">' . esc_html__( 'Nothing here is required for a correct, safe result.', 'janitorix-media-audit' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->number(
			'history_retention',
			__( 'Scan records to keep', 'janitorix-media-audit' ),
			__( '0 means keep forever, which is the default. A scan record is the only answer to "what did the plugin tell me last month", so nothing is discarded unless you ask for it.', 'janitorix-media-audit' ),
			(int) $settings['history_retention']
		);

		$this->toggle(
			'debug_mode',
			__( 'Debug mode', 'janitorix-media-audit' ),
			__( 'Show the diagnostics below. Useful when reporting a problem.', 'janitorix-media-audit' ),
			$settings['debug_mode']
		);

		echo '</tbody></table></details>';

		submit_button( __( 'Save settings', 'janitorix-media-audit' ) );
		echo '</form>';
	}

	/**
	 * One checkbox row.
	 *
	 * @param string $name  The field name.
	 * @param string $label The row's label.
	 * @param string $help  Description text under the control.
	 * @param bool   $on    Its current value.
	 */
	private function toggle( string $name, string $label, string $help, bool $on ): void {
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s" value="1"%s aria-label="%s"> %s</label><p class="description">%s</p></td></tr>',
			esc_html( $label ),
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_attr( $label ),
			esc_html__( 'Enabled', 'janitorix-media-audit' ),
			esc_html( $help )
		);
	}

	/**
	 * One numeric-input row.
	 *
	 * @param string $name  The field name.
	 * @param string $label The row's label.
	 * @param string $help  Description text under the control.
	 * @param int    $value Its current value.
	 */
	private function number( string $name, string $label, string $help, int $value ): void {
		printf(
			'<tr><th scope="row"><label for="janitorix-%1$s">%2$s</label></th><td><input type="number" min="0" id="janitorix-%1$s" name="%1$s" value="%3$d" class="small-text"><p class="description">%4$s</p></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			absint( $value ),
			esc_html( $help )
		);
	}

	/** The one-time "Settings saved" banner after a redirect. */
	private function result_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( empty( $_GET['janitorix_saved'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Settings saved.', 'janitorix-media-audit' )
		);
	}

	/** Environment facts that matter when something goes wrong. */
	private function diagnostics(): void {
		global $wpdb;

		echo '<h2>' . esc_html__( 'Diagnostics', 'janitorix-media-audit' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';

		$this->row( __( 'Plugin version', 'janitorix-media-audit' ), JANITORIX_VERSION );
		$this->row( __( 'WordPress', 'janitorix-media-audit' ), get_bloginfo( 'version' ) );
		$this->row( __( 'PHP', 'janitorix-media-audit' ), PHP_VERSION );

		// esc_like(), because a table name contains underscores and an
		// underscore is a single-character wildcard in LIKE. Unescaped,
		// "wp_janitorix_scans" also matches "wpXjanitorixXscans" — so this
		// health check could report a healthy database on the strength of a
		// table belonging to something else entirely.
		$tables_ok = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Tables::scans() ) ) );

		$this->row(
			__( 'Database', 'janitorix-media-audit' ),
			$tables_ok
				? __( 'Tables present.', 'janitorix-media-audit' )
				: __( 'Tables missing — deactivate and reactivate the plugin.', 'janitorix-media-audit' )
		);

		$this->row(
			__( 'Media trash', 'janitorix-media-audit' ),
			defined( 'MEDIA_TRASH' ) && MEDIA_TRASH
				? __( 'Enabled.', 'janitorix-media-audit' )
				: __( 'Not enabled by your site. This plugin trashes images directly, so removals stay reversible either way, and it writes a restore record before touching anything. Recovery does not depend on this setting.', 'janitorix-media-audit' )
		);

		echo '</tbody></table>';
	}

	/**
	 * One label/value diagnostics row.
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
