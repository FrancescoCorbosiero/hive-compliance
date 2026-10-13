<?php
/**
 * The render pipeline: placeholders → Parsedown → wp_kses_post → output.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Render;

use Hive\Compliance\Catalog\Catalog;
use Hive\Compliance\Catalog\Entry;
use Hive\Compliance\Document\Document;
use Hive\Compliance\Document\Repository;
use Hive\Compliance\Hooks;
use Hive\Compliance\I18n\Languages;
use Hive\Compliance\Placeholders\Resolver;
use Hive\Compliance\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders documents and fragments for the front end, and arbitrary Markdown for admin previews.
 *
 * Front-end rules: internal documents never render (for anyone); only published documents
 * render for visitors; managers see drafts with a banner and [missing: …] markers.
 */
final class Renderer {

	/**
	 * Consent plugins' cookie-list shortcodes allowed inside documents (filterable).
	 */
	const DEFAULT_SHORTCODES = array( 'cmplz-document', 'cmplz-cookies', 'cmplz-manage-consent', 'cmplz-revoke-link', 'iub-cookie-policy', 'iub-pp', 'iub-tc', 'cookie_audit', 'cky_audit_table', 'cookiebot_declaration' );

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
	 * Placeholder resolver.
	 *
	 * @var Resolver
	 */
	private Resolver $resolver;

	/**
	 * Languages.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Markdown converter.
	 *
	 * @var MarkdownRenderer
	 */
	private MarkdownRenderer $markdown;

	/**
	 * Constructor.
	 *
	 * @param Catalog    $catalog    Catalog.
	 * @param Repository $repository Repository.
	 * @param Resolver   $resolver   Placeholder resolver.
	 * @param Languages  $languages  Languages.
	 */
	public function __construct( Catalog $catalog, Repository $repository, Resolver $resolver, Languages $languages ) {
		$this->catalog    = $catalog;
		$this->repository = $repository;
		$this->resolver   = $resolver;
		$this->languages  = $languages;
		$this->markdown   = new MarkdownRenderer();
	}

	/**
	 * Front-end rendering of a page or fragment document (shortcode, block).
	 *
	 * @param string $doc_id Catalog ID.
	 * @param string $lang   Language; '' uses the current language with default-language fallback.
	 */
	public function render_document( string $doc_id, string $lang = '' ): string {
		$entry = $this->catalog->get( $doc_id );
		if ( null === $entry || $entry->is_internal() ) {
			return '';
		}
		return $this->render_public( $entry, $lang, false );
	}

	/**
	 * Front-end rendering of a fragment (shortcode [hive_fragment], hive_fragment()).
	 *
	 * @param string $doc_id Catalog ID of a fragment.
	 * @param string $lang   Language; '' as in render_document().
	 */
	public function render_fragment( string $doc_id, string $lang = '' ): string {
		$entry = $this->catalog->get( $doc_id );
		if ( null === $entry || ! $entry->is_fragment() ) {
			return '';
		}
		return $this->render_public( $entry, $lang, true );
	}

	/**
	 * Runs the pipeline on any Markdown (editor preview, print view). No visibility checks:
	 * callers must be admin screens guarded by the capability.
	 *
	 * @param string $markdown Markdown.
	 * @param Entry  $entry    Catalog entry.
	 * @param string $lang     Language.
	 * @param bool   $markers  Show [missing: …] markers.
	 * @return array{html: string, missing: string[]}
	 */
	public function render_markdown( string $markdown, Entry $entry, string $lang, bool $markers = true ): array {
		$document = new Document( 0, $entry->id, $lang, $markdown, 0, Document::STATUS_DRAFT, '', '', array(), array() );
		return $this->pipeline( $document, $entry, $markers, $entry->is_fragment() );
	}

	/**
	 * Whether the current user sees drafts and missing-value markers.
	 */
	public function viewer_is_manager(): bool {
		return current_user_can( Plugin::CAPABILITY );
	}

	/**
	 * Shared front-end logic for pages and fragments.
	 *
	 * @param Entry  $entry  Catalog entry (never internal).
	 * @param string $lang   Requested language.
	 * @param bool   $inline Fragment output.
	 */
	private function render_public( Entry $entry, string $lang, bool $inline ): string {
		$manager  = $this->viewer_is_manager();
		$document = $this->find_document( $entry->id, $lang );

		$visible = null !== $document && $document->is_published();
		if ( null !== $document ) {
			/** This filter is documented in src/Hooks.php */
			$visible = (bool) apply_filters( Hooks::DOCUMENT_VISIBLE, $visible, $document, $entry );
		}

		if ( ! $visible && ! $manager ) {
			return '';
		}
		if ( null === $document || $document->is_empty() ) {
			return $inline ? '' : $this->notice( __( 'This compliance document has no content yet. Only managers see this notice.', 'hive-compliance' ) );
		}

		$html = $this->pipeline( $document, $entry, $manager, $inline )['html'];
		if ( $manager ) {
			wp_enqueue_style( 'hive-compliance-front', HIVE_COMPLIANCE_URL . 'assets/css/front.css', array(), HIVE_COMPLIANCE_VERSION );
		}
		if ( ! $visible && ! $inline ) {
			$html = $this->notice( __( 'Draft: not visible to visitors. Only managers see this document.', 'hive-compliance' ) ) . $html;
		}
		if ( $inline ) {
			return $html;
		}
		return '<div class="hive-document hive-document--' . esc_attr( $entry->id ) . '" lang="' . esc_attr( $document->lang ) . '">' . $html . '</div>';
	}

	/**
	 * The document to show: the requested language, or for the current language the
	 * default-language document when no published translation exists.
	 *
	 * @param string $doc_id Catalog ID.
	 * @param string $lang   Requested language ('' = current).
	 */
	private function find_document( string $doc_id, string $lang ): ?Document {
		if ( '' !== $lang ) {
			return $this->repository->find( $doc_id, $lang );
		}
		$current  = $this->languages->current();
		$document = $this->repository->find( $doc_id, $current );
		if ( null !== $document && $document->is_published() ) {
			return $document;
		}
		$default  = $this->languages->default_language();
		$fallback = $current !== $default ? $this->repository->find( $doc_id, $default ) : null;
		return null !== $fallback && $fallback->is_published() ? $fallback : $document;
	}

	/**
	 * The pipeline itself.
	 *
	 * @param Document $document Document (its Markdown is rendered).
	 * @param Entry    $entry    Catalog entry.
	 * @param bool     $markers  Show missing-value markers.
	 * @param bool     $inline   Unwrap a single paragraph.
	 * @return array{html: string, missing: string[]}
	 */
	private function pipeline( Document $document, Entry $entry, bool $markers, bool $inline ): array {
		$context = array(
			'lang'   => $document->lang,
			'doc_id' => $document->doc_id,
			'entry'  => $entry,
		);

		/** This filter is documented in src/Hooks.php */
		$markdown   = (string) apply_filters( Hooks::RENDER_MARKDOWN, $document->markdown, $document, $context );
		$resolution = $this->resolver->resolve( $markdown, $context );
		$html       = $this->markdown->to_html( $resolution->markdown, $inline );
		$html       = Resolver::finalize( $html, $resolution->missing, $markers );
		$html       = wp_kses_post( $html );
		$html       = $this->run_allowed_shortcodes( $html );

		/** This filter is documented in src/Hooks.php */
		$html = (string) apply_filters( Hooks::RENDERED_HTML, $html, $document, $context );

		return array(
			'html'    => $html,
			'missing' => $resolution->missing,
		);
	}

	/**
	 * Runs the allow-listed shortcodes and neutralises every other registered shortcode,
	 * so that the_content's do_shortcode cannot run them either. Only self-closing
	 * shortcodes are supported inside documents.
	 *
	 * @param string $html Sanitised HTML.
	 */
	private function run_allowed_shortcodes( string $html ): string {
		/** This filter is documented in src/Hooks.php */
		$allowed = array_values( array_filter( (array) apply_filters( Hooks::ALLOWED_SHORTCODES, self::DEFAULT_SHORTCODES ), 'shortcode_exists' ) );

		$parts = preg_split( '/(\[\/?[a-zA-Z_][\w-]*(?:[^\[\]]*)\])/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		foreach ( (array) $parts as $index => $part ) {
			if ( 1 !== $index % 2 ) {
				$out .= $part;
				continue;
			}
			$tag = (string) preg_replace( '/^\[\/?([a-zA-Z_][\w-]*).*$/s', '$1', $part );
			if ( in_array( $tag, $allowed, true ) ) {
				$out .= do_shortcode( $part );
			} elseif ( shortcode_exists( $tag ) ) {
				$out .= '&#91;' . substr( $part, 1 );
			} else {
				$out .= $part;
				// Not a registered shortcode: plain text, e.g. the [missing: …] marker.
			}
		}
		return $out;
	}

	/**
	 * Notice shown to managers only.
	 *
	 * @param string $message Message.
	 */
	private function notice( string $message ): string {
		wp_enqueue_style( 'hive-compliance-front', HIVE_COMPLIANCE_URL . 'assets/css/front.css', array(), HIVE_COMPLIANCE_VERSION );
		return '<p class="hive-document-notice">' . esc_html( $message ) . '</p>';
	}
}
