<?php
/**
 * A tiny picture that belongs to nobody, for testing the connection.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\AltText\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Test connection must prove the model accepts images — without sending the
 * user a single byte of their own library. So the sample travels WITH the
 * plugin: an 8×8 red square, embedded below, small enough to be a constant
 * and real enough to be a PNG. If the model answers about a red square, the
 * whole path (key, endpoint, model, vision) works.
 */
final class SampleImage {

	/** An 8×8 red PNG. Decoded size: 8×8, three channels, no alpha. */
	public const BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFElEQVQImWM8ISfHgA0wYRUdtBIA0MoBFM8rfhsAAAAASUVORK5CYII=';

	/** What the sample is. */
	public const MIME = 'image/png';
}
