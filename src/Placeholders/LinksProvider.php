<?php
/**
 * Placeholders of the links.* namespace: URLs of the pages bound to public documents.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Placeholders;

use Hive\Compliance\Catalog\Catalog;
use Hive\Compliance\I18n\Languages;
use Hive\Compliance\Render\PageBindings;

defined( 'ABSPATH' ) || exit;

/**
 * {{links.<page document id>}} resolves to the bound, published page in the render language.
 */
final class LinksProvider implements Provider {

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
	 * Namespace label.
	 */
	public function label(): string {
		return __( 'Links to document pages', 'hive-compliance' );
	}

	/**
	 * One key per page-type document.
	 *
	 * @return array<string, string>
	 */
	public function keys(): array {
		$lang = Languages::locale_to_code( (string) get_user_locale() );
		$keys = array();
		foreach ( $this->catalog->pages() as $id => $entry ) {
			$keys[ $id ] = $entry->title( $lang );
		}
		return $keys;
	}

	/**
	 * Whether the key is a page document.
	 *
	 * @param string $key Key.
	 */
	public function is_known( string $key ): bool {
		$entry = $this->catalog->get( $key );
		return null !== $entry && $entry->is_page();
	}

	/**
	 * URL of the bound page.
	 *
	 * @param string               $key     Document ID.
	 * @param array<string, mixed> $context Render context (lang).
	 */
	public function value( string $key, array $context ): ?string {
		$default = $this->languages->default_language();
		$lang    = (string) ( $context['lang'] ?? $default );
		return $this->bindings->url( $key, $lang, $default );
	}

	/**
	 * Always URLs.
	 *
	 * @param string $key Key.
	 */
	public function is_url( string $key ): bool {
		return true;
	}
}
