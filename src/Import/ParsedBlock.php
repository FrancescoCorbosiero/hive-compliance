<?php
/**
 * One document block read from a bundle.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Import;

use Hive\Compliance\Export\BundleFormat;

defined( 'ABSPATH' ) || exit;

/**
 * Begin attributes, front matter and body exactly as found in the bundle.
 */
final class ParsedBlock {

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $attributes   Begin-delimiter attributes (id, lang, type, version, base_hash).
	 * @param string                $end_id       id attribute of the end delimiter ('' when missing).
	 * @param array<string, mixed>  $front_matter Front matter fields (required_elements is a list).
	 * @param bool                  $has_front    Whether the front matter fences were found.
	 * @param string                $body         Markdown body, as written.
	 * @param int                   $line         1-based line of the begin delimiter.
	 * @param string[]              $problems     Structural problems (missing end, id mismatch...).
	 * @param string                $raw          The block text from begin to end delimiter.
	 */
	public function __construct(
		public readonly array $attributes,
		public readonly string $end_id,
		public readonly array $front_matter,
		public readonly bool $has_front,
		public readonly string $body,
		public readonly int $line,
		public readonly array $problems,
		public readonly string $raw
	) {}

	/**
	 * Document ID.
	 */
	public function id(): string {
		return (string) ( $this->attributes['id'] ?? '' );
	}

	/**
	 * Language code.
	 */
	public function lang(): string {
		return (string) ( $this->attributes['lang'] ?? '' );
	}

	/**
	 * Key used to identify the block ("id|lang").
	 */
	public function key(): string {
		return $this->id() . '|' . $this->lang();
	}

	/**
	 * The base_hash attribute.
	 */
	public function base_hash(): string {
		return (string) ( $this->attributes['base_hash'] ?? '' );
	}

	/**
	 * The changelog_note from the front matter.
	 */
	public function changelog_note(): string {
		return trim( (string) ( $this->front_matter['changelog_note'] ?? '' ) );
	}

	/**
	 * The block written back in bundle format (equals $raw for an untouched export).
	 */
	public function serialize(): string {
		return BundleFormat::block(
			array(
				'id'           => $this->id(),
				'lang'         => $this->lang(),
				'type'         => (string) ( $this->attributes['type'] ?? '' ),
				'version'      => (int) ( $this->attributes['version'] ?? 0 ),
				'base_hash'    => $this->base_hash(),
				'front_matter' => $this->front_matter,
				'body'         => $this->body,
			)
		);
	}
}
