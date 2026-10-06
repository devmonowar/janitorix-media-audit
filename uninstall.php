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
delete_option( 'janitorix_review' );
delete_option( JanitorixMediaAudit\AltText\Ai\AiSettings::option_name() );

// Anything the plugin wrote onto attachments rather than into its own tables:
// the user's decisions, which live there so they outlive a rebuild, and the
// cached file hashes. Dropping the tables does not reach either, so removing the
// plugin has to — two rows per image is not a footprint a cleanup plugin gets to
// leave behind. Each class names its own keys so this list cannot fall out of
// date the next time one is added.
$janitorix_meta_keys = array_merge(
	array( JanitorixMediaAudit\Core\UserDecisions::meta_key() ),
	JanitorixMediaAudit\Media\MediaFacts::meta_keys(),
	array(
		JanitorixMediaAudit\AltText\AltDecisions::meta_key(),
		JanitorixMediaAudit\AltText\AltUndo::meta_key(),
	)
);

foreach ( $janitorix_meta_keys as $janitorix_meta_key ) {
	delete_post_meta_by_key( $janitorix_meta_key );
}

// The AI's parked suggestions and cached answers live in transients, which
// have no registry to ask — both the value row and its timeout row go, by
// prefix. On an external object cache there is nothing to sweep (transients
// may never touch the options table), which is why those TTLs stay short: a
// leftover there evaporates on its own within days, not years.
global $wpdb;

$janitorix_transient_like = $wpdb->esc_like( '_transient_' . JanitorixMediaAudit\AltText\Ai\AiCache::PREFIX ) . '%';
$janitorix_timeout_like   = $wpdb->esc_like( '_transient_timeout_' . JanitorixMediaAudit\AltText\Ai\AiCache::PREFIX ) . '%';

$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
		$wpdb->options,
		$janitorix_transient_like,
		$janitorix_timeout_like
	)
);
