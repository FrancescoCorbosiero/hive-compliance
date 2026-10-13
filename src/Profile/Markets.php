<?php
/**
 * Market codes used by the store profile.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Market codes: EU country codes (ISO 3166-1 alpha-2), UK, CH, US-<state>, CA, BR, JP, AU, other.
 */
final class Markets {

	const EU = array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE' );

	const OTHER = array( 'UK', 'CH', 'CA', 'BR', 'JP', 'AU', 'other' );

	const US_STATES = array( 'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY' );

	/**
	 * Every valid market code.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		$us = array_map( static fn( $state ) => 'US-' . $state, self::US_STATES );
		return array_merge( self::EU, self::OTHER, $us );
	}

	/**
	 * Whether a code is a valid market.
	 *
	 * @param string $code Market code.
	 */
	public static function is_valid( string $code ): bool {
		return in_array( $code, self::all(), true );
	}

	/**
	 * Whether a country code is an EU member state.
	 *
	 * @param string $country ISO code.
	 */
	public static function is_eu( string $country ): bool {
		return in_array( strtoupper( $country ), self::EU, true );
	}

	/**
	 * Region of a market: eu, uk, ch, us, ca, br, jp, au or other.
	 *
	 * @param string $code Market code.
	 */
	public static function region( string $code ): string {
		if ( self::is_eu( $code ) ) {
			return 'eu';
		}
		if ( 0 === strpos( $code, 'US-' ) ) {
			return 'us';
		}
		return in_array( $code, self::OTHER, true ) ? strtolower( $code ) : 'other';
	}

	/**
	 * Maps a WooCommerce country code to a market code. Returns null for the US (states must be chosen).
	 *
	 * @param string $country ISO 3166-1 alpha-2 code.
	 */
	public static function from_country( string $country ): ?string {
		$country = strtoupper( $country );
		if ( 'US' === $country ) {
			return null;
		}
		if ( 'GB' === $country ) {
			return 'UK';
		}
		if ( self::is_eu( $country ) || in_array( $country, self::OTHER, true ) ) {
			return $country;
		}
		return 'other';
	}

	/**
	 * Human label of a market code.
	 *
	 * @param string $code Market code.
	 */
	public static function label( string $code ): string {
		if ( 0 === strpos( $code, 'US-' ) ) {
			/* translators: %s: US state code. */
			return sprintf( __( 'United States – %s', 'hive-compliance' ), substr( $code, 3 ) );
		}
		$labels = array(
			'UK'    => __( 'United Kingdom', 'hive-compliance' ),
			'CH'    => __( 'Switzerland', 'hive-compliance' ),
			'CA'    => __( 'Canada', 'hive-compliance' ),
			'BR'    => __( 'Brazil', 'hive-compliance' ),
			'JP'    => __( 'Japan', 'hive-compliance' ),
			'AU'    => __( 'Australia', 'hive-compliance' ),
			'other' => __( 'Other countries', 'hive-compliance' ),
		);
		if ( isset( $labels[ $code ] ) ) {
			return $labels[ $code ];
		}
		return self::country_name( $code );
	}

	/**
	 * Country name from WooCommerce when available, otherwise the code.
	 *
	 * @param string $country ISO code.
	 */
	public static function country_name( string $country ): string {
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$countries = WC()->countries->get_countries();
			if ( isset( $countries[ $country ] ) ) {
				return html_entity_decode( (string) $countries[ $country ], ENT_QUOTES, 'UTF-8' );
			}
		}
		$names = array(
			'AT' => __( 'Austria', 'hive-compliance' ),
			'BE' => __( 'Belgium', 'hive-compliance' ),
			'BG' => __( 'Bulgaria', 'hive-compliance' ),
			'HR' => __( 'Croatia', 'hive-compliance' ),
			'CY' => __( 'Cyprus', 'hive-compliance' ),
			'CZ' => __( 'Czechia', 'hive-compliance' ),
			'DK' => __( 'Denmark', 'hive-compliance' ),
			'EE' => __( 'Estonia', 'hive-compliance' ),
			'FI' => __( 'Finland', 'hive-compliance' ),
			'FR' => __( 'France', 'hive-compliance' ),
			'DE' => __( 'Germany', 'hive-compliance' ),
			'GR' => __( 'Greece', 'hive-compliance' ),
			'HU' => __( 'Hungary', 'hive-compliance' ),
			'IE' => __( 'Ireland', 'hive-compliance' ),
			'IT' => __( 'Italy', 'hive-compliance' ),
			'LV' => __( 'Latvia', 'hive-compliance' ),
			'LT' => __( 'Lithuania', 'hive-compliance' ),
			'LU' => __( 'Luxembourg', 'hive-compliance' ),
			'MT' => __( 'Malta', 'hive-compliance' ),
			'NL' => __( 'Netherlands', 'hive-compliance' ),
			'PL' => __( 'Poland', 'hive-compliance' ),
			'PT' => __( 'Portugal', 'hive-compliance' ),
			'RO' => __( 'Romania', 'hive-compliance' ),
			'SK' => __( 'Slovakia', 'hive-compliance' ),
			'SI' => __( 'Slovenia', 'hive-compliance' ),
			'ES' => __( 'Spain', 'hive-compliance' ),
			'SE' => __( 'Sweden', 'hive-compliance' ),
			'GB' => __( 'United Kingdom', 'hive-compliance' ),
			'CH' => __( 'Switzerland', 'hive-compliance' ),
			'US' => __( 'United States', 'hive-compliance' ),
		);
		return $names[ $country ] ?? $country;
	}
}
