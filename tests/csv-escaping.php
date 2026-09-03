<?php
/**
 * A CSV export that a spreadsheet reads as data, not as instructions.
 *
 * Run:  php tests/csv-escaping.php     (or: composer test)
 *
 * The export exists so a site owner can take the verdict list off the screen
 * and hand it to whoever has to decide — and the way that person reads it is by
 * opening it in a spreadsheet. A cell beginning with `=`, `+`, `-` or `@` is a
 * formula there, so the export's own audience is what makes the guard
 * necessary.
 *
 * These assertions are the guard's contract in both directions. Prefixing too
 * eagerly would be its own bug: a filename is something the reader is trying to
 * recognise, and a stray character in front of every one of them makes the
 * report worse at the only job it has.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( "This script is for the command line only.\n" );
}

require_once __DIR__ . '/bootstrap.php';

require_once dirname( __DIR__ ) . '/src/Support/Csv.php';

use JanitorixMediaAudit\Support\Csv;

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

// ------------------------------------------------- what must be neutralised ---

/**
 * The four characters every major spreadsheet treats as "a formula starts
 * here", plus the two whitespace characters a reader may strip before looking
 * at what is underneath.
 */
$dangerous = array(
	'=cmd|\' /C calc\'!A0'         => 'equals',
	'+1+1'                         => 'plus',
	'-2+3+cmd|\' /C calc\'!A0'     => 'minus',
	'@SUM(1+1)*cmd|\' /C calc\'!A0' => 'at',
	"\t=1+1"                       => 'leading tab',
	"\r=1+1"                       => 'leading carriage return',
);

foreach ( $dangerous as $input => $label ) {
	$out = Csv::cell( $input );

	check(
		"A cell starting with a {$label} is neutralised",
		is_string( $out ) && "\t" === $out[0] && substr( $out, 1 ) === $input,
		'got: ' . var_export( $out, true )
	);
}

// ------------------------------------------------- what must be left alone ---

$ordinary = array(
	'hero-banner-2024.jpg',
	'photo (1).png',
	'a=b.jpg',            // The trigger only counts at the start of the cell.
	'2024-summer.jpg',
	'Move to Trash',
	'97% confidence (Very High).',
	'',
);

foreach ( $ordinary as $input ) {
	check(
		'An ordinary value is returned untouched: ' . ( '' === $input ? '(empty)' : $input ),
		Csv::cell( $input ) === $input
	);
}

/**
 * Non-strings are returned as they are. An integer cannot begin with a trigger
 * character, and prefixing one would turn a number a spreadsheet can sort into
 * text it cannot.
 */
check( 'An integer stays an integer', 0 === Csv::cell( 0 ) );
check( 'A negative integer is not treated as a formula', -5 === Csv::cell( -5 ) );
check( 'Null stays null', null === Csv::cell( null ) );

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

echo "\nNo exported cell can execute in a spreadsheet.\n";
exit( 0 );
