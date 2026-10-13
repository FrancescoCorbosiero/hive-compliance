<?php
/**
 * Store facts and profile detection with WooCommerce active.
 *
 * Skipped unless the suite runs with HIVE_TESTS_WITH_WOOCOMMERCE=1 and WooCommerce installed
 * next to the plugin (wp-env does both).
 *
 * @package Hive\Compliance
 */

class WooCommerceTest extends Hive_TestCase {

	/**
	 * Gateway state before the test (gateway objects live for the whole run).
	 *
	 * @var array<string, array{0: string, 1: string, 2: array}>
	 */
	private array $gateway_state = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded (set HIVE_TESTS_WITH_WOOCOMMERCE=1).' );
		}
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			$this->gateway_state[ $id ] = array( $gateway->enabled, $gateway->title, (array) $gateway->settings );
		}
		$this->refresh();
	}

	public function tear_down(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				if ( isset( $this->gateway_state[ $id ] ) ) {
					list( $gateway->enabled, $gateway->title, $gateway->settings ) = $this->gateway_state[ $id ];
				}
			}
		}
		parent::tear_down();
	}

	/**
	 * Forgets cached facts, detection and profile after settings change.
	 */
	private function refresh(): void {
		$this->plugin()->store_facts()->reset();
		$this->plugin()->profile()->reset();
		$this->plugin()->detector()->reset();
	}

	/**
	 * A shipping zone with one enabled flat-rate method.
	 */
	private function zone( string $name, array $locations ): WC_Shipping_Zone {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( $name );
		foreach ( $locations as $code => $type ) {
			$zone->add_location( $code, $type );
		}
		$zone->save();
		$zone->add_shipping_method( 'flat_rate' );
		return $zone;
	}

	/**
	 * Store-check warnings keyed by ID.
	 */
	private function checks(): array {
		$this->refresh();
		$out = array();
		foreach ( $this->plugin()->store_checks()->warnings() as $warning ) {
			$out[ $warning['id'] ] = $warning;
		}
		return $out;
	}

	public function test_store_facts_come_from_woocommerce_settings() {
		update_option( 'woocommerce_store_address', 'Via Roma 1' );
		update_option( 'woocommerce_store_postcode', '20121' );
		update_option( 'woocommerce_store_city', 'Milano' );
		update_option( 'woocommerce_default_country', 'IT:MI' );
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_allowed_countries', 'specific' );
		update_option( 'woocommerce_specific_allowed_countries', array( 'IT', 'FR' ) );
		update_option( 'woocommerce_ship_to_countries', '' );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		update_option( 'woocommerce_enable_reviews', 'no' );
		update_option( 'woocommerce_enable_myaccount_registration', 'yes' );

		$this->zone( 'Italia', array( 'IT' => 'country' ) );

		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->assertArrayHasKey( 'bacs', $gateways );
		foreach ( $gateways as $gateway ) {
			$gateway->enabled = 'bacs' === $gateway->id ? 'yes' : 'no';
		}

		$facts = $this->plugin()->store_facts();
		$this->assertSame( 'Via Roma 1, 20121 Milano, Italy', $facts->text( 'store_address' ) );
		$this->assertSame( 'Italy', $facts->text( 'store_country' ) );
		$this->assertSame( 'EUR', $facts->text( 'currency' ) );
		$this->assertSame( 'Italy, France', $facts->text( 'selling_countries' ) );
		$this->assertSame( 'Italy', $facts->text( 'shipping_countries' ), 'Shipping countries come from the shipping zones.' );
		$this->assertSame( 'Italia (Italy)', $facts->text( 'shipping_zones' ) );
		$this->assertSame( $gateways['bacs']->get_title(), $facts->text( 'payment_gateways' ) );
		$this->assertSame( 'enabled', $facts->text( 'guest_checkout' ) );
		$this->assertSame( 'disabled', $facts->text( 'reviews_enabled' ) );
		$this->assertSame( 'enabled', $facts->text( 'account_registration' ) );
		$this->assertSame( 'WooCommerce settings', $facts->all()['currency']['source'] );

		// The same values reach documents through {{store.*}}.
		$this->plugin()->placeholders()->reset();
		$resolution = $this->plugin()->resolver()->resolve( 'Valuta: {{store.currency}}', array( 'lang' => 'it' ) );
		$this->assertStringContainsString( 'Valuta: EUR', $resolution->markdown );
	}

	public function test_profile_defaults_are_detected_from_woocommerce() {
		update_option( 'woocommerce_default_country', 'IT:MI' );
		update_option( 'woocommerce_allowed_countries', 'specific' );
		update_option( 'woocommerce_specific_allowed_countries', array( 'IT', 'FR', 'US' ) );
		update_option( 'woocommerce_enable_reviews', 'yes' );
		update_option( 'woocommerce_enable_myaccount_registration', 'no' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
		update_option( 'woocommerce_pickup_location_settings', array( 'enabled' => 'yes' ) );

		$shoe = new WC_Product_Simple();
		$shoe->set_name( 'Sneaker' );
		$shoe->set_status( 'publish' );
		$shoe->save();
		$guide = new WC_Product_Simple();
		$guide->set_name( 'Guide' );
		$guide->set_virtual( true );
		$guide->set_downloadable( true );
		$guide->set_status( 'publish' );
		$guide->save();

		$detected = $this->plugin()->profile()->detected();
		$this->assertSame( 'IT', $detected['seller_country']['value'] );
		$this->assertEqualsCanonicalizing( array( 'IT', 'FR' ), $detected['markets']['value'] );
		$this->assertArrayHasKey( 'note', $detected['markets'], 'Selling to the US asks for the states.' );
		$this->assertSame( array( 'physical', 'digital_content' ), $detected['product_types']['value'] );
		$this->assertContains( 'reviews', $detected['features']['value'] );
		$this->assertContains( 'physical_store_or_pickup', $detected['features']['value'] );
		$this->assertNotContains( 'accounts', $detected['features']['value'] );

		// Until the wizard is saved, the detected answers drive applicability.
		$applicable = array_map( static fn( $entry ) => $entry->id, $this->plugin()->applicability()->applicable() );
		$this->assertContains( 'review_policy', $applicable );
		$this->assertContains( 'digital_content_terms', $applicable );
		$this->assertContains( 'local_pickup_terms', $applicable );
	}

	public function test_countries_are_named_in_words_and_shipping_follows_the_zones() {
		update_option( 'woocommerce_allowed_countries', 'all' );
		update_option( 'woocommerce_ship_to_countries', '' );
		$this->zone( 'Italia', array( 'IT' => 'country' ) );
		$this->zone( 'Nord America', array( 'NA' => 'continent' ) );
		$idle = new WC_Shipping_Zone();
		$idle->set_zone_name( 'Germania (no methods)' );
		$idle->add_location( 'DE', 'country' );
		$idle->save();
		$this->refresh();

		$facts = $this->plugin()->store_facts();
		$this->assertSame( 'all countries', $facts->text( 'selling_countries' ) );
		$this->assertSame( 'Italy, North America', $facts->text( 'shipping_countries' ), 'Zones without an enabled method do not ship.' );
		$this->assertContains( 'US', $facts->shipping_coverage()['codes'], 'A continent covers its countries.' );

		$warning = $this->checks()['selling_everywhere'] ?? null;
		$this->assertNotNull( $warning, 'Selling to all countries with limited zones is flagged.' );
		$this->assertSame( array( 'Italy', 'North America' ), $warning['details'] );

		// Shipping everywhere else too: the setting and the zones agree.
		( new WC_Shipping_Zone( 0 ) )->add_shipping_method( 'flat_rate' );
		$this->refresh();
		$this->assertSame( 'Italy, North America, all other countries', $facts->text( 'shipping_countries' ) );
		$this->assertArrayNotHasKey( 'selling_everywhere', $this->checks() );

		update_option( 'woocommerce_allowed_countries', 'all_except' );
		update_option( 'woocommerce_all_except_countries', array( 'DE', 'FR' ) );
		$this->refresh();
		$this->assertSame( 'all countries except Germany, France', $facts->text( 'selling_countries' ) );

		update_option( 'woocommerce_allowed_countries', 'specific' );
		update_option( 'woocommerce_specific_allowed_countries', array_keys( WC()->countries->get_countries() ) );
		$this->refresh();
		$this->assertDoesNotMatchRegularExpression( '/\d+ countries/', $facts->text( 'selling_countries' ), 'Never "250 countries".' );
		$this->assertStringContainsString( 'Italy', $facts->text( 'selling_countries' ) );

		update_option( 'woocommerce_ship_to_countries', 'disabled' );
		$this->refresh();
		$this->assertSame( '', $facts->text( 'shipping_countries' ), 'No shipping, no shipping countries.' );
	}

	public function test_payment_methods_are_listed_once_by_title() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$titles   = array(
			'bacs'   => 'Card',
			'cheque' => ' card ',
			'cod'    => 'Apple Pay',
		);
		foreach ( $gateways as $id => $gateway ) {
			$gateway->enabled = isset( $titles[ $id ] ) ? 'yes' : 'no';
			$gateway->title   = $titles[ $id ] ?? $gateway->title;
		}
		$this->refresh();
		$this->assertSame( 'Card, Apple Pay', $this->plugin()->store_facts()->text( 'payment_gateways' ) );
	}

	public function test_payment_surcharges_from_pay_for_payment_are_flagged() {
		$gateway           = WC()->payment_gateways()->payment_gateways()['bacs'];
		$gateway->enabled  = 'yes';
		$gateway->settings = array_merge( (array) $gateway->settings, array( 'pay4pay_charges_fixed' => '2,50' ) );
		$this->assertArrayNotHasKey( 'payment_surcharge', $this->checks(), 'Only while Pay for Payment is active.' );

		update_option( 'active_plugins', array( 'woocommerce-pay-for-payment/woocommerce-pay-for-payment.php' ) );
		$warning = $this->checks()['payment_surcharge'] ?? null;
		$this->assertNotNull( $warning );
		$this->assertStringContainsString( 'art. 62 Codice del Consumo', $warning['message'] );
		$this->assertStringStartsWith( $gateway->get_title() . ': +2.50', $warning['details'][0] );

		$gateway->settings = array_merge( (array) $gateway->settings, array( 'pay4pay_charges_fixed' => '-2' ) );
		$this->assertArrayNotHasKey( 'payment_surcharge', $this->checks(), 'Discounts are allowed.' );
	}

	public function test_profile_markets_without_a_shipping_zone_are_listed() {
		$this->plugin()->profile()->save(
			array(
				'seller_country' => 'IT',
				'markets'        => array( 'IT', 'FR', 'UK', 'US-CA', 'other' ),
				'customer_types' => array( 'b2c' ),
				'product_types'  => array( 'physical' ),
			)
		);
		$this->zone( 'Italia', array( 'IT' => 'country' ) );
		$this->zone( 'California', array( 'US:CA' => 'state' ) );
		$warning = $this->checks()['markets_without_shipping'] ?? null;
		$this->assertNotNull( $warning );
		$this->assertSame( array( 'France', 'United Kingdom' ), $warning['details'] );

		$this->plugin()->profile()->save(
			array(
				'seller_country' => 'IT',
				'markets'        => array( 'IT' ),
				'product_types'  => array( 'physical' ),
			)
		);
		$this->assertArrayNotHasKey( 'markets_without_shipping', $this->checks() );
	}

	public function test_saved_answers_that_differ_from_woocommerce_are_flagged() {
		update_option( 'woocommerce_default_country', 'IT:MI' );
		update_option( 'woocommerce_allowed_countries', 'specific' );
		update_option( 'woocommerce_specific_allowed_countries', array( 'IT', 'FR' ) );
		$this->login_as( 'administrator' );

		$this->plugin()->profile()->save(
			array(
				'seller_country' => 'US',
				'markets'        => array( 'IT' ),
			)
		);
		$this->assertStringContainsString( 'Differs from WooCommerce → Settings → General → Store address: Italy (IT).', $this->render_screen( 'profile', array( 'step' => 'seller' ) ) );
		$this->assertStringContainsString( 'Differs from WooCommerce → Settings → General → Selling location(s): France, Italy.', $this->render_screen( 'profile', array( 'step' => 'markets' ) ) );

		$this->plugin()->profile()->save(
			array(
				'seller_country' => 'IT',
				'markets'        => array( 'IT', 'FR' ),
			)
		);
		$this->assertStringNotContainsString( 'Differs from', $this->render_screen( 'profile', array( 'step' => 'seller' ) ) );
		$this->assertStringNotContainsString( 'Differs from', $this->render_screen( 'profile', array( 'step' => 'markets' ) ) );
	}
}
