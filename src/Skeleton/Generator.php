<?php
/**
 * Skeleton documents: headings from the required elements and their placeholders, no prose.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Skeleton;

use Hive\Compliance\Catalog\Entry;
use Hive\Compliance\Document\Document;
use Hive\Compliance\Installer;
use Hive\Compliance\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creates draft skeletons for empty documents. Never overwrites existing content.
 */
final class Generator {

	/**
	 * Plugin services.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin services.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Skeleton Markdown of an entry.
	 *
	 * One "## label {#key}" heading per required element, followed by that element's
	 * placeholders as a list. Placeholders used by the document but not tied to an element
	 * come first.
	 *
	 * @param Entry $entry Catalog entry.
	 */
	public function skeleton( Entry $entry ): string {
		$mapped = array();
		foreach ( $entry->required_elements as $element ) {
			$mapped = array_merge( $mapped, $element['placeholders'] );
		}

		$blocks   = array();
		$unmapped = array_values( array_diff( $entry->placeholders_used, $mapped ) );
		$list     = static fn( array $tokens ) => implode( "\n", array_map( static fn( $token ) => '- {{' . $token . '}}', $tokens ) );
		if ( $unmapped ) {
			$blocks[] = $list( $unmapped );
		}
		foreach ( $entry->required_elements as $element ) {
			$block = '## ' . $element['label'] . ' {#' . $element['key'] . '}';
			if ( $element['placeholders'] ) {
				$block .= "\n\n" . $list( $element['placeholders'] );
			}
			$blocks[] = $block;
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * Creates the skeleton of one empty document as a draft.
	 *
	 * @param Entry  $entry Catalog entry.
	 * @param string $lang  Language.
	 * @return Document|WP_Error|null Null when the document already has content.
	 */
	public function generate( Entry $entry, string $lang ) {
		$existing = $this->plugin->repository()->find( $entry->id, $lang );
		if ( null !== $existing && ! $existing->is_empty() ) {
			return null;
		}
		return $this->plugin->repository()->save(
			$entry->id,
			$lang,
			$this->skeleton( $entry ),
			array(
				'title'  => $entry->title( $lang ),
				/* translators: %s: catalog version. */
				'note'   => sprintf( __( 'Skeleton generated from catalog %s.', 'hive-compliance' ), $this->plugin->catalog()->version() ),
				'source' => Document::SOURCE_MANUAL,
				'status' => Document::STATUS_DRAFT,
			)
		);
	}

	/**
	 * Skeletons for every applicable entry that is still empty, in the given languages.
	 *
	 * @param string[] $languages Language codes.
	 * @return array{created: int, skipped: int}
	 */
	public function generate_applicable( array $languages ): array {
		$created = 0;
		$skipped = 0;
		foreach ( $this->plugin->applicability()->applicable() as $entry ) {
			foreach ( $languages as $lang ) {
				$result = $this->generate( $entry, $lang );
				if ( $result instanceof Document ) {
					++$created;
				} else {
					++$skipped;
				}
			}
		}
		return array(
			'created' => $created,
			'skipped' => $skipped,
		);
	}

	/**
	 * First activation: skeletons for the applicable documents in the default language.
	 * Runs once, on the first admin request after activation (hooked to admin_init).
	 */
	public function maybe_generate_on_first_run(): void {
		if ( ! get_option( Installer::OPTION_PENDING_SKELETONS ) || ! $this->plugin->catalog()->is_valid() ) {
			return;
		}
		delete_option( Installer::OPTION_PENDING_SKELETONS );
		$this->generate_applicable( array( $this->plugin->languages()->default_language() ) );
	}
}
