<?php
/**
 * Bindings between public documents and WordPress pages (option hive_page_bindings).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Render;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Stores doc_id => lang => page ID and resolves the links.* URLs.
 */
final class PageBindings {

	const OPTION = 'hive_page_bindings';

	/**
	 * All bindings.
	 *
	 * @return array<string, array<string, int>>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		$clean  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $doc_id => $languages ) {
			foreach ( is_array( $languages ) ? $languages : array() as $lang => $page_id ) {
				if ( absint( $page_id ) > 0 ) {
					$clean[ (string) $doc_id ][ (string) $lang ] = absint( $page_id );
				}
			}
		}
		return $clean;
	}

	/**
	 * Page bound to a document language (0 when none or when the page no longer exists).
	 *
	 * @param string $doc_id Catalog ID.
	 * @param string $lang   Language code.
	 */
	public function get( string $doc_id, string $lang ): int {
		$page_id = $this->all()[ $doc_id ][ $lang ] ?? 0;
		return $page_id && $this->is_usable_page( $page_id ) ? $page_id : 0;
	}

	/**
	 * Binds a page.
	 *
	 * @param string $doc_id  Catalog ID.
	 * @param string $lang    Language code.
	 * @param int    $page_id Page ID.
	 */
	public function set( string $doc_id, string $lang, int $page_id ): void {
		$all                     = $this->all();
		$all[ $doc_id ][ $lang ] = $page_id;
		update_option( self::OPTION, $all, true );
	}

	/**
	 * Removes a binding.
	 *
	 * @param string $doc_id Catalog ID.
	 * @param string $lang   Language code.
	 */
	public function remove( string $doc_id, string $lang ): void {
		$all = $this->all();
		unset( $all[ $doc_id ][ $lang ] );
		if ( empty( $all[ $doc_id ] ) ) {
			unset( $all[ $doc_id ] );
		}
		update_option( self::OPTION, $all, true );
	}

	/**
	 * Page for a language, falling back to the default language, then to any bound language.
	 *
	 * @param string $doc_id           Catalog ID.
	 * @param string $lang             Requested language.
	 * @param string $default_language Default language.
	 */
	public function resolve( string $doc_id, string $lang, string $default_language ): int {
		foreach ( array_unique( array( $lang, $default_language ) ) as $candidate ) {
			$page_id = $this->get( $doc_id, $candidate );
			if ( $page_id ) {
				return $page_id;
			}
		}
		foreach ( array_keys( $this->all()[ $doc_id ] ?? array() ) as $candidate ) {
			$page_id = $this->get( $doc_id, (string) $candidate );
			if ( $page_id ) {
				return $page_id;
			}
		}
		return 0;
	}

	/**
	 * Public URL of the bound page, or '' when there is none or it is not published
	 * (an unpublished page would be a dead link for visitors).
	 *
	 * @param string $doc_id           Catalog ID.
	 * @param string $lang             Language code.
	 * @param string $default_language Default language.
	 */
	public function url( string $doc_id, string $lang, string $default_language ): string {
		$page_id = $this->resolve( $doc_id, $lang, $default_language );
		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}
		return (string) get_permalink( $page_id );
	}

	/**
	 * Binding of a page, if any.
	 *
	 * @param int $page_id Page ID.
	 * @return array{doc_id: string, lang: string}|null
	 */
	public function binding_for_page( int $page_id ): ?array {
		foreach ( $this->all() as $doc_id => $languages ) {
			foreach ( $languages as $lang => $bound ) {
				if ( $bound === $page_id ) {
					return array(
						'doc_id' => (string) $doc_id,
						'lang'   => (string) $lang,
					);
				}
			}
		}
		return null;
	}

	/**
	 * Whether a page exists and is not trashed.
	 *
	 * @param int $page_id Page ID.
	 */
	private function is_usable_page( int $page_id ): bool {
		$page = get_post( $page_id );
		return $page instanceof WP_Post && 'page' === $page->post_type && 'trash' !== $page->post_status;
	}
}
