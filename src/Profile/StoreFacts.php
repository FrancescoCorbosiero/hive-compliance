<?php
/**
 * Auto-detected store facts (the read-only store.* placeholders).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Profile;

use Hive\Compliance\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Reads facts from WordPress and WooCommerce. Without WooCommerce, the shop facts are empty.
 */
final class StoreFacts {

	/**
	 * Facts cached per request.
	 *
	 * @var array<string, array{label: string, value: mixed, source: string}>|null
	 */
	private ?array $facts = null;

	/**
	 * Shipping coverage cached per request.
	 *
	 * @var array{codes: string[], names: string[], rest_of_world: bool, zones: int}|null
	 */
	private ?array $coverage = null;

	/**
	 * Labels of the store.* keys.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'site_url'             => __( 'Site URL', 'hive-compliance' ),
			'site_name'            => __( 'Site name', 'hive-compliance' ),
			'store_address'        => __( 'Store address', 'hive-compliance' ),
			'store_country'        => __( 'Store country', 'hive-compliance' ),
			'currency'             => __( 'Currency', 'hive-compliance' ),
			'selling_countries'    => __( 'Selling countries', 'hive-compliance' ),
			'shipping_countries'   => __( 'Shipping countries', 'hive-compliance' ),
			'shipping_zones'       => __( 'Shipping zones', 'hive-compliance' ),
			'payment_gateways'     => __( 'Enabled payment methods', 'hive-compliance' ),
			'guest_checkout'       => __( 'Guest checkout', 'hive-compliance' ),
			'reviews_enabled'      => __( 'Product reviews', 'hive-compliance' ),
			'account_registration' => __( 'Account registration', 'hive-compliance' ),
		);
	}

	/**
	 * All facts with label, value (string, list or bool) and source.
	 *
	 * @return array<string, array{label: string, value: mixed, source: string}>
	 */
	public function all(): array {
		if ( null !== $this->facts ) {
			return $this->facts;
		}

		$values = array(
			'site_url'  => home_url( '/' ),
			'site_name' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		$source = __( 'WordPress settings', 'hive-compliance' );
		$facts  = array();
		foreach ( $values as $key => $value ) {
			$facts[ $key ] = $this->fact( $key, $value, $source );
		}

		$woo = class_exists( 'WooCommerce' ) && function_exists( 'WC' );
		foreach ( $this->woocommerce_values( $woo ) as $key => $value ) {
			$facts[ $key ] = $this->fact( $key, $value, $woo ? __( 'WooCommerce settings', 'hive-compliance' ) : __( 'WooCommerce is not active', 'hive-compliance' ) );
		}

		/** This filter is documented in src/Hooks.php */
		$this->facts = (array) apply_filters( Hooks::STORE_FACTS, $facts );
		return $this->facts;
	}

	/**
	 * A fact rendered as text ('' when unknown). Lists are comma-separated; booleans are yes/no.
	 *
	 * @param string $key Fact key.
	 */
	public function text( string $key ): string {
		$fact = $this->all()[ $key ] ?? null;
		if ( null === $fact ) {
			return '';
		}
		$value = $fact['value'];
		if ( is_bool( $value ) ) {
			return $value ? __( 'enabled', 'hive-compliance' ) : __( 'disabled', 'hive-compliance' );
		}
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}
		return null === $value ? '' : (string) $value;
	}

	/**
	 * Forgets the cached facts.
	 */
	public function reset(): void {
		$this->facts    = null;
		$this->coverage = null;
	}

	/**
	 * Builds one fact.
	 *
	 * @param string $key    Key.
	 * @param mixed  $value  Value.
	 * @param string $source Source.
	 * @return array{label: string, value: mixed, source: string}
	 */
	private function fact( string $key, $value, string $source ): array {
		return array(
			'label'  => self::labels()[ $key ] ?? $key,
			'value'  => $value,
			'source' => $source,
		);
	}

	/**
	 * WooCommerce facts (null values when WooCommerce is not active).
	 *
	 * @param bool $woo Whether WooCommerce is active.
	 * @return array<string, mixed>
	 */
	private function woocommerce_values( bool $woo ): array {
		$keys = array( 'store_address', 'store_country', 'currency', 'selling_countries', 'shipping_countries', 'shipping_zones', 'payment_gateways', 'guest_checkout', 'reviews_enabled', 'account_registration' );
		if ( ! $woo ) {
			return array_fill_keys( $keys, null );
		}

		$countries = WC()->countries;
		$base      = $countries ? $countries->get_base_country() : '';

		return array(
			'store_address'        => $this->store_address(),
			'store_country'        => $base ? Markets::country_name( $base ) : null,
			'currency'             => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
			'selling_countries'    => $this->selling_countries(),
			'shipping_countries'   => $this->shipping_countries(),
			'shipping_zones'       => $this->shipping_zones(),
			'payment_gateways'     => $this->payment_gateways(),
			'guest_checkout'       => 'yes' === get_option( 'woocommerce_enable_guest_checkout' ),
			'reviews_enabled'      => 'yes' === get_option( 'woocommerce_enable_reviews' ),
			'account_registration' => 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) || 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' ),
		);
	}

	/**
	 * Store address on one line.
	 */
	private function store_address(): ?string {
		$parts = array(
			get_option( 'woocommerce_store_address', '' ),
			get_option( 'woocommerce_store_address_2', '' ),
			trim( get_option( 'woocommerce_store_postcode', '' ) . ' ' . get_option( 'woocommerce_store_city', '' ) ),
		);
		$base  = (string) get_option( 'woocommerce_default_country', '' );
		if ( '' !== $base ) {
			$parts[] = Markets::country_name( (string) strtok( $base, ':' ) );
		}
		$parts = array_filter( array_map( 'trim', array_map( 'strval', $parts ) ) );
		return $parts ? implode( ', ', $parts ) : null;
	}

	/**
	 * Country names for codes.
	 *
	 * @param string[] $codes Country codes.
	 * @return string[]
	 */
	private function country_names( array $codes ): array {
		return array_values( array_map( array( Markets::class, 'country_name' ), array_map( 'strval', $codes ) ) );
	}

	/**
	 * Selling locations: "all countries" in words, or the country names.
	 *
	 * @return string|string[]
	 */
	private function selling_countries() {
		$mode = (string) get_option( 'woocommerce_allowed_countries', 'all' );
		if ( 'specific' === $mode ) {
			return $this->country_names( (array) get_option( 'woocommerce_specific_allowed_countries', array() ) );
		}
		$except = 'all_except' === $mode ? $this->country_names( (array) get_option( 'woocommerce_all_except_countries', array() ) ) : array();
		/* translators: %s: list of countries. */
		return $except ? sprintf( __( 'all countries except %s', 'hive-compliance' ), implode( ', ', $except ) ) : __( 'all countries', 'hive-compliance' );
	}

	/**
	 * Shipping countries, derived from the shipping zones that have an enabled method.
	 * The "rest of the world" zone adds "all other countries" (or "all countries" alone).
	 *
	 * @return string[]|null Null when shipping is disabled.
	 */
	private function shipping_countries(): ?array {
		if ( 'disabled' === get_option( 'woocommerce_ship_to_countries' ) ) {
			return null;
		}
		$coverage = $this->shipping_coverage();
		$names    = $coverage['names'];
		if ( $coverage['rest_of_world'] ) {
			$names[] = $names ? __( 'all other countries', 'hive-compliance' ) : __( 'all countries', 'hive-compliance' );
		}
		return $names;
	}

	/**
	 * Where the shop actually ships: countries of the shipping zones with an enabled method
	 * (states count for their country; continents for all their countries), and whether the
	 * "locations not covered by your other zones" zone ships too.
	 *
	 * @return array{codes: string[], names: string[], rest_of_world: bool, zones: int}
	 */
	public function shipping_coverage(): array {
		if ( null !== $this->coverage ) {
			return $this->coverage;
		}
		$coverage = array(
			'codes'         => array(),
			'names'         => array(),
			'rest_of_world' => false,
			'zones'         => 0,
		);
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! function_exists( 'WC' ) || ! WC()->countries ) {
			$this->coverage = $coverage;
			return $coverage;
		}

		$continents = WC()->countries->get_continents();
		foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
			if ( ! self::ships( (array) ( $zone['shipping_methods'] ?? array() ) ) ) {
				continue;
			}
			++$coverage['zones'];
			foreach ( (array) ( $zone['zone_locations'] ?? array() ) as $location ) {
				$type = (string) ( is_object( $location ) ? $location->type : ( $location['type'] ?? '' ) );
				$code = (string) ( is_object( $location ) ? $location->code : ( $location['code'] ?? '' ) );
				if ( 'continent' === $type && isset( $continents[ $code ] ) ) {
					$coverage['codes']   = array_merge( $coverage['codes'], (array) $continents[ $code ]['countries'] );
					$coverage['names'][] = html_entity_decode( (string) $continents[ $code ]['name'], ENT_QUOTES, 'UTF-8' );
				} elseif ( 'country' === $type || 'state' === $type ) {
					$country             = (string) strtok( $code, ':' );
					$coverage['codes'][] = $country;
					$coverage['names'][] = Markets::country_name( $country );
				}
			}
		}
		$coverage['rest_of_world'] = self::ships( ( new \WC_Shipping_Zone( 0 ) )->get_shipping_methods( true ) );
		$coverage['codes']         = array_values( array_unique( $coverage['codes'] ) );
		$coverage['names']         = array_values( array_unique( $coverage['names'] ) );

		$this->coverage = $coverage;
		return $coverage;
	}

	/**
	 * Whether a list of shipping methods has an enabled one.
	 *
	 * @param array<int, mixed> $methods Shipping method objects.
	 */
	private static function ships( array $methods ): bool {
		foreach ( $methods as $method ) {
			if ( is_object( $method ) && 'yes' === $method->enabled ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Shipping zones as "Zone (regions)".
	 *
	 * @return string[]
	 */
	private function shipping_zones(): array {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}
		$out = array();
		foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
			$name    = wp_strip_all_tags( (string) ( $zone['zone_name'] ?? '' ) );
			$regions = wp_strip_all_tags( (string) ( $zone['formatted_zone_location'] ?? '' ) );
			$out[]   = '' !== $regions ? sprintf( '%s (%s)', $name, $regions ) : $name;
		}
		return $out;
	}

	/**
	 * Titles of the enabled payment gateways, once each. Several plugins register the same
	 * methods (WooPayments and PayPal both offer cards, Apple Pay and Google Pay).
	 *
	 * @return string[]
	 */
	private function payment_gateways(): array {
		if ( ! WC()->payment_gateways() ) {
			return array();
		}
		$out = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( 'yes' !== $gateway->enabled ) {
				continue;
			}
			$title = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $gateway->get_title(), ENT_QUOTES, 'UTF-8' ) ) ) );
			$key   = mb_strtolower( $title, 'UTF-8' );
			if ( '' !== $title && ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $title;
			}
		}
		return array_values( $out );
	}
}
