<?php
/**
 * The AI provider's judgement, pinned without a key or a network.
 *
 * Run:  php tests/alt-ai-provider.php     (or: composer test)
 *
 * What is asserted here is the provider's character, not its connections:
 * the prompt carries the constraints, the reply is distrusted on arrival,
 * the cache answers twice-asked questions, and every failure names itself
 * through last_error() instead of dying quietly. The transport is the same
 * fake the adapter tests use; transients are an in-memory array in this file.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( "This script is for the command line only.\n" );
}

require_once __DIR__ . '/bootstrap.php';

require_once dirname( __DIR__ ) . '/src/AltText/Contracts/SuggestionProvider.php';
require_once dirname( __DIR__ ) . '/src/AltText/RuleBasedProvider.php';
require_once dirname( __DIR__ ) . '/src/AltText/Ai/OpenAiCompatibleAdapter.php';
require_once dirname( __DIR__ ) . '/src/AltText/Ai/AiCache.php';
require_once dirname( __DIR__ ) . '/src/AltText/Ai/AiSuggestionProvider.php';

use JanitorixMediaAudit\AltText\Ai\AiCache;
use JanitorixMediaAudit\AltText\Ai\AiSuggestionProvider;
use JanitorixMediaAudit\AltText\Ai\OpenAiCompatibleAdapter;

// ------------------------------------------------- test-only transients ---

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * @var array<string,mixed>
	 */
	$GLOBALS['janitorix_test_transients'] = array();

	/**
	 * @param string $key
	 * @return mixed
	 */
	function get_transient( string $key ) { // phpcs:ignore
		return $GLOBALS['janitorix_test_transients'][ $key ] ?? false;
	}

	/**
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $ttl
	 */
	function set_transient( string $key, $value, int $ttl = 0 ): bool { // phpcs:ignore
		$GLOBALS['janitorix_test_transients'][ $key ] = $value;

		return true;
	}

	/**
	 * @param string $key
	 */
	function delete_transient( string $key ): bool { // phpcs:ignore
		unset( $GLOBALS['janitorix_test_transients'][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data
	 */
	function wp_json_encode( $data ): string { // phpcs:ignore
		$json = json_encode( $data );

		return is_string( $json ) ? $json : '{}';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing
	 */
	function is_wp_error( $thing ): bool { // phpcs:ignore
		return false;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * @param mixed $response
	 */
	function wp_remote_retrieve_response_code( $response ): int { // phpcs:ignore
		return is_array( $response ) ? (int) ( $response['code'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * @param mixed $response
	 */
	function wp_remote_retrieve_body( $response ): string { // phpcs:ignore
		return is_array( $response ) && isset( $response['body'] ) ? (string) $response['body'] : '';
	}
}

$passed = 0;
$failed = array();

/**
 * @param string $name   What is being guaranteed.
 * @param bool   $ok     Whether it holds.
 * @param string $detail Shown only on failure.
 */
function check( string $name, bool $ok, string $detail = '' ): void {
	global $passed, $failed;

	if ( $ok ) {
		++$passed;
		return;
	}

	$failed[] = '' !== $detail ? "$name\n      $detail" : $name;
}

/**
 * A provider wired to fakes: scripted replies, a fixed image.
 *
 * @param callable $script  Receives ($url, $args), returns the fake reply.
 * @param int      $calls   Filled with the number of transport calls.
 */
function provider_for( callable $script, int &$calls ): AiSuggestionProvider {
	$GLOBALS['janitorix_test_transients'] = array();

	$transport = function ( string $url, array $args ) use ( $script, &$calls ) {
		++$calls;

		return $script( $url, $args );
	};

	$adapter = new OpenAiCompatibleAdapter( 'https://api.example.com/v1', 'sk-test-KEY', 'test-model', $transport );

	$loader = function ( int $id ): array {
		return array(
			'mime'   => 'image/jpeg',
			'base64' => base64_encode( 'fake-bytes-for-' . $id ),
		);
	};

	return new AiSuggestionProvider( $adapter, 'test-model', 'en_US', $loader );
}

/**
 * @param string $text The assistant's text.
 */
function reply( string $text ): array {
	return array(
		'code' => 200,
		'body' => (string) json_encode(
			array(
				'choices' => array(
					array(
						'message' => array(
							'content' => $text,
						),
					),
				),
			)
		),
	);
}

function context(): array {
	return array(
		'attachment_id' => 42,
		'filename'      => 'sunset-dhaka.jpg',
		'title'         => 'sunset-dhaka',
		'parent_title'  => 'Summer collection',
	);
}

// ------------------------------------------------- prompt carries the rules ---

$messages = AiSuggestionProvider::build_messages(
	context(),
	array(
		'mime'   => 'image/jpeg',
		'base64' => 'AAA',
	),
	'bn_BD'
);

$flat = (string) json_encode( $messages );

check( 'prompt names the file', false !== strpos( $flat, 'sunset-dhaka.jpg' ), $flat );
check( 'prompt names the parent page', false !== strpos( $flat, 'Summer collection' ), $flat );
check( 'prompt names the site language', false !== strpos( $flat, 'bn_BD' ), $flat );
check( 'prompt states the 125 limit', false !== strpos( $flat, '125' ), $flat );
check( 'prompt forbids the wasted opening', false !== strpos( $flat, 'image of' ), $flat );
check( 'image travels as a data URI', false !== strpos( $flat, 'data:image' ) && false !== strpos( $flat, 'base64,AAA' ), $flat );

// ------------------------------------------------- replies are distrusted ---

check( 'quotes stripped', 'A red bicycle' === AiSuggestionProvider::clean_text( '"A red bicycle"' ) );
check( 'opening stripped, capital kept', 'A cat on a wall' === AiSuggestionProvider::clean_text( 'Image of a cat on a wall' ) );
check( 'lecture dropped, answer kept', 'A red bicycle' === AiSuggestionProvider::clean_text( "Here is the alt text:\nA red bicycle" ) );
check( 'long reply capped', 125 === strlen( AiSuggestionProvider::clean_text( str_repeat( 'c', 200 ) ) ) );
check( 'empty stays empty', '' === AiSuggestionProvider::clean_text( '   ' ) );

// ------------------------------------------------- cache keys differ honestly ---

check(
	'locale changes the key',
	AiCache::result_key( 1, 'm', 'en_US' ) !== AiCache::result_key( 1, 'm', 'bn_BD' )
);
check(
	'model changes the key',
	AiCache::result_key( 1, 'm1', 'en_US' ) !== AiCache::result_key( 1, 'm2', 'en_US' )
);
check(
	'image changes the key',
	AiCache::result_key( 1, 'm', 'en_US' ) !== AiCache::result_key( 2, 'm', 'en_US' )
);
check(
	'file change changes the key',
	AiCache::result_key( 1, 'm', 'en_US', '111' ) !== AiCache::result_key( 1, 'm', 'en_US', '222' )
);
check(
	'no file keeps the old key',
	AiCache::result_key( 1, 'm', 'en_US' ) === AiCache::result_key( 1, 'm', 'en_US', '' )
);
check(
	'pending key names its owner and image',
	'jalt_ai_p_7_42' === AiCache::pending_key( 7, 42 )
);

// ------------------------------------------------- full suggest() path ---

$calls    = 0;
$provider = provider_for(
	function (): array {
		return reply( '"Image of a red bicycle"' );
	},
	$calls
);

$suggestion = $provider->suggest( context() );

check(
	'quoted, wasted reply arrives cleaned',
	null !== $suggestion && 'A red bicycle' === $suggestion['text'] && 'ai' === $suggestion['source'],
	var_export( $suggestion, true )
);

$suggestion = $provider->suggest( context() );

check(
	'second ask served from cache, not billed twice',
	null !== $suggestion && 1 === $calls,
	"calls: $calls"
);

$calls    = 0;
$provider = provider_for(
	function (): array {
		return array(
			'code' => 429,
			'body' => '{"error":{"message":"slow down"}}',
		);
	},
	$calls
);

check(
	'rate limit declines with its name',
	null === $provider->suggest( context() ) && 'rate-limited' === $provider->last_error(),
	$provider->last_error()
);

$broken_loader = function (): array {
	return array( 'error' => 'unsupported-type' );
};

$adapter = new OpenAiCompatibleAdapter(
	'https://api.example.com/v1',
	'sk-test-KEY',
	'test-model',
	function (): array {
		return reply( 'never asked' );
	}
);

$provider   = new AiSuggestionProvider( $adapter, 'test-model', 'en_US', $broken_loader );
$suggestion = $provider->suggest( context() );

check(
	'unpreparable image declines with its reason',
	null === $suggestion && 'unsupported-type' === $provider->last_error(),
	$provider->last_error()
);

$calls    = 0;
$provider = provider_for(
	function (): array {
		return reply( '""' );
	},
	$calls
);

check(
	'reply that cleans to nothing is an error, not a suggestion',
	null === $provider->suggest( context() ) && 'empty-reply' === $provider->last_error(),
	$provider->last_error()
);

check(
	'no id declines without asking',
	null === $provider->suggest(
		array(
			'filename'     => 'x.jpg',
			'title'        => '',
			'parent_title' => '',
		)
	) && 'missing-image' === $provider->last_error()
);

// ------------------------------------------------------------------ report ---

echo "\n" . str_repeat( '-', 62 ) . "\n";

foreach ( $failed as $failure ) {
	echo "  FAIL  {$failure}\n";
}

printf( "%d/%d assertions passed\n", $passed, $passed + count( $failed ) );

if ( $failed ) {
	printf( "\n%d FAILURE(S).\n", count( $failed ) );
	exit( 1 );
}

echo "\nThe prompt constrains, the reply is cleaned, failures name themselves.\n";
exit( 0 );
