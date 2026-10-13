<?php
/**
 * Reads document blocks from an LLM bundle.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Import;

use Hive\Compliance\Export\BundleFormat;

defined( 'ABSPATH' ) || exit;

/**
 * Line-based parser. Only whole-line delimiters count; the instruction header and the
 * read-only context section are ignored entirely.
 */
final class BundleParser {

	/**
	 * Parses a bundle.
	 *
	 * @param string $bundle Bundle text.
	 * @return array{blocks: ParsedBlock[], errors: string[]} Blocks and bundle-level structure errors.
	 */
	public function parse( string $bundle ): array {
		if ( 0 === strncmp( $bundle, "\xEF\xBB\xBF", 3 ) ) {
			$bundle = substr( $bundle, 3 );
		}
		$lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $bundle ) );

		$blocks     = array();
		$errors     = array();
		$in_context = false;
		$current    = null;

		foreach ( $lines as $index => $line ) {
			$number  = $index + 1;
			$trimmed = rtrim( $line );

			if ( null === $current && BundleFormat::CONTEXT_BEGIN === $trimmed ) {
				$in_context = true;
				continue;
			}
			if ( $in_context ) {
				$in_context = BundleFormat::CONTEXT_END !== $trimmed;
				continue;
			}

			if ( preg_match( BundleFormat::BEGIN_PATTERN, $trimmed, $match ) ) {
				if ( null !== $current ) {
					$current['problems'][] = 'missing_end';
					$blocks[]              = $this->finish( $current );
				}
				$current = array(
					'attributes' => BundleFormat::attributes( $match[1] ),
					'line'       => $number,
					'lines'      => array(),
					'raw'        => array( $line ),
					'problems'   => array(),
				);
				continue;
			}

			if ( preg_match( BundleFormat::END_PATTERN, $trimmed, $match ) ) {
				if ( null === $current ) {
					/* translators: %d: line number. */
					$errors[] = sprintf( __( 'Line %d: end delimiter without a matching begin delimiter.', 'hive-compliance' ), $number );
					continue;
				}
				$current['raw'][]  = $line;
				$current['end_id'] = BundleFormat::attributes( $match[1] )['id'] ?? '';
				$blocks[]          = $this->finish( $current );
				$current           = null;
				continue;
			}

			if ( 0 === strpos( ltrim( $line ), '<!-- hive:' ) ) {
				/* translators: %d: line number. */
				$errors[] = sprintf( __( 'Line %d: malformed delimiter.', 'hive-compliance' ), $number );
				if ( null !== $current ) {
					$current['problems'][] = 'malformed_delimiter';
				}
			}

			if ( null !== $current ) {
				$current['lines'][] = $line;
				$current['raw'][]   = $line;
			}
		}//end foreach

		if ( null !== $current ) {
			$current['problems'][] = 'missing_end';
			$blocks[]              = $this->finish( $current );
		}

		return array(
			'blocks' => $blocks,
			'errors' => $errors,
		);
	}

	/**
	 * Splits a block's lines into front matter and body.
	 *
	 * @param array<string, mixed> $state Collected block state.
	 */
	private function finish( array $state ): ParsedBlock {
		$lines    = $state['lines'];
		$problems = $state['problems'];
		$front    = array();
		$body     = $lines;
		$has      = false;

		if ( isset( $lines[0] ) && '---' === rtrim( $lines[0] ) ) {
			$close = null;
			$count = count( $lines );
			for ( $i = 1; $i < $count; $i++ ) {
				if ( '---' === rtrim( $lines[ $i ] ) ) {
					$close = $i;
					break;
				}
			}
			if ( null !== $close ) {
				$has   = true;
				$front = $this->front_matter( array_slice( $lines, 1, $close - 1 ) );
				$body  = array_slice( $lines, $close + 1 );
			}
		}
		if ( ! $has ) {
			$problems[] = 'front_matter_missing';
		}

		$end_id = (string) ( $state['end_id'] ?? '' );
		if ( isset( $state['end_id'] ) && (string) ( $state['attributes']['id'] ?? '' ) !== $end_id ) {
			$problems[] = 'end_id_mismatch';
		}

		return new ParsedBlock(
			$state['attributes'],
			$end_id,
			$front,
			$has,
			implode( "\n", $body ),
			(int) $state['line'],
			array_values( array_unique( $problems ) ),
			implode( "\n", $state['raw'] )
		);
	}

	/**
	 * Reads "key: value" lines (one-line YAML scalars and a flow list for required_elements).
	 *
	 * @param string[] $lines Front matter lines.
	 * @return array<string, mixed>
	 */
	private function front_matter( array $lines ): array {
		$out = array();
		foreach ( $lines as $line ) {
			if ( ! preg_match( '/^([a-z_]+):\s?(.*)$/', rtrim( $line ), $match ) ) {
				continue;
			}
			$value = $match[2];
			if ( 'required_elements' === $match[1] ) {
				preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $value, $items );
				$out['required_elements'] = array_map( static fn( $item ) => BundleFormat::unquote( '"' . $item . '"' ), $items[1] );
				continue;
			}
			$out[ $match[1] ] = BundleFormat::unquote( $value );
		}
		return $out;
	}
}
