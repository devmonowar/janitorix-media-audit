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

		if ( is_admin() ) {
			( new \JanitorixMediaAudit\Admin\Menu() )->register();
		}

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
}
