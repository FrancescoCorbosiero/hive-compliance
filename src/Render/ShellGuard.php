<?php
/**
 * Keeps bound pages as thin shells: only the hive/document block (or the shortcode).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Render;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Detects bound pages with extra content and warns in the editors.
 */
final class ShellGuard {

	const PROBLEM_EXTRA_CONTENT  = 'extra_content';
	const PROBLEM_MISSING_BLOCK  = 'missing_block';
	const PROBLEM_OTHER_DOCUMENT = 'other_document';

	/**
	 * Page bindings.
	 *
	 * @var PageBindings
	 */
	private PageBindings $bindings;

	/**
	 * Constructor.
	 *
	 * @param PageBindings $bindings Page bindings.
	 */
	public function __construct( PageBindings $bindings ) {
		$this->bindings = $bindings;
	}

	/**
	 * Hooks the editor warnings.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_guard' ) );
		add_action( 'admin_notices', array( $this, 'classic_editor_notice' ) );
	}

	/**
	 * Problems of a page's content for a document (empty array = thin shell).
	 *
	 * @param string $content Page content.
	 * @param string $doc_id  Bound document ID.
	 * @return string[] Problem codes.
	 */
	public static function inspect( string $content, string $doc_id ): array {
		$problems = array();
		$shells   = 0;
		foreach ( parse_blocks( $content ) as $block ) {
			foreach ( self::classify( $block, $doc_id ) as $kind ) {
				if ( 'shell' === $kind ) {
					++$shells;
				} else {
					$problems[] = 'other_document' === $kind ? self::PROBLEM_OTHER_DOCUMENT : self::PROBLEM_EXTRA_CONTENT;
				}
			}
		}
		if ( 0 === $shells && ! in_array( self::PROBLEM_OTHER_DOCUMENT, $problems, true ) ) {
			$problems[] = self::PROBLEM_MISSING_BLOCK;
		}
		if ( $shells > 1 ) {
			$problems[] = self::PROBLEM_EXTRA_CONTENT;
		}
		return array_values( array_unique( $problems ) );
	}

	/**
	 * Problems of every bound page.
	 *
	 * @return array<string, array<string, array{page_id: int, problems: string[]}>> doc_id => lang => result.
	 */
	public function audit(): array {
		$out = array();
		foreach ( $this->bindings->all() as $doc_id => $languages ) {
			foreach ( $languages as $lang => $page_id ) {
				$page = get_post( $page_id );
				if ( ! $page instanceof WP_Post || 'trash' === $page->post_status ) {
					continue;
				}
				$out[ $doc_id ][ $lang ] = array(
					'page_id'  => (int) $page_id,
					'problems' => self::inspect( (string) $page->post_content, (string) $doc_id ),
				);
			}
		}
		return $out;
	}

	/**
	 * Block editor: live warning while editing a bound page.
	 */
	public function enqueue_block_editor_guard(): void {
		$post    = get_post();
		$binding = $post instanceof WP_Post && 'page' === $post->post_type ? $this->bindings->binding_for_page( (int) $post->ID ) : null;
		if ( null === $binding ) {
			return;
		}
		wp_enqueue_script( 'hive-compliance-page-guard', HIVE_COMPLIANCE_URL . 'assets/js/page-guard.js', array( 'wp-data', 'wp-notices', 'wp-block-editor' ), HIVE_COMPLIANCE_VERSION, true );
		wp_add_inline_script(
			'hive-compliance-page-guard',
			'window.hiveCompliancePageGuard = ' . wp_json_encode(
				array(
					'docId'   => $binding['doc_id'],
					'message' => $this->message( $binding['doc_id'] ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Classic editor: warning on the edit screen of a bound page with extra content.
	 */
	public function classic_editor_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || 'page' !== $screen->post_type || ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) ) {
			return;
		}
		$post    = get_post();
		$binding = $post instanceof WP_Post ? $this->bindings->binding_for_page( (int) $post->ID ) : null;
		if ( null === $binding || array() === self::inspect( (string) $post->post_content, $binding['doc_id'] ) ) {
			return;
		}
		printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $this->message( $binding['doc_id'] ) ) );
	}

	/**
	 * Classifies one top-level block: what it contributes (shell, other_document, content).
	 *
	 * @param array<string, mixed> $block  Parsed block.
	 * @param string               $doc_id Bound document ID.
	 * @return string[] Kinds found; empty for blank blocks.
	 */
	private static function classify( array $block, string $doc_id ): array {
		$name = $block['blockName'] ?? null;

		if ( 'hive/document' === $name ) {
			return array( ( (string) ( $block['attrs']['docId'] ?? '' ) ) === $doc_id ? 'shell' : 'other_document' );
		}
		if ( null !== $name && ! in_array( $name, array( 'core/shortcode', 'core/paragraph', 'core/freeform' ), true ) ) {
			return array( 'content' );
		}

		// Classic content, paragraphs and shortcode blocks: look for [hive_document] and any other text.
		$text  = html_entity_decode( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ), ENT_QUOTES, 'UTF-8' );
		$kinds = array();
		$rest  = (string) preg_replace_callback(
			'/\[hive_document\b([^\]]*)\]/',
			static function ( array $found ) use ( $doc_id, &$kinds ): string {
				$atts    = shortcode_parse_atts( $found[1] );
				$kinds[] = ( is_array( $atts ) ? (string) ( $atts['id'] ?? '' ) : '' ) === $doc_id ? 'shell' : 'other_document';
				return '';
			},
			$text
		);
		if ( '' !== trim( str_replace( "\u{00A0}", ' ', $rest ) ) ) {
			$kinds[] = 'content';
		}
		return $kinds;
	}

	/**
	 * Warning text.
	 *
	 * @param string $doc_id Document ID.
	 */
	private function message( string $doc_id ): string {
		/* translators: %s: document ID. */
		return sprintf( __( 'This page is bound to the compliance document "%s". Keep only the Compliance document block here and edit the text in Hive Compliance → Documents: anything else on the page creates a second source of truth.', 'hive-compliance' ), $doc_id );
	}
}
