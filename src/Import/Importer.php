<?php
/**
 * Bundle import: analysis for the review screen, then applying accepted documents.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Import;

use Hive\Compliance\Document\Document;
use Hive\Compliance\Hooks;
use Hive\Compliance\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Never a blind overwrite: only documents the user accepted, without errors, unchanged on
 * the site since the review, are applied. Documents absent from the bundle are untouched.
 */
final class Importer {

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
	 * Parses and validates a bundle against the current site state.
	 *
	 * @param string $bundle Bundle text.
	 * @return array{reports: BlockReport[], errors: string[]}
	 */
	public function analyze( string $bundle ): array {
		$parsed    = ( new BundleParser() )->parse( $bundle );
		$validator = new BundleValidator( $this->plugin );
		$counts    = array_count_values( array_map( static fn( ParsedBlock $block ) => $block->key(), $parsed['blocks'] ) );
		$reports   = array();
		foreach ( $parsed['blocks'] as $block ) {
			// A document present twice is ambiguous: every copy is an error.
			$reports[] = $validator->validate( $block, $counts[ $block->key() ] > 1 );
		}
		$errors = $parsed['errors'];
		if ( ! $parsed['blocks'] ) {
			$errors[] = __( 'No document blocks were found. Paste or upload the complete bundle returned by the model.', 'hive-compliance' );
		}
		return array(
			'reports' => $reports,
			'errors'  => $errors,
		);
	}

	/**
	 * Applies accepted documents.
	 *
	 * @param string                $bundle      Bundle text.
	 * @param string[]              $accepted    Accepted keys ("id|lang").
	 * @param array<string, string> $seen_hashes Key => stored-content hash shown on the review screen.
	 * @param bool                  $publish     Publish the applied documents.
	 * @return array{applied: Document[], skipped: array<string, string>}
	 */
	public function apply( string $bundle, array $accepted, array $seen_hashes, bool $publish ): array {
		$applied = array();
		$skipped = array();
		foreach ( $this->analyze( $bundle )['reports'] as $report ) {
			$key = $report->key();
			if ( ! in_array( $key, $accepted, true ) ) {
				continue;
			}
			if ( BlockReport::CHANGED !== $report->status() ) {
				$skipped[ $key ] = BlockReport::INVALID === $report->status() ? __( 'it has errors', 'hive-compliance' ) : __( 'nothing changed', 'hive-compliance' );
				continue;
			}
			if ( ( $seen_hashes[ $key ] ?? '' ) !== $report->current_hash() ) {
				$skipped[ $key ] = __( 'it changed on the site after the review; review the import again', 'hive-compliance' );
				continue;
			}

			$result = $this->apply_report( $report, $publish );
			if ( is_wp_error( $result ) ) {
				$skipped[ $key ] = $result->get_error_message();
			} else {
				$applied[] = $result;
			}
		}//end foreach
		return array(
			'applied' => $applied,
			'skipped' => $skipped,
		);
	}

	/**
	 * Saves one document: new revision, version +1, changelog entry with source "import",
	 * last_reviewed today. Status is unchanged unless $publish.
	 *
	 * @param BlockReport $report  Validated, changed block.
	 * @param bool        $publish Publish on apply.
	 * @return Document|\WP_Error
	 */
	private function apply_report( BlockReport $report, bool $publish ) {
		/** This action is documented in src/Hooks.php */
		do_action( Hooks::BEFORE_APPLY_IMPORT, $report->block, $report->current );

		$args = array(
			'title'         => $report->entry->title( $report->block->lang() ),
			'note'          => '' !== $report->block->changelog_note() ? $report->block->changelog_note() : __( 'Imported from an LLM bundle.', 'hive-compliance' ),
			'source'        => Document::SOURCE_IMPORT,
			'last_reviewed' => current_time( 'Y-m-d' ),
		);
		if ( $publish ) {
			$args['status'] = Document::STATUS_PUBLISHED;
		}

		$document = $this->plugin->repository()->save( $report->block->id(), $report->block->lang(), $report->markdown, $args );
		if ( ! is_wp_error( $document ) ) {
			/** This action is documented in src/Hooks.php */
			do_action( Hooks::AFTER_APPLY_IMPORT, $document, $report->block );
		}
		return $document;
	}
}
