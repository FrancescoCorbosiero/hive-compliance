<?php
/**
 * Validation rules of imported document blocks.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Import;

use Hive\Compliance\Document\Markdown;
use Hive\Compliance\Hooks;
use Hive\Compliance\Lint\Finding;
use Hive\Compliance\Placeholders\Syntax;
use Hive\Compliance\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Errors (cannot apply): delimiter integrity, unknown document or language, duplicate block,
 * a placeholder dropped compared with the site, unknown placeholder namespace, HTML/scripts.
 * Warnings (apply after confirming): base_hash conflict, literal URLs, empty changelog note,
 * TODO(data) markers, missing required headings. The read-only context is never read.
 */
final class BundleValidator {

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
	 * Validates one block.
	 *
	 * @param ParsedBlock $block     Block.
	 * @param bool        $duplicate Whether an earlier block has the same id and language.
	 */
	public function validate( ParsedBlock $block, bool $duplicate = false ): BlockReport {
		$findings = $this->structure( $block, $duplicate );
		$entry    = $this->plugin->catalog()->get( $block->id() );
		$lang     = $block->lang();

		if ( null === $entry || ! $this->plugin->languages()->is_enabled( $lang ) ) {
			return new BlockReport( $block, $entry, null, Markdown::normalize( $block->body ), false, $findings );
		}

		$current  = $this->plugin->repository()->find( $entry->id, $lang );
		$stored   = null === $current ? '' : $current->markdown;
		$markdown = Markdown::normalize( $block->body );
		$changed  = $markdown !== $stored;
		$base     = null;

		if ( $changed ) {
			if ( '' !== $block->base_hash() && $block->base_hash() !== Markdown::hash( $stored ) ) {
				$base       = $this->base_markdown( $block, $current );
				$findings[] = new Finding(
					'base_hash_conflict',
					Finding::CONFLICT,
					sprintf(
						/* translators: 1: exported version, 2: current version. */
						__( 'Conflict: the document changed on the site after the export (exported version %1$s, site version %2$s). Applying replaces the site changes with the imported text.', 'hive-compliance' ),
						(string) ( $block->attributes['version'] ?? '?' ),
						null === $current ? '0' : (string) $current->version
					)
				);
			}

			$dropped = array_values( array_diff( Syntax::tokens( $stored ), Syntax::tokens( $markdown ) ) );
			if ( $dropped ) {
				$findings[] = new Finding( 'placeholder_removed', Finding::ERROR, __( 'Placeholders present on the site are missing from the import (they must be kept, never replaced by literal values).', 'hive-compliance' ), array_map( static fn( $token ) => '{{' . $token . '}}', $dropped ) );
			}

			$findings = array_merge( $findings, $this->plugin->linter()->lint( $markdown, $entry, $lang ) );

			if ( '' === $block->changelog_note() ) {
				$findings[] = new Finding( 'changelog_note_empty', Finding::WARNING, __( 'The changelog_note is empty: the change will be recorded without a reason.', 'hive-compliance' ) );
			}
		}//end if

		/** This filter is documented in src/Hooks.php */
		$findings = (array) apply_filters( Hooks::IMPORT_FINDINGS, $findings, $block, $current );
		$findings = array_values( array_filter( $findings, static fn( $finding ) => $finding instanceof Finding ) );

		return new BlockReport( $block, $entry, $current, $markdown, $changed, $findings, $base );
	}

	/**
	 * Delimiter integrity, known document and language, duplicates.
	 *
	 * @param ParsedBlock $block     Block.
	 * @param bool        $duplicate Duplicate flag.
	 * @return Finding[]
	 */
	private function structure( ParsedBlock $block, bool $duplicate ): array {
		$findings = array();
		$messages = array(
			'missing_end'          => __( 'The end delimiter is missing.', 'hive-compliance' ),
			'end_id_mismatch'      => __( 'The end delimiter does not match the begin delimiter.', 'hive-compliance' ),
			'front_matter_missing' => __( 'The YAML front matter (between --- lines) is missing.', 'hive-compliance' ),
			'malformed_delimiter'  => __( 'A malformed hive: delimiter appears inside the block.', 'hive-compliance' ),
		);
		foreach ( $block->problems as $problem ) {
			$findings[] = new Finding( 'delimiter_' . $problem, Finding::ERROR, $messages[ $problem ] ?? $problem );
		}

		$missing = array_diff( array( 'id', 'lang', 'type', 'version', 'base_hash' ), array_keys( $block->attributes ) );
		if ( $missing ) {
			$findings[] = new Finding( 'delimiter_attributes', Finding::ERROR, __( 'The begin delimiter lost attributes.', 'hive-compliance' ), array_values( $missing ) );
		}

		$entry = $this->plugin->catalog()->get( $block->id() );
		if ( null === $entry ) {
			/* translators: %s: document ID. */
			$findings[] = new Finding( 'unknown_document', Finding::ERROR, sprintf( __( 'Unknown document ID "%s".', 'hive-compliance' ), $block->id() ) );
		} elseif ( isset( $block->attributes['type'] ) && $block->attributes['type'] !== $entry->type ) {
			$findings[] = new Finding( 'type_mismatch', Finding::ERROR, __( 'The type attribute does not match the catalog.', 'hive-compliance' ) );
		}
		if ( ! $this->plugin->languages()->is_enabled( $block->lang() ) ) {
			/* translators: %s: language code. */
			$findings[] = new Finding( 'unknown_language', Finding::ERROR, sprintf( __( 'Unknown or disabled language "%s".', 'hive-compliance' ), $block->lang() ) );
		}
		if ( $duplicate ) {
			$findings[] = new Finding( 'duplicate_block', Finding::ERROR, __( 'The same document and language appear more than once.', 'hive-compliance' ) );
		}
		return $findings;
	}

	/**
	 * Text of the exported version, found among the revisions by its hash (three-way note).
	 *
	 * @param ParsedBlock                             $block   Block.
	 * @param \Hive\Compliance\Document\Document|null $current Stored document.
	 */
	private function base_markdown( ParsedBlock $block, $current ): ?string {
		if ( Markdown::hash( '' ) === $block->base_hash() ) {
			return '';
		}
		if ( null === $current ) {
			return null;
		}
		$revision = $this->plugin->repository()->find_revision_by_hash( $current, $block->base_hash() );
		return null === $revision ? null : Markdown::normalize( $revision->post_content );
	}
}
