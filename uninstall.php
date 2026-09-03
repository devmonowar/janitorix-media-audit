<?php
/**
 * Runs when the plugin is deleted from the admin.
 *
 * A cleanup plugin that leaves its own tables and options behind has not
 * practised what it preaches. Everything this plugin created is removed.
 *
 * Media is never touched here. Uninstalling the plugin removes the plugin's
 * bookkeeping — it does not undo, and must never trigger, any deletion of a
 * user's images.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The main plugin file never ran on uninstall, so the constant the autoloader
// depends on must be defined here.
defined( 'JANITORIX_PATH' ) || define( 'JANITORIX_PATH', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/src/Core/Autoloader.php';

JanitorixMediaAudit\Core\Autoloader::register();
JanitorixMediaAudit\Database\Tables::drop();

delete_option( 'janitorix_schema_version' );
delete_option( 'janitorix_settings' );

// Anything the plugin wrote onto attachments rather than into its own tables:
// the user's decisions, which live there so they outlive a rebuild, and the
// cached file hashes. Dropping the tables does not reach either, so removing the
// plugin has to — two rows per image is not a footprint a cleanup plugin gets to
// leave behind. Each class names its own keys so this list cannot fall out of
// date the next time one is added.
$janitorix_meta_keys = array_merge(
	array( JanitorixMediaAudit\Core\UserDecisions::meta_key() ),
	JanitorixMediaAudit\Media\MediaFacts::meta_keys()
);

foreach ( $janitorix_meta_keys as $janitorix_meta_key ) {
	delete_post_meta_by_key( $janitorix_meta_key );
}
