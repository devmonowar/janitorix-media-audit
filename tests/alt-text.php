<?php
/**
 * Alt-text suggestions a human would not be embarrassed by.
 *
 * Run:  php tests/alt-text.php     (or: composer test)
 *
 * The rule-based provider is the only suggestion source in v1, and its rules
 * are the kind that rot silently: a filename pattern nobody photographs any
 * more, a length cap somebody "helpfully" raises. These assertions pin the
 * contract — suggest() declines rather than guesses, weak_reason() flags only
 * what it can name — so a future AI provider inherits the bar instead of
 * lowering it.
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

use JanitorixMediaAudit\AltText\RuleBasedProvider;

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

$provider = new RuleBasedProvider();

// ------------------------------------------------- filename suggestions ---

$suggestion = $provider->suggest(
	array(
		'filename'     => '2024/10/sunset-over-dhaka.jpg',
		'title'        => 'sunset-over-dhaka',
		'parent_title' => '',
	)
);

check(
	'hyphenated filename becomes words',
	null !== $suggestion && 'Sunset over dhaka' === $suggestion['text'] && 'filename' === $suggestion['source'],
	var_export( $suggestion, true )
);

$suggestion = $provider->suggest(
	array(
		'filename'     => 'team_photo_2024.png',
		'title'        => 'team_photo_2024',
		'parent_title' => '',
	)
);

check(
	'underscores become spaces',
	null !== $suggestion && 'Team photo 2024' === $suggestion['text'],
	var_export( $suggestion, true )
);

// ------------------------------------------------- declining, not guessing ---

check(
	'camera debris declined when nothing better exists',
	null === $provider->suggest(
		array(
			'filename'     => 'IMG_2034.jpg',
			'title'        => 'IMG_2034',
			'parent_title' => '',
		)
	)
);

check(
	'screenshot debris declined',
	null === $provider->suggest(
		array(
			'filename'     => 'Screenshot 2024-01-01 at 10.00.00.png',
			'title'        => 'Screenshot 2024-01-01 at 10.00.00',
			'parent_title' => '',
		)
	)
);

check(
	'empty everything declined',
	null === $provider->suggest(
		array(
			'filename'     => '',
			'title'        => '',
			'parent_title' => '',
		)
	)
);

// ------------------------------------------------- fallback order ---

$suggestion = $provider->suggest(
	array(
		'filename'     => 'IMG_2034.jpg',
		'title'        => 'Our new office',
		'parent_title' => 'Contact',
	)
);

check(
	'meaningful title beats debris filename',
	null !== $suggestion && 'Our new office' === $suggestion['text'] && 'title' === $suggestion['source'],
	var_export( $suggestion, true )
);

$suggestion = $provider->suggest(
	array(
		'filename'     => 'IMG_2034.jpg',
		'title'        => 'IMG_2034',
		'parent_title' => 'Summer collection',
	)
);

check(
	'parent title is the last resort, not the first guess',
	null !== $suggestion && 'Summer collection' === $suggestion['text'] && 'parent' === $suggestion['source'],
	var_export( $suggestion, true )
);

$suggestion = $provider->suggest(
	array(
		'filename'     => 'sunset.jpg',
		'title'        => 'sunset',
		'parent_title' => 'Sunset',
	)
);

check(
	'title echoing the filename is not a second opinion',
	null !== $suggestion && 'filename' === $suggestion['source'],
	var_export( $suggestion, true )
);

$suggestion = $provider->suggest(
	array(
		'filename'     => 'IMG_2034.jpg',
		'title'        => 'Image of our new office',
		'parent_title' => '',
	)
);

check(
	'wasted opening stripped from title',
	null !== $suggestion && 'Our new office' === $suggestion['text'],
	var_export( $suggestion, true )
);

// ------------------------------------------------- weak detection ---

check( 'good alt passes', '' === $provider->weak_reason( 'Sunset over Dhaka' ) );
check( 'empty is not weak', '' === $provider->weak_reason( '' ) );
check( 'whitespace-only is not weak', '' === $provider->weak_reason( '   ' ) );
check( 'single word image flagged', 'generic' === $provider->weak_reason( 'image' ) );
check( 'single word photo flagged', 'generic' === $provider->weak_reason( 'Photo' ) );
check( 'camera debris alt flagged', 'generic' === $provider->weak_reason( 'IMG_2034' ) );
check( 'bare number flagged', 'generic' === $provider->weak_reason( '2034' ) );
check( 'two-letter alt too short', 'too-short' === $provider->weak_reason( 'ab' ) );
check(
	'126 characters too long',
	'too-long' === $provider->weak_reason( str_repeat( 'a', 126 ) ),
	(string) strlen( str_repeat( 'a', 126 ) )
);
check( '125 characters exactly fine', '' === $provider->weak_reason( str_repeat( 'a', 125 ) ) );

// A suggestion must never itself be weak: whatever suggest() returns has to
// pass the same bar the list holds existing alts to.
$suggestion = $provider->suggest(
	array(
		'filename'     => 'a-very-long-filename-that-keeps-going-and-going-and-going-and-going-and-going-and-going-and-going-and-going-and-going.jpg',
		'title'        => '',
		'parent_title' => '',
	)
);

check(
	'long filename suggestion capped at 125',
	null !== $suggestion && '' === $provider->weak_reason( $suggestion['text'] ),
	var_export( $suggestion, true )
);

// ------------------------------------------------- custom text cap ---

check(
	'hand-written text capped like a suggestion',
	125 === strlen( RuleBasedProvider::cap_text( str_repeat( 'b', 200 ) ) )
);
check(
	'hand-written text under the cap untouched',
	'My own words' === RuleBasedProvider::cap_text( 'My own words' )
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

echo "\nNo suggestion guesses, and no suggestion fails its own bar.\n";
exit( 0 );
