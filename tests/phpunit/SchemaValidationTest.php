<?php
/**
 * Catalog validation rejects malformed catalogs.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Catalog\Catalog;

class SchemaValidationTest extends Hive_TestCase {

	private function valid_document(): array {
		return array(
			'id'                    => 'terms_of_sale',
			'title'                 => array( 'en' => 'Terms' ),
			'type'                  => 'page',
			'group'                 => 'core',
			'applies_when'          => array( 'always' => true ),
			'required_elements'     => array(
				array(
					'key'       => 'seller',
					'label'     => 'Seller',
					'legal_ref' => 'Art. 1',
				),
			),
			'placeholders_used'     => array( 'business.legal_name' ),
			'legal_refs'            => array( 'Art. 1' ),
			'review_cadence_months' => 12,
			'checklist_status'      => 'complete',
		);
	}

	/**
	 * Validates a catalog built from the given documents.
	 *
	 * @return string[]
	 */
	private function errors_for( array $documents ): array {
		$catalog = new Catalog( '', '' );
		$schema  = json_decode( (string) file_get_contents( HIVE_COMPLIANCE_DIR . 'catalog/catalog.schema.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data    = json_decode(
			wp_json_encode(
				array(
					'catalog_version' => '1.0.0',
					'documents'       => $documents,
				)
			)
		);
		return $catalog->validate( $data, $schema );
	}

	public function test_valid_minimal_catalog_passes() {
		$this->assertSame( array(), $this->errors_for( array( $this->valid_document() ) ) );
	}

	public function test_missing_required_property_fails() {
		$doc = $this->valid_document();
		unset( $doc['legal_refs'] );
		$this->assertNotEmpty( $this->errors_for( array( $doc ) ) );
	}

	public function test_unknown_type_fails() {
		$doc         = $this->valid_document();
		$doc['type'] = 'widget';
		$this->assertNotEmpty( $this->errors_for( array( $doc ) ) );
	}

	public function test_unknown_property_fails() {
		$doc          = $this->valid_document();
		$doc['color'] = 'red';
		$this->assertNotEmpty( $this->errors_for( array( $doc ) ) );
	}

	public function test_unknown_rule_operator_fails() {
		$doc                 = $this->valid_document();
		$doc['applies_when'] = array(
			'all' => array(
				array(
					'field' => 'markets',
					'op'    => 'gt',
					'value' => 'IT',
				),
			),
		);
		$this->assertNotEmpty( $this->errors_for( array( $doc ) ) );
	}

	public function test_unknown_rule_field_fails() {
		$doc                 = $this->valid_document();
		$doc['applies_when'] = array(
			'any' => array(
				array(
					'field' => 'weather',
					'op'    => 'true',
				),
			),
		);
		$this->assertNotEmpty( $this->errors_for( array( $doc ) ) );
	}

	public function test_unknown_placeholder_namespace_fails() {
		$doc                      = $this->valid_document();
		$doc['placeholders_used'] = array( 'secrets.api_key' );
		$this->assertNotEmpty( $this->errors_for( array( $doc ) ) );
	}

	public function test_duplicate_ids_fail() {
		$errors = $this->errors_for( array( $this->valid_document(), $this->valid_document() ) );
		$this->assertStringContainsString( 'duplicate id', implode( "\n", $errors ) );
	}

	public function test_group_and_type_must_match() {
		$doc          = $this->valid_document();
		$doc['group'] = 'internal';
		$errors       = $this->errors_for( array( $doc ) );
		$this->assertStringContainsString( 'requires type "internal"', implode( "\n", $errors ) );
	}

	public function test_invalid_lint_pattern_fails() {
		$doc         = $this->valid_document();
		$doc['lint'] = array(
			array(
				'id'       => 'broken',
				'pattern'  => '([unclosed',
				'severity' => 'error',
				'message'  => 'x',
			),
		);
		$this->assertStringContainsString( 'invalid pattern', implode( "\n", $this->errors_for( array( $doc ) ) ) );
	}

	public function test_invalid_catalog_file_disables_entries() {
		$path = get_temp_dir() . 'hive-invalid-catalog.json';
		file_put_contents( $path, '{"catalog_version": "x", "documents": []}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$catalog = new Catalog( $path, HIVE_COMPLIANCE_DIR . 'catalog/catalog.schema.json' );
		$this->assertFalse( $catalog->is_valid() );
		$this->assertSame( array(), $catalog->entries() );
		wp_delete_file( $path );
	}
}
