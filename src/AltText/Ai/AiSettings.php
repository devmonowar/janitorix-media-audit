<?php
/**
 * The AI settings: enablement, endpoint, model — and the key, kept apart.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\AltText\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Everything about the optional AI lives in ONE option that is NOT the main
 * settings option, for two reasons. First, the key must never travel with the
 * ordinary settings — no export, no diagnostic dump, no "settings saved"
 * round-trip should be able to carry it somewhere it does not belong. Second,
 * `get()` below never returns the key at all: screens that render settings
 * cannot leak what they were never given. The only way out is `get_key()`,
 * called by the two handlers that actually dial out.
 */
final class AiSettings {

	/** The option holding enablement, endpoint, model — and the key. */
	public const OPTION = 'janitorix_alt_ai';

	/**
	 * Defaults. Disabled, keyless, modeless: on a fresh install the AI
	 * might as well not exist, and the screen behaves exactly like 1.1.0.
	 *
	 * @var array<string,mixed>
	 */
	private const DEFAULTS = array(
		'enabled'  => false,
		'base_url' => 'https://api.openai.com/v1',
		'model'    => '',
		'key'      => '',
	);

	/**
	 * Every AI setting except the key, stored values merged over defaults.
	 *
	 * Render from this. The key is not here and cannot leak through here.
	 *
	 * @return array{enabled:bool,base_url:string,model:string}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'enabled'  => ! empty( $stored['enabled'] ),
			'base_url' => isset( $stored['base_url'] ) && is_string( $stored['base_url'] ) && '' !== trim( $stored['base_url'] )
				? trim( $stored['base_url'] )
				: self::DEFAULTS['base_url'],
			'model'    => isset( $stored['model'] ) && is_string( $stored['model'] ) ? trim( $stored['model'] ) : '',
		);
	}

	/**
	 * The saved key, for the two handlers that dial out. Nothing else calls this.
	 */
	public static function get_key(): string {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) && isset( $stored['key'] ) && is_string( $stored['key'] ) ? $stored['key'] : '';
	}

	/**
	 * Whether a key is saved, without saying what it is.
	 */
	public static function has_key(): bool {
		return '' !== self::get_key();
	}

	/**
	 * Whether the AI button may appear: enabled, keyed, named, pointed.
	 *
	 * Pure read of the option — and the default-off behaviour test pins it:
	 * fresh install, false, screen renders like 1.1.0.
	 */
	public static function is_available(): bool {
		$settings = self::get();

		return $settings['enabled'] && '' !== $settings['model'] && '' !== $settings['base_url'] && self::has_key();
	}

	/**
	 * What the settings screen may show about the key: set or not, plus the
	 * last four characters so the owner can tell keys apart. Never the key.
	 */
	public static function masked(): string {
		$key = self::get_key();

		if ( '' === $key ) {
			return '';
		}

		return '••••••••' . substr( $key, -4 );
	}

	/**
	 * Store what the settings form posted.
	 *
	 * A blank key field keeps the old key — browsers never see the saved one
	 * (the field renders empty), so "unchanged" arrives as "". A base URL
	 * that does not start with https:// is dropped in favour of the old one
	 * unless the site explicitly allows plain HTTP; silently keeping the old
	 * value is documented beside the field.
	 *
	 * @param array<string,mixed> $input Raw $_POST-shaped data.
	 */
	public static function save( array $input ): void {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$old_url = isset( $stored['base_url'] ) && is_string( $stored['base_url'] ) ? trim( $stored['base_url'] ) : self::DEFAULTS['base_url'];
		$new_url = isset( $input['janitorix_alt_ai_base_url'] ) && is_string( $input['janitorix_alt_ai_base_url'] ) ? trim( $input['janitorix_alt_ai_base_url'] ) : $old_url;

		if ( 0 !== stripos( $new_url, 'https://' ) && ! apply_filters( 'janitorix_alt_ai_allow_http', false ) ) {
			$new_url = $old_url;
		}

		if ( '' === $new_url ) {
			$new_url = self::DEFAULTS['base_url'];
		}

		$model = isset( $input['janitorix_alt_ai_model'] ) && is_string( $input['janitorix_alt_ai_model'] )
			? substr( trim( $input['janitorix_alt_ai_model'] ), 0, 100 )
			: '';

		$clean = array(
			'enabled'  => ! empty( $input['janitorix_alt_ai_enabled'] ),
			'base_url' => $new_url,
			'model'    => $model,
			'key'      => isset( $stored['key'] ) && is_string( $stored['key'] ) ? $stored['key'] : '',
		);

		// A blank key field means "unchanged" — the saved key never renders
		// into HTML, so the browser cannot send it back. The remove-key
		// checkbox is the only path that deletes it; a newly typed key wins
		// over the checkbox, so replacing and removing cannot mix.
		if ( ! empty( $input['janitorix_alt_ai_remove_key'] ) ) {
			$clean['key'] = '';
		}
		if ( isset( $input['janitorix_alt_ai_key'] ) && is_string( $input['janitorix_alt_ai_key'] ) && '' !== trim( $input['janitorix_alt_ai_key'] ) ) {
			$clean['key'] = substr( trim( $input['janitorix_alt_ai_key'] ), 0, 500 );
		}

		update_option( self::OPTION, $clean, false );
	}

	/**
	 * Build the adapter from saved settings, or null when unavailable.
	 *
	 * One construction site for both handlers — the test-connection button
	 * and the per-image suggest — so neither can assemble credentials
	 * differently from the other.
	 *
	 * @param callable|null $transport Test seam, passed through to the adapter.
	 */
	public static function adapter( ?callable $transport = null ): ?OpenAiCompatibleAdapter {
		if ( ! self::is_available() ) {
			return null;
		}

		$settings = self::get();

		return new OpenAiCompatibleAdapter( $settings['base_url'], self::get_key(), $settings['model'], $transport );
	}

	/**
	 * One-click presets: label, endpoint, a working default model, key link.
	 *
	 * Three fields stop most people — a preset reduces it to "pick yours,
	 * paste the key". Models retire (we watched two die for new keys in a
	 * week), so these are starting points, not promises: the fields stay
	 * editable, and a wrong model fails loudly with its own message rather
	 * than billing quietly for the wrong one.
	 *
	 * @return array<string,array{label:string,base_url:string,model:string,key_url:string,key_label:string}>
	 */
	public static function presets(): array {
		$presets = array(
			'gemini'     => array(
				'label'     => 'Gemini',
				'base_url'  => 'https://generativelanguage.googleapis.com/v1beta/openai',
				'model'     => 'gemini-3.5-flash-lite',
				'key_url'   => 'https://aistudio.google.com/apikey',
				'key_label' => 'Get a free Gemini key',
			),
			'openai'     => array(
				'label'     => 'OpenAI',
				'base_url'  => 'https://api.openai.com/v1',
				'model'     => 'gpt-4o-mini',
				'key_url'   => 'https://platform.openai.com/api-keys',
				'key_label' => 'Get an OpenAI key',
			),
			'groq'       => array(
				'label'     => 'Groq',
				'base_url'  => 'https://api.groq.com/openai/v1',
				'model'     => 'qwen/qwen3.8-27b',
				'key_url'   => 'https://console.groq.com/keys',
				'key_label' => 'Get a free Groq key',
			),
			'openrouter' => array(
				'label'     => 'OpenRouter',
				'base_url'  => 'https://openrouter.ai/api/v1',
				'model'     => 'openai/gpt-4o-mini',
				'key_url'   => 'https://openrouter.ai/keys',
				'key_label' => 'Get an OpenRouter key',
			),
		);

		/**
		 * Change the AI preset buttons.
		 *
		 * @param array<string,array{label:string,base_url:string,model:string,key_url:string,key_label:string}> $presets Keyed by preset id.
		 */
		return apply_filters( 'janitorix_alt_ai_presets', $presets );
	}

	/**
	 * The option name, for the uninstaller.
	 */
	public static function option_name(): string {
		return self::OPTION;
	}
}
