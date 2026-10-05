<?php
/**
 * The contract every alt-text suggestion source obeys.
 *
 * @package JanitorixMediaAudit
 */

declare( strict_types=1 );

namespace JanitorixMediaAudit\AltText\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Suggests alt text; never writes it.
 *
 * A provider reads and returns words. Applying a suggestion to an image —
 * with its backup, undo, and capability checks — belongs to the screen that
 * owns the write path, not to the thing that thought of the words. That split
 * is what lets a future AI provider slot in beside the rule-based one without
 * touching anything that writes.
 *
 * Providers return machine keys, never sentences: the screen translates at
 * render time, so an untranslated key is a missing string, not a wrong one.
 */
interface SuggestionProvider {

	/**
	 * Machine name, stable across releases — stored nowhere, but shown beside
	 * the suggestion as its source.
	 */
	public function id(): string;

	/**
	 * The human-readable name shown beside a suggestion ("Suggested from …").
	 * Translated at render time by the caller.
	 */
	public function label(): string;

	/**
	 * Suggest alt text for one image, or decline.
	 *
	 * Declining is a normal answer, not a failure: a provider that cannot
	 * improve on silence returns null rather than a guess dressed as help.
	 *
	 * @param array{filename:string,title:string,parent_title:string} $context What is known about the image.
	 * @return array{text:string,source:string}|null The suggestion and which
	 *               context field it came from ('filename', 'title' or
	 *               'parent'), or null when there is nothing worth suggesting.
	 */
	public function suggest( array $context ): ?array;
}
