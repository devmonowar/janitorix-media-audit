<?php
/**
 * Wires the pipeline together.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\Core;

use JanitorixMediaAudit\Database\Tables;
use JanitorixMediaAudit\Scanner\ACFScanner;
use JanitorixMediaAudit\Scanner\ContentScanner;
use JanitorixMediaAudit\Scanner\CustomizerScanner;
use JanitorixMediaAudit\Scanner\ElementorScanner;
use JanitorixMediaAudit\Scanner\GenericFallbackScanner;
use JanitorixMediaAudit\Scanner\GutenbergScanner;
use JanitorixMediaAudit\Scanner\MediaRelationshipScanner;
use JanitorixMediaAudit\Scanner\MenuScanner;
use JanitorixMediaAudit\Scanner\ScannerRegistry;
use JanitorixMediaAudit\Scanner\TemplateScanner;
use JanitorixMediaAudit\Scanner\ThemeOptionsScanner;
use JanitorixMediaAudit\Scanner\WidgetScanner;
use JanitorixMediaAudit\Scanner\WooCommerceScanner;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's single entry point: builds the registry, registers every
 * scanner, and wires up WordPress's own hooks.
 */
final class Plugin {

	/**
	 * The one instance, WordPress-plugin-singleton style.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Every registered scanner.
	 *
	 * @var ScannerRegistry
	 */
	private ScannerRegistry $registry;

	/** The plugin's single instance, created on first use. */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Private — use instance(). */
	private function __construct() {
		$this->registry = new ScannerRegistry();
	}

	/** Register every scanner and wire up WordPress's own hooks. */
	public function boot(): void {
		// Scanners are independent of one another — none reads another's output
		// — which is why they can be built and registered in any order.
		$this->registry->add( new ContentScanner() );
		$this->registry->add( new MediaRelationshipScanner() );
		$this->registry->add( new GutenbergScanner() );
		$this->registry->add( new ThemeOptionsScanner() );
		$this->registry->add( new CustomizerScanner() );
		$this->registry->add( new WidgetScanner() );
		$this->registry->add( new MenuScanner() );
		$this->registry->add( new TemplateScanner() );
		$this->registry->add( new ElementorScanner() );
		$this->registry->add( new ACFScanner() );
		$this->registry->add( new WooCommerceScanner() );
		$this->registry->add( new GenericFallbackScanner() );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- already carries the plugin's real prefix, "janitorix_"; the sniff expects the full "janitorix_media_audit_" form.
		do_action( 'janitorix_register_scanners', $this->registry );

		register_activation_hook( JANITORIX_FILE, array( Tables::class, 'install' ) );

		// Deactivating pauses the plugin; it must not leave a scan tick firing
		// into a handler that no longer exists. Uninstall does the same, for the
		// same reason — this just covers the more common case of a plain
		// deactivate, without waiting for a full removal.
		register_deactivation_hook(
			JANITORIX_FILE,
			static function (): void {
				wp_clear_scheduled_hook( 'janitorix_scan_tick' );
			}
		);

		if ( is_admin() ) {
			( new \JanitorixMediaAudit\Admin\Menu() )->register();
		}

		// A background scan advances one batch per cron tick, so a large library
		// finishes over several ticks without any single request timing out.
		add_action( 'janitorix_scan_tick', array( $this, 'run_scan_tick' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// Registers both `wp janitorix scan` and `wp janitorix explain <id>`.
			\WP_CLI::add_command( 'janitorix', \JanitorixMediaAudit\API\CLI\ScanCommand::class );
		}
	}

	/** Every registered scanner. */
	public function registry(): ScannerRegistry {
		return $this->registry;
	}

	/** A fresh controller, wired to this plugin's registry. */
	public function controller(): ScanController {
		return new ScanController( $this->registry );
	}

	/**
	 * One cron batch of an in-progress scan; reschedules itself until done.
	 *
	 * @param int $scan_id The already-open scan to advance.
	 */
	public function run_scan_tick( int $scan_id ): void {
		$result = $this->controller()->tick( $scan_id );

		// With background processing off, a tick that has already been scheduled
		// still finishes its batch — stopping mid-scan would leave the row open
		// forever — but it does not queue another.
		if ( ! $result['done'] && Settings::is_on( 'background_scan' ) ) {
			wp_schedule_single_event( time() + 5, 'janitorix_scan_tick', array( $scan_id ) );
		}
	}
}
