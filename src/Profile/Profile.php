<?php
/**
 * Store profile (option hive_profile): answers of the setup wizard.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Profile;

use Hive\Compliance\Integrations\Detector;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, pre-fills (from WooCommerce and detected plugins) and stores the profile.
 */
final class Profile {

	const OPTION = 'hive_profile';

	const CUSTOMER_TYPES = array( 'b2c', 'b2b' );
	const PRODUCT_TYPES  = array( 'physical', 'digital_content', 'services', 'subscriptions' );
	const PRODUCT_FLAGS  = array( 'used_goods', 'refurbished', 'custom_made', 'age_restricted', 'food', 'cosmetics', 'electronics', 'batteries', 'dropshipping', 'non_eu_suppliers' );
	const FEATURES       = array( 'accounts', 'reviews', 'newsletter', 'abandoned_cart', 'loyalty_referral', 'gift_cards', 'contests', 'bnpl', 'marketplace_multivendor', 'ai_chatbot', 'physical_store_or_pickup', 'environmental_claims' );

	/**
	 * Integration detector (plugin-based pre-fill).
	 *
	 * @var Detector
	 */
	private Detector $detector;

	/**
	 * Detected values, cached per request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $detected = null;

	/**
	 * Constructor.
	 *
	 * @param Detector $detector Integration detector.
	 */
	public function __construct( Detector $detector ) {
		$this->detector = $detector;
	}

	/**
	 * Empty profile.
	 *
	 * @return array<string, mixed>
	 */
	public static function blank(): array {
		return array(
			'seller_country'      => '',
			'is_micro_enterprise' => false,
			'markets'             => array(),
			'customer_types'      => array(),
			'product_types'       => array(),
			'product_flags'       => array(),
			'features'            => array(),
			'completed'           => false,
			'updated_at'          => 0,
		);
	}

	/**
	 * Whether the wizard was saved at least once.
	 */
	public function is_saved(): bool {
		return is_array( get_option( self::OPTION, null ) );
	}

	/**
	 * The effective profile: the saved answers, or the detected defaults before the first save.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$stored = get_option( self::OPTION, null );
		if ( is_array( $stored ) ) {
			return array_merge( self::blank(), $this->sanitize( $stored ) );
		}

		$profile = self::blank();
		foreach ( $this->detected() as $field => $detected ) {
			$profile[ $field ] = $detected['value'];
		}
		return $profile;
	}

	/**
	 * Profile plus derived fields, as seen by catalog rules.
	 *
	 * @param array<string, mixed>|null $profile Profile; defaults to get().
	 * @return array<string, mixed>
	 */
	public function context( ?array $profile = null ): array {
		return self::derive( $profile ?? $this->get() );
	}

	/**
	 * Adds derived fields: market_regions, sells_outside_eu, seller_in_eu.
	 *
	 * @param array<string, mixed> $profile Profile.
	 * @return array<string, mixed>
	 */
	public static function derive( array $profile ): array {
		$profile = array_merge( self::blank(), $profile );
		$regions = array();
		foreach ( (array) $profile['markets'] as $market ) {
			$regions[] = Markets::region( (string) $market );
		}
		$regions = array_values( array_unique( $regions ) );

		$profile['market_regions']   = $regions;
		$profile['sells_outside_eu'] = array() !== array_diff( $regions, array( 'eu' ) );
		$profile['seller_in_eu']     = Markets::is_eu( (string) $profile['seller_country'] );
		return $profile;
	}

	/**
	 * Saves the profile.
	 *
	 * @param array<string, mixed> $input Raw answers.
	 * @return array<string, mixed> Stored profile.
	 */
	public function save( array $input ): array {
		$clean               = $this->sanitize( $input );
		$clean['updated_at'] = time();
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * Sanitises profile answers.
	 *
	 * @param array<string, mixed> $input Raw answers.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input ): array {
		$country = strtoupper( sanitize_text_field( (string) ( $input['seller_country'] ?? '' ) ) );

		return array(
			'seller_country'      => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
			'is_micro_enterprise' => ! empty( $input['is_micro_enterprise'] ),
			'markets'             => self::pick( $input['markets'] ?? array(), Markets::all() ),
			'customer_types'      => self::pick( $input['customer_types'] ?? array(), self::CUSTOMER_TYPES ),
			'product_types'       => self::pick( $input['product_types'] ?? array(), self::PRODUCT_TYPES ),
			'product_flags'       => self::pick( $input['product_flags'] ?? array(), self::PRODUCT_FLAGS ),
			'features'            => self::pick( $input['features'] ?? array(), self::FEATURES ),
			'completed'           => ! empty( $input['completed'] ),
			'updated_at'          => absint( $input['updated_at'] ?? 0 ),
		);
	}

	/**
	 * Values WooCommerce and the active plugins already answer, with where each came from.
	 *
	 * @return array<string, array<string, mixed>> Field => {value, source, sources?, note?}.
	 */
	public function detected(): array {
		if ( null !== $this->detected ) {
			return $this->detected;
		}

		$woo     = class_exists( 'WooCommerce' );
		$country = $this->detect_country();
		$markets = $this->detect_markets( $country['value'] );

		$this->detected = array(
			'seller_country' => $country,
			'markets'        => $markets,
			'customer_types' => array(
				'value'  => array( 'b2c' ),
				'source' => __( 'Default', 'hive-compliance' ),
			),
			'product_types'  => $this->detect_product_types( $woo ),
			'features'       => $this->detect_features( $woo ),
		);
		return $this->detected;
	}

	/**
	 * Forgets cached detection.
	 */
	public function reset(): void {
		$this->detected = null;
	}

	/**
	 * Labels of the list options, by field.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function option_labels(): array {
		return array(
			'customer_types' => array(
				'b2c' => __( 'Consumers (B2C)', 'hive-compliance' ),
				'b2b' => __( 'Businesses (B2B)', 'hive-compliance' ),
			),
			'product_types'  => array(
				'physical'        => __( 'Physical goods', 'hive-compliance' ),
				'digital_content' => __( 'Digital content (downloads, streaming)', 'hive-compliance' ),
				'services'        => __( 'Services', 'hive-compliance' ),
				'subscriptions'   => __( 'Subscriptions', 'hive-compliance' ),
			),
			'product_flags'  => array(
				'used_goods'       => __( 'Used goods', 'hive-compliance' ),
				'refurbished'      => __( 'Refurbished goods', 'hive-compliance' ),
				'custom_made'      => __( 'Custom-made or personalised goods', 'hive-compliance' ),
				'age_restricted'   => __( 'Age-restricted goods', 'hive-compliance' ),
				'food'             => __( 'Food or beverages', 'hive-compliance' ),
				'cosmetics'        => __( 'Cosmetics', 'hive-compliance' ),
				'electronics'      => __( 'Electrical and electronic equipment', 'hive-compliance' ),
				'batteries'        => __( 'Batteries (alone or inside products)', 'hive-compliance' ),
				'dropshipping'     => __( 'Dropshipping', 'hive-compliance' ),
				'non_eu_suppliers' => __( 'Shipped from suppliers outside the EU', 'hive-compliance' ),
			),
			'features'       => array(
				'accounts'                 => __( 'Customer accounts', 'hive-compliance' ),
				'reviews'                  => __( 'Product reviews', 'hive-compliance' ),
				'newsletter'               => __( 'Newsletter / e-mail marketing', 'hive-compliance' ),
				'abandoned_cart'           => __( 'Abandoned-cart e-mails', 'hive-compliance' ),
				'loyalty_referral'         => __( 'Loyalty or referral programme', 'hive-compliance' ),
				'gift_cards'               => __( 'Gift cards', 'hive-compliance' ),
				'contests'                 => __( 'Contests or prize draws', 'hive-compliance' ),
				'bnpl'                     => __( 'Buy now, pay later', 'hive-compliance' ),
				'marketplace_multivendor'  => __( 'Marketplace with third-party sellers', 'hive-compliance' ),
				'ai_chatbot'               => __( 'AI chatbot or assistant', 'hive-compliance' ),
				'physical_store_or_pickup' => __( 'Physical store or local pickup', 'hive-compliance' ),
				'environmental_claims'     => __( 'Environmental claims (eco, green, sustainable…)', 'hive-compliance' ),
			),
		);
	}

	/**
	 * Keeps the allowed values of a list, in the allowed order.
	 *
	 * @param mixed    $values  Raw values.
	 * @param string[] $allowed Allowed values.
	 * @return string[]
	 */
	private static function pick( $values, array $allowed ): array {
		$values = array_map( 'strval', is_array( $values ) ? $values : array() );
		return array_values( array_intersect( $allowed, $values ) );
	}

	/**
	 * Seller country from the WooCommerce store address, else from the site language.
	 *
	 * @return array{value: string, source: string}
	 */
	private function detect_country(): array {
		$base = (string) get_option( 'woocommerce_default_country', '' );
		if ( class_exists( 'WooCommerce' ) && '' !== $base ) {
			return array(
				'value'  => strtoupper( (string) strtok( $base, ':' ) ),
				'source' => __( 'WooCommerce → Settings → General → Store address', 'hive-compliance' ),
			);
		}
		$parts   = explode( '_', (string) get_locale() );
		$country = isset( $parts[1] ) && preg_match( '/^[A-Z]{2}$/', $parts[1] ) ? $parts[1] : '';
		return array(
			'value'  => $country,
			'source' => __( 'Site language', 'hive-compliance' ),
		);
	}

	/**
	 * Markets from the WooCommerce selling locations.
	 *
	 * @param string $seller_country Seller country.
	 * @return array{value: string[], source: string, note?: string}
	 */
	private function detect_markets( string $seller_country ): array {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) || ! WC()->countries ) {
			$market = '' !== $seller_country ? Markets::from_country( $seller_country ) : null;
			return array(
				'value'  => $market ? array( $market ) : array(),
				'source' => __( 'Seller country', 'hive-compliance' ),
			);
		}

		$all  = array_keys( WC()->countries->get_countries() );
		$mode = (string) get_option( 'woocommerce_allowed_countries', 'all' );
		if ( 'specific' === $mode ) {
			$countries = (array) get_option( 'woocommerce_specific_allowed_countries', array() );
		} elseif ( 'all_except' === $mode ) {
			$countries = array_diff( $all, (array) get_option( 'woocommerce_all_except_countries', array() ) );
		} else {
			$countries = $all;
		}

		$markets = array();
		$has_us  = false;
		foreach ( $countries as $country ) {
			$market = Markets::from_country( (string) $country );
			if ( null === $market ) {
				$has_us = true;
				continue;
			}
			$markets[] = $market;
		}

		$detected = array(
			'value'  => array_values( array_intersect( Markets::all(), array_unique( $markets ) ) ),
			'source' => __( 'WooCommerce → Settings → General → Selling location(s)', 'hive-compliance' ),
		);
		if ( $has_us ) {
			$detected['note'] = __( 'WooCommerce sells to the United States: select the states you target.', 'hive-compliance' );
		}
		return $detected;
	}

	/**
	 * Product types from the catalogue of products.
	 *
	 * @param bool $woo Whether WooCommerce is active.
	 * @return array{value: string[], source: string}
	 */
	private function detect_product_types( bool $woo ): array {
		if ( ! $woo || ! function_exists( 'wc_get_products' ) ) {
			return array(
				'value'  => array( 'physical' ),
				'source' => __( 'Default', 'hive-compliance' ),
			);
		}

		$types = array();
		$query = array(
			'limit'  => 1,
			'return' => 'ids',
			'status' => 'publish',
		);
		if ( wc_get_products( array_merge( $query, array( 'virtual' => false ) ) ) ) {
			$types[] = 'physical';
		}
		if ( wc_get_products( array_merge( $query, array( 'downloadable' => true ) ) ) ) {
			$types[] = 'digital_content';
		}
		if ( class_exists( 'WC_Subscriptions' ) || $this->detector->has_role( 'subscriptions' ) ) {
			$types[] = 'subscriptions';
		}

		return array(
			'value'  => $types ? $types : array( 'physical' ),
			'source' => __( 'WooCommerce products', 'hive-compliance' ),
		);
	}

	/**
	 * Features WooCommerce settings and active plugins reveal.
	 *
	 * @param bool $woo Whether WooCommerce is active.
	 * @return array{value: string[], source: string, sources: array<string, string>}
	 */
	private function detect_features( bool $woo ): array {
		$sources = array();

		if ( $woo ) {
			if ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) || 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) ) {
				$sources['accounts'] = __( 'WooCommerce → Settings → Accounts & Privacy', 'hive-compliance' );
			}
			if ( 'yes' === get_option( 'woocommerce_enable_reviews' ) ) {
				$sources['reviews'] = __( 'WooCommerce → Settings → Products → Reviews', 'hive-compliance' );
			}
			if ( $this->has_local_pickup() ) {
				$sources['physical_store_or_pickup'] = __( 'WooCommerce → Settings → Shipping (local pickup)', 'hive-compliance' );
			}
		}

		$by_role = array(
			'newsletter'              => 'email_marketing',
			'abandoned_cart'          => 'cart_recovery',
			'loyalty_referral'        => 'loyalty',
			'gift_cards'              => 'gift_cards',
			'bnpl'                    => 'payments_bnpl',
			'marketplace_multivendor' => 'marketplace',
			'ai_chatbot'              => 'ai_chatbot',
		);
		foreach ( $by_role as $feature => $role ) {
			$services = $this->detector->with_role( $role );
			if ( $services ) {
				$sources[ $feature ] = implode( ', ', array_unique( array_column( $services, 'service' ) ) );
			}
		}

		return array(
			'value'   => array_values( array_intersect( self::FEATURES, array_keys( $sources ) ) ),
			'source'  => __( 'WooCommerce settings and active plugins', 'hive-compliance' ),
			'sources' => $sources,
		);
	}

	/**
	 * Whether local pickup is enabled (block-based pickup or a legacy shipping method).
	 */
	private function has_local_pickup(): bool {
		$pickup = get_option( 'woocommerce_pickup_location_settings', array() );
		if ( is_array( $pickup ) && 'yes' === ( $pickup['enabled'] ?? '' ) ) {
			return true;
		}
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return false;
		}
		$zones   = \WC_Shipping_Zones::get_zones();
		$zones[] = array( 'shipping_methods' => ( new \WC_Shipping_Zone( 0 ) )->get_shipping_methods( true ) );
		foreach ( $zones as $zone ) {
			foreach ( (array) ( $zone['shipping_methods'] ?? array() ) as $method ) {
				if ( is_object( $method ) && in_array( $method->id, array( 'local_pickup', 'pickup_location' ), true ) && 'yes' === $method->enabled ) {
					return true;
				}
			}
		}
		return false;
	}
}
