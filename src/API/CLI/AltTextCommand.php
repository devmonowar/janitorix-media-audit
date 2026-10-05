<?php
/**
 * Alt-text coverage from the command line. Reads only.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\API\CLI;

use JanitorixMediaAudit\AltText\AltStats;

defined( 'ABSPATH' ) || exit;

/**
 * Reports alt-text coverage and nothing else.
 *
 * This command writes nothing — no scan, no suggestion, no setting. It reads
 * the same summary the admin screen and the Dashboard card render, so the
 * number in a CI report is the number on the screen. The JSON keys below are
 * a public contract: scripts parse them, so a key is never renamed, only
 * added to.
 */
final class AltTextCommand {

	/**
	 * Show alt-text coverage for the media library.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : How to print the summary: 'table' (default) or 'json'. The JSON
	 * object carries total, good, weak, missing, decorative and coverage.
	 *
	 * ## EXAMPLES
	 *
	 *     wp janitorix alt stats
	 *     wp janitorix alt stats --format=json
	 *
	 * @param string[]             $args  Positional arguments (unused).
	 * @param array<string,string> $assoc The parsed --flags.
	 *
	 * @when after_wp_load
	 */
	public function stats( array $args, array $assoc ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI always calls a command callback with (array $args, array $assoc), whether or not the command uses either.
		$summary = ( new AltStats() )->summary();
		$format  = $assoc['format'] ?? 'table';

		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $summary ) );

			return;
		}

		if ( 'table' !== $format ) {
			\WP_CLI::error( 'Use --format=table or --format=json.' );
		}

		\WP_CLI::log( sprintf( 'Alt-text coverage — %d%% of %d images', $summary['coverage'], $summary['total'] ) );
		\WP_CLI::log( str_repeat( '─', 64 ) );
		\WP_CLI::log( sprintf( '  %-12s %d', 'Good', $summary['good'] ) );
		\WP_CLI::log( sprintf( '  %-12s %d', 'Weak', $summary['weak'] ) );
		\WP_CLI::log( sprintf( '  %-12s %d', 'Missing', $summary['missing'] ) );
		\WP_CLI::log( sprintf( '  %-12s %d', 'Decorative', $summary['decorative'] ) );
	}
}
