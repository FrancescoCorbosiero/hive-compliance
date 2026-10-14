<?php
/**
 * Catalog loading and JSON Schema validation.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Catalog\Catalog;
use Hive\Compliance\Catalog\Entry;

class CatalogTest extends Hive_TestCase {

	const SPEC_IDS = array(
		'core'          => array( 'legal_notice', 'privacy_policy', 'cookie_policy', 'terms_of_sale', 'withdrawal_policy', 'withdrawal_form', 'refund_policy', 'shipping_policy', 'legal_guarantee', 'payment_methods', 'dispute_resolution', 'contact_support' ),
		'conditional'   => array( 'commercial_guarantee', 'authenticity_guarantee', 'used_goods_notice', 'accessibility_statement', 'pricing_discount_policy', 'review_policy', 'subscription_terms', 'digital_content_terms', 'b2b_terms', 'marketplace_seller_terms', 'dsa_notice_action', 'loyalty_terms', 'gift_card_terms', 'contest_rules', 'promo_terms', 'bnpl_disclosure', 'age_restricted_policy', 'product_safety_recalls', 'weee_takeback', 'battery_takeback', 'packaging_epr', 'environmental_claims', 'ai_disclosure', 'import_duties_notice', 'local_pickup_terms' ),
		'international' => array( 'uk_privacy_addendum', 'uk_consumer_terms_addendum', 'ch_privacy_addendum', 'us_state_privacy_notice', 'us_notice_at_collection', 'us_do_not_sell_share', 'us_auto_renewal_disclosure', 'us_prop65', 'ca_privacy_addendum', 'br_lgpd_addendum', 'jp_tokushoho', 'au_consumer_guarantee' ),
		'fragment'      => array( 'frag_checkout_terms_consent', 'frag_order_button_label', 'frag_account_privacy_notice', 'frag_contact_form_notice', 'frag_newsletter_consent', 'frag_cookie_banner_text', 'frag_email_unsubscribe_footer', 'frag_abandoned_cart_notice', 'frag_ca_casl_consent', 'frag_withdrawal_button_label', 'frag_withdrawal_confirm_label', 'frag_withdrawal_receipt_email', 'frag_order_email_legal_snippet', 'frag_digital_content_waiver', 'frag_age_gate' ),
		'internal'      => array( 'int_ropa', 'int_retention_schedule', 'int_processor_register', 'int_toms', 'int_lia', 'int_dpia', 'int_breach_procedure', 'int_breach_log', 'int_dsr_log' ),
	);

	private function catalog(): Catalog {
		$catalog = $this->plugin()->catalog();
		$catalog->reset();
		return $catalog;
	}

	public function test_shipped_catalog_validates_against_its_schema() {
		$catalog = $this->catalog();
		$this->assertSame( array(), $catalog->errors() );
		$this->assertTrue( $catalog->is_valid() );
		$this->assertSame( '1.1.0', $catalog->version() );
	}

	public function test_catalog_contains_exactly_the_spec_ids_in_their_groups() {
		$catalog  = $this->catalog();
		$expected = array();
		foreach ( self::SPEC_IDS as $group => $ids ) {
			foreach ( $ids as $id ) {
				$entry = $catalog->get( $id );
				$this->assertInstanceOf( Entry::class, $entry, $id );
				$this->assertSame( $group, $entry->group, $id );
				$expected[] = $id;
			}
		}
		$this->assertEqualsCanonicalizing( $expected, array_keys( $catalog->entries() ) );
	}

	public function test_core_conditional_and_fragments_are_complete() {
		foreach ( $this->catalog()->entries() as $entry ) {
			if ( in_array( $entry->group, array( 'core', 'conditional', 'fragment' ), true ) ) {
				$this->assertSame( 'complete', $entry->checklist_status, $entry->id );
				$this->assertNotEmpty( $entry->required_elements, $entry->id );
				foreach ( $entry->required_elements as $element ) {
					$this->assertNotSame( '', trim( $element['label'] ), $entry->id . '.' . $element['key'] );
				}
			} else {
				$this->assertSame( 'draft', $entry->checklist_status, $entry->id );
			}
			$this->assertArrayHasKey( 'en', $entry->titles, $entry->id );
			$this->assertArrayHasKey( 'it', $entry->titles, $entry->id );
		}
	}

	public function test_element_placeholders_are_declared_and_links_target_pages() {
		$catalog = $this->catalog();
		foreach ( $catalog->entries() as $entry ) {
			foreach ( $entry->required_elements as $element ) {
				foreach ( $element['placeholders'] as $placeholder ) {
					$this->assertContains( $placeholder, $entry->placeholders_used, $entry->id . '.' . $element['key'] );
				}
			}
			foreach ( $entry->all_placeholders() as $placeholder ) {
				if ( 0 === strpos( $placeholder, 'links.' ) ) {
					$target = $catalog->get( substr( $placeholder, 6 ) );
					$this->assertNotNull( $target, $placeholder );
					$this->assertTrue( $target->is_page(), $placeholder );
				}
			}
		}
	}

	public function test_legal_guarantee_requires_the_harmonised_notice() {
		$entry = $this->catalog()->get( 'legal_guarantee' );
		$refs  = implode( ' ', array_column( $entry->required_elements, 'legal_ref' ) );
		$this->assertContains( 'harmonised_notice', $entry->element_keys() );
		$this->assertStringContainsString( '2025/1960', $refs );
	}

	public function test_dispute_resolution_flags_odr_links_as_errors() {
		$entry = $this->catalog()->get( 'dispute_resolution' );
		$rule  = null;
		foreach ( $entry->lint as $candidate ) {
			if ( 'odr_link' === $candidate['id'] ) {
				$rule = $candidate;
			}
		}
		$this->assertNotNull( $rule );
		$this->assertSame( 'error', $rule['severity'] );
		$this->assertSame( 1, preg_match( '~' . $rule['pattern'] . '~iu', 'See https://ec.europa.eu/consumers/odr/main' ) );
	}

	public function test_withdrawal_fragments_note_the_italian_default_labels() {
		$catalog = $this->catalog();
		$this->assertStringContainsString( 'recedi dal contratto qui', implode( ' ', $catalog->get( 'frag_withdrawal_button_label' )->legal_refs ) );
		$this->assertStringContainsString( 'Conferma recesso', implode( ' ', $catalog->get( 'frag_withdrawal_confirm_label' )->legal_refs ) );
		$this->assertStringContainsString( '54-bis', implode( ' ', $catalog->get( 'frag_withdrawal_receipt_email' )->legal_refs ) );
	}

	public function test_bnpl_disclosure_cites_the_consumer_credit_directive() {
		$refs = implode( ' ', $this->catalog()->get( 'bnpl_disclosure' )->legal_refs );
		$this->assertStringContainsString( '2023/2225', $refs );
		$this->assertStringContainsString( '20 November 2026', $refs );
	}

	public function test_every_element_label_exists_in_italian_and_english() {
		foreach ( $this->plugin()->catalog()->entries() as $entry ) {
			foreach ( $entry->required_elements as $element ) {
				$where = $entry->id . '.' . $element['key'];
				$this->assertNotSame( '', $element['labels']['it'] ?? '', $where );
				$this->assertNotSame( '', $element['labels']['en'] ?? '', $where );
				$this->assertNotSame( $element['labels']['en'], $element['labels']['it'], $where . ' is translated' );
			}
		}
		$element = $this->plugin()->catalog()->get( 'terms_of_sale' )->required_elements[0];
		$this->assertSame( 'Identità del venditore, indirizzo geografico e recapiti', Entry::element_label( $element, 'it-IT' ) );
		$this->assertSame( 'Seller identity, geographical address and contact details', Entry::element_label( $element, 'de' ), 'Falls back to English.' );
	}

	public function test_a_plain_string_label_is_still_accepted() {
		$entry = Entry::from_array(
			array(
				'id'                    => 'x',
				'title'                 => array( 'en' => 'X' ),
				'type'                  => 'page',
				'group'                 => 'core',
				'applies_when'          => array( 'always' => true ),
				'required_elements'     => array(
					array(
						'key'       => 'a',
						'label'     => 'Only English',
						'legal_ref' => '',
					),
				),
				'placeholders_used'     => array(),
				'legal_refs'            => array(),
				'review_cadence_months' => 12,
				'checklist_status'      => 'draft',
			)
		);
		$this->assertSame( 'Only English', Entry::element_label( $entry->required_elements[0], 'it' ) );
	}

	public function test_titles_fall_back_to_primary_language_then_english() {
		$entry = $this->catalog()->get( 'terms_of_sale' );
		$this->assertSame( 'Termini e condizioni di vendita', $entry->title( 'it' ) );
		$this->assertSame( 'Termini e condizioni di vendita', $entry->title( 'it-CH' ) );
		$this->assertSame( 'Terms and conditions of sale', $entry->title( 'de' ) );
	}
}
