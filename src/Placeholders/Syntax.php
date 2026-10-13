<?php
/**
 * Placeholder syntax: {{namespace.key}}.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Placeholders;

defined( 'ABSPATH' ) || exit;

/**
 * Finds well-formed and malformed placeholder tokens.
 */
final class Syntax {

	/**
	 * A well-formed token: {{namespace.key}}, lowercase, no spaces.
	 */
	const TOKEN = '/\{\{([a-z][a-z0-9_]*)\.([a-z0-9_]+)\}\}/';

	/**
	 * Unique well-formed tokens ("ns.key"), in order of appearance.
	 *
	 * @param string $markdown Markdown.
	 * @return string[]
	 */
	public static function tokens( string $markdown ): array {
		preg_match_all( self::TOKEN, $markdown, $matches, PREG_SET_ORDER );
		$tokens = array();
		foreach ( $matches as $match ) {
			$tokens[ $match[1] . '.' . $match[2] ] = true;
		}
		return array_keys( $tokens );
	}

	/**
	 * Placeholder-like text that is not a well-formed token ("{{ Business.VAT }}", "{{vat_id}}",
	 * an unclosed "{{business.email"), which would print literally.
	 *
	 * @param string $markdown Markdown.
	 * @return string[]
	 */
	public static function malformed( string $markdown ): array {
		$found = array();
		foreach ( explode( "\n", $markdown ) as $line ) {
			$offset = 0;
			while ( false !== ( $start = strpos( $line, '{{', $offset ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$end = strpos( $line, '}}', $start + 2 );
				if ( false === $end ) {
					$found[] = substr( $line, $start, 40 );
					break;
				}
				$candidate = substr( $line, $start, $end - $start + 2 );
				if ( ! preg_match( '/^' . trim( self::TOKEN, '/' ) . '$/', $candidate ) ) {
					$found[] = $candidate;
				}
				$offset = $end + 2;
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Splits "ns.key".
	 *
	 * @param string $token Token without braces.
	 * @return array{0: string, 1: string}
	 */
	public static function split( string $token ): array {
		$parts = explode( '.', $token, 2 );
		return array( $parts[0], $parts[1] ?? '' );
	}
}
