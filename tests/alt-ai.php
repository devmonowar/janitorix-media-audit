<?php
/**
 * The AI boundary, pinned without ever touching the network.
 *
 * Run:  php tests/alt-ai.php     (or: composer test)
 *
 * The adapter's promises are the kind that rot silently: the key leaking
 * into an error string, a 429 read as a generic failure, plain HTTP let
 * through. Every one of them is asserted here through a fake transport, so
 * production's `wp_remote_post` is the only thing these tests do not cover —
 * and that function belongs to WordPress, not to this plugin.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( "This script is for the command line only.\n" );
}

require_once __DIR__ . '/bootstrap.php';

require_once dirname( __DIR__ ) . '/src/AltText/Ai/OpenAiCompatibleAdapter.php';
require_once dirname( __DIR__ ) . '/src/AltText/Ai/AiSettings.php';
require_once dirname( __DIR__ ) . '/src/AltText/Ai/SampleImage.php';

use JanitorixMediaAudit\AltText\Ai\AiSettings;
use JanitorixMediaAudit\AltText\Ai\OpenAiCompatibleAdapter;
use JanitorixMediaAudit\AltText\Ai\SampleImage;

// ------------------------------------------------- test-only WP surface ---

// These exist because the adapter is honest about what it calls: JSON
// encoding and response reading belong to WordPress in production. They live
// here, not in bootstrap.php, because no engine under test may need them.

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
	 * True only for the test double below — the fake transport otherwise
	 * answers with plain arrays, exactly like a decoded HTTP reply.
	 *
	 * @param mixed $thing
	 */
	function is_wp_error( $thing ): bool { // phpcs:ignore
		return $thing instanceof FakeWpError;
	}
}

/**
 * Stands in for WP_Error: carries a code and a message, nothing else.
 */
final class FakeWpError {

	/**
	 * @var string
	 */
	private $message;

	/**
	 * @param string $message What get_error_message() returns.
	 */
	public function __construct( string $message ) {
		$this->message = $message;
	}

	/**
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->message;
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
 * Build an adapter whose transport answers from a script.
 *
 * @param callable $script Receives ($url, $args), returns the fake reply.
 * @param array    $seen   Filled with every ($url, $args) the fake saw.
 */
function adapter_for( callable $script, array &$seen ): OpenAiCompatibleAdapter {
	$transport = function ( string $url, array $args ) use ( $script, &$seen ) {
		$seen[] = array( $url, $args );

		return $script( $url, $args );
	};

	return new OpenAiCompatibleAdapter( 'https://api.example.com/v1', 'sk-test-SECRETKEY123', 'test-model', $transport );
}

/**
 * A chat-completions reply carrying one assistant message.
 *
 * @param string $text The assistant's text.
 * @param int    $code The HTTP status to report.
 */
function reply( string $text, int $code = 200 ): array {
	return array(
		'code' => $code,
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

// ------------------------------------------------- happy path ---

$seen    = array();
$adapter = adapter_for(
	function (): array {
		return reply( 'A red bicycle by a wall' );
	},
	$seen
);

$result = $adapter->complete( array( array( 'role' => 'user', 'content' => 'x' ) ) );

check(
	'success returns the text',
	isset( $result['ok'], $result['text'] ) && true === $result['ok'] && 'A red bicycle by a wall' === $result['text'],
	var_export( $result, true )
);

check(
	'posts to chat/completions',
	1 === count( $seen ) && 'https://api.example.com/v1/chat/completions' === $seen[0][0],
	var_export( $seen, true )
);

check(
	'key travels in the Authorization header',
	'Bearer sk-test-SECRETKEY123' === ( $seen[0][1]['headers']['Authorization'] ?? null )
);

check(
	'30-second timeout, no more',
	30 === ( $seen[0][1]['timeout'] ?? 0 )
);

// ------------------------------------------------- error mapping ---

$cases = array(
	'401 means bad credentials, not a bad request' => array(
		array(
			'code' => 401,
			'body' => '{"error":{"message":"Incorrect API key"}}',
		),
		'unauthorized',
	),
	'429 tells the user to wait, not to reconfigure' => array(
		array(
			'code' => 429,
			'body' => '{"error":{"message":"Rate limit reached"}}',
		),
		'rate-limited',
	),
	'unknown model names its own failure' => array(
		array(
			'code' => 400,
			'body' => '{"error":{"message":"The model test-model does not exist"}}',
		),
		'bad-model',
	),
	'garbage body is a bad response, not a bad model' => array(
		array(
			'code' => 500,
			'body' => '<html>bad gateway</html>',
		),
		'bad-response',
	),
	'empty choices are a bad response' => array(
		array(
			'code' => 200,
			'body' => '{"choices":[]}',
		),
		'bad-response',
	),
);

foreach ( $cases as $name => $case ) {
	$seen    = array();
	$adapter = adapter_for(
		function () use ( $case ): array {
			return $case[0];
		},
		$seen
	);

	$result = $adapter->complete( array() );

	check(
		$name,
		isset( $result['ok'], $result['error'] ) && false === $result['ok'] && $case[1] === $result['error'],
		var_export( $result, true )
	);
}

$seen    = array();
$adapter = adapter_for(
	function (): FakeWpError {
		return new FakeWpError( 'cURL error 28: Operation timed out' );
	},
	$seen
);

$result = $adapter->complete( array() );

check(
	'cURL timeout maps to timeout, not transport-error',
	isset( $result['error'] ) && 'timeout' === $result['error'],
	var_export( $result, true )
);

$seen    = array();
$adapter = adapter_for(
	function (): FakeWpError {
		return new FakeWpError( 'cURL error 6: Could not resolve host' );
	},
	$seen
);

$result = $adapter->complete( array() );

check(
	'DNS failure is a transport error',
	isset( $result['error'] ) && 'transport-error' === $result['error'],
	var_export( $result, true )
);

// ------------------------------------------------- the key never leaks ---

$seen    = array();
$adapter = adapter_for(
	function (): array {
		return array(
			'code' => 401,
			'body' => '{"error":{"message":"Incorrect API key sk-test-SECRETKEY123"}}',
		);
	},
	$seen
);

$result = $adapter->complete( array() );

check(
	'key in the provider echo does not reach the caller',
	false === strpos( (string) json_encode( $result ), 'sk-test-SECRETKEY123' ),
	var_export( $result, true )
);

// ------------------------------------------------- plain HTTP refused ---

$seen    = array();
$plain   = new OpenAiCompatibleAdapter(
	'http://192.168.1.10:1234/v1',
	'sk-test-SECRETKEY123',
	'test-model',
	function () use ( &$seen ): array {
		$seen[] = true;

		return reply( 'should never arrive' );
	}
);

$result = $plain->complete( array() );

check(
	'plain HTTP refused before any request',
	isset( $result['error'] ) && 'insecure-url' === $result['error'] && empty( $seen ),
	var_export( $result, true )
);

$GLOBALS['janitorix_test_filters']['janitorix_alt_ai_allow_http'] = function (): bool {
	return true;
};

$result = $plain->complete( array() );

check(
	'explicit filter allows plain HTTP for local relays',
	isset( $result['ok'] ) && true === $result['ok'],
	var_export( $result, true )
);

unset( $GLOBALS['janitorix_test_filters']['janitorix_alt_ai_allow_http'] );

// ------------------------------------------------- missing config ---

$seen    = array();
$adapter = new OpenAiCompatibleAdapter( 'https://api.example.com/v1', '', 'test-model', function (): array {
	return reply( 'x' );
} );

$result = $adapter->complete( array() );

check(
	'empty key refuses without a request',
	isset( $result['error'] ) && 'not-configured' === $result['error'] && empty( $seen ),
	var_export( $result, true )
);

// ------------------------------------------------- settings: off by default ---

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $name
	 * @param mixed  $value
	 * @param bool   $autoload
	 */
	function update_option( string $name, $value, bool $autoload = true ): bool { // phpcs:ignore
		$GLOBALS['janitorix_test_options'][ $name ] = $value;
		$GLOBALS['janitorix_test_autoload'][ $name ] = $autoload;

		return true;
	}
}

$GLOBALS['janitorix_test_options']  = array();
$GLOBALS['janitorix_test_autoload'] = array();

check( 'fresh install: AI unavailable', false === AiSettings::is_available() );
check( 'fresh install: no key', false === AiSettings::has_key() );
check( 'fresh install: masked empty', '' === AiSettings::masked() );

$defaults = AiSettings::get();

check( 'fresh install: disabled default', false === $defaults['enabled'] );
check( 'fresh install: sane endpoint default', 0 === strpos( $defaults['base_url'], 'https://' ) );

// The key the tests never had must not appear anywhere the settings expose.
check(
	'get() never carries the key',
	false === strpos( (string) json_encode( AiSettings::get() ), 'SECRET' )
);

AiSettings::save(
	array(
		'janitorix_alt_ai_enabled'  => '1',
		'janitorix_alt_ai_base_url' => 'https://api.example.com/v1',
		'janitorix_alt_ai_model'    => 'test-model',
		'janitorix_alt_ai_key'      => 'sk-test-SECRETKEY123',
	)
);

check( 'saved: available', true === AiSettings::is_available() );
check( 'saved: key hidden, last4 shown', '••••••••Y123' === AiSettings::masked() );
check(
	'key option stored non-autoloaded',
	false === ( $GLOBALS['janitorix_test_autoload'][ AiSettings::OPTION ] ?? null )
);

// Blank key submit keeps the old key — the browser never sees it.
AiSettings::save(
	array(
		'janitorix_alt_ai_enabled'  => '1',
		'janitorix_alt_ai_base_url' => 'https://api.example.com/v1',
		'janitorix_alt_ai_model'    => 'test-model',
		'janitorix_alt_ai_key'      => '   ',
	)
);

check( 'blank key keeps the old one', true === AiSettings::has_key() );

// Plain HTTP is dropped in favour of the saved endpoint.
AiSettings::save(
	array(
		'janitorix_alt_ai_enabled'  => '1',
		'janitorix_alt_ai_base_url' => 'http://192.168.1.10:1234/v1',
		'janitorix_alt_ai_model'    => 'test-model',
		'janitorix_alt_ai_key'      => '',
	)
);

$after = AiSettings::get();

check( 'http endpoint refused, old kept', 'https://api.example.com/v1' === $after['base_url'], $after['base_url'] );

// ------------------------------------------------- sample image is real ---

$size = getimagesizefromstring( (string) base64_decode( SampleImage::BASE64, true ) );

check(
	'embedded sample decodes to a tiny image',
	is_array( $size ) && 8 === $size[0] && 8 === $size[1] && 'image/png' === ( $size['mime'] ?? '' ),
	var_export( $size, true )
);

// ------------------------------------------------- messages name the fix ---

foreach ( array( 'timeout', 'unauthorized', 'rate-limited', 'bad-model', 'unknown-key' ) as $key ) {
	$message = OpenAiCompatibleAdapter::user_message( $key );

	check(
		"message for $key is human",
		is_string( $message ) && '' !== $message && false === strpos( $message, 'sk-test-SECRETKEY123' ),
		$message
	);
}

check(
	'unexpected answer quotes the service',
	false !== strpos( OpenAiCompatibleAdapter::user_message( 'bad-response', 'HTTP 403: Project deactivated' ), 'HTTP 403: Project deactivated' )
);

check(
	'known keys ignore detail',
	false !== strpos( OpenAiCompatibleAdapter::user_message( 'unauthorized' ), 'rejected' )
	&& false === strpos( OpenAiCompatibleAdapter::user_message( 'unauthorized', 'HTTP 401: x' ), 'HTTP 401' )
);

$seen    = array();
$adapter = adapter_for(
	function (): array {
		return array(
			'code' => 403,
			'body' => '{"error":{"message":"Project has been deactivated"}}',
		);
	},
	$seen
);

$result = $adapter->complete( array() );

check(
	'declined service words travel with the failure',
	isset( $result['error'], $result['detail'] ) && 'bad-response' === $result['error']
		&& 'HTTP 403: Project has been deactivated' === $result['detail'],
	var_export( $result, true )
);

// ------------------------------------------------- presets are honest ---

$presets = AiSettings::presets();

check( 'four presets ship', 4 === count( $presets ) );

foreach ( $presets as $id => $preset ) {
	$shape_ok = isset( $preset['label'], $preset['base_url'], $preset['model'], $preset['key_url'], $preset['key_label'] )
		&& '' !== $preset['label'] && '' !== $preset['model'];

	check( "preset $id complete", $shape_ok );
	check( "preset $id endpoint encrypted", 0 === strpos( $preset['base_url'], 'https://' ), $preset['base_url'] );
	check( "preset $id key link encrypted", 0 === strpos( $preset['key_url'], 'https://' ), $preset['key_url'] );
}

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

echo "\nThe key travels once, errors name themselves, HTTP stays encrypted.\n";
exit( 0 );
