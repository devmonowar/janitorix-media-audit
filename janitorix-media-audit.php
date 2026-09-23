<?php
/**
 * Plugin Name:       Janitorix Media Audit
 * Plugin URI:        https://devmonowar.github.io/janitorix-media-audit/
 * Description:       Finds unused images and removes them safely — by proving they are unused first.
 * Version:           1.0.5
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Monowar Hossain
 * Author URI:        https://devmonowar.github.io/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       janitorix-media-audit
 * Domain Path:       /languages
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit;

defined( 'ABSPATH' ) || exit;

// Hardcoded (not get_file_data): reading the header costs fopen + fread +
// regex on every page load, frontend included. Keep in sync with the
// `Version:` header above + readme.txt Stable tag + changelog on release.
define( 'JANITORIX_VERSION', '1.0.5' );
define( 'JANITORIX_FILE', __FILE__ );
define( 'JANITORIX_PATH', plugin_dir_path( __FILE__ ) );
define( 'JANITORIX_URL', plugin_dir_url( __FILE__ ) );

require_once JANITORIX_PATH . 'src/Core/Autoloader.php';

Core\Autoloader::register();
Core\Plugin::instance()->boot();
