<?php
/**
 * Validation and normalisation of business identifiers and contacts.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Business;

defined( 'ABSPATH' ) || exit;

/**
 * Pure validation helpers. Each check returns a Check result; nothing here blocks saving.
 */
final class Validator {

	/**
	 * VAT number formats (after the country prefix). EL is Greece's VAT prefix; XI is Northern Ireland.
	 */
	const VAT_FORMATS = array(
		'AT' => 'U\d{8}',
		'BE' => '[01]\d{9}',
		'BG' => '\d{9,10}',
		'CY' => '\d{8}[A-Z]',
		'CZ' => '\d{8,10}',
		'DE' => '\d{9}',
		'DK' => '\d{8}',
		'EE' => '\d{9}',
		'EL' => '\d{9}',
		'ES' => '[A-Z0-9]\d{7}[A-Z0-9]',
		'FI' => '\d{8}',
		'FR' => '[A-HJ-NP-Z0-9]{2}\d{9}',
		'HR' => '\d{11}',
		'HU' => '\d{8}',
		'IE' => '\d{7}[A-W][A-I]?|\d[A-Z+*]\d{5}[A-W]',
		'IT' => '\d{11}',
		'LT' => '\d{9}|\d{12}',
		'LU' => '\d{8}',
		'LV' => '\d{11}',
		'MT' => '\d{8}',
		'NL' => '\d{9}B\d{2}',
		'PL' => '\d{10}',
		'PT' => '\d{9}',
		'RO' => '[1-9]\d{1,9}',
		'SE' => '\d{10}01',
		'SI' => '\d{8}',
		'SK' => '\d{10}',
		'XI' => '\d{9}|\d{12}|GD\d{3}|HA\d{3}',
		'GB' => '\d{9}|\d{12}|GD\d{3}|HA\d{3}',
		'CH' => 'E\d{9}(?:MWST|TVA|IVA)?',
	);

	/**
	 * International calling codes by country.
	 */
	const CALLING_CODES = array(
		'AT' => '43',
		'BE' => '32',
		'BG' => '359',
		'HR' => '385',
		'CY' => '357',
		'CZ' => '420',
		'DK' => '45',
		'EE' => '372',
		'FI' => '358',
		'FR' => '33',
		'DE' => '49',
		'GR' => '30',
		'HU' => '36',
		'IE' => '353',
		'IT' => '39',
		'LV' => '371',
		'LT' => '370',
		'LU' => '352',
		'MT' => '356',
		'NL' => '31',
		'PL' => '48',
		'PT' => '351',
		'RO' => '40',
		'SK' => '421',
		'SI' => '386',
		'ES' => '34',
		'SE' => '46',
		'GB' => '44',
		'CH' => '41',
		'NO' => '47',
		'IS' => '354',
		'LI' => '423',
		'SM' => '378',
		'VA' => '39',
		'MC' => '377',
		'US' => '1',
		'CA' => '1',
		'BR' => '55',
		'JP' => '81',
		'AU' => '61',
	);

	/**
	 * Countries whose national numbers keep the leading 0 after the country code.
	 */
	const KEEP_TRUNK_ZERO = array( 'IT', 'SM', 'VA' );

	/**
	 * Legal forms and the spellings they are recognised by (lowercase, dots and spaces removed).
	 */
	const LEGAL_FORMS = array(
		'impresa_individuale' => array( 'impresaindividuale', 'dittaindividuale', 'ditta', 'soletrader' ),
		'snc'                 => array( 'snc' ),
		'sas'                 => array( 'sas' ),
		'srl'                 => array( 'srl' ),
		'srls'                => array( 'srls' ),
		'spa'                 => array( 'spa' ),
		'other'               => array(),
	);

	/**
	 * Webmail domains (first label) that are not PEC providers.
	 */
	const WEBMAIL = array( 'gmail', 'googlemail', 'yahoo', 'hotmail', 'outlook', 'libero', 'icloud' );

	/**
	 * Italian partita IVA: 11 digits with the official check digit
	 * (odd positions summed; even positions doubled, minus 9 when above 9; check = (10 - sum mod 10) mod 10).
	 *
	 * @param string $value Digits, without the IT prefix.
	 */
	public static function is_valid_partita_iva( string $value ): bool {
		if ( ! preg_match( '/^\d{11}$/', $value ) || '0000000' === substr( $value, 0, 7 ) ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$digit = (int) $value[ $i ];
			if ( 1 === $i % 2 ) {
				$digit *= 2;
				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}
			$sum += $digit;
		}
		return ( 10 - $sum % 10 ) % 10 === (int) $value[10];
	}

	/**
	 * Italian codice fiscale: 16-character personal code with its check character,
	 * or an 11-digit numeric code (companies) using the partita IVA algorithm.
	 *
	 * @param string $value Code.
	 */
	public static function is_valid_codice_fiscale( string $value ): bool {
		$value = strtoupper( $value );
		if ( preg_match( '/^\d{11}$/', $value ) ) {
			return self::is_valid_partita_iva( $value );
		}
		if ( ! preg_match( '/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $value ) ) {
			return false;
		}
		$odd = array( 1, 0, 5, 7, 9, 13, 15, 17, 19, 21, 2, 4, 18, 20, 11, 3, 6, 8, 12, 14, 16, 10, 22, 25, 24, 23 );
		$sum = 0;
		for ( $i = 0; $i < 15; $i++ ) {
			$char  = $value[ $i ];
			$index = ctype_digit( $char ) ? (int) $char : ord( $char ) - 65;
			$sum  += 0 === $i % 2 ? $odd[ $index ] : $index;
		}
		return chr( 65 + $sum % 26 ) === $value[15];
	}

	/**
	 * VAT identification number.
	 *
	 * Accepts a country prefix (EU, XI, GB, CH; GR is read as EL). Without a prefix the number is read
	 * in the business country. Italian numbers also need a valid check digit.
	 *
	 * @param string $value           Raw input.
	 * @param string $default_country Country used when there is no prefix.
	 */
	public static function vat_id( string $value, string $default_country ): Check {
		$normalized = strtoupper( (string) preg_replace( '/[\s.\-\/]+/', '', $value ) );
		if ( '' === $normalized ) {
			return Check::ok( '' );
		}

		$country = strtoupper( $default_country );
		$number  = $normalized;
		if ( preg_match( '/^([A-Z]{2})(.+)$/', $normalized, $match ) && isset( self::VAT_FORMATS[ 'GR' === $match[1] ? 'EL' : $match[1] ] ) ) {
			$country    = 'GR' === $match[1] ? 'EL' : $match[1];
			$number     = $match[2];
			$normalized = $country . $number;
		} elseif ( 'GR' === $country ) {
			$country = 'EL';
		}

		if ( ! isset( self::VAT_FORMATS[ $country ] ) ) {
			return Check::warning( $normalized, __( 'No format check is available for this country.', 'hive-compliance' ) );
		}
		if ( 'IT' === $country && ! self::is_valid_partita_iva( $number ) ) {
			return Check::error( $normalized, __( 'Invalid partita IVA: 11 digits with a valid check digit are required.', 'hive-compliance' ) );
		}
		if ( ! preg_match( '/^(?:' . self::VAT_FORMATS[ $country ] . ')$/', $number ) ) {
			/* translators: %s: country code. */
			return Check::error( $normalized, sprintf( __( 'This is not a valid %s VAT number format.', 'hive-compliance' ), $country ) );
		}
		return Check::ok( $normalized );
	}

	/**
	 * Tax code: Italian codice fiscale when the business is Italian, free text otherwise.
	 *
	 * @param string $value   Raw input.
	 * @param string $country Business country.
	 */
	public static function tax_code( string $value, string $country ): Check {
		$normalized = strtoupper( (string) preg_replace( '/\s+/', '', $value ) );
		if ( '' === $normalized || 'IT' !== strtoupper( $country ) ) {
			return Check::ok( $normalized );
		}
		return self::is_valid_codice_fiscale( $normalized )
			? Check::ok( $normalized )
			: Check::error( $normalized, __( 'Invalid codice fiscale (16 characters with a valid check character, or 11 digits).', 'hive-compliance' ) );
	}

	/**
	 * Italian REA number ("MI-1234567").
	 *
	 * @param string $value   Raw input.
	 * @param string $country Business country.
	 */
	public static function rea( string $value, string $country ): Check {
		$normalized = strtoupper( (string) preg_replace( '/\s+/', '', $value ) );
		if ( '' === $normalized || 'IT' !== strtoupper( $country ) ) {
			return Check::ok( $normalized );
		}
		if ( preg_match( '/^([A-Z]{2})[-\/]?(\d{1,7})$/', $normalized, $match ) ) {
			return Check::ok( $match[1] . '-' . $match[2] );
		}
		return Check::error( $normalized, __( 'REA numbers look like "MI-1234567" (province code and number).', 'hive-compliance' ) );
	}

	/**
	 * E-mail address.
	 *
	 * @param string $value Raw input.
	 */
	public static function email( string $value ): Check {
		$value = trim( $value );
		if ( '' === $value ) {
			return Check::ok( '' );
		}
		return is_email( $value )
			? Check::ok( strtolower( $value ) )
			: Check::error( $value, __( 'This is not a valid e-mail address.', 'hive-compliance' ) );
	}

	/**
	 * Phone number normalised to E.164 (+<country code><number>).
	 *
	 * @param string $value   Raw input.
	 * @param string $country Country used for numbers without an international prefix.
	 */
	public static function phone( string $value, string $country ): Check {
		$raw = trim( $value );
		if ( '' === $raw ) {
			return Check::ok( '' );
		}

		// "+44 (0)20 ..." : the bracketed trunk zero is not dialled after the country code.
		$digits = (string) preg_replace( '/^(\+|00)(\d{1,3})\s*\(0\)/', '$1$2', $raw );
		$digits = (string) preg_replace( '/[\s.\-\/()]+/', '', $digits );
		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = '+' . substr( $digits, 2 );
		}
		if ( '+' !== substr( $digits, 0, 1 ) ) {
			$country = strtoupper( $country );
			if ( ! isset( self::CALLING_CODES[ $country ] ) ) {
				return Check::error( $raw, __( 'Add the international prefix (e.g. +39).', 'hive-compliance' ) );
			}
			if ( ! in_array( $country, self::KEEP_TRUNK_ZERO, true ) ) {
				$digits = (string) preg_replace( '/^0/', '', $digits );
			}
			$digits = '+' . self::CALLING_CODES[ $country ] . $digits;
		}

		return preg_match( '/^\+[1-9]\d{6,14}$/', $digits )
			? Check::ok( $digits )
			: Check::error( $raw, __( 'This is not a valid phone number.', 'hive-compliance' ) );
	}

	/**
	 * Legal form: one of the LEGAL_FORMS keys. Known spellings ("S.r.l.") are recognised;
	 * any other text becomes "other".
	 *
	 * @param string $value Raw input.
	 */
	public static function legal_form( string $value ): Check {
		$value = strtolower( trim( $value ) );
		if ( '' === $value || isset( self::LEGAL_FORMS[ $value ] ) ) {
			return Check::ok( $value );
		}
		$compact = (string) preg_replace( '/[\s.\-_]+/', '', $value );
		foreach ( self::LEGAL_FORMS as $key => $spellings ) {
			if ( in_array( $compact, $spellings, true ) ) {
				return Check::ok( $key );
			}
		}
		return Check::ok( 'other' );
	}

	/**
	 * Certified e-mail (PEC): a valid address; a common webmail domain gets a warning, since
	 * PEC mailboxes come from accredited providers. Never blocks.
	 *
	 * @param string $value Raw input.
	 */
	public static function pec( string $value ): Check {
		$check = self::email( $value );
		if ( Check::OK !== $check->severity || '' === $check->value ) {
			return $check;
		}
		$domain = substr( (string) strrchr( $check->value, '@' ), 1 );
		if ( preg_match( '/^(' . implode( '|', self::WEBMAIL ) . ')\.[a-z]{2,}(\.[a-z]{2,})?$/', $domain ) ) {
			return Check::warning( $check->value, __( 'This looks like an ordinary webmail address, not a PEC mailbox. PEC addresses come from accredited providers (e.g. pec.it, legalmail.it).', 'hive-compliance' ) );
		}
		return $check;
	}

	/**
	 * Absolute http(s) URL.
	 *
	 * @param string $value Raw input.
	 */
	public static function url( string $value ): Check {
		$value = trim( $value );
		if ( '' === $value ) {
			return Check::ok( '' );
		}
		$clean = esc_url_raw( $value, array( 'http', 'https' ) );
		// Syntax only (wp_http_validate_url() would resolve the host). Never keep an unsafe value.
		$parts = wp_parse_url( $clean );
		$valid = '' !== $clean
			&& false !== filter_var( $clean, FILTER_VALIDATE_URL )
			&& in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			&& false !== strpos( (string) ( $parts['host'] ?? '' ), '.' );
		return $valid
			? Check::ok( $clean )
			: Check::error( '', __( 'Enter a full https:// address.', 'hive-compliance' ) );
	}
}
