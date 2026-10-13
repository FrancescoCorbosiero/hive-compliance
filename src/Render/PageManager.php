<?php
/**
 * Creates and assigns the pages that show public documents.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Render;

use Hive\Compliance\Catalog\Catalog;
use Hive\Compliance\I18n\Languages;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * "Create / assign page" actions. New pages are created as drafts: the plugin never
 * publishes a page by itself.
 */
final class PageManager {

	/**
	 * Page bindings.
	 *
	 * @var PageBindings
	 */
	private PageBindings $bindings;

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
	 * Constructor.
	 *
	 * @param PageBindings $bindings  Page bindings.
	 * @param Catalog      $catalog   Catalog.
	 * @param Languages    $languages Languages.
	 */
	public function __construct( PageBindings $bindings, Catalog $catalog, Languages $languages ) {
		$this->bindings  = $bindings;
		$this->catalog   = $catalog;
		$this->languages = $languages;
	}

	/**
	 * Block markup of a shell page.
	 *
	 * @param string $doc_id Catalog ID.
	 * @param string $lang   Language code.
	 */
	public static function shell_content( string $doc_id, string $lang ): string {
		return '<!-- wp:hive/document ' . wp_json_encode(
			array(
				'docId' => $doc_id,
				'lang'  => $lang,
			)
		) . ' /-->';
	}

	/**
	 * Creates a draft page containing only the block, and binds it.
	 *
	 * @param string $doc_id Catalog ID.
	 * @param string $lang   Language code.
	 * @return int|WP_Error Page ID.
	 */
	public function create( string $doc_id, string $lang ) {
		$entry = $this->catalog->get( $doc_id );
		if ( null === $entry || ! $entry->is_page() ) {
			return new WP_Error( 'hive_not_a_page', __( 'Only page documents can be bound to a page.', 'hive-compliance' ) );
		}

		$page_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'draft',
					'post_title'   => $entry->title( $lang ),
					'post_content' => self::shell_content( $doc_id, $lang ),
				)
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		$this->languages->assign_post_language( (int) $page_id, $lang );
		$this->bindings->set( $doc_id, $lang, (int) $page_id );
		return (int) $page_id;
	}

	/**
	 * Binds an existing page. Returns the shell problems found (the binding is made anyway).
	 *
	 * @param string $doc_id  Catalog ID.
	 * @param string $lang    Language code.
	 * @param int    $page_id Page ID.
	 * @return string[]|WP_Error Problem codes (see ShellGuard).
	 */
	public function assign( string $doc_id, string $lang, int $page_id ) {
		$entry = $this->catalog->get( $doc_id );
		if ( null === $entry || ! $entry->is_page() ) {
			return new WP_Error( 'hive_not_a_page', __( 'Only page documents can be bound to a page.', 'hive-compliance' ) );
		}
		$page = get_post( $page_id );
		if ( ! $page instanceof WP_Post || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
			return new WP_Error( 'hive_invalid_page', __( 'Choose an existing page.', 'hive-compliance' ) );
		}
		$other = $this->bindings->binding_for_page( $page_id );
		if ( null !== $other && ( $other['doc_id'] !== $doc_id || $other['lang'] !== $lang ) ) {
			/* translators: 1: document ID, 2: language code. */
			return new WP_Error( 'hive_page_taken', sprintf( __( 'This page is already bound to %1$s (%2$s).', 'hive-compliance' ), $other['doc_id'], $other['lang'] ) );
		}

		$this->bindings->set( $doc_id, $lang, $page_id );
		return ShellGuard::inspect( (string) $page->post_content, $doc_id );
	}

	/**
	 * Removes a binding (the page itself is left untouched).
	 *
	 * @param string $doc_id Catalog ID.
	 * @param string $lang   Language code.
	 */
	public function unbind( string $doc_id, string $lang ): void {
		$this->bindings->remove( $doc_id, $lang );
	}
}
