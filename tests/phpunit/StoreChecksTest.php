<?php
/**
 * Store checks that do not need WooCommerce, and their dashboard card.
 *
 * @package Hive\Compliance
 */

class StoreChecksTest extends Hive_TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->login_as( 'administrator' );
	}

	/**
	 * Warning IDs.
	 */
	private function ids(): array {
		$this->plugin()->detector()->reset();
		$this->plugin()->profile()->reset();
		return array_column( $this->plugin()->store_checks()->warnings(), 'details', 'id' );
	}

	public function test_bnpl_service_without_the_profile_feature_is_flagged() {
		update_option( 'active_plugins', array( 'klarna-payments-for-woocommerce/klarna-payments-for-woocommerce.php', 'scalapay-payment-gateway/scalapay.php' ) );
		$this->plugin()->profile()->save(
			array(
				'seller_country' => 'IT',
				'markets'        => array( 'IT' ),
				'features'       => array( 'reviews' ),
			)
		);
		$this->assertSame( array( 'Klarna', 'Scalapay' ), $this->ids()['bnpl_feature'] ?? null );

		$this->plugin()->profile()->save(
			array(
				'seller_country' => 'IT',
				'markets'        => array( 'IT' ),
				'features'       => array( 'reviews', 'bnpl' ),
			)
		);
		$this->assertArrayNotHasKey( 'bnpl_feature', $this->ids() );
	}

	public function test_dashboard_shows_the_store_checks_card() {
		update_option( 'active_plugins', array( 'klarna-payments-for-woocommerce/klarna-payments-for-woocommerce.php' ) );
		$this->plugin()->profile()->save(
			array(
				'seller_country' => 'IT',
				'markets'        => array( 'IT' ),
			)
		);
		$this->plugin()->detector()->reset();
		$html = $this->render_screen( '' );
		$this->assertStringContainsString( 'Store checks', $html );
		$this->assertStringContainsString( 'A buy-now-pay-later service is active', $html );
	}
}
