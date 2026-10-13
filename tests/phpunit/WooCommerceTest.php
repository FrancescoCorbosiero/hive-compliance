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

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded (set HIVE_TESTS_WITH_WOOCOMMERCE=1).' );
		}
		$this->plugin()->store_facts()->reset();
		$this->plugin()->profile()->reset();
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

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Italia' );
		$zone->add_location( 'IT', 'country' );
		$zone->save();

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
		$this->assertSame( 'Italy, France', $facts->text( 'shipping_countries' ) );
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
