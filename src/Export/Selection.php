<?php
/**
 * Which documents and languages an export covers.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Export;

use Hive\Compliance\Catalog\Applicability;
use Hive\Compliance\Catalog\Catalog;
use Hive\Compliance\Catalog\Entry;
use Hive\Compliance\I18n\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Selection modes: all applicable documents, by group, or individual documents.
 */
final class Selection {

	const MODE_APPLICABLE = 'applicable';
	const MODE_GROUPS     = 'groups';
	const MODE_DOCUMENTS  = 'documents';

	/**
	 * Constructor.
	 *
	 * @param string   $mode             applicable|groups|documents.
	 * @param string[] $groups           Groups (groups mode).
	 * @param string[] $doc_ids          Document IDs (documents mode).
	 * @param string[] $languages        Language codes.
	 * @param bool     $include_internal Whether internal documents are included.
	 */
	public function __construct(
		public readonly string $mode,
		public readonly array $groups,
		public readonly array $doc_ids,
		public readonly array $languages,
		public readonly bool $include_internal
	) {}

	/**
	 * Builds a selection from sanitised input (form post or print-view query).
	 *
	 * @param array<string, mixed> $input     Input.
	 * @param Languages            $languages Languages service.
	 */
	public static function from_input( array $input, Languages $languages ): self {
		$mode = (string) ( $input['mode'] ?? self::MODE_APPLICABLE );
		$mode = in_array( $mode, array( self::MODE_APPLICABLE, self::MODE_GROUPS, self::MODE_DOCUMENTS ), true ) ? $mode : self::MODE_APPLICABLE;

		$langs = array_values( array_intersect( $languages->enabled(), array_map( array( Languages::class, 'normalize_code' ), (array) ( $input['langs'] ?? array() ) ) ) );
		if ( ! $langs ) {
			$langs = $languages->enabled();
		}

		return new self(
			$mode,
			array_values( array_intersect( Entry::GROUPS, (array) ( $input['groups'] ?? array() ) ) ),
			array_values( array_filter( array_map( 'sanitize_key', (array) ( $input['docs'] ?? array() ) ) ) ),
			$langs,
			! empty( $input['include_internal'] )
		);
	}

	/**
	 * Query arguments that reproduce this selection.
	 *
	 * @return array<string, mixed>
	 */
	public function to_query(): array {
		return array(
			'mode'             => $this->mode,
			'groups'           => $this->groups,
			'docs'             => $this->doc_ids,
			'langs'            => $this->languages,
			'include_internal' => $this->include_internal ? 1 : 0,
		);
	}

	/**
	 * Selected entries, in catalog order.
	 *
	 * @param Catalog       $catalog       Catalog.
	 * @param Applicability $applicability Applicability.
	 * @return Entry[]
	 */
	public function entries( Catalog $catalog, Applicability $applicability ): array {
		$out = array();
		foreach ( $catalog->entries() as $id => $entry ) {
			if ( $entry->is_internal() && ! $this->include_internal ) {
				continue;
			}
			$selected = match ( $this->mode ) {
				self::MODE_GROUPS    => in_array( $entry->group, $this->groups, true ),
				self::MODE_DOCUMENTS => in_array( $id, $this->doc_ids, true ),
				default              => $applicability->applies( $entry ),
			};
			if ( $selected ) {
				$out[] = $entry;
			}
		}
		return $out;
	}
}
