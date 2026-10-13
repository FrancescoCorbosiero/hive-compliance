<?php
/**
 * Skeleton generation.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Document\Document;
use Hive\Compliance\Installer;
use Hive\Compliance\Lint\Finding;

class SkeletonTest extends Hive_TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->plugin()->settings()->update(
			array(
				'default_language'  => 'it',
				'enabled_languages' => array( 'it', 'en' ),
			)
		);
		$this->plugin()->profile()->save(
			array(
				'seller_country' => 'IT',
				'markets'        => array( 'IT' ),
				'customer_types' => array( 'b2c' ),
				'product_types'  => array( 'physical' ),
			)
		);
		$this->plugin()->placeholders()->reset();
		$this->login_as( 'administrator' );
	}

	public function test_skeletons_contain_only_headings_and_placeholders() {
		$linter = $this->plugin()->linter();
		foreach ( $this->plugin()->catalog()->entries() as $entry ) {
			$skeleton = $this->plugin()->skeletons()->skeleton( $entry );
			foreach ( explode( "\n", $skeleton ) as $line ) {
				if ( '' === $line ) {
					continue;
				}
				$this->assertMatchesRegularExpression( '/^(## [^\n]+ \{#[a-z0-9_]+\}|- \{\{[a-z]+\.[a-z0-9_]+\}\})$/', $line, $entry->id . ': no prose allowed' );
			}
			foreach ( $entry->required_elements as $element ) {
				$this->assertStringContainsString( '## ' . $element['label'] . ' {#' . $element['key'] . '}', $skeleton );
			}
			foreach ( $entry->all_placeholders() as $placeholder ) {
				$this->assertStringContainsString( '{{' . $placeholder . '}}', $skeleton );
			}
			$rules = array_map( static fn( Finding $finding ) => $finding->rule, $linter->lint( $skeleton, $entry, 'it' ) );
			$this->assertNotContains( 'missing_heading', $rules, $entry->id );
			$this->assertNotContains( 'unknown_namespace', $rules, $entry->id );
			$this->assertNotContains( 'malformed_placeholder', $rules, $entry->id );
		}
	}

	public function test_generate_creates_drafts_and_never_overwrites() {
		$entry = $this->plugin()->catalog()->get( 'legal_notice' );
		$doc   = $this->plugin()->skeletons()->generate( $entry, 'it' );
		$this->assertInstanceOf( Document::class, $doc );
		$this->assertSame( Document::STATUS_DRAFT, $doc->status );
		$this->assertSame( 1, $doc->version );
		$this->assertStringContainsString( '{{business.vat_id}}', $doc->markdown );

		$this->plugin()->repository()->save( 'legal_notice', 'it', 'Testo scritto a mano.' );
		$this->assertNull( $this->plugin()->skeletons()->generate( $entry, 'it' ) );
		$this->assertSame( 'Testo scritto a mano.', $this->plugin()->repository()->get( 'legal_notice', 'it' )->markdown );
	}

	public function test_bulk_generation_covers_empty_applicable_documents() {
		$this->plugin()->repository()->save( 'privacy_policy', 'it', 'Già scritta.' );
		$result = $this->plugin()->skeletons()->generate_applicable( array( 'it' ) );

		$applicable = $this->plugin()->applicability()->applicable();
		$this->assertSame( count( $applicable ) - 1, $result['created'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 'Già scritta.', $this->plugin()->repository()->get( 'privacy_policy', 'it' )->markdown );
		$this->assertNull( $this->plugin()->repository()->find( 'b2b_terms', 'it' ), 'Not applicable: not generated.' );
		$this->assertNull( $this->plugin()->repository()->find( 'terms_of_sale', 'en' ), 'Only the requested languages.' );
	}

	public function test_first_activation_generates_once_in_the_default_language() {
		update_option( Installer::OPTION_PENDING_SKELETONS, 1 );
		$this->login_as( 'subscriber' );
		$this->plugin()->first_run();
		$this->assertSame( '1', (string) get_option( Installer::OPTION_PENDING_SKELETONS ), 'Waits for a user who manages compliance.' );

		$this->login_as( 'administrator' );
		$this->plugin()->first_run();
		$this->assertFalse( get_option( Installer::OPTION_PENDING_SKELETONS ) );

		$terms = $this->plugin()->repository()->get( 'terms_of_sale', 'it' );
		$this->assertSame( Document::STATUS_DRAFT, $terms->status );
		$this->assertStringContainsString( '{#seller_identity}', $terms->markdown );
		$this->assertNull( $this->plugin()->repository()->find( 'terms_of_sale', 'en' ) );

		$this->plugin()->repository()->save( 'terms_of_sale', 'it', 'Edited' );
		$this->plugin()->first_run();
		$this->assertSame( 'Edited', $this->plugin()->repository()->get( 'terms_of_sale', 'it' )->markdown, 'Runs only once.' );
	}

	public function test_installer_schedules_skeletons_only_on_the_first_install() {
		delete_option( Installer::OPTION_DB_VERSION );
		delete_option( Installer::OPTION_PENDING_SKELETONS );
		Installer::install();
		$this->assertSame( '1', (string) get_option( Installer::OPTION_PENDING_SKELETONS ) );

		delete_option( Installer::OPTION_PENDING_SKELETONS );
		Installer::install();
		$this->assertFalse( get_option( Installer::OPTION_PENDING_SKELETONS ), 'Reactivation does not regenerate.' );
	}
}
