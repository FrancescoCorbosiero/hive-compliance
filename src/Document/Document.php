<?php
/**
 * Immutable snapshot of one stored document (doc_id + language).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Value object. Build it through Repository, never from request data.
 */
final class Document {

	const STATUS_EMPTY     = 'empty';
	const STATUS_DRAFT     = 'draft';
	const STATUS_PUBLISHED = 'published';

	const SOURCE_MANUAL = 'manual';
	const SOURCE_IMPORT = 'import';

	/**
	 * Constructor.
	 *
	 * @param int                              $post_id        Post ID, 0 when not stored yet.
	 * @param string                           $doc_id         Catalog ID.
	 * @param string                           $lang           BCP-47 language code.
	 * @param string                           $markdown       Normalised Markdown.
	 * @param int                              $version        Content version, 0 when never written.
	 * @param string                           $status         empty|draft|published.
	 * @param string                           $last_reviewed  Y-m-d or ''.
	 * @param string                           $effective_date Y-m-d or ''.
	 * @param array<int, array<string, mixed>> $changelog      Changelog entries, oldest first.
	 * @param array<string, bool>              $checklist      Required element key => ticked.
	 * @param string                           $modified_gmt   Last modification (MySQL GMT) or ''.
	 */
	public function __construct(
		public readonly int $post_id,
		public readonly string $doc_id,
		public readonly string $lang,
		public readonly string $markdown,
		public readonly int $version,
		public readonly string $status,
		public readonly string $last_reviewed,
		public readonly string $effective_date,
		public readonly array $changelog,
		public readonly array $checklist,
		public readonly string $modified_gmt = ''
	) {}

	/**
	 * A not-yet-stored document.
	 *
	 * @param string $doc_id Catalog ID.
	 * @param string $lang   Language code.
	 */
	public static function blank( string $doc_id, string $lang ): self {
		return new self( 0, $doc_id, $lang, '', 0, self::STATUS_EMPTY, '', '', array(), array() );
	}

	/**
	 * Whether the document exists in the database.
	 */
	public function exists(): bool {
		return $this->post_id > 0;
	}

	/**
	 * Whether the document has no content.
	 */
	public function is_empty(): bool {
		return '' === $this->markdown;
	}

	/**
	 * Whether the document is published.
	 */
	public function is_published(): bool {
		return self::STATUS_PUBLISHED === $this->status && ! $this->is_empty();
	}

	/**
	 * Hash of the current content, as written in export bundles.
	 */
	public function hash(): string {
		return Markdown::hash( $this->markdown );
	}

	/**
	 * Changelog entry of a given version, if any.
	 *
	 * @param int $version Version number.
	 * @return array<string, mixed>|null
	 */
	public function changelog_entry( int $version ): ?array {
		foreach ( $this->changelog as $entry ) {
			if ( (int) ( $entry['version'] ?? 0 ) === $version ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Number of ticked checklist items among the given keys.
	 *
	 * @param string[] $keys Required element keys.
	 */
	public function checklist_done( array $keys ): int {
		$done = 0;
		foreach ( $keys as $key ) {
			if ( ! empty( $this->checklist[ $key ] ) ) {
				++$done;
			}
		}
		return $done;
	}
}
