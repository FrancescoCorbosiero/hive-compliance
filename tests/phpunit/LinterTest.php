<?php
/**
 * Content checks shared by the editor and the importer.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Lint\Finding;

class LinterTest extends Hive_TestCase {

	/**
	 * Findings keyed by rule.
	 *
	 * @return array<string, Finding>
	 */
	private function lint( string $markdown, string $doc_id = 'terms_of_sale' ): array {
		$this->plugin()->placeholders()->reset();
		$entry    = $this->plugin()->catalog()->get( $doc_id );
		$findings = array();
		foreach ( $this->plugin()->linter()->lint( $markdown, $entry, 'it' ) as $finding ) {
			$findings[ $finding->rule ] = $finding;
		}
		return $findings;
	}

	public function test_markup_rules_are_errors() {
		$findings = $this->lint( "<script>x</script>\n<div class=\"a\">b</div>\n<!-- note -->\n[a](javascript:alert(1))\n{onclick=\"x\"}" );
		foreach ( array( 'script', 'html_tag', 'html_comment', 'javascript_url', 'event_handler' ) as $rule ) {
			$this->assertArrayHasKey( $rule, $findings, $rule );
			$this->assertSame( Finding::ERROR, $findings[ $rule ]->severity, $rule );
		}
	}

	public function test_autolinks_and_text_are_not_html() {
		$findings = $this->lint( "See <https://www.garanteprivacy.it> and a < b, Online = yes.\n\n## Title" );
		$this->assertArrayNotHasKey( 'html_tag', $findings );
		$this->assertArrayNotHasKey( 'event_handler', $findings );
		$this->assertArrayHasKey( 'literal_url', $findings );
		$this->assertSame( Finding::WARNING, $findings['literal_url']->severity );
	}

	public function test_placeholder_rules() {
		$findings = $this->lint( '{{business.vat_id}} {{secret.key}} {{business.nope}} {{ business.email }}' );
		$this->assertSame( Finding::ERROR, $findings['unknown_namespace']->severity );
		$this->assertSame( array( '{{secret.key}}' ), $findings['unknown_namespace']->details );
		$this->assertSame( Finding::WARNING, $findings['unknown_placeholder']->severity );
		$this->assertSame( array( '{{business.nope}}' ), $findings['unknown_placeholder']->details );
		$this->assertSame( Finding::ERROR, $findings['malformed_placeholder']->severity );
	}

	public function test_todo_markers_are_listed() {
		$findings = $this->lint( "TODO(data): number of the ADR body\n\nText TODO(data): return address" );
		$this->assertSame( array( 'number of the ADR body', 'return address' ), $findings['todo_data']->details );
	}

	public function test_required_headings_by_anchor_or_label() {
		$entry    = $this->plugin()->catalog()->get( 'withdrawal_form' );
		$markdown = "## Destinatario {#addressee}\n\n## Statement of withdrawal from the contract for the goods or services\n";
		$findings = $this->lint( $markdown, 'withdrawal_form' );
		$missing  = $findings['missing_heading']->details;
		$this->assertCount( count( $entry->required_elements ) - 2, $missing );
		$this->assertStringContainsString( '{#order_details}', implode( ' ', $missing ) );
	}

	public function test_catalog_rule_flags_odr_links() {
		$findings = $this->lint( 'Piattaforma: https://ec.europa.eu/consumers/odr', 'dispute_resolution' );
		$this->assertSame( Finding::ERROR, $findings['catalog_odr_link']->severity );
		$this->assertArrayHasKey( 'catalog_odr_mention', $findings );
	}

	public function test_only_registered_disallowed_shortcodes_are_flagged() {
		add_shortcode( 'contact-form-7', '__return_empty_string' );
		$findings = $this->lint( "[contact-form-7 id=\"1\"]\n\n[Nome Cognome]\n\n[cmplz-document type=\"cookie-statement\"]" );
		remove_shortcode( 'contact-form-7' );
		$this->assertSame( array( '[contact-form-7 id="1"]' ), $findings['shortcode_not_allowed']->details );
	}

	public function test_clean_document_has_no_findings_but_headings() {
		$findings = $this->lint( "## Venditore {#seller_identity}\n\n{{business.legal_name}}, {{links.privacy_policy}}" );
		$this->assertSame( array( 'missing_heading' ), array_keys( $findings ) );
	}
}
