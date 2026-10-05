<?php
/**
 * Registers the admin screens.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\Admin;

use JanitorixMediaAudit\Admin\Pages\AltTextPage;
use JanitorixMediaAudit\Admin\Pages\DashboardPage;
use JanitorixMediaAudit\Admin\Pages\HistoryPage;
use JanitorixMediaAudit\Admin\Pages\ImageDetailsPage;
use JanitorixMediaAudit\Admin\Pages\ImagesPage;
use JanitorixMediaAudit\Admin\Pages\SettingsPage;
use JanitorixMediaAudit\AltText\AltDecisions;
use JanitorixMediaAudit\AltText\AltUndo;
use JanitorixMediaAudit\AltText\RuleBasedProvider;
use JanitorixMediaAudit\Core\Plugin;
use JanitorixMediaAudit\Core\Settings;
use JanitorixMediaAudit\Core\UserDecisions;
use JanitorixMediaAudit\Reports\ScanExport;

defined( 'ABSPATH' ) || exit;

/**
 * Six screens, and the handlers that act on them.
 *
 * The destructive controls in this directory were built AFTER their gatekeeper,
 * never before — building a Trash button ahead of the Safety Engine is how a
 * codebase ends up with a delete path that never checked whether deletion was
 * allowed.
 *
 * Nothing here decides anything. Each handler verifies the nonce and the
 * capability, then hands the request to the Cleanup Engine, which refuses on
 * the Safety Engine's word. This class must never call a delete function
 * directly, and must never render a claim about what the plugin does that the
 * code below does not back up.
 */
final class Menu {

	private const CAPABILITY = 'manage_options';
	public const SLUG        = 'janitorix-media-audit';

	/** Wire up every admin_post handler and the menu itself. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_post_janitorix_start_scan', array( $this, 'handle_start_scan' ) );
		add_action( 'admin_post_janitorix_cleanup', array( $this, 'handle_cleanup' ) );
		add_action( 'admin_post_janitorix_bulk', array( $this, 'handle_bulk' ) );
		add_action( 'admin_post_janitorix_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_janitorix_decide', array( $this, 'handle_decide' ) );
		add_action( 'admin_post_janitorix_export_scan', array( $this, 'handle_export_scan' ) );
		add_action( 'admin_post_janitorix_forget_scan', array( $this, 'handle_forget_scan' ) );
		add_action( 'admin_post_janitorix_alt_row', array( $this, 'handle_alt_row' ) );
		add_action( 'admin_post_janitorix_alt_bulk', array( $this, 'handle_alt_bulk' ) );
		add_action( 'admin_post_janitorix_alt_custom', array( $this, 'handle_alt_custom' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( JANITORIX_FILE ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Add a "Settings" link to this plugin's row on the Plugins screen.
	 *
	 * @param string[] $links The row's existing action links.
	 * @return string[] The same links, with Settings first.
	 */
	public function add_settings_link( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ),
				esc_html__( 'Settings', 'janitorix-media-audit' )
			)
		);

		return $links;
	}

	/** Register the top-level menu and every submenu page. */
	public function add_pages(): void {
		add_menu_page(
			__( 'Janitorix Media Audit', 'janitorix-media-audit' ),
			__( 'Janitorix', 'janitorix-media-audit' ),
			self::CAPABILITY,
			self::SLUG,
			array( new DashboardPage(), 'render' ),
			'dashicons-images-alt2',
			81
		);

		// Relabels the first submenu item add_menu_page() already created —
		// same parent and menu slug, so WordPress reuses the top-level hook
		// rather than registering a second one. Passing a callback here (even
		// a fresh `new DashboardPage()`) would hook render() a second time,
		// since WordPress treats two distinct object instances as two
		// distinct callbacks and never de-duplicates them: the page would
		// render twice.
		add_submenu_page(
			self::SLUG,
			__( 'Dashboard', 'janitorix-media-audit' ),
			__( 'Dashboard', 'janitorix-media-audit' ),
			self::CAPABILITY,
			self::SLUG,
			''
		);

		add_submenu_page(
			self::SLUG,
			__( 'Images', 'janitorix-media-audit' ),
			__( 'Images', 'janitorix-media-audit' ),
			self::CAPABILITY,
			self::SLUG . '-images',
			array( new ImagesPage(), 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Scan History', 'janitorix-media-audit' ),
			__( 'Scan History', 'janitorix-media-audit' ),
			self::CAPABILITY,
			self::SLUG . '-history',
			array( new HistoryPage(), 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Alt Text', 'janitorix-media-audit' ),
			__( 'Alt Text', 'janitorix-media-audit' ),
			self::CAPABILITY,
			self::SLUG . '-alt',
			array( new AltTextPage(), 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'janitorix-media-audit' ),
			__( 'Settings', 'janitorix-media-audit' ),
			self::CAPABILITY,
			self::SLUG . '-settings',
			array( new SettingsPage(), 'render' )
		);

		// Reachable by link from the Images screen, not listed in the menu.
		add_submenu_page(
			'',
			__( 'Image Details', 'janitorix-media-audit' ),
			__( 'Image Details', 'janitorix-media-audit' ),
			self::CAPABILITY,
			self::SLUG . '-image',
			array( new ImageDetailsPage(), 'render' )
		);
	}

	/**
	 * The one action these screens can take, and it is not destructive: it
	 * reads the site and writes a report.
	 */
	public function handle_start_scan(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run a scan.', 'janitorix-media-audit' ) );
		}

		check_admin_referer( 'janitorix_start_scan' );

		Plugin::instance()->controller()->scan( true );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG,
					'janitorix_scanned' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Save the settings that govern something.
	 *
	 * The same nonce and capability check as every other handler here. Settings
	 * are not destructive, but the option they write is read by the scan path,
	 * and an unauthenticated write into it would change what the plugin
	 * concludes about somebody's library.
	 */
	public function handle_save_settings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'janitorix-media-audit' ) );
		}

		check_admin_referer( 'janitorix_save_settings' );

		// Settings::save() decides what is storable; everything else is dropped.
		Settings::save( wp_unslash( $_POST ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::SLUG . '-settings',
					'janitorix_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Record — or clear — what the user has decided about one image.
	 *
	 * Not routed through the Cleanup Engine, because nothing here is destructive:
	 * it writes a meta value and changes what future scans recommend. The
	 * capability and nonce checks are the same, since it changes what the plugin
	 * will and will not offer to delete.
	 */
	public function handle_decide(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change this.', 'janitorix-media-audit' ) );
		}

		check_admin_referer( 'janitorix_decide' );

		$attachment_id = isset( $_POST['image'] ) ? absint( wp_unslash( $_POST['image'] ) ) : 0;
		$decision      = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';

		if ( $attachment_id > 0 ) {
			UserDecisions::set( $attachment_id, $decision );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG . '-image',
					'id'                => $attachment_id,
					'janitorix_decided' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Apply one decision to a set of images, then return to the list.
	 *
	 * `UserDecisions::set()` treats anything it does not recognise as "clear",
	 * which is what makes the Clear button work without a special case here.
	 *
	 * @param int[]  $ids      The images to apply this decision to.
	 * @param string $decision One of UserDecisions' constants, or '' to clear.
	 */
	private function apply_decision( array $ids, string $decision ): void {
		foreach ( array_unique( array_filter( $ids ) ) as $attachment_id ) {
			UserDecisions::set( (int) $attachment_id, $decision );
		}

		$this->redirect_to_images(
			sprintf(
				/* translators: %d: number of images updated */
				_n( 'Decision recorded for %d image.', 'Decision recorded for %d images.', count( $ids ), 'janitorix-media-audit' ),
				count( $ids )
			),
			true
		);
	}

	/**
	 * Send one archived scan as CSV or JSON.
	 *
	 * A report you cannot take away from the screen is a report you cannot show
	 * anybody. This reads; it changes nothing.
	 */
	public function handle_export_scan(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export a scan.', 'janitorix-media-audit' ) );
		}

		check_admin_referer( 'janitorix_export_scan' );

		$scan_id = isset( $_GET['scan'] ) ? absint( wp_unslash( $_GET['scan'] ) ) : 0;
		$format  = isset( $_GET['format'] ) && 'json' === $_GET['format'] ? 'json' : 'csv';

		$repo = Plugin::instance()->controller()->repository();

		if ( $scan_id < 1 || null === $repo->find( $scan_id ) ) {
			wp_die( esc_html__( 'That scan is no longer stored.', 'janitorix-media-audit' ) );
		}

		( new ScanExport( $repo ) )->send( $scan_id, $format );
	}

	/**
	 * Delete one scan record.
	 *
	 * The specification is emphatic that this must never delete a media file and
	 * that it "must be impossible to get wrong". The handler holds up its end by
	 * having nowhere else to go: it calls exactly one repository method, and that
	 * method touches four plugin tables and knows no WordPress deletion function.
	 */
	public function handle_forget_scan(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to delete a scan record.', 'janitorix-media-audit' ) );
		}

		check_admin_referer( 'janitorix_forget_scan' );

		$scan_id = isset( $_POST['scan'] ) ? absint( wp_unslash( $_POST['scan'] ) ) : 0;

		if ( $scan_id > 0 ) {
			Plugin::instance()->controller()->repository()->forget( $scan_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::SLUG . '-history',
					'janitorix_forgot' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * The destructive path, and the only one.
	 *
	 * It does no deciding of its own: capability, nonce, then straight to the
	 * Cleanup Engine, which refuses unless the Safety Engine agrees. Every
	 * refusal is shown to the user with its reason.
	 */
	public function handle_cleanup(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'janitorix-media-audit' ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		$do            = sanitize_key( (string) ( $_POST['do'] ?? '' ) );

		check_admin_referer( 'janitorix_cleanup_' . $attachment_id );

		$cleanup = new \JanitorixMediaAudit\Cleanup\CleanupEngine();

		switch ( $do ) {
			case 'trash':
				$result = $cleanup->trash( $attachment_id );
				break;
			case 'restore':
				$result = $cleanup->restore( $attachment_id );
				break;
			case 'delete':
				// The confirmation is a separate, explicit acknowledgement —
				// the gate says "permitted", this says "the human meant it".
				$result = $cleanup->delete_permanently( $attachment_id, ! empty( $_POST['confirmed'] ) );
				break;
			default:
				$result = array(
					'ok'      => false,
					'message' => __( 'Unknown action.', 'janitorix-media-audit' ),
				);
		}

		$destination = 'delete' === $do && $result['ok']
			? admin_url( 'admin.php?page=' . self::SLUG . '-images' )
			: admin_url( 'admin.php?page=' . self::SLUG . '-image&id=' . $attachment_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'janitorix_result'  => $result['ok'] ? 'ok' : 'refused',
					'janitorix_message' => rawurlencode( $result['message'] ),
				),
				$destination
			)
		);
		exit;
	}

	/**
	 * Bulk trash.
	 *
	 * The only bulk action offered, and deliberately the reversible one. There
	 * is no bulk permanent deletion: destroying two hundred files on one click
	 * is not a feature, it is an accident waiting for a mis-click.
	 */
	public function handle_bulk(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'janitorix-media-audit' ) );
		}

		check_admin_referer( 'janitorix_bulk' );

		$ids = array_map( 'intval', (array) ( $_POST['images'] ?? array() ) );

		if ( empty( $ids ) ) {
			$this->redirect_to_images( __( 'No images were selected.', 'janitorix-media-audit' ), false );
		}

		// Two intentions share this form because they act on the same ticked
		// boxes. They are told apart by which button was pressed, and they part
		// company immediately: recording a decision is not destructive and never
		// reaches the Cleanup Engine.
		if ( isset( $_POST['decision'] ) ) {
			$this->apply_decision( $ids, sanitize_key( wp_unslash( $_POST['decision'] ) ) );
		}

		$result = ( new \JanitorixMediaAudit\Cleanup\CleanupEngine() )->trash_many( $ids );

		$message = sprintf(
			/* translators: %d: number of images moved to trash */
			_n( '%d image moved to Trash.', '%d images moved to Trash.', $result['trashed'], 'janitorix-media-audit' ),
			$result['trashed']
		);

		// A refusal among a batch is an expected outcome, not an error — but it
		// must be reported, or the user will believe the whole selection went.
		if ( $result['refused'] > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of images held back */
				_n(
					'%d was held back — open it to see why.',
					'%d were held back — open them to see why.',
					$result['refused'],
					'janitorix-media-audit'
				),
				$result['refused']
			);
		}

		$this->redirect_to_images( $message, $result['trashed'] > 0 );
	}

	/**
	 * One alt-text row action: apply a suggestion, mark decorative, or undo.
	 *
	 * A GET link carrying a nonce — core's own row-action shape (the same as
	 * Activate on the Plugins screen) — verified here before anything is
	 * written. The link carries only the image id, never the words: the
	 * suggestion is recomputed below, so a crafted URL cannot plant arbitrary
	 * alt text.
	 */
	public function handle_alt_row(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'janitorix-media-audit' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified on the next line; reading before verifying would be the violation, not reading itself.
		$do            = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$attachment_id = isset( $_GET['image'] ) ? absint( wp_unslash( $_GET['image'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		check_admin_referer( 'janitorix_alt_row' );

		if ( $attachment_id < 1 || null === get_post( $attachment_id ) ) {
			$this->redirect_to_alt( __( 'That image no longer exists.', 'janitorix-media-audit' ), false );
		}

		switch ( $do ) {
			case 'apply':
				$this->alt_apply( $attachment_id );
				break;
			case 'decorative':
				AltDecisions::set( $attachment_id, true );
				$this->redirect_to_alt( __( 'Marked as decorative.', 'janitorix-media-audit' ), true );
				break;
			case 'undecorate':
				AltDecisions::set( $attachment_id, false );
				$this->redirect_to_alt( __( 'No longer marked as decorative.', 'janitorix-media-audit' ), true );
				break;
			case 'undo':
				$this->alt_undo( $attachment_id );
				break;
			default:
				$this->redirect_to_alt( __( 'Unknown action.', 'janitorix-media-audit' ), false );
		}
	}

	/**
	 * Bulk alt-text work on the ticked rows.
	 *
	 * The selection can never exceed one page, which is what keeps this from
	 * becoming a timeout: twenty-five small writes, each decided
	 * individually, not one giant one.
	 */
	public function handle_alt_bulk(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'janitorix-media-audit' ) );
		}

		check_admin_referer( 'janitorix_alt_bulk' );

		$ids = array_map( 'intval', (array) ( $_POST['images'] ?? array() ) );
		$ids = array_values( array_filter( array_unique( $ids ) ) );

		if ( empty( $ids ) ) {
			$this->redirect_to_alt( __( 'No images were selected.', 'janitorix-media-audit' ), false );
		}

		$action = isset( $_POST['janitorix_alt_bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['janitorix_alt_bulk_action'] ) ) : '';

		$applied = 0;
		$skipped = 0;

		foreach ( $ids as $attachment_id ) {
			if ( null === get_post( $attachment_id ) ) {
				++$skipped;
				continue;
			}

			if ( 'decorative' === $action ) {
				AltDecisions::set( $attachment_id, true );
				++$applied;
				continue;
			}

			if ( 'clear' === $action ) {
				AltDecisions::set( $attachment_id, false );
				++$applied;
				continue;
			}

			if ( 'apply' === $action && $this->alt_apply_quiet( $attachment_id ) ) {
				++$applied;
				continue;
			}

			++$skipped;
		}

		// An unrecognised action is refused the same way as an empty
		// selection: nothing was decided, so nothing is reported as done.
		if ( ! in_array( $action, array( 'apply', 'decorative', 'clear' ), true ) ) {
			$this->redirect_to_alt( __( 'Choose a bulk action first.', 'janitorix-media-audit' ), false );
		}

		$message = sprintf(
			/* translators: %d: number of images updated */
			_n( '%d image updated.', '%d images updated.', $applied, 'janitorix-media-audit' ),
			$applied
		);

		// A skipped image is an expected outcome — no suggestion worth
		// applying — not an error. But it must be reported, or the user will
		// believe the whole selection went.
		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of images skipped */
				_n( '%d had no suggestion and was skipped.', '%d had no suggestion and were skipped.', $skipped, 'janitorix-media-audit' ),
				$skipped
			);
		}

		$this->redirect_to_alt( $message, $applied > 0 );
	}

	/**
	 * Apply the computed suggestion to one image, with undo kept.
	 *
	 * @param int $attachment_id The image to update.
	 */
	private function alt_apply( int $attachment_id ): void {
		if ( ! $this->alt_apply_quiet( $attachment_id ) ) {
			$this->redirect_to_alt( __( 'No suggestion worth applying for that image.', 'janitorix-media-audit' ), false );
		}

		$this->redirect_to_alt( __( 'Suggestion applied. Undo is available if it reads wrong.', 'janitorix-media-audit' ), true );
	}

	/**
	 * Apply without redirecting — the bulk loop's version.
	 *
	 * @param int $attachment_id The image to update.
	 * @return bool Whether a suggestion was applied.
	 */
	private function alt_apply_quiet( int $attachment_id ): bool {
		$suggestion = $this->suggest_for( $attachment_id );

		if ( null === $suggestion ) {
			return false;
		}

		$current = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		AltUndo::save( $attachment_id, is_string( $current ) ? $current : '' );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $suggestion['text'] );

		return true;
	}

	/**
	 * Restore the remembered alt text.
	 *
	 * @param int $attachment_id The image to restore.
	 */
	private function alt_undo( int $attachment_id ): void {
		$backup = AltUndo::peek( $attachment_id );

		if ( null === $backup ) {
			$this->redirect_to_alt( __( 'Nothing to undo for that image.', 'janitorix-media-audit' ), false );
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $backup['text'] );
		AltUndo::clear( $attachment_id );

		$this->redirect_to_alt( __( 'Undone — the previous alt text is back.', 'janitorix-media-audit' ), true );
	}

	/**
	 * Suggest for one image: filename, title, parent title.
	 *
	 * The same triple the list builds at render (see AltTextPage) — rebuilt
	 * here so the handler never trusts words that arrived in the request.
	 *
	 * @param int $attachment_id The image to suggest for.
	 * @return array{text:string,source:string}|null
	 */
	private function suggest_for( int $attachment_id ): ?array {
		$post = get_post( $attachment_id );

		if ( null === $post ) {
			return null;
		}

		$file         = get_attached_file( $attachment_id );
		$parent_title = 0 !== (int) $post->post_parent ? get_the_title( (int) $post->post_parent ) : '';

		return ( new RuleBasedProvider() )->suggest(
			array(
				'filename'     => is_string( $file ) ? wp_basename( $file ) : '',
				'title'        => (string) $post->post_title,
				'parent_title' => is_string( $parent_title ) ? $parent_title : '',
			)
		);
	}

	/**
	 * Save hand-written alt text from a row's suggestion box.
	 *
	 * The box arrives prefilled with the suggestion, but what is saved is
	 * whatever the person left in it — their own words included. Same write
	 * guarantees as an apply: the previous value is kept for undo, length is
	 * capped like a suggestion, and a weak custom text is still saved (the
	 * list will flag it Weak) rather than refused — refusing a person's own
	 * words would be paternalism, not safety.
	 */
	public function handle_alt_custom(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'janitorix-media-audit' ) );
		}

		check_admin_referer( 'janitorix_alt_custom' );

		$attachment_id = isset( $_POST['image'] ) ? absint( wp_unslash( $_POST['image'] ) ) : 0;
		$text          = isset( $_POST['janitorix_alt_text'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['janitorix_alt_text'] ) ) ) : '';

		if ( $attachment_id < 1 || null === get_post( $attachment_id ) ) {
			$this->redirect_to_alt( __( 'That image no longer exists.', 'janitorix-media-audit' ), false );
		}

		if ( '' === $text ) {
			$this->redirect_to_alt( __( 'Write some alt text first — or mark the image decorative instead.', 'janitorix-media-audit' ), false );
		}

		$current = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		AltUndo::save( $attachment_id, is_string( $current ) ? $current : '' );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', RuleBasedProvider::cap_text( $text ) );

		$this->redirect_to_alt( __( 'Alt text saved. Undo is available if it reads wrong.', 'janitorix-media-audit' ), true );
	}

	/**
	 * Return to the Alt Text screen with a one-time result notice.
	 *
	 * @param string $message The notice text, shown once via the query arg.
	 * @param bool   $ok       Whether the action succeeded.
	 */
	private function redirect_to_alt( string $message, bool $ok ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG . '-alt',
					'janitorix_result'  => $ok ? 'ok' : 'refused',
					'janitorix_message' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Return to the Images screen with a one-time result notice.
	 *
	 * @param string $message The notice text, shown once via the query arg.
	 * @param bool   $ok       Whether the action succeeded.
	 */
	private function redirect_to_images( string $message, bool $ok ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG . '-images',
					'janitorix_result'  => $ok ? 'ok' : 'refused',
					'janitorix_message' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Load the plugin's own admin styles and script, only on its own screens.
	 *
	 * Both are real files under `assets/`, enqueued by URL. The code itself is
	 * static and cacheable; only the handful of values that vary — translated
	 * strings and the confirm-actions setting — are printed ahead of the script,
	 * as JSON, on a single namespaced object.
	 *
	 * @param string $hook The current admin page's hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'janitorix-admin',
			JANITORIX_URL . 'assets/css/admin.css',
			array(),
			JANITORIX_VERSION
		);

		wp_enqueue_script(
			'janitorix-admin',
			JANITORIX_URL . 'assets/js/admin.js',
			array(),
			JANITORIX_VERSION,
			true
		);

		wp_add_inline_script(
			'janitorix-admin',
			'window.janitorixMediaAudit = ' . wp_json_encode(
				array(
					'selectNothing'  => __( 'Select at least one image first.', 'janitorix-media-audit' ),
					'confirmTrash'   => __( 'image(s) will be moved to Trash. They can be restored afterwards. Continue?', 'janitorix-media-audit' ),
					'confirmActions' => Settings::is_on( 'confirm_actions' ),
				)
			) . ';',
			'before'
		);
	}
}
