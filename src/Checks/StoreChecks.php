<?php
/**
 * Compliance checks on the store setup, shown on the dashboard.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Checks;

use Hive\Compliance\Integrations\Detector;
use Hive\Compliance\Profile\Markets;
use Hive\Compliance\Profile\Profile;
use Hive\Compliance\Profile\StoreFacts;

defined( 'ABSPATH' ) || exit;

/**
 * Warnings about WooCommerce settings and active plugins that conflict with the documents
 * or with consumer law. They never block anything.
 */
final class StoreChecks {

	const PAY_FOR_PAYMENT = 'woocommerce-pay-for-payment';

	/**
	 * Store facts.
	 *
	 * @var StoreFacts
	 */
	private StoreFacts $facts;

	/**
	 * Store profile.
	 *
	 * @var Profile
	 */
	private Profile $profile;

	/**
	 * Integration detector.
	 *
	 * @var Detector
	 */
	private Detector $detector;

	/**
	 * Scan of widgets, menus and template parts.
	 *
	 * @var IdentityScan
	 */
	private IdentityScan $identity;

	/**
	 * Constructor.
	 *
	 * @param StoreFacts   $facts    Store facts.
	 * @param Profile      $profile  Store profile.
	 * @param Detector     $detector Integration detector.
	 * @param IdentityScan $identity Header/footer identity scan.
	 */
	public function __construct( StoreFacts $facts, Profile $profile, Detector $detector, IdentityScan $identity ) {
		$this->facts    = $facts;
		$this->profile  = $profile;
		$this->detector = $detector;
		$this->identity = $identity;
	}

	/**
	 * Every warning that applies.
	 *
	 * @return array<int, array{id: string, message: string, details: string[]}>
	 */
	public function warnings(): array {
		return array_merge(
			$this->selling_everywhere(),
			$this->payment_surcharges(),
			$this->bnpl_feature(),
			$this->markets_without_shipping(),
			$this->identity_mismatches()
		);
	}

	/**
	 * Widgets, menus or header/footer template parts show business details that differ from
	 * Business Data.
	 *
	 * @return array<int, array{id: string, message: string, details: string[]}>
	 */
	private function identity_mismatches(): array {
		$found = $this->identity->mismatches();
		if ( ! $found ) {
			return array();
		}
		return array(
			self::warning(
				'identity_mismatch',
				__( 'The site header or footer shows business details that differ from Business Data. Correct them, or replace the text with the Business identity block or [hive_business].', 'hive-compliance' ),
				$found
			),
		);
	}

	/**
	 * WooCommerce sells or ships to "all countries" while the shipping zones cover fewer.
	 *
	 * @return array<int, array{id: string, message: string, details: string[]}>
	 */
	private function selling_everywhere(): array {
		if ( ! self::woocommerce() || ! self::ships() ) {
			return array();
		}
		$all      = 'all' === get_option( 'woocommerce_allowed_countries', 'all' ) || 'all' === get_option( 'woocommerce_ship_to_countries' );
		$coverage = $this->facts->shipping_coverage();
		if ( ! $all || $coverage['rest_of_world'] ) {
			return array();
		}
		return array(
			self::warning(
				'selling_everywhere',
				__( 'WooCommerce sells to all countries, but the shipping zones cover only these. Restrict the selling locations or add shipping zones.', 'hive-compliance' ),
				$coverage['names'] ? $coverage['names'] : array( __( 'No shipping zone has an enabled shipping method.', 'hive-compliance' ) )
			),
		);
	}

	/**
	 * Pay for Payment adds a fee to an enabled payment method.
	 *
	 * @return array<int, array{id: string, message: string, details: string[]}>
	 */
	private function payment_surcharges(): array {
		if ( ! self::woocommerce() || ! WC()->payment_gateways() || ! $this->detector->is_active( self::PAY_FOR_PAYMENT ) ) {
			return array();
		}
		$fees = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( 'yes' !== $gateway->enabled ) {
				continue;
			}
			$settings = is_array( $gateway->settings ) && $gateway->settings ? $gateway->settings : (array) get_option( 'woocommerce_' . $gateway->id . '_settings', array() );
			$fixed    = self::amount( $settings['pay4pay_charges_fixed'] ?? 0 );
			$percent  = self::amount( $settings['pay4pay_charges_percentage'] ?? 0 );
			$charges  = array();
			if ( $fixed > 0 ) {
				$charges[] = '+' . number_format_i18n( $fixed, 2 ) . ' ' . get_woocommerce_currency();
			}
			if ( $percent > 0 ) {
				$charges[] = '+' . number_format_i18n( $percent, 2 ) . '%';
			}
			if ( $charges ) {
				$fees[] = wp_strip_all_tags( (string) $gateway->get_title() ) . ': ' . implode( ', ', $charges );
			}
		}
		if ( ! $fees ) {
			return array();
		}
		return array(
			self::warning(
				'payment_surcharge',
				__( 'Pay for Payment adds a fee to these payment methods. Surcharges for using a means of payment are prohibited for consumers in Italy (art. 62 Codice del Consumo); discounts are allowed.', 'hive-compliance' ),
				$fees
			),
		);
	}

	/**
	 * A buy-now-pay-later service is active but the profile does not say so.
	 *
	 * @return array<int, array{id: string, message: string, details: string[]}>
	 */
	private function bnpl_feature(): array {
		$services = $this->detector->with_role( 'payments_bnpl' );
		if ( ! $services || in_array( 'bnpl', (array) $this->profile->get()['features'], true ) ) {
			return array();
		}
		return array(
			self::warning(
				'bnpl_feature',
				__( 'A buy-now-pay-later service is active, but "Buy now, pay later" is off in the store profile. Turn it on (Store Profile → Features) so the BNPL disclosure applies.', 'hive-compliance' ),
				array_values( array_unique( array_column( $services, 'service' ) ) )
			),
		);
	}

	/**
	 * Profile markets that no shipping zone covers (physical goods only).
	 *
	 * @return array<int, array{id: string, message: string, details: string[]}>
	 */
	private function markets_without_shipping(): array {
		if ( ! self::woocommerce() || ! self::ships() ) {
			return array();
		}
		$profile = $this->profile->get();
		if ( ! in_array( 'physical', (array) $profile['product_types'], true ) ) {
			return array();
		}
		$coverage = $this->facts->shipping_coverage();
		if ( $coverage['rest_of_world'] ) {
			return array();
		}
		$missing = array();
		foreach ( (array) $profile['markets'] as $market ) {
			$market  = (string) $market;
			$country = 0 === strpos( $market, 'US-' ) ? 'US' : ( 'UK' === $market ? 'GB' : $market );
			if ( 'other' !== $market && ! in_array( $country, $coverage['codes'], true ) ) {
				$missing[] = Markets::label( $market );
			}
		}
		if ( ! $missing ) {
			return array();
		}
		return array(
			self::warning(
				'markets_without_shipping',
				__( 'These markets of the store profile have no shipping zone. Add a zone, or remove the market from the profile.', 'hive-compliance' ),
				$missing
			),
		);
	}

	/**
	 * Builds a warning.
	 *
	 * @param string   $id      Warning ID.
	 * @param string   $message Message.
	 * @param string[] $details Items.
	 * @return array{id: string, message: string, details: string[]}
	 */
	private static function warning( string $id, string $message, array $details ): array {
		return array(
			'id'      => $id,
			'message' => $message,
			'details' => array_values( array_map( 'strval', $details ) ),
		);
	}

	/**
	 * Whether WooCommerce is active.
	 */
	private static function woocommerce(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
	}

	/**
	 * Whether the shop ships at all.
	 */
	private static function ships(): bool {
		return 'disabled' !== get_option( 'woocommerce_ship_to_countries' );
	}

	/**
	 * A fee setting as a number ("2,50" and "2.50" both work).
	 *
	 * @param mixed $value Setting value.
	 */
	private static function amount( $value ): float {
		return (float) str_replace( ',', '.', trim( (string) $value ) );
	}
}
