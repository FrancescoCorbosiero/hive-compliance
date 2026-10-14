<?php
/**
 * Footer lists of links to the legal pages ([hive_legal_links], hive/legal-links).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Render;

use Hive\Compliance\Catalog\Catalog;
use Hive\Compliance\Document\Document;
use Hive\Compliance\Document\Repository;
use Hive\Compliance\Hooks;
use Hive\Compliance\I18n\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Links to the bound, published pages of one link group, in catalog order, titled with the
 * document titles. Documents without a published page or not published themselves are skipped.
 */
final class LegalLinks {

	const GROUPS = array( 'legal', 'assistance' );

	/**
	 * Catalog.
	 *
	 * @var Catalog
	 */
	private Catalog $catalog;

	/**
	 * Document repository.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Page bindings.
	 *
	 * @var PageBindings
	 */
	private PageBindings $bindings;

	/**
	 * Languages.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Constructor.
	 *
	 * @param Catalog      $catalog    Catalog.
	 * @param Repository   $repository Repository.
	 * @param PageBindings $bindings   Page bindings.
	 * @param Languages    $languages  Languages.
	 */
	public function __construct( Catalog $catalog, Repository $repository, PageBindings $bindings, Languages $languages ) {
		$this->catalog    = $catalog;
		$this->repository = $repository;
		$this->bindings   = $bindings;
		$this->languages  = $languages;
	}

	/**
	 * Links of a group.
	 *
	 * @param string $group legal|assistance; '' for every page document.
	 * @param string $lang  Language ('' = current, falling back to the default language).
	 * @return array<int, array{id: string, title: string, url: string}>
	 */
	public function links( string $group, string $lang = '' ): array {
		$group   = in_array( $group, self::GROUPS, true ) ? $group : '';
		$lang    = '' !== $lang ? $lang : $this->languages->current();
		$default = $this->languages->default_language();

		$links = array();
		foreach ( $this->catalog->pages() as $id => $entry ) {
			if ( '' !== $group && $entry->link_group !== $group ) {
				continue;
			}
			$document = $this->published( $id, $lang, $default );
			$url      = null === $document ? '' : $this->bindings->url( $id, $document->lang, $default );
			if ( '' === $url ) {
				continue;
			}
			$links[] = array(
				'id'    => $id,
				'title' => $entry->title( $document->lang ),
				'url'   => $url,
			);
		}

		/** This filter is documented in src/Hooks.php */
		$links = (array) apply_filters( Hooks::LEGAL_LINKS, $links, $group, $lang );
		return array_values( array_filter( $links, static fn( $link ) => is_array( $link ) && '' !== (string) ( $link['url'] ?? '' ) && '' !== (string) ( $link['title'] ?? '' ) ) );
	}

	/**
	 * HTML list of the links ('' when there are none).
	 *
	 * @param string $group legal|assistance; '' for all.
	 * @param string $lang  Language ('' = current).
	 */
	public function render( string $group, string $lang = '' ): string {
		$links = $this->links( $group, $lang );
		if ( ! $links ) {
			return '';
		}
		$items = '';
		foreach ( $links as $link ) {
			$items .= sprintf( '<li><a href="%1$s">%2$s</a></li>', esc_url( (string) $link['url'] ), esc_html( (string) $link['title'] ) );
		}
		$class = 'hive-legal-links' . ( in_array( $group, self::GROUPS, true ) ? ' hive-legal-links--' . $group : '' );
		return '<ul class="' . esc_attr( $class ) . '">' . $items . '</ul>';
	}

	/**
	 * The published document for a language, else the published default-language document.
	 *
	 * @param string $doc_id   Catalog ID.
	 * @param string $lang     Language.
	 * @param string $fallback Default language.
	 */
	private function published( string $doc_id, string $lang, string $fallback ): ?Document {
		foreach ( array_unique( array( $lang, $fallback ) ) as $candidate ) {
			$document = $this->repository->find( $doc_id, $candidate );
			if ( null !== $document && $document->is_published() ) {
				return $document;
			}
		}
		return null;
	}
}
