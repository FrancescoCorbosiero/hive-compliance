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
	 * @param array<int, array<string, mixed>> $required_elements     {key, label (English), labels (language => label), legal_ref, placeholders}.
	 * @param string[]                         $placeholders_used     Placeholder keys.
	 * @param string[]                         $legal_refs            Legal references.
	 * @param int                              $review_cadence_months Review cadence.
	 * @param string                           $checklist_status      complete|draft.
	 * @param string                           $description           Short description.
	 * @param array<int, array<string, mixed>> $lint                  Content lint rules {id, pattern, severity, message}.
	 * @param string                           $link_group            Footer link list of a page: legal|assistance ('' = none).
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
		public readonly array $lint = array(),
		public readonly string $link_group = ''
	) {}

	/**
	 * Builds an entry from decoded catalog data.
	 *
	 * @param array<string, mixed> $data Entry data (validated).
	 */
	public static function from_array( array $data ): self {
		$elements = array();
		foreach ( (array) ( $data['required_elements'] ?? array() ) as $element ) {
			// A label is a language => text map, or a single English string.
			$labels     = is_array( $element['label'] ) ? array_map( 'strval', $element['label'] ) : array( 'en' => (string) $element['label'] );
			$elements[] = array(
				'key'          => (string) $element['key'],
				'label'        => $labels['en'] ?? (string) reset( $labels ),
				'labels'       => $labels,
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
			array_values( (array) ( $data['lint'] ?? array() ) ),
			(string) ( $data['link_group'] ?? '' )
		);
	}

	/**
	 * Title in a language: exact code, then primary subtag, then English, then any.
	 *
	 * @param string $lang Language code.
	 */
	public function title( string $lang ): string {
		return self::localized( $this->titles, $lang );
	}

	/**
	 * Label of a required element in a language, with the same fallbacks as the title.
	 *
	 * @param array<string, mixed> $element Required element.
	 * @param string               $lang    Language code.
	 */
	public static function element_label( array $element, string $lang ): string {
		return self::localized( (array) ( $element['labels'] ?? array( 'en' => (string) $element['label'] ) ), $lang );
	}

	/**
	 * Text in a language: exact code, then primary subtag, then English, then any.
	 *
	 * @param array<string, string> $texts Language => text.
	 * @param string                $lang  Language code.
	 */
	private static function localized( array $texts, string $lang ): string {
		if ( isset( $texts[ $lang ] ) ) {
			return $texts[ $lang ];
		}
		$primary = Languages::primary( $lang );
		if ( isset( $texts[ $primary ] ) ) {
			return $texts[ $primary ];
		}
		return $texts['en'] ?? (string) reset( $texts );
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
