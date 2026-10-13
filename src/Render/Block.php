<?php
/**
 * The hive/document dynamic block (no build step: block.json + plain JS).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Render;

use Hive\Compliance\Catalog\Catalog;
use Hive\Compliance\I18n\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the block and supplies the editor with the list of documents.
 */
final class Block {

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Catalog.
	 *
	 * @var Catalog
	 */
	private Catalog $catalog;

	/**
	 * Languages.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Editor script handle generated from block.json.
	 *
	 * @var string
	 */
	private string $editor_handle = '';

	/**
	 * Constructor.
	 *
	 * @param Renderer  $renderer  Renderer.
	 * @param Catalog   $catalog   Catalog.
	 * @param Languages $languages Languages.
	 */
	public function __construct( Renderer $renderer, Catalog $catalog, Languages $languages ) {
		$this->renderer  = $renderer;
		$this->catalog   = $catalog;
		$this->languages = $languages;
	}

	/**
	 * Registers the block type (on init). The document list is only built in the block editor.
	 */
	public function register(): void {
		$type = register_block_type(
			HIVE_COMPLIANCE_DIR . 'blocks/document',
			array( 'render_callback' => array( $this, 'render' ) )
		);
		if ( $type && ! empty( $type->editor_script_handles[0] ) ) {
			$this->editor_handle = (string) $type->editor_script_handles[0];
			add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
		}
	}

	/**
	 * Passes the documents and languages to the editor script.
	 */
	public function editor_assets(): void {
		wp_add_inline_script( $this->editor_handle, 'window.hiveComplianceBlock = ' . wp_json_encode( $this->editor_data() ) . ';', 'before' );
		wp_set_script_translations( $this->editor_handle, 'hive-compliance', HIVE_COMPLIANCE_DIR . 'languages' );
	}

	/**
	 * Server-side render.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( $attributes ): string {
		$doc_id = sanitize_key( (string) ( $attributes['docId'] ?? '' ) );
		$lang   = Languages::normalize_code( (string) ( $attributes['lang'] ?? '' ) );
		$html   = $this->renderer->render_document( $doc_id, $lang );
		if ( '' === $html ) {
			return '';
		}
		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	/**
	 * Documents and languages for the block inspector (public documents only).
	 *
	 * @return array{documents: array<int, array{value: string, label: string}>, languages: array<int, array{value: string, label: string}>}
	 */
	private function editor_data(): array {
		$lang      = Languages::locale_to_code( (string) get_user_locale() );
		$documents = array();
		foreach ( $this->catalog->entries() as $entry ) {
			if ( $entry->is_page() ) {
				$documents[] = array(
					'value' => $entry->id,
					'label' => $entry->title( $lang ),
				);
			}
		}

		$languages = array();
		foreach ( $this->languages->enabled() as $code ) {
			$languages[] = array(
				'value' => $code,
				'label' => $this->languages->label( $code ),
			);
		}

		return array(
			'documents' => $documents,
			'languages' => $languages,
		);
	}
}
