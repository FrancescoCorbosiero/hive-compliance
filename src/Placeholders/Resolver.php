<?php
/**
 * Resolves {{namespace.key}} placeholders at render time.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Placeholders;

use Hive\Compliance\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Values are substituted before Markdown parsing (so {{links.*}} work inside Markdown links),
 * escaped so that business data cannot inject Markdown. Missing values become sentinels that
 * finalize() turns into nothing (visitors) or a visible [missing: ns.key] marker (managers).
 * Stored content is never modified.
 */
final class Resolver {

	const SENTINEL_OPEN  = "\u{E000}";
	const SENTINEL_CLOSE = "\u{E001}";

	/**
	 * Namespace registry.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry Registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * The registry.
	 */
	public function registry(): Registry {
		return $this->registry;
	}

	/**
	 * Value of a token, or null when unknown or empty.
	 *
	 * @param string               $token   "ns.key".
	 * @param array<string, mixed> $context Render context (lang, doc_id).
	 */
	public function value( string $token, array $context ): ?string {
		list( $prefix, $key ) = Syntax::split( $token );
		$provider             = $this->registry->get( $prefix );
		$value                = null !== $provider && $provider->is_known( $key ) ? $provider->value( $key, $context ) : null;

		/** This filter is documented in src/Hooks.php */
		$value = apply_filters( Hooks::PLACEHOLDER_VALUE, $value, $prefix, $key, $context );
		if ( null === $value || ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return null;
		}
		return (string) $value;
	}

	/**
	 * Substitutes every well-formed token.
	 *
	 * @param string               $markdown Markdown.
	 * @param array<string, mixed> $context  Render context.
	 */
	public function resolve( string $markdown, array $context ): Resolution {
		$missing = array();
		$used    = array();

		$resolved = (string) preg_replace_callback(
			Syntax::TOKEN,
			function ( array $found ) use ( $context, &$missing, &$used ): string {
				$token          = $found[1] . '.' . $found[2];
				$used[ $token ] = true;
				$value          = $this->value( $token, $context );
				if ( null === $value ) {
					$index = array_search( $token, $missing, true );
					if ( false === $index ) {
						$missing[] = $token;
						$index     = count( $missing ) - 1;
					}
					return self::SENTINEL_OPEN . $index . self::SENTINEL_CLOSE;
				}
				$provider = $this->registry->get( $found[1] );
				return null !== $provider && $provider->is_url( $found[2] ) ? self::escape_url( $value ) : self::escape_text( $value );
			},
			$markdown
		);

		return new Resolution( $resolved, $missing, array_keys( $used ) );
	}

	/**
	 * Tokens of a Markdown text that have no value (for dashboard counts).
	 *
	 * @param string               $markdown Markdown.
	 * @param array<string, mixed> $context  Render context.
	 * @return string[]
	 */
	public function missing( string $markdown, array $context ): array {
		$missing = array();
		foreach ( Syntax::tokens( $markdown ) as $token ) {
			if ( null === $this->value( $token, $context ) ) {
				$missing[] = $token;
			}
		}
		return $missing;
	}

	/**
	 * Replaces the sentinels in rendered HTML. Links whose URL is missing are unwrapped,
	 * so visitors never get a dead link.
	 *
	 * @param string   $html         HTML produced from the resolved Markdown.
	 * @param string[] $missing      Missing tokens (Resolution::$missing).
	 * @param bool     $show_markers Whether to show [missing: …] markers.
	 */
	public static function finalize( string $html, array $missing, bool $show_markers ): string {
		$sentinel = '\x{E000}(\d+)\x{E001}';
		$marker   = static function ( string $index ) use ( $missing, $show_markers ): string {
			return $show_markers ? self::marker( (string) ( $missing[ (int) $index ] ?? '' ) ) : '';
		};

		$html = (string) preg_replace_callback(
			'~<a\b[^>]*\bhref="[^"]*' . $sentinel . '[^"]*"[^>]*>(.*?)</a>~su',
			static fn( array $m ) => $m[2] . $marker( $m[1] ),
			$html
		);
		$html = (string) preg_replace_callback(
			'~<img\b[^>]*' . $sentinel . '[^>]*>~su',
			static fn( array $m ) => $marker( $m[1] ),
			$html
		);
		do {
			$before = $html;
			$html   = (string) preg_replace( '~(="[^"]*)' . $sentinel . '~u', '$1', $html );
		} while ( $before !== $html );

		return (string) preg_replace_callback(
			'~' . $sentinel . '~u',
			static fn( array $m ) => $marker( $m[1] ),
			$html
		);
	}

	/**
	 * Visible marker for a missing value.
	 *
	 * @param string $token Token.
	 */
	public static function marker( string $token ): string {
		return '<mark class="hive-missing">[missing: ' . esc_html( $token ) . ']</mark>';
	}

	/**
	 * Escapes a text value for Markdown: inline syntax characters, and block syntax at the start.
	 * Line breaks are collapsed so a value cannot start new blocks.
	 *
	 * @param string $value Raw value.
	 */
	public static function escape_text( string $value ): string {
		$value = trim( (string) preg_replace( '/[ \t]*\R+[ \t]*/u', ', ', trim( $value ) ) );
		$value = addcslashes( $value, '\\`*_[]{}|~' );
		if ( preg_match( '/^[#>+\-]/', $value ) ) {
			$value = '\\' . $value;
		} elseif ( preg_match( '/^(\d+)([.)])/', $value, $match ) ) {
			$value = $match[1] . '\\' . substr( $value, strlen( $match[1] ) );
		}
		return $value;
	}

	/**
	 * Sanitises a URL value so it cannot break Markdown link syntax.
	 *
	 * @param string $value Raw URL.
	 */
	public static function escape_url( string $value ): string {
		$url = esc_url_raw( trim( $value ) );
		return str_replace( array( ' ', '(', ')', '<', '>', '[', ']' ), array( '%20', '%28', '%29', '%3C', '%3E', '%5B', '%5D' ), $url );
	}
}
