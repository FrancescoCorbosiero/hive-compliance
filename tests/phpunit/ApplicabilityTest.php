<?php
/**
 * Applicability rules over the store profile.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Catalog\RuleEngine;
use Hive\Compliance\Hooks;
use Hive\Compliance\Profile\Profile;

class ApplicabilityTest extends Hive_TestCase {

	private function context( array $profile ): array {
		return Profile::derive( array_merge( Profile::blank(), $profile ) );
	}

	private function applicable_ids( array $profile ): array {
		return array_keys( $this->plugin()->applicability()->applicable( $this->context( $profile ) ) );
	}

	private function rule( array $rule, array $context ): bool {
		return ( new RuleEngine() )->evaluate( $rule, $context );
	}

	public function test_operators() {
		$context = array(
			'seller_country'      => 'IT',
			'markets'             => array( 'IT', 'FR' ),
			'is_micro_enterprise' => false,
		);

		$this->assertTrue( $this->rule( array( 'always' => true ), $context ) );
		$this->assertTrue( $this->rule( array( 'all' => array( array( 'field' => 'seller_country', 'op' => 'eq', 'value' => 'IT' ) ) ), $context ) );
		$this->assertFalse( $this->rule( array( 'all' => array( array( 'field' => 'seller_country', 'op' => 'eq', 'value' => 'FR' ) ) ), $context ) );
		$this->assertTrue( $this->rule( array( 'all' => array( array( 'field' => 'seller_country', 'op' => 'in', 'value' => array( 'DE', 'IT' ) ) ) ), $context ) );
		$this->assertTrue( $this->rule( array( 'all' => array( array( 'field' => 'markets', 'op' => 'in', 'value' => array( 'FR', 'UK' ) ) ) ), $context ) );
		$this->assertTrue( $this->rule( array( 'all' => array( array( 'field' => 'markets', 'op' => 'includes', 'value' => 'FR' ) ) ), $context ) );
		$this->assertFalse( $this->rule( array( 'all' => array( array( 'field' => 'markets', 'op' => 'includes', 'value' => array( 'FR', 'UK' ) ) ) ), $context ) );
		$this->assertFalse( $this->rule( array( 'all' => array( array( 'field' => 'is_micro_enterprise', 'op' => 'true' ) ) ), $context ) );
		$this->assertTrue( $this->rule( array( 'not' => array( 'field' => 'is_micro_enterprise', 'op' => 'true' ) ), $context ) );
		$this->assertTrue( $this->rule( array( 'any' => array( array( 'field' => 'markets', 'op' => 'includes', 'value' => 'UK' ), array( 'all' => array( array( 'field' => 'seller_country', 'op' => 'eq', 'value' => 'IT' ) ) ) ) ), $context ) );
		$this->assertTrue( $this->rule( array( 'all' => array( array( 'field' => 'is_micro_enterprise', 'op' => 'eq', 'value' => false ) ) ), $context ) );
	}

	public function test_malformed_rules_never_apply() {
		$this->assertFalse( $this->rule( array(), array() ) );
		$this->assertFalse( $this->rule( array( 'always' => 'yes' ), array() ) );
		$this->assertFalse( $this->rule( array( 'all' => array() ), array() ) );
		$this->assertFalse( $this->rule( array( 'all' => array( array( 'field' => 'markets', 'op' => 'gt', 'value' => 1 ) ) ), array( 'markets' => array( 'IT' ) ) ) );
		$this->assertFalse( $this->rule( array( 'all' => array( array( 'field' => 'missing', 'op' => 'eq', 'value' => 'x' ) ) ), array() ) );
	}

	public function test_core_documents_apply_to_a_b2c_shop() {
		$ids = $this->applicable_ids(
			array(
				'seller_country' => 'IT',
				'markets'        => array( 'IT' ),
				'customer_types' => array( 'b2c' ),
				'product_types'  => array( 'physical' ),
			)
		);
		foreach ( CatalogTest::SPEC_IDS['core'] as $id ) {
			$this->assertContains( $id, $ids );
		}
		$this->assertNotContains( 'b2b_terms', $ids );
		$this->assertNotContains( 'uk_privacy_addendum', $ids );
		$this->assertNotContains( 'import_duties_notice', $ids );
	}

	public function test_consumer_only_documents_do_not_apply_to_b2b_only_shops() {
		$ids = $this->applicable_ids(
			array(
				'customer_types' => array( 'b2b' ),
				'product_types'  => array( 'physical' ),
			)
		);
		$this->assertContains( 'b2b_terms', $ids );
		$this->assertContains( 'privacy_policy', $ids );
		$this->assertNotContains( 'withdrawal_policy', $ids );
		$this->assertNotContains( 'legal_guarantee', $ids );
		$this->assertNotContains( 'dispute_resolution', $ids );
	}

	public function test_accessibility_statement_exempts_micro_enterprises_providing_services_only() {
		$base = array(
			'customer_types'      => array( 'b2c' ),
			'is_micro_enterprise' => true,
			'product_types'       => array( 'services' ),
		);
		$this->assertNotContains( 'accessibility_statement', $this->applicable_ids( $base ) );
		$this->assertContains( 'accessibility_statement', $this->applicable_ids( array_merge( $base, array( 'product_types' => array( 'services', 'physical' ) ) ) ) );
		$this->assertContains( 'accessibility_statement', $this->applicable_ids( array_merge( $base, array( 'is_micro_enterprise' => false ) ) ) );
	}

	public function test_markets_drive_international_documents() {
		$ids = $this->applicable_ids(
			array(
				'customer_types' => array( 'b2c' ),
				'product_types'  => array( 'physical', 'subscriptions' ),
				'markets'        => array( 'IT', 'UK', 'US-CA', 'CH' ),
			)
		);
		foreach ( array( 'uk_privacy_addendum', 'uk_consumer_terms_addendum', 'ch_privacy_addendum', 'us_state_privacy_notice', 'us_notice_at_collection', 'us_do_not_sell_share', 'us_auto_renewal_disclosure', 'us_prop65', 'import_duties_notice' ) as $id ) {
			$this->assertContains( $id, $ids );
		}
		$this->assertNotContains( 'jp_tokushoho', $ids );

		$ny = $this->applicable_ids(
			array(
				'customer_types' => array( 'b2c' ),
				'markets'        => array( 'US-NY' ),
			)
		);
		$this->assertContains( 'us_state_privacy_notice', $ny );
		$this->assertNotContains( 'us_notice_at_collection', $ny );
	}

	public function test_flags_and_features_drive_conditional_documents() {
		$ids = $this->applicable_ids(
			array(
				'customer_types' => array( 'b2c' ),
				'product_types'  => array( 'physical', 'digital_content' ),
				'product_flags'  => array( 'used_goods', 'electronics', 'batteries', 'age_restricted', 'non_eu_suppliers' ),
				'features'       => array( 'reviews', 'newsletter', 'bnpl', 'ai_chatbot', 'gift_cards' ),
				'markets'        => array( 'IT' ),
			)
		);
		foreach ( array( 'used_goods_notice', 'authenticity_guarantee', 'commercial_guarantee', 'weee_takeback', 'battery_takeback', 'age_restricted_policy', 'frag_age_gate', 'import_duties_notice', 'review_policy', 'frag_newsletter_consent', 'bnpl_disclosure', 'ai_disclosure', 'gift_card_terms', 'digital_content_terms', 'frag_digital_content_waiver' ) as $id ) {
			$this->assertContains( $id, $ids );
		}
		$this->assertNotContains( 'frag_ca_casl_consent', $ids );
		$this->assertNotContains( 'contest_rules', $ids );
	}

	public function test_derived_fields() {
		$context = $this->context(
			array(
				'seller_country' => 'IT',
				'markets'        => array( 'IT', 'US-TX', 'other' ),
			)
		);
		$this->assertEqualsCanonicalizing( array( 'eu', 'us', 'other' ), $context['market_regions'] );
		$this->assertTrue( $context['sells_outside_eu'] );
		$this->assertTrue( $context['seller_in_eu'] );

		$eu = $this->context( array( 'markets' => array( 'IT', 'FR' ) ) );
		$this->assertFalse( $eu['sells_outside_eu'] );
	}

	public function test_profile_sanitize_drops_unknown_values_and_saves() {
		$profile = $this->plugin()->profile();
		$saved   = $profile->save(
			array(
				'seller_country'      => 'it',
				'is_micro_enterprise' => '1',
				'markets'             => array( 'IT', 'XX', 'US-CA', 'US-ZZ' ),
				'customer_types'      => array( 'b2c', 'robots' ),
				'features'            => array( 'reviews', '<script>' ),
			)
		);
		$this->assertSame( 'IT', $saved['seller_country'] );
		$this->assertTrue( $saved['is_micro_enterprise'] );
		$this->assertSame( array( 'IT', 'US-CA' ), $saved['markets'] );
		$this->assertSame( array( 'b2c' ), $saved['customer_types'] );
		$this->assertSame( array( 'reviews' ), $saved['features'] );
		$this->assertTrue( $profile->is_saved() );
		$this->assertSame( array( 'IT', 'US-CA' ), $profile->get()['markets'] );
	}

	public function test_unsaved_profile_uses_detected_defaults_with_sources() {
		delete_option( Profile::OPTION );
		$profile = $this->plugin()->profile();
		$profile->reset();
		$detected = $profile->detected();

		$this->assertSame( array( 'b2c' ), $detected['customer_types']['value'] );
		$this->assertNotEmpty( $detected['customer_types']['source'] );
		$this->assertSame( array( 'b2c' ), $profile->get()['customer_types'] );
		$this->assertContains( 'withdrawal_policy', array_keys( $this->plugin()->applicability()->applicable() ) );
	}

	public function test_applies_filter_can_override() {
		$entry = $this->plugin()->catalog()->get( 'contest_rules' );
		$force = static fn() => true;
		add_filter( Hooks::DOCUMENT_APPLIES, $force );
		$this->assertTrue( $this->plugin()->applicability()->applies( $entry, $this->context( array() ) ) );
		remove_filter( Hooks::DOCUMENT_APPLIES, $force );
		$this->assertFalse( $this->plugin()->applicability()->applies( $entry, $this->context( array() ) ) );
	}
}
