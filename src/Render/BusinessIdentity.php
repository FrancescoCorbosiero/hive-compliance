<?php
/**
 * The legal identity line from Business Data ([hive_business], hive/business-identity).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Render;

use Hive\Compliance\Business\BusinessData;
use Hive\Compliance\Hooks;
use Hive\Compliance\I18n\Languages;
use Hive\Compliance\Profile\Markets;

defined( 'ABSPATH' ) || exit;

/**
 * Builds "trade name — legal name, legal form, address, P.IVA …, REA …, PEC …, e-mail …, phone …".
 * Empty fields are left out entirely, labels included.
 */
final class BusinessIdentity {

	/**
	 * Fields of the identity line, in output order.
	 */
	const FIELDS = array( 'trade_name', 'legal_name', 'legal_form', 'address', 'vat_id', 'rea', 'pec', 'email', 'phone' );

	/**
	 * Business data.
	 *
	 * @var BusinessData
	 */
	private BusinessData $business;

	/**
	 * Languages.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Constructor.
	 *
	 * @param BusinessData $business  Business data.
	 * @param Languages    $languages Languages.
	 */
	public function __construct( BusinessData $business, Languages $languages ) {
		$this->business  = $business;
		$this->languages = $languages;
	}

	/**
	 * One Business Data field as plain text ('' when empty or unknown).
	 *
	 * @param string $field Field key (any Business Data field).
	 */
	public function value( string $field ): string {
		if ( 'legal_form' === $field ) {
			return $this->business->legal_form_text();
		}
		$fields = BusinessData::fields();
		if ( ! isset( $fields[ $field ] ) ) {
			return '';
		}
		$value = trim( $this->business->value( $field ) );
		if ( 'country' === $field && '' !== $value ) {
			return Markets::country_name( $value );
		}
		// Multi-line values (the address) become one line.
		return (string) preg_replace( '/\s*[\r\n]+\s*/', ', ', $value );
	}

	/**
	 * Labelled parts of the identity line, keyed by field, in output order.
	 *
	 * @param string[] $fields Fields to include (subset of FIELDS).
	 * @param string   $lang   Language of the labels.
	 * @return array<string, string>
	 */
	public function parts( array $fields, string $lang ): array {
		$labels = self::labels( $lang );
		$parts  = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! in_array( $field, $fields, true ) ) {
				continue;
			}
			$value = $this->value( $field );
			if ( '' === $value ) {
				continue;
			}
			// "Rossi S.r.l." already says S.r.l.
			if ( 'legal_form' === $field && isset( $parts['legal_name'] ) && false !== stripos( $parts['legal_name'], $value ) ) {
				continue;
			}
			$parts[ $field ] = isset( $labels[ $field ] ) ? $labels[ $field ] . ' ' . $value : $value;
		}

		/** This filter is documented in src/Hooks.php */
		return array_filter( array_map( 'strval', (array) apply_filters( Hooks::BUSINESS_IDENTITY, $parts, $fields, $lang ) ), static fn( $part ) => '' !== $part );
	}

	/**
	 * The identity line as plain text: trade name, a dash, then the other parts comma-separated.
	 *
	 * @param string[] $fields Fields to include.
	 * @param string   $lang   Language ('' = current).
	 */
	public function line( array $fields = self::FIELDS, string $lang = '' ): string {
		$parts = $this->parts( $fields, '' !== $lang ? $lang : $this->languages->current() );
		$head  = $parts['trade_name'] ?? '';
		unset( $parts['trade_name'] );
		$rest = implode( ', ', $parts );
		if ( '' === $head || '' === $rest ) {
			return $head . $rest;
		}
		return $head . ' — ' . $rest;
	}

	/**
	 * Escaped HTML of the identity line ('' when there is nothing to show).
	 *
	 * @param string[] $fields Fields to include.
	 * @param string   $lang   Language ('' = current).
	 */
	public function render( array $fields = self::FIELDS, string $lang = '' ): string {
		$line = $this->line( $fields, $lang );
		return '' === $line ? '' : '<span class="hive-business-identity">' . esc_html( $line ) . '</span>';
	}

	/**
	 * Fields from a comma-separated list, minus the excluded ones; all fields when the list is empty.
	 *
	 * @param string $shown  Comma-separated fields to show.
	 * @param string $hidden Comma-separated fields to hide.
	 * @return string[]
	 */
	public static function fields_from( string $shown, string $hidden = '' ): array {
		$split  = static fn( string $csv ) => array_filter( array_map( 'sanitize_key', explode( ',', $csv ) ) );
		$fields = '' === trim( $shown ) ? self::FIELDS : array_values( array_intersect( self::FIELDS, $split( $shown ) ) );
		return array_values( array_diff( $fields, $split( $hidden ) ) );
	}

	/**
	 * Labels written before the values, in the language of the page.
	 *
	 * @param string $lang Language code.
	 * @return array<string, string>
	 */
	public static function labels( string $lang ): array {
		if ( 'it' === Languages::primary( $lang ) ) {
			return array(
				'vat_id' => 'P.IVA',
				'rea'    => 'REA',
				'pec'    => 'PEC',
				'email'  => 'E-mail',
				'phone'  => 'Tel.',
			);
		}
		return array(
			'vat_id' => 'VAT no.',
			'rea'    => 'REA',
			'pec'    => 'PEC',
			'email'  => 'E-mail',
			'phone'  => 'Phone',
		);
	}

	/**
	 * Field labels for the block inspector.
	 *
	 * @return array<string, string>
	 */
	public static function field_labels(): array {
		$fields = BusinessData::fields();
		$out    = array();
		foreach ( self::FIELDS as $field ) {
			$out[ $field ] = $fields[ $field ]['label'];
		}
		return $out;
	}
}
