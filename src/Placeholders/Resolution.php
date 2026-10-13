<?php
/**
 * Result of resolving the placeholders of a Markdown text.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Placeholders;

defined( 'ABSPATH' ) || exit;

/**
 * Markdown with values substituted and sentinels where values are missing.
 */
final class Resolution {

	/**
	 * Constructor.
	 *
	 * @param string   $markdown Markdown with values and sentinels.
	 * @param string[] $missing  Missing tokens; a sentinel's number is its index here.
	 * @param string[] $used     Every token found.
	 */
	public function __construct(
		public readonly string $markdown,
		public readonly array $missing,
		public readonly array $used
	) {}
}
