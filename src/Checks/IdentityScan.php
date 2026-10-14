<?php
/**
 * Finds business details in theme areas (widgets, menus, header/footer template parts)
 * that differ from Business Data.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Checks;

use Hive\Compliance\Business\BusinessData;

defined( 'ABSPATH' ) || exit;

/**
 * A simple string scan: VAT numbers written after a label (P.IVA, Partita IVA, VAT), PEC
 * addresses and e-mail addresses. Hard-coded copies drift; the Business identity block does not.
 */
final class IdentityScan {

	/**
	 * Business data.
	 *
	 * @var BusinessData
	 */
	private BusinessData $business;

	/**
	 * Constructor.
	 *
	 * @param BusinessData $business Business data.
	 */
	public function __construct( BusinessData $business ) {
		$this->business = $business;
	}

	/**
	 * Values found that differ from Business Data, as "where: what" lines.
	 *
	 * @return string[]
	 */
	public function mismatches(): array {
		$vat   = self::vat_number( $this->business->value( 'vat_id' ) );
		$pec   = strtolower( $this->business->value( 'pec' ) );
		$known = array_filter( array_map( 'strtolower', array( $this->business->value( 'email' ), $pec, $this->business->value( 'dpo_email' ) ) ) );

		$out = array();
		foreach ( $this->sources() as $where => $content ) {
			$plain = html_entity_decode( wp_strip_all_tags( str_replace( '<', ' <', $content ) ), ENT_QUOTES, 'UTF-8' );

			foreach ( self::vat_numbers( $plain ) as $found ) {
				if ( self::vat_number( $found ) !== $vat ) {
					/* translators: 1: where, 2: VAT number found, 3: VAT number in Business Data. */
					$out[] = sprintf( __( '%1$s: VAT number %2$s (Business Data: %3$s)', 'hive-compliance' ), $where, $found, '' !== $vat ? $this->business->value( 'vat_id' ) : __( 'not set', 'hive-compliance' ) );
				}
			}

			foreach ( self::emails( $content . ' ' . $plain ) as $email ) {
				if ( self::looks_like_pec( $email, $plain ) ) {
					if ( $email !== $pec ) {
						/* translators: 1: where, 2: PEC found, 3: PEC in Business Data. */
						$out[] = sprintf( __( '%1$s: PEC %2$s (Business Data: %3$s)', 'hive-compliance' ), $where, $email, '' !== $pec ? $pec : __( 'not set', 'hive-compliance' ) );
					}
				} elseif ( ! in_array( $email, $known, true ) ) {
					/* translators: 1: where, 2: e-mail address found. */
					$out[] = sprintf( __( '%1$s: e-mail %2$s (not in Business Data)', 'hive-compliance' ), $where, $email );
				}
			}
		}//end foreach
		return array_values( array_unique( $out ) );
	}

	/**
	 * Content of the theme areas, keyed by a human label: active widgets, navigation menus,
	 * and header or footer template parts saved in the Site Editor.
	 *
	 * @return array<string, string>
	 */
	public function sources(): array {
		global $wp_registered_sidebars;

		$sources  = array();
		$sidebars = get_option( 'sidebars_widgets', array() );
		foreach ( is_array( $sidebars ) ? $sidebars : array() as $sidebar_id => $widget_ids ) {
			if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widget_ids ) ) {
				continue;
			}
			$name = (string) ( $wp_registered_sidebars[ $sidebar_id ]['name'] ?? $sidebar_id );
			foreach ( $widget_ids as $widget_id ) {
				if ( ! preg_match( '/^(.+)-(\d+)$/', (string) $widget_id, $m ) ) {
					continue;
				}
				$instances = get_option( 'widget_' . $m[1], array() );
				$instance  = is_array( $instances ) ? ( $instances[ (int) $m[2] ] ?? null ) : null;
				if ( is_array( $instance ) ) {
					/* translators: 1: widget area, 2: widget ID. */
					$sources[ sprintf( __( 'Widget area “%1$s” (%2$s)', 'hive-compliance' ), $name, $widget_id ) ] = self::flatten( $instance );
				}
			}
		}

		foreach ( wp_get_nav_menus() as $menu ) {
			$text = array();
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				if ( is_object( $item ) ) {
					$text[] = implode( ' ', array( (string) $item->title, (string) $item->url, (string) $item->attr_title, (string) $item->description ) );
				}
			}
			/* translators: %s: menu name. */
			$sources[ sprintf( __( 'Menu “%s”', 'hive-compliance' ), $menu->name ) ] = implode( "\n", $text );
		}

		$parts = get_posts(
			array(
				'post_type'        => 'wp_template_part',
				'post_status'      => 'publish',
				'numberposts'      => 50,
				'suppress_filters' => true,
			)
		);
		foreach ( $parts as $part ) {
			if ( preg_match( '/header|footer/', $part->post_name ) ) {
				/* translators: %s: template part name. */
				$sources[ sprintf( __( 'Template part “%s”', 'hive-compliance' ), $part->post_title ? $part->post_title : $part->post_name ) ] = (string) $part->post_content;
			}
		}

		return array_filter( $sources );
	}

	/**
	 * VAT numbers written after a label ("P.IVA 01234567897", "VAT: IT01234567897").
	 *
	 * @param string $text Plain text.
	 * @return string[]
	 */
	private static function vat_numbers( string $text ): array {
		preg_match_all( '/(?:P\.?\s?IVA|Partita\s+IVA|VAT(?:\s+(?:no\.?|number|ID))?)\s*[:.]?\s*((?:[A-Z]{2}\s?)?\d[\d ]{6,14}\d)/iu', $text, $m );
		return array_values( array_unique( array_map( 'trim', $m[1] ) ) );
	}

	/**
	 * A VAT number reduced to its digits and letters, without the country prefix.
	 *
	 * @param string $value VAT number.
	 */
	private static function vat_number( string $value ): string {
		$value = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $value ) );
		return (string) preg_replace( '/^[A-Z]{2}(?=\d)/', '', $value );
	}

	/**
	 * E-mail addresses (also inside mailto: links), lowercase.
	 *
	 * @param string $text Text or HTML.
	 * @return string[]
	 */
	private static function emails( string $text ): array {
		preg_match_all( '/[A-Z0-9._%+\-]+@[A-Z0-9\-]+(?:\.[A-Z0-9\-]+)*\.[A-Z]{2,}/i', $text, $m );
		return array_values( array_unique( array_map( 'strtolower', $m[0] ) ) );
	}

	/**
	 * Whether an address is a PEC: a certified-mail domain, or written right after "PEC".
	 *
	 * @param string $email E-mail address.
	 * @param string $text  Surrounding plain text.
	 */
	private static function looks_like_pec( string $email, string $text ): bool {
		$domain = substr( (string) strrchr( $email, '@' ), 1 );
		if ( preg_match( '/(^|\.)(pec|legalmail|postacert|cert)\./', $domain . '.' ) ) {
			return true;
		}
		return 1 === preg_match( '/\bPEC\b\s*[:.]?\s*' . preg_quote( $email, '/' ) . '/iu', $text );
	}

	/**
	 * Every string of a widget instance, joined.
	 *
	 * @param array<mixed> $instance Widget settings.
	 */
	private static function flatten( array $instance ): string {
		$strings = array();
		array_walk_recursive(
			$instance,
			static function ( $value ) use ( &$strings ) {
				if ( is_string( $value ) ) {
					$strings[] = $value;
				}
			}
		);
		return implode( "\n", $strings );
	}
}
