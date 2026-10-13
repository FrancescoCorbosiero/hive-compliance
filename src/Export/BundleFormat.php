<?php
/**
 * The LLM bundle format, shared by the exporter and the parser.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Serialisation of the bundle. The parser strips exactly the framing written here, so
 * serialize(parse(bundle)) === bundle and parsed bodies equal the stored Markdown.
 *
 * Block layout:
 *
 *     <!-- hive:begin id="…" lang="…" type="…" version="…" base_hash="sha256:…" -->
 *     ---
 *     title: "…"
 *     last_reviewed: 2026-10-13
 *     effective_date: 2026-07-09
 *     changelog_note: ""
 *     required_elements: ["key: label [legal ref]", …]
 *     ---
 *     <markdown body>
 *     <!-- hive:end id="…" -->
 */
final class BundleFormat {

	/**
	 * Instruction header, verbatim.
	 */
	const HEADER = "# hive-compliance bundle — instructions for the AI\n"
		. "You are updating legal/compliance documents for the shop described below.\n"
		. "Rules:\n"
		. "1. Keep every `<!-- hive:begin … -->` / `<!-- hive:end … -->` delimiter exactly as is. Do not add, remove, rename or reorder them.\n"
		. "2. Keep every `{{placeholder}}` token unchanged. Never replace a placeholder with a literal value. Do not invent new placeholders; if you need data that has no placeholder, write `TODO(data): <what is needed>`.\n"
		. "3. Edit only the Markdown between the YAML front matter and the end delimiter, plus the `changelog_note` field.\n"
		. "4. For every document you change, set `changelog_note` to one line describing what changed and why (cite the rule).\n"
		. "5. Use the REQUIRED ELEMENTS checklist of each document; every element must be covered.\n"
		. "6. No HTML, no scripts, no links except `{{links.*}}` placeholders and official authority URLs.\n"
		. '7. Return the complete bundle, including documents you did not change.';

	const CONTEXT_BEGIN = '<!-- hive:context begin -->';
	const CONTEXT_END   = '<!-- hive:context end -->';

	/**
	 * Begin delimiter (a whole line).
	 */
	const BEGIN_PATTERN = '/^<!-- hive:begin ((?:[a-z_]+="[^"]*"\s*)+)-->$/';

	/**
	 * End delimiter (a whole line).
	 */
	const END_PATTERN = '/^<!-- hive:end ((?:[a-z_]+="[^"]*"\s*)+)-->$/';

	/**
	 * Front matter fields, in order.
	 */
	const FRONT_MATTER_FIELDS = array( 'title', 'last_reviewed', 'effective_date', 'changelog_note', 'required_elements' );

	/**
	 * Begin delimiter line.
	 *
	 * @param string $id      Catalog ID.
	 * @param string $lang    Language code.
	 * @param string $type    page|fragment|internal.
	 * @param int    $version Current version.
	 * @param string $hash    sha256 hash of the current Markdown.
	 */
	public static function begin_line( string $id, string $lang, string $type, int $version, string $hash ): string {
		return sprintf( '<!-- hive:begin id="%s" lang="%s" type="%s" version="%d" base_hash="%s" -->', $id, $lang, $type, $version, $hash );
	}

	/**
	 * End delimiter line.
	 *
	 * @param string $id Catalog ID.
	 */
	public static function end_line( string $id ): string {
		return sprintf( '<!-- hive:end id="%s" -->', $id );
	}

	/**
	 * One document block.
	 *
	 * @param array{id: string, lang: string, type: string, version: int, base_hash: string, front_matter: array<string, mixed>, body: string} $block Block data.
	 */
	public static function block( array $block ): string {
		return self::begin_line( $block['id'], $block['lang'], $block['type'], (int) $block['version'], $block['base_hash'] ) . "\n"
			. self::front_matter( $block['front_matter'] )
			. $block['body'] . "\n"
			. self::end_line( $block['id'] );
	}

	/**
	 * Front matter, including the --- fences and a trailing newline.
	 *
	 * @param array<string, mixed> $fields Fields (strings; required_elements is a list of strings).
	 */
	public static function front_matter( array $fields ): string {
		$lines = array( '---' );
		foreach ( self::FRONT_MATTER_FIELDS as $name ) {
			$value = $fields[ $name ] ?? '';
			if ( is_array( $value ) ) {
				$lines[] = $name . ': [' . implode( ', ', array_map( array( self::class, 'quote' ), array_map( 'strval', $value ) ) ) . ']';
			} elseif ( in_array( $name, array( 'last_reviewed', 'effective_date' ), true ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ) {
				$lines[] = $name . ': ' . $value;
			} else {
				$lines[] = $name . ': ' . self::quote( (string) $value );
			}
		}
		$lines[] = '---';
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * YAML double-quoted scalar on one line.
	 *
	 * @param string $value Value.
	 */
	public static function quote( string $value ): string {
		$value = (string) preg_replace( '/\s*\R\s*/u', ' ', $value );
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
	}

	/**
	 * Reads a one-line YAML scalar (double-quoted, single-quoted or plain).
	 *
	 * @param string $raw Raw value after "key:".
	 */
	public static function unquote( string $raw ): string {
		$raw = trim( $raw );
		if ( strlen( $raw ) >= 2 && '"' === $raw[0] && '"' === substr( $raw, -1 ) ) {
			return (string) preg_replace_callback(
				'/\\\\(["\\\\nt\/])/',
				static fn( array $m ) => array(
					'"'  => '"',
					'\\' => '\\',
					'n'  => ' ',
					't'  => ' ',
					'/'  => '/',
				)[ $m[1] ],
				substr( $raw, 1, -1 )
			);
		}
		if ( strlen( $raw ) >= 2 && "'" === $raw[0] && "'" === substr( $raw, -1 ) ) {
			return str_replace( "''", "'", substr( $raw, 1, -1 ) );
		}
		return $raw;
	}

	/**
	 * Attributes of a delimiter ("id" => "…").
	 *
	 * @param string $attributes Attribute string.
	 * @return array<string, string>
	 */
	public static function attributes( string $attributes ): array {
		preg_match_all( '/([a-z_]+)="([^"]*)"/', $attributes, $matches, PREG_SET_ORDER );
		$out = array();
		foreach ( $matches as $match ) {
			$out[ $match[1] ] = $match[2];
		}
		return $out;
	}

	/**
	 * Makes a context value safe: one line and no delimiter-like comments.
	 *
	 * @param string $value Value.
	 */
	public static function context_value( string $value ): string {
		$value = trim( (string) preg_replace( '/\s*\R\s*/u', ' ', $value ) );
		return str_replace( array( '<!--', '-->' ), array( '< !--', '-- >' ), $value );
	}
}
