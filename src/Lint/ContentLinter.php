<?php
/**
 * Content rules shared by the editor (live checks) and the importer.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Lint;

use Hive\Compliance\Catalog\Entry;
use Hive\Compliance\Document\Markdown;
use Hive\Compliance\Hooks;
use Hive\Compliance\Placeholders\Registry;
use Hive\Compliance\Placeholders\Syntax;
use Hive\Compliance\Render\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a document's Markdown: no HTML or scripts, known placeholders, no literal URLs,
 * TODO(data) markers, required-element headings, allowed shortcodes and catalog lint rules.
 */
final class ContentLinter {

	/**
	 * Placeholder namespaces.
	 *
	 * @var Registry
	 */
	private Registry $placeholders;

	/**
	 * Constructor.
	 *
	 * @param Registry $placeholders Placeholder registry.
	 */
	public function __construct( Registry $placeholders ) {
		$this->placeholders = $placeholders;
	}

	/**
	 * Lints a document body.
	 *
	 * @param string $markdown Markdown body.
	 * @param Entry  $entry    Catalog entry.
	 * @param string $lang     Language code.
	 * @return Finding[]
	 */
	public function lint( string $markdown, Entry $entry, string $lang ): array {
		$findings = array_merge(
			$this->markup( $markdown ),
			$this->placeholders( $markdown ),
			$this->urls( $markdown ),
			$this->todos( $markdown ),
			$this->headings( $markdown, $entry ),
			$this->shortcodes( $markdown ),
			$this->catalog_rules( $markdown, $entry )
		);

		/** This filter is documented in src/Hooks.php */
		$findings = (array) apply_filters( Hooks::LINT_FINDINGS, $findings, $markdown, $entry, $lang );
		return array_values( array_filter( $findings, static fn( $finding ) => $finding instanceof Finding ) );
	}

	/**
	 * HTML tags, comments, scripts, javascript: URLs and on*= handlers (errors).
	 *
	 * @param string $markdown Markdown.
	 * @return Finding[]
	 */
	private function markup( string $markdown ): array {
		$findings = array();

		if ( preg_match_all( '~<script\b[^>]*>?~i', $markdown, $m ) ) {
			$findings[] = new Finding( 'script', Finding::ERROR, __( 'Script tags are not allowed.', 'hive-compliance' ), self::unique( $m[0] ) );
		}
		if ( preg_match_all( '~</?([a-zA-Z][a-zA-Z0-9-]*)(?=[\s>/])[^>]*>~', $markdown, $m ) ) {
			$findings[] = new Finding( 'html_tag', Finding::ERROR, __( 'HTML tags are not allowed: use Markdown only.', 'hive-compliance' ), self::unique( $m[0] ) );
		}
		if ( preg_match_all( '~<!--.*?(?:-->|$)~s', $markdown, $m ) ) {
			$findings[] = new Finding( 'html_comment', Finding::ERROR, __( 'HTML comments are not allowed inside a document.', 'hive-compliance' ), self::unique( $m[0] ) );
		}
		if ( preg_match_all( '~(?:java|vb)script\s*:|data\s*:\s*text/html~i', $markdown, $m ) ) {
			$findings[] = new Finding( 'javascript_url', Finding::ERROR, __( 'javascript: and similar URLs are not allowed.', 'hive-compliance' ), self::unique( $m[0] ) );
		}
		if ( preg_match_all( '~\bon[a-z]{3,}\s*=\s*["\']~i', $markdown, $m ) ) {
			$findings[] = new Finding( 'event_handler', Finding::ERROR, __( 'Event handler attributes (on…=) are not allowed.', 'hive-compliance' ), self::unique( $m[0] ) );
		}
		return $findings;
	}

	/**
	 * Malformed tokens, unknown namespaces (errors) and unknown keys (warnings).
	 *
	 * @param string $markdown Markdown.
	 * @return Finding[]
	 */
	private function placeholders( string $markdown ): array {
		$findings  = array();
		$malformed = Syntax::malformed( $markdown );
		if ( $malformed ) {
			$findings[] = new Finding( 'malformed_placeholder', Finding::ERROR, __( 'Malformed placeholders (use {{namespace.key}}, lowercase, no spaces).', 'hive-compliance' ), $malformed );
		}

		$unknown_namespace = array();
		$unknown_key       = array();
		foreach ( Syntax::tokens( $markdown ) as $token ) {
			list( $prefix ) = Syntax::split( $token );
			if ( ! $this->placeholders->has_namespace( $prefix ) ) {
				$unknown_namespace[] = '{{' . $token . '}}';
			} elseif ( ! $this->placeholders->is_known( $token ) ) {
				$unknown_key[] = '{{' . $token . '}}';
			}
		}
		if ( $unknown_namespace ) {
			$findings[] = new Finding( 'unknown_namespace', Finding::ERROR, __( 'Placeholders in an unknown namespace (allowed: business, store, links, custom).', 'hive-compliance' ), $unknown_namespace );
		}
		if ( $unknown_key ) {
			$findings[] = new Finding( 'unknown_placeholder', Finding::WARNING, __( 'Unknown placeholders: they will render as missing.', 'hive-compliance' ), $unknown_key );
		}
		return $findings;
	}

	/**
	 * Literal URLs (warning): links should use {{links.*}} or point to official authorities.
	 *
	 * @param string $markdown Markdown.
	 * @return Finding[]
	 */
	private function urls( string $markdown ): array {
		if ( ! preg_match_all( '~\b(?:https?://|www\.)[^\s<>()\[\]{}"\']+|\bmailto:[^\s<>()\[\]]+~i', $markdown, $m ) ) {
			return array();
		}
		return array( new Finding( 'literal_url', Finding::WARNING, __( 'Literal URLs: link to your own pages with {{links.*}}; keep only official authority URLs.', 'hive-compliance' ), self::unique( $m[0] ) ) );
	}

	/**
	 * TODO(data): markers (warning, listed).
	 *
	 * @param string $markdown Markdown.
	 * @return Finding[]
	 */
	private function todos( string $markdown ): array {
		if ( ! preg_match_all( '~TODO\(data\):\s*([^\n]*)~', $markdown, $m ) ) {
			return array();
		}
		return array( new Finding( 'todo_data', Finding::WARNING, __( 'Data still needed (TODO(data) markers).', 'hive-compliance' ), self::unique( array_map( 'trim', $m[1] ) ) ) );
	}

	/**
	 * Required elements without a heading (warning). A heading counts when it carries the
	 * element's {#key} anchor or its text equals the element's label. Not applied to fragments.
	 *
	 * @param string $markdown Markdown.
	 * @param Entry  $entry    Catalog entry.
	 * @return Finding[]
	 */
	private function headings( string $markdown, Entry $entry ): array {
		if ( $entry->is_fragment() ) {
			return array();
		}
		$missing = array();
		foreach ( $entry->required_elements as $element ) {
			if ( ! self::has_heading( $markdown, $element['key'], $element['label'] ) ) {
				$missing[] = $element['label'] . ' {#' . $element['key'] . '}';
			}
		}
		if ( ! $missing ) {
			return array();
		}
		return array( new Finding( 'missing_heading', Finding::WARNING, __( 'Required elements without a heading.', 'hive-compliance' ), $missing ) );
	}

	/**
	 * Whether a required element has a heading.
	 *
	 * @param string $markdown Markdown.
	 * @param string $key      Element key.
	 * @param string $label    Element label.
	 */
	public static function has_heading( string $markdown, string $key, string $label ): bool {
		foreach ( Markdown::headings( $markdown ) as $heading ) {
			if ( $heading['anchor'] === $key || Markdown::same_heading( $heading['text'], $label ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Registered shortcodes that are not allowed inside documents (warning: they print as text).
	 *
	 * @param string $markdown Markdown.
	 * @return Finding[]
	 */
	private function shortcodes( string $markdown ): array {
		if ( ! preg_match_all( '~\[([a-zA-Z_][\w-]*)(?=[\s\]/])[^\]]*\]~', $markdown, $m, PREG_SET_ORDER ) ) {
			return array();
		}
		/** This filter is documented in src/Hooks.php */
		$allowed  = (array) apply_filters( Hooks::ALLOWED_SHORTCODES, Renderer::DEFAULT_SHORTCODES );
		$rejected = array();
		foreach ( $m as $match ) {
			if ( shortcode_exists( $match[1] ) && ! in_array( $match[1], $allowed, true ) ) {
				$rejected[] = $match[0];
			}
		}
		if ( ! $rejected ) {
			return array();
		}
		return array( new Finding( 'shortcode_not_allowed', Finding::WARNING, __( 'Shortcodes that will print as plain text (only consent-plugin cookie lists run inside documents).', 'hive-compliance' ), self::unique( $rejected ) ) );
	}

	/**
	 * Lint rules defined in the catalog entry (e.g. links to the discontinued ODR platform).
	 *
	 * @param string $markdown Markdown.
	 * @param Entry  $entry    Catalog entry.
	 * @return Finding[]
	 */
	private function catalog_rules( string $markdown, Entry $entry ): array {
		$findings = array();
		foreach ( $entry->lint as $rule ) {
			if ( preg_match_all( '~' . str_replace( '~', '\~', (string) $rule['pattern'] ) . '~iu', $markdown, $m ) ) {
				$findings[] = new Finding( 'catalog_' . $rule['id'], 'error' === $rule['severity'] ? Finding::ERROR : Finding::WARNING, (string) $rule['message'], self::unique( $m[0] ) );
			}
		}
		return $findings;
	}

	/**
	 * Unique, trimmed, at most 20 items.
	 *
	 * @param string[] $items Items.
	 * @return string[]
	 */
	private static function unique( array $items ): array {
		$items = array_values( array_unique( array_filter( array_map( static fn( $item ) => trim( (string) $item ), $items ), 'strlen' ) ) );
		return array_slice( $items, 0, 20 );
	}
}
