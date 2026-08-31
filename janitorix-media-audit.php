<?php
/**
 * Plugin Name:       Janitorix Media Audit
 * Plugin URI:        https://github.com/devmonowar/janitorix-media-audit
 * Description:       Finds unused images and removes them safely — by proving they are unused first.
 * Version:           1.0.3
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Monowar Hossain
 * Author URI:        https://devmonowar.github.io/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       janitorix-media-audit
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit;

defined( 'ABSPATH' ) || exit;

// The `Version:` header above is the single source of truth — read it
// dynamically rather than repeating the number here, so a release only ever
// changes one line.
define( 'JANITORIX_VERSION', get_file_data( __FILE__, array( 'Version' => 'Version' ) )['Version'] );
define( 'JANITORIX_FILE', __FILE__ );
define( 'JANITORIX_PATH', plugin_dir_path( __FILE__ ) );
define( 'JANITORIX_URL', plugin_dir_url( __FILE__ ) );

require_once JANITORIX_PATH . 'src/Core/Autoloader.php';

Core\Autoloader::register();
Core\Plugin::instance()->boot();
