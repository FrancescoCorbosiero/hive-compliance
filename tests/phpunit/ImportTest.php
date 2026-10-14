<?php
/**
 * Bundle round-trip, import validation rules (one failing fixture each), review and apply.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Document\Document;
use Hive\Compliance\Document\Markdown;
use Hive\Compliance\Export\BundleExporter;
use Hive\Compliance\Export\BundleFormat;
use Hive\Compliance\Export\Selection;
use Hive\Compliance\Import\BlockReport;
use Hive\Compliance\Import\BundleParser;
use Hive\Compliance\Import\Importer;
use Hive\Compliance\Import\ImportSession;
use Hive\Compliance\Admin\ImportScreen;
use Hive\Compliance\Export\JsonExporter;
use Hive\Compliance\Lint\Finding;

class ImportTest extends Hive_TestCase {

	const BASE = "## Venditore {#seller_identity}\n\n{{business.legal_name}}, P.IVA {{business.vat_id}}.";

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
		$this->plugin()->business()->save( array( 'legal_name' => 'Rossi S.r.l.' ) );
		$this->plugin()->placeholders()->reset();
		$this->login_as( 'administrator' );

		$repo = $this->plugin()->repository();
		$repo->save( 'terms_of_sale', 'it', self::BASE, array( 'status' => Document::STATUS_PUBLISHED ) );
		$repo->save( 'dispute_resolution', 'it', "## Reclami {#complaints_contact}\n\n{{business.email}}" );
		$repo->flush();
	}

	private function importer(): Importer {
		return new Importer( $this->plugin() );
	}

	private function export(): string {
		return ( new BundleExporter( $this->plugin() ) )->export( Selection::from_input( array( 'include_internal' => true ), $this->plugin()->languages() ) );
	}

	/**
	 * Loads a fixture and fills in the current hash and version of the seeded documents.
	 */
	private function load_fixture( string $file, array $extra = array() ): string {
		$content = $this->fixture( 'import/' . $file );
		$doc_id  = false !== strpos( $content, 'id="dispute_resolution"' ) ? 'dispute_resolution' : 'terms_of_sale';
		$current = $this->plugin()->repository()->get( $doc_id, 'it' );
		return strtr(
			$content,
			array_merge(
				array(
					'%HASH%'    => $current->hash(),
					'%VERSION%' => (string) $current->version,
				),
				$extra
			)
		);
	}

	private function report_for( array $analysis, string $key ): ?BlockReport {
		foreach ( $analysis['reports'] as $report ) {
			if ( $report->key() === $key ) {
				return $report;
			}
		}
		return null;
	}

	public function test_export_parse_round_trip_is_byte_stable() {
		$repo = $this->plugin()->repository();
		$repo->save( 'privacy_policy', 'it', "## Titolare \"Rossi\" {#controller_identity}\n\nTesto con \\*escape\\*, `codice`, è à ü.\n\n---\n\ntitle: not front matter\n\n```\n---\n```\n\nRiga con due spazi  \nfine" );
		$repo->save( 'cookie_policy', 'en', '' );
		$bundle = $this->export();

		$parsed = ( new BundleParser() )->parse( $bundle );
		$this->assertSame( array(), $parsed['errors'] );
		$this->assertNotEmpty( $parsed['blocks'] );

		$serialized = array();
		foreach ( $parsed['blocks'] as $block ) {
			$this->assertSame( array(), $block->problems, $block->key() );
			$this->assertSame( $block->raw, $block->serialize(), $block->key() );
			$this->assertSame( $repo->get( $block->id(), $block->lang() )->markdown, $block->body, $block->key() );
			$serialized[] = $block->serialize();
		}

		$tail = substr( $bundle, strpos( $bundle, BundleFormat::CONTEXT_END ) + strlen( BundleFormat::CONTEXT_END ) + 2 );
		$this->assertSame( $tail, implode( "\n\n", $serialized ) . "\n", 'Re-serialising the parsed blocks gives back the exported bytes.' );
	}

	public function test_importing_the_untouched_export_shows_zero_changes() {
		$analysis = $this->importer()->analyze( $this->export() );
		$this->assertSame( array(), $analysis['errors'] );
		foreach ( $analysis['reports'] as $report ) {
			$this->assertSame( BlockReport::UNCHANGED, $report->status(), $report->key() );
			$this->assertSame( array(), $report->findings, $report->key() );
		}
	}

	public function test_crlf_and_bom_do_not_count_as_changes() {
		$bundle   = "\xEF\xBB\xBF" . str_replace( "\n", "\r\n", $this->export() );
		$analysis = $this->importer()->analyze( $bundle );
		$statuses = array_unique( array_map( static fn( $report ) => $report->status(), $analysis['reports'] ) );
		$this->assertSame( array( BlockReport::UNCHANGED ), array_values( $statuses ) );
	}

	/**
	 * Fixture => [rule, severity].
	 */
	public function rule_fixtures(): array {
		return array(
			array( '01-delimiter-missing-end.md', 'delimiter_missing_end', Finding::ERROR ),
			array( '02-delimiter-end-id-mismatch.md', 'delimiter_end_id_mismatch', Finding::ERROR ),
			array( '03-delimiter-front-matter-missing.md', 'delimiter_front_matter_missing', Finding::ERROR ),
			array( '04-delimiter-attributes.md', 'delimiter_attributes', Finding::ERROR ),
			array( '05-unknown-document.md', 'unknown_document', Finding::ERROR ),
			array( '06-unknown-language.md', 'unknown_language', Finding::ERROR ),
			array( '07-type-mismatch.md', 'type_mismatch', Finding::ERROR ),
			array( '08-duplicate-block.md', 'duplicate_block', Finding::ERROR ),
			array( '10-placeholder-removed.md', 'placeholder_removed', Finding::ERROR ),
			array( '11-unknown-namespace.md', 'unknown_namespace', Finding::ERROR ),
			array( '12-malformed-placeholder.md', 'malformed_placeholder', Finding::ERROR ),
			array( '13-html-tag.md', 'html_tag', Finding::ERROR ),
			array( '14-script.md', 'script', Finding::ERROR ),
			array( '15-javascript-url.md', 'javascript_url', Finding::ERROR ),
			array( '16-event-handler.md', 'event_handler', Finding::ERROR ),
			array( '21-catalog-odr-link.md', 'catalog_odr_link', Finding::ERROR ),
			array( '17-literal-url.md', 'literal_url', Finding::WARNING ),
			array( '18-changelog-note-empty.md', 'changelog_note_empty', Finding::WARNING ),
			array( '19-todo-data.md', 'todo_data', Finding::WARNING ),
			array( '20-missing-heading.md', 'missing_heading', Finding::WARNING ),
		);
	}

	/**
	 * @dataProvider rule_fixtures
	 */
	public function test_each_validation_rule_has_a_failing_fixture( string $file, string $rule, string $severity ) {
		$analysis = $this->importer()->analyze( $this->load_fixture( $file ) );
		$findings = array();
		foreach ( $analysis['reports'] as $report ) {
			foreach ( $report->findings as $finding ) {
				$findings[ $finding->rule ] = $finding;
			}
		}
		$this->assertArrayHasKey( $rule, $findings, $file . ' → ' . implode( ', ', array_keys( $findings ) ) );
		$this->assertSame( $severity, $findings[ $rule ]->severity );

		$report = $analysis['reports'][ count( $analysis['reports'] ) - 1 ];
		$hashes = array( $report->key() => $report->current_hash() );
		$result = $this->importer()->apply( $this->load_fixture( $file ), array( $report->key() ), $hashes, false );
		if ( Finding::ERROR === $severity ) {
			$this->assertSame( BlockReport::INVALID, $report->status() );
			$this->assertSame( array(), $result['applied'], 'Errors cannot be applied.' );
		} else {
			$this->assertSame( BlockReport::CHANGED, $report->status() );
			$this->assertCount( 1, $result['applied'], 'Warnings can be applied after confirmation.' );
		}
	}

	/**
	 * Findings of one document in an analysed bundle, keyed by rule.
	 */
	private function findings_for( array $analysis, string $key ): array {
		$findings = array();
		foreach ( $this->report_for( $analysis, $key )->findings as $finding ) {
			$findings[ $finding->rule ] = $finding;
		}
		return $findings;
	}

	public function test_rewritten_heading_text_with_the_anchor_kept_raises_no_heading_warning() {
		$entry = $this->plugin()->catalog()->get( 'terms_of_sale' );
		$this->plugin()->repository()->save( 'terms_of_sale', 'it', $this->plugin()->skeletons()->skeleton( $entry, 'it' ) );
		$bundle = $this->export();

		// The model rewrites every heading of the document in its own words, keeping the anchors.
		$rewritten = preg_replace_callback(
			'~(<!-- hive:begin id="terms_of_sale" lang="it".*?<!-- hive:end id="terms_of_sale" -->)~s',
			static fn( $m ) => preg_replace( '/^## .+ \{#([a-z0-9_]+)\}$/m', '## Sezione riscritta ($1) {#$1}', $m[1] ),
			$bundle
		);
		$this->assertNotSame( $bundle, $rewritten );
		$findings = $this->findings_for( $this->importer()->analyze( $rewritten ), 'terms_of_sale|it' );
		$this->assertArrayNotHasKey( 'missing_heading', $findings );

		// The exact label without its anchor no longer counts.
		$label     = $entry->required_elements[0]['labels']['it'];
		$no_anchor = str_replace( '## ' . $label . ' {#seller_identity}', '## ' . $label, $bundle );
		$findings  = $this->findings_for( $this->importer()->analyze( $no_anchor ), 'terms_of_sale|it' );
		$this->assertSame( array( $label . ' {#seller_identity}' ), $findings['missing_heading']->details );
	}

	public function test_fragments_are_imported_without_a_heading_check() {
		$this->plugin()->repository()->save( 'frag_checkout_terms_consent', 'it', '{{links.terms_of_sale}} {{links.withdrawal_policy}} {{links.privacy_policy}}' );
		$bundle   = $this->export();
		$text     = 'Ho letto le [condizioni di vendita]({{links.terms_of_sale}}), le [informazioni sul recesso]({{links.withdrawal_policy}}) e l\'[informativa privacy]({{links.privacy_policy}}).';
		$imported = str_replace( "---\n{{links.terms_of_sale}} {{links.withdrawal_policy}} {{links.privacy_policy}}\n<!-- hive:end", "---\n" . $text . "\n<!-- hive:end", $bundle );
		$this->assertNotSame( $bundle, $imported );

		$analysis = $this->importer()->analyze( $imported );
		$this->assertSame( BlockReport::CHANGED, $this->report_for( $analysis, 'frag_checkout_terms_consent|it' )->status() );
		$this->assertArrayNotHasKey( 'missing_heading', $this->findings_for( $analysis, 'frag_checkout_terms_consent|it' ) );
	}

	public function test_bundles_exported_with_the_previous_header_still_import() {
		$bundle = $this->export();
		$old    = str_replace( BundleFormat::HEADER, str_replace( " Heading text may be rewritten in the document's language as long as its `{#key}` anchor is kept exactly.", '', BundleFormat::HEADER ), $bundle );
		$this->assertNotSame( $bundle, $old );
		$analysis = $this->importer()->analyze( $old );
		$this->assertSame( array(), $analysis['errors'] );
		foreach ( $analysis['reports'] as $report ) {
			$this->assertSame( BlockReport::UNCHANGED, $report->status(), $report->key() );
		}
	}

	public function test_base_hash_conflict_is_flagged_with_a_three_way_base() {
		$old_hash = Markdown::hash( self::BASE );
		$this->plugin()->repository()->save( 'terms_of_sale', 'it', self::BASE . "\n\nModifica fatta sul sito dopo l'export." );
		$this->plugin()->repository()->flush();

		$analysis = $this->importer()->analyze( $this->load_fixture( '09-base-hash-conflict.md', array( '%OLD_HASH%' => $old_hash ) ) );
		$report   = $this->report_for( $analysis, 'terms_of_sale|it' );
		$this->assertTrue( $report->has_rule( 'base_hash_conflict' ) );
		$this->assertTrue( $report->has( Finding::CONFLICT ) );
		$this->assertSame( self::BASE, $report->base_markdown, 'The exported version is found among the revisions.' );
		$this->assertSame( BlockReport::CHANGED, $report->status(), 'A conflict can be applied after confirmation.' );
	}

	public function test_bundle_level_structure_errors() {
		$analysis = $this->importer()->analyze( $this->fixture( 'import/90-stray-end.md' ) );
		$this->assertNotEmpty( $analysis['errors'] );
		$this->assertSame( array(), $analysis['reports'] );
	}

	public function test_context_section_is_ignored() {
		$analysis = $this->importer()->analyze( $this->load_fixture( '91-context-ignored.md' ) );
		$this->assertCount( 1, $analysis['reports'], 'Delimiters inside the context section are ignored.' );
		$report = $analysis['reports'][0];
		$this->importer()->apply( $this->load_fixture( '91-context-ignored.md' ), array( $report->key() ), array( $report->key() => $report->current_hash() ), false );
		$this->assertSame( 'Rossi S.r.l.', $this->plugin()->business()->value( 'legal_name' ), 'Business data is never imported.' );
	}

	public function test_apply_creates_revision_version_changelog_and_review_date() {
		$bundle   = str_replace( "P.IVA {{business.vat_id}}.\n<!-- hive:end id=\"terms_of_sale\" -->", "P.IVA {{business.vat_id}}.\n\nNuove condizioni di consegna.\n<!-- hive:end id=\"terms_of_sale\" -->", $this->export() );
		$bundle   = preg_replace( '/(id="terms_of_sale" lang="it"[^\n]*\n---\n(?:[^\n]*\n){3})changelog_note: ""/', '$1changelog_note: "Added delivery terms (art. 61 Cod. Cons.)"', $bundle, 1 );
		$analysis = $this->importer()->analyze( $bundle );
		$report   = $this->report_for( $analysis, 'terms_of_sale|it' );
		$this->assertSame( BlockReport::CHANGED, $report->status() );
		$before = $this->plugin()->repository()->get( 'terms_of_sale', 'it' );

		$result = $this->importer()->apply( $bundle, array( 'terms_of_sale|it' ), array( 'terms_of_sale|it' => $report->current_hash() ), false );
		$this->assertCount( 1, $result['applied'] );
		$after = $this->plugin()->repository()->get( 'terms_of_sale', 'it' );
		$this->assertSame( $before->version + 1, $after->version );
		$changelog = $after->changelog;
		$last      = end( $changelog );
		$this->assertSame( 'import', $last['source'] );
		$this->assertSame( 'Added delivery terms (art. 61 Cod. Cons.)', $last['note'] );
		$this->assertGreaterThan( 0, $last['revision_id'] );
		$this->assertSame( current_time( 'Y-m-d' ), $after->last_reviewed );
		$this->assertSame( Document::STATUS_PUBLISHED, $after->status, 'A published document stays published.' );
		$this->assertStringContainsString( 'Nuove condizioni di consegna.', $after->markdown );

		$untouched = $this->plugin()->repository()->get( 'dispute_resolution', 'it' );
		$this->assertSame( 1, $untouched->version, 'Other documents are untouched.' );
	}

	public function test_status_stays_draft_unless_publish_on_apply() {
		$bundle = str_replace( "{{business.email}}\n<!-- hive:end id=\"dispute_resolution\" -->", "{{business.email}}\n\n## Organismi ADR {#adr_bodies}\n<!-- hive:end id=\"dispute_resolution\" -->", $this->export() );
		$report = $this->report_for( $this->importer()->analyze( $bundle ), 'dispute_resolution|it' );
		$hashes = array( 'dispute_resolution|it' => $report->current_hash() );

		$this->importer()->apply( $bundle, array( 'dispute_resolution|it' ), $hashes, false );
		$this->assertSame( Document::STATUS_DRAFT, $this->plugin()->repository()->get( 'dispute_resolution', 'it' )->status );

		$bundle2 = str_replace( "## Organismi ADR {#adr_bodies}\n", "## Organismi ADR {#adr_bodies}\n\nTODO(data): nome dell'organismo ADR\n", $this->export() );
		$report2 = $this->report_for( $this->importer()->analyze( $bundle2 ), 'dispute_resolution|it' );
		$this->importer()->apply( $bundle2, array( 'dispute_resolution|it' ), array( 'dispute_resolution|it' => $report2->current_hash() ), true );
		$this->assertSame( Document::STATUS_PUBLISHED, $this->plugin()->repository()->get( 'dispute_resolution', 'it' )->status );
	}

	public function test_unaccepted_or_stale_documents_are_not_applied() {
		$bundle = str_replace( "P.IVA {{business.vat_id}}.\n<!-- hive:end id=\"terms_of_sale\" -->", "P.IVA {{business.vat_id}}. Modificato.\n<!-- hive:end id=\"terms_of_sale\" -->", $this->export() );
		$report = $this->report_for( $this->importer()->analyze( $bundle ), 'terms_of_sale|it' );

		$none = $this->importer()->apply( $bundle, array(), array(), false );
		$this->assertSame( array(), $none['applied'] );

		$stale = $this->importer()->apply( $bundle, array( 'terms_of_sale|it' ), array( 'terms_of_sale|it' => 'sha256:old' ), false );
		$this->assertSame( array(), $stale['applied'] );
		$this->assertArrayHasKey( 'terms_of_sale|it', $stale['skipped'] );
		$this->assertSame( self::BASE, $this->plugin()->repository()->get( 'terms_of_sale', 'it' )->markdown );
		$this->assertSame( BlockReport::CHANGED, $report->status() );
	}

	public function test_review_offers_select_all_when_several_documents_can_be_accepted() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'hive-compliance_page_hive-compliance-import' );
		$screen = new ImportScreen( $this->plugin() );
		$render = static function ( string $bundle ) use ( $screen ): string {
			$_GET = array( 'session' => ImportSession::create( $bundle, ImportSession::KIND_BUNDLE ) );
			ob_start();
			$screen->render();
			$_GET = array();
			return (string) ob_get_clean();
		};

		$one  = str_replace( "{{business.email}}\n<!-- hive:end id=\"dispute_resolution\" -->", "{{business.email}} Aggiornato.\n<!-- hive:end id=\"dispute_resolution\" -->", $this->export() );
		$html = $render( $one );
		$this->assertStringNotContainsString( 'data-hive-select="hive-import-reports"', $html, 'One document: nothing to select in bulk.' );

		$two  = str_replace( "P.IVA {{business.vat_id}}.\n<!-- hive:end id=\"terms_of_sale\" -->", "P.IVA {{business.vat_id}}. Aggiornato.\n<!-- hive:end id=\"terms_of_sale\" -->", $one );
		$html = $render( $two );
		$this->assertSame( 2, substr_count( $html, 'data-hive-select="hive-import-reports"' ), 'Above the documents and next to Apply.' );
		$this->assertStringContainsString( '<div id="hive-import-reports">', $html );
		$this->assertSame( 2, substr_count( $html, 'name="accept[]"' ) );
	}

	public function test_review_and_restore_screens_render() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'hive-compliance_page_hive-compliance-import' );
		$screen = new ImportScreen( $this->plugin() );

		$bundle = str_replace( "P.IVA {{business.vat_id}}.\n<!-- hive:end id=\"terms_of_sale\" -->", "P.IVA {{business.vat_id}}. <b>x</b>\n<!-- hive:end id=\"terms_of_sale\" -->", $this->export() );
		$bundle = str_replace( "{{business.email}}\n<!-- hive:end id=\"dispute_resolution\" -->", "{{business.email}} Aggiornato.\n<!-- hive:end id=\"dispute_resolution\" -->", $bundle );
		$_GET   = array( 'session' => ImportSession::create( $bundle, ImportSession::KIND_BUNDLE ) );
		ob_start();
		$screen->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'diff-addedline', $html );
		$this->assertStringContainsString( 'name="accept[]" value="dispute_resolution|it"', $html, 'Changed documents can be accepted.' );
		$this->assertStringNotContainsString( 'value="terms_of_sale|it"', $html, 'Documents with errors cannot be accepted.' );
		$this->assertTrue( $this->report_for( $this->importer()->analyze( $bundle ), 'terms_of_sale|it' )->has_rule( 'html_tag' ) );

		$_GET = array( 'session' => ImportSession::create( ( new JsonExporter( $this->plugin() ) )->export(), ImportSession::KIND_JSON ) );
		ob_start();
		$screen->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="confirm"', $html );

		$_GET = array();
		ob_start();
		$screen->render();
		$this->assertStringContainsString( 'name="bundle_file"', (string) ob_get_clean() );
	}
}
