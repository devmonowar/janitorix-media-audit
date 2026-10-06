<?php
/**
 * Every suggestion source, in one place.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\AltText;

use JanitorixMediaAudit\AltText\Ai\AiSettings;
use JanitorixMediaAudit\AltText\Ai\AiSuggestionProvider;
use JanitorixMediaAudit\AltText\Contracts\SuggestionProvider;

defined( 'ABSPATH' ) || exit;

/**
 * The rule-based provider is always here; the AI one joins when the owner
 * enabled it and saved a key. Both the screen and the handlers ask here
 * rather than constructing providers themselves, so there is exactly one
 * place a third provider can join: the filter below.
 */
final class SuggestionProviders {

	/**
	 * Every available provider, keyed by id().
	 *
	 * @return array<string,SuggestionProvider>
	 */
	public static function all(): array {
		$providers = array(
			'rule-based' => new RuleBasedProvider(),
		);

		$adapter = AiSettings::adapter();

		if ( null !== $adapter ) {
			$settings = AiSettings::get();

			$providers['ai'] = new AiSuggestionProvider( $adapter, $settings['model'], get_locale() );
		}

		/**
		 * Add your own alt-text suggestion provider.
		 *
		 * @param array<string,SuggestionProvider> $providers Keyed by id().
		 */
		return apply_filters( 'janitorix_alt_suggestion_providers', $providers );
	}

	/**
	 * Translate a suggestion source key at render time — and in AJAX replies.
	 *
	 * One function for both, so the button's label and the reloaded row's
	 * label can never disagree about what answered.
	 *
	 * @param string $source One of 'filename', 'title', 'parent', 'ai:<model>', or a provider id.
	 */
	public static function source_label( string $source ): string {
		if ( 0 === strpos( $source, 'ai:' ) ) {
			$model = substr( $source, 3 );

			return sprintf(
				/* translators: %s: the AI model name */
				__( 'AI: %s', 'janitorix-media-audit' ),
				'' !== $model ? $model : __( 'model', 'janitorix-media-audit' )
			);
		}

		$labels = array(
			'filename' => __( 'filename', 'janitorix-media-audit' ),
			'title'    => __( 'title', 'janitorix-media-audit' ),
			'parent'   => __( 'parent post', 'janitorix-media-audit' ),
		);

		return $labels[ $source ] ?? $source;
	}
}
