<?php
/**
 * Constants PHPStan needs that are defined at runtime by the plugin bootstrap.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

$janitorix_main_file = __DIR__ . '/../janitorix-media-audit.php';

// The plugin itself reads its version from this same header via
// get_file_data() — unavailable here, since PHPStan runs without WordPress
// loaded. Reading the header comment directly keeps this the only other
// place that names the version, and it stays correct without being told to.
preg_match( '/^\s*\*\s*Version:\s*(\S+)/mi', (string) file_get_contents( $janitorix_main_file, false, null, 0, 2000 ), $janitorix_version_match );

define( 'JANITORIX_VERSION', $janitorix_version_match[1] ?? '0.0.0' );
define( 'JANITORIX_FILE', $janitorix_main_file );
define( 'JANITORIX_PATH', __DIR__ . '/../' );
define( 'JANITORIX_URL', 'https://example.test/wp-content/plugins/janitorix-media-audit/' );
