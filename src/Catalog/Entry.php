<?php
/**
 * One catalog entry (a document type).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Catalog;

use Hive\Compliance\I18n\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable catalog entry built from catalog.json.
 */
final class Entry {

	const TYPE_PAGE     = 'page';
	const TYPE_FRAGMENT = 'fragment';
	const TYPE_INTERNAL = 'internal';

	const GROUPS = array( 'core', 'conditional', 'international', 'fragment', 'internal' );

	/**
	 * Constructor.
	 *
	 * @param string                           $id                    Catalog ID.
	 * @param array<string, string>            $titles                Language => title.
	 * @param string                           $type                  page|fragment|internal.
	 * @param string                           $group                 Catalog group.
	 * @param array<string, mixed>             $applies_when          Applicability rule.
	 * @param array<int, array<string, mixed>> $required_elements     {key, label, legal_ref, placeholders}.
	 * @param string[]                         $placeholders_used     Placeholder keys.
	 * @param string[]                         $legal_refs            Legal references.
	 * @param int                              $review_cadence_months Review cadence.
	 * @param string                           $checklist_status      complete|draft.
	 * @param string                           $description           Short description.
	 * @param array<int, array<string, mixed>> $lint                  Content lint rules {id, pattern, severity, message}.
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $titles,
		public readonly string $type,
		public readonly string $group,
		public readonly array $applies_when,
		public readonly array $required_elements,
		public readonly array $placeholders_used,
		public readonly array $legal_refs,
		public readonly int $review_cadence_months,
		public readonly string $checklist_status,
		public readonly string $description = '',
		public readonly array $lint = array()
	) {}

	/**
	 * Builds an entry from decoded catalog data.
	 *
	 * @param array<string, mixed> $data Entry data (validated).
	 */
	public static function from_array( array $data ): self {
		$elements = array();
		foreach ( (array) ( $data['required_elements'] ?? array() ) as $element ) {
			$elements[] = array(
				'key'          => (string) $element['key'],
				'label'        => (string) $element['label'],
				'legal_ref'    => (string) ( $element['legal_ref'] ?? '' ),
				'placeholders' => array_values( (array) ( $element['placeholders'] ?? array() ) ),
			);
		}

		return new self(
			(string) $data['id'],
			(array) $data['title'],
			(string) $data['type'],
			(string) $data['group'],
			(array) $data['applies_when'],
			$elements,
			array_values( (array) $data['placeholders_used'] ),
			array_values( (array) $data['legal_refs'] ),
			(int) $data['review_cadence_months'],
			(string) $data['checklist_status'],
			(string) ( $data['description'] ?? '' ),
			array_values( (array) ( $data['lint'] ?? array() ) )
		);
	}

	/**
	 * Title in a language: exact code, then primary subtag, then English, then any.
	 *
	 * @param string $lang Language code.
	 */
	public function title( string $lang ): string {
		if ( isset( $this->titles[ $lang ] ) ) {
			return $this->titles[ $lang ];
		}
		$primary = Languages::primary( $lang );
		if ( isset( $this->titles[ $primary ] ) ) {
			return $this->titles[ $primary ];
		}
		return $this->titles['en'] ?? (string) reset( $this->titles );
	}

	/**
	 * Whether it renders as a standalone page.
	 */
	public function is_page(): bool {
		return self::TYPE_PAGE === $this->type;
	}

	/**
	 * Whether it is a fragment (inline text).
	 */
	public function is_fragment(): bool {
		return self::TYPE_FRAGMENT === $this->type;
	}

	/**
	 * Whether it is internal (never rendered on the front end).
	 */
	public function is_internal(): bool {
		return self::TYPE_INTERNAL === $this->type;
	}

	/**
	 * Keys of the required elements.
	 *
	 * @return string[]
	 */
	public function element_keys(): array {
		return array_column( $this->required_elements, 'key' );
	}

	/**
	 * Every placeholder the entry mentions (document level and per element).
	 *
	 * @return string[]
	 */
	public function all_placeholders(): array {
		$all = $this->placeholders_used;
		foreach ( $this->required_elements as $element ) {
			$all = array_merge( $all, $element['placeholders'] );
		}
		return array_values( array_unique( $all ) );
	}
}
