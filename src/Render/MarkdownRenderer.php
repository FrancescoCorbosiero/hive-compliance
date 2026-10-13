<?php
/**
 * Markdown to HTML with ParsedownExtra in safe mode.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Render;

use Hive\Compliance\Lib\ParsedownExtra;

defined( 'ABSPATH' ) || exit;

/**
 * Safe mode is on and raw HTML is escaped: documents are Markdown only.
 */
final class MarkdownRenderer {

	/**
	 * Converts Markdown to HTML.
	 *
	 * @param string $markdown Markdown (placeholders already resolved).
	 * @param bool   $inline   Unwrap a single paragraph (fragments used as labels).
	 */
	public function to_html( string $markdown, bool $inline = false ): string {
		$parser = new ParsedownExtra();
		$parser->setSafeMode( true );
		$parser->setMarkupEscaped( true );
		$parser->setBreaksEnabled( false );

		$html = (string) $parser->text( $markdown );

		// Safe mode percent-encodes the colon of footnote anchors (#fn:1); restore it so they work.
		$html = (string) preg_replace( '/href="#(fn|fnref\d*)%3A/', 'href="#$1:', $html );

		if ( $inline && preg_match( '~^<p>((?:(?!</?p>).)*)</p>$~s', trim( $html ), $match ) ) {
			$html = $match[1];
		}
		return trim( $html );
	}
}
