<?php
/**
 * Making a spreadsheet read a value as a value.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A CSV cell that begins with `=`, `+`, `-` or `@` is not text to a spreadsheet
 * — it is a formula, and Excel, LibreOffice and Sheets will all offer to run it
 * when the file is opened.
 *
 * That matters more here than in most exports. The whole point of this one is
 * that a site owner can take the list off the screen and hand it to whoever has
 * to decide, and the way they read it is by opening it in a spreadsheet. The
 * export's audience IS the attack surface.
 *
 * WordPress's own `sanitize_file_name()` strips `=` and `+` from an uploaded
 * filename and trims a leading `-`, so an ordinary upload through wp-admin
 * cannot carry a working formula. That is not a defence this plugin gets to
 * rely on: attachments also arrive by migration, by FTP, and from code that
 * never calls `sanitize_file_name()` at all, and the `sanitize_file_name`
 * filter can change what it strips. Guarding costs one invisible character on
 * an affected cell and removes the question entirely.
 *
 * A tab is used rather than the more common leading apostrophe because the
 * apostrophe is Excel's own escape and is hidden only there — LibreOffice, a
 * text editor and `head` all show it, which would put a stray quote in front of
 * a filename the reader is trying to recognise. Every spreadsheet drops a
 * leading tab on import.
 */
final class Csv {

	/**
	 * Characters a spreadsheet treats as "a formula starts here".
	 *
	 * The whitespace ones are included because some readers strip leading
	 * whitespace first and then look at what is underneath.
	 */
	private const TRIGGERS = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * One cell, safe to open in a spreadsheet.
	 *
	 * Non-strings are returned untouched: an integer cannot begin with a
	 * trigger character, and prefixing it would turn a number into text.
	 *
	 * @param mixed $value The value going into the cell.
	 *
	 * @return mixed The value, prefixed if a spreadsheet would read it as a formula.
	 */
	public static function cell( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		return in_array( $value[0], self::TRIGGERS, true ) ? "\t" . $value : $value;
	}
}
