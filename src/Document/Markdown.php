<?php
/**
 * Markdown helpers: normalisation, hashing, headings.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Document;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions on Markdown strings. No WordPress dependency.
 */
final class Markdown {

	/**
	 * Canonical form used for storage, hashing and comparison.
	 *
	 * Strips a UTF-8 BOM and NUL bytes, converts line endings to LF, repairs invalid UTF-8,
	 * drops leading blank lines and trailing whitespace. Idempotent.
	 *
	 * @param string $markdown Raw Markdown.
	 */
	public static function normalize( string $markdown ): string {
		if ( 0 === strncmp( $markdown, "\xEF\xBB\xBF", 3 ) ) {
			$markdown = substr( $markdown, 3 );
		}
		$markdown = str_replace( array( "\r\n", "\r", "\0" ), array( "\n", "\n", '' ), $markdown );
		if ( ! mb_check_encoding( $markdown, 'UTF-8' ) ) {
			$markdown = mb_scrub( $markdown, 'UTF-8' );
		}
		$markdown = (string) preg_replace( '/\A(?:[ \t]*\n)+/', '', $markdown );
		return rtrim( $markdown );
	}

	/**
	 * Content hash in the bundle's "sha256:<hex>" format.
	 *
	 * @param string $markdown Normalised Markdown.
	 */
	public static function hash( string $markdown ): string {
		return 'sha256:' . hash( 'sha256', $markdown );
	}

	/**
	 * ATX headings of a document, ignoring fenced code blocks.
	 *
	 * @param string $markdown Markdown.
	 * @return array<int, array{level: int, text: string, anchor: string, line: int}>
	 */
	public static function headings( string $markdown ): array {
		$headings = array();
		$in_fence = false;
		foreach ( explode( "\n", $markdown ) as $index => $line ) {
			if ( preg_match( '/^\s{0,3}(```|~~~)/', $line ) ) {
				$in_fence = ! $in_fence;
				continue;
			}
			if ( ! $in_fence && preg_match( '/^\s{0,3}(#{1,6})[ \t]+(.+?)[ \t#]*$/u', $line, $match ) ) {
				$headings[] = self::heading( strlen( $match[1] ), $match[2], $index + 1 );
			}
		}
		return $headings;
	}

	/**
	 * Splits a heading text into its text and its {#anchor} attribute.
	 *
	 * @param int    $level Heading level.
	 * @param string $text  Heading text, possibly ending with "{#anchor .class}".
	 * @param int    $line  1-based line number.
	 * @return array{level: int, text: string, anchor: string, line: int}
	 */
	private static function heading( int $level, string $text, int $line ): array {
		$anchor = '';
		if ( preg_match( '/\s*\{#([-\w]+)(?:[ ]+[.#][-\w]+)*[ ]*\}\s*$/u', $text, $attr ) ) {
			$anchor = $attr[1];
			$text   = rtrim( trim( substr( $text, 0, -strlen( $attr[0] ) ) ), " \t#" );
		}
		return array(
			'level'  => $level,
			'text'   => $text,
			'anchor' => $anchor,
			'line'   => $line,
		);
	}

	/**
	 * Compares two heading texts loosely (case, whitespace and trailing punctuation insensitive).
	 *
	 * @param string $a First heading.
	 * @param string $b Second heading.
	 */
	public static function same_heading( string $a, string $b ): bool {
		$clean = static function ( string $text ): string {
			$text = mb_strtolower( trim( $text ), 'UTF-8' );
			$text = (string) preg_replace( '/[\s]+/u', ' ', $text );
			return rtrim( $text, ' .:;' );
		};
		return $clean( $a ) === $clean( $b );
	}
}
