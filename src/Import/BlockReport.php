<?php
/**
 * Validation result of one imported block.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Import;

use Hive\Compliance\Catalog\Entry;
use Hive\Compliance\Document\Document;
use Hive\Compliance\Lint\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Status: invalid (errors, cannot apply), changed (can apply, maybe after confirming
 * warnings), unchanged (nothing to apply).
 */
final class BlockReport {

	const INVALID   = 'invalid';
	const CHANGED   = 'changed';
	const UNCHANGED = 'unchanged';

	/**
	 * Constructor.
	 *
	 * @param ParsedBlock   $block         Parsed block.
	 * @param Entry|null    $entry         Catalog entry, when known.
	 * @param Document|null $current       Stored document, when any.
	 * @param string        $markdown      Normalised imported Markdown.
	 * @param bool          $changed       Whether it differs from the stored Markdown.
	 * @param Finding[]     $findings      Findings.
	 * @param string|null   $base_markdown Exported (base) version when it differs from the site and is known.
	 */
	public function __construct(
		public readonly ParsedBlock $block,
		public readonly ?Entry $entry,
		public readonly ?Document $current,
		public readonly string $markdown,
		public readonly bool $changed,
		public readonly array $findings,
		public readonly ?string $base_markdown = null
	) {}

	/**
	 * Block key ("id|lang").
	 */
	public function key(): string {
		return $this->block->key();
	}

	/**
	 * Report status.
	 */
	public function status(): string {
		if ( $this->has( Finding::ERROR ) ) {
			return self::INVALID;
		}
		return $this->changed ? self::CHANGED : self::UNCHANGED;
	}

	/**
	 * Whether a finding of the given severity exists.
	 *
	 * @param string $severity error|warning|conflict.
	 */
	public function has( string $severity ): bool {
		foreach ( $this->findings as $finding ) {
			if ( $finding->severity === $severity ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a finding with the given rule exists.
	 *
	 * @param string $rule Rule ID.
	 */
	public function has_rule( string $rule ): bool {
		foreach ( $this->findings as $finding ) {
			if ( $finding->rule === $rule ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hash of the stored Markdown (the state the review was made against).
	 */
	public function current_hash(): string {
		return \Hive\Compliance\Document\Markdown::hash( null === $this->current ? '' : $this->current->markdown );
	}
}
