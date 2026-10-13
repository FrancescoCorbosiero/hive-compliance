<?php
/**
 * Render pipeline, visibility rules, shortcodes and block.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Document\Document;

class RenderTest extends Hive_TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->plugin()->settings()->update(
			array(
				'default_language'  => 'it',
				'enabled_languages' => array( 'it', 'en' ),
			)
		);
		$this->plugin()->placeholders()->reset();
	}

	private function publish( string $doc_id, string $markdown, string $lang = 'it', string $status = Document::STATUS_PUBLISHED ): Document {
		$previous = get_current_user_id();
		$this->login_as( 'administrator' );
		$document = $this->plugin()->repository()->save( $doc_id, $lang, $markdown, array( 'status' => $status ) );
		wp_set_current_user( $previous );
		$this->plugin()->repository()->flush();
		return $document;
	}

	private function as_visitor(): void {
		wp_set_current_user( 0 );
	}

	public function test_published_document_renders_markdown_with_values() {
		$this->plugin()->business()->save( array( 'legal_name' => 'Rossi & Figli S.r.l.' ) );
		$this->publish( 'legal_notice', "## Titolare {#identity}\n\nVenditore: **{{business.legal_name}}**" );
		$this->as_visitor();

		$html = $this->plugin()->renderer()->render_document( 'legal_notice', 'it' );
		$this->assertStringContainsString( '<h2 id="identity">Titolare</h2>', $html );
		$this->assertStringContainsString( '<strong>Rossi &amp; Figli S.r.l.</strong>', $html );
		$this->assertStringContainsString( 'class="hive-document hive-document--legal_notice"', $html );
	}

	public function test_html_scripts_and_javascript_links_are_neutralised() {
		$this->publish( 'terms_of_sale', "<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n[click](javascript:alert(1))\n\n<b onclick=\"x()\">b</b>" );
		$this->as_visitor();

		$html = $this->plugin()->renderer()->render_document( 'terms_of_sale', 'it' );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<b ', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html, 'Raw HTML is shown as text.' );
	}

	public function test_missing_values_are_hidden_from_visitors_and_marked_for_managers() {
		$this->publish( 'legal_notice', 'P.IVA: {{business.vat_id}}' );

		$this->as_visitor();
		$visitor = $this->plugin()->renderer()->render_document( 'legal_notice', 'it' );
		$this->assertStringContainsString( 'P.IVA: </p>', $visitor );
		$this->assertStringNotContainsString( 'missing', $visitor );

		$this->login_as( 'administrator' );
		$manager = $this->plugin()->renderer()->render_document( 'legal_notice', 'it' );
		$this->assertStringContainsString( '<mark class="hive-missing">[missing: business.vat_id]</mark>', $manager );
	}

	public function test_drafts_are_hidden_from_visitors_and_flagged_for_managers() {
		$this->publish( 'cookie_policy', '## Cookie', 'it', Document::STATUS_DRAFT );

		$this->as_visitor();
		$this->assertSame( '', $this->plugin()->renderer()->render_document( 'cookie_policy', 'it' ) );
		$this->assertSame( '', do_shortcode( '[hive_document id="cookie_policy" lang="it"]' ) );

		$this->login_as( 'editor' );
		$this->assertSame( '', $this->plugin()->renderer()->render_document( 'cookie_policy', 'it' ), 'Editors are not managers.' );

		$this->login_as( 'administrator' );
		$html = $this->plugin()->renderer()->render_document( 'cookie_policy', 'it' );
		$this->assertStringContainsString( 'hive-document-notice', $html );
		$this->assertStringContainsString( '<h2>Cookie</h2>', $html );
	}

	public function test_internal_documents_never_render_on_the_front_end() {
		$this->publish( 'int_ropa', '## Registro dei trattamenti {{business.legal_name}}' );
		$block = '<!-- wp:hive/document {"docId":"int_ropa","lang":"it"} /-->';

		foreach ( array( 0, $this->login_as( 'administrator' ) ) as $user_id ) {
			wp_set_current_user( $user_id );
			$this->assertSame( '', $this->plugin()->renderer()->render_document( 'int_ropa', 'it' ) );
			$this->assertSame( '', $this->plugin()->renderer()->render_document( 'int_ropa' ) );
			$this->assertSame( '', $this->plugin()->renderer()->render_fragment( 'int_ropa', 'it' ) );
			$this->assertSame( '', do_shortcode( '[hive_document id="int_ropa" lang="it"]' ) );
			$this->assertSame( '', do_shortcode( '[hive_fragment id="int_ropa"]' ) );
			$this->assertSame( '', hive_fragment( 'int_ropa', 'it' ) );
			$this->assertSame( '', trim( do_blocks( $block ) ) );
			$this->assertStringNotContainsString( 'Registro', apply_filters( 'the_content', $block ) );
		}
	}

	public function test_fragments_render_inline_and_unwrap_missing_links() {
		$this->publish( 'frag_checkout_terms_consent', 'Ho letto i [termini]({{links.terms_of_sale}}).' );
		$this->as_visitor();
		$this->assertSame( 'Ho letto i termini.', hive_fragment( 'frag_checkout_terms_consent', 'it' ) );

		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$this->plugin()->bindings()->set( 'terms_of_sale', 'it', $page );
		$this->assertSame( 'Ho letto i <a href="' . get_permalink( $page ) . '">termini</a>.', hive_fragment( 'frag_checkout_terms_consent', 'it' ) );
		$this->assertSame( '', hive_fragment( 'terms_of_sale', 'it' ), 'Only fragments render through hive_fragment().' );
	}

	public function test_only_allow_listed_shortcodes_run() {
		add_shortcode( 'cmplz-document', static fn() => '<table class="cookies">COOKIE LIST</table>' );
		add_shortcode( 'evil', static fn() => 'EVIL' );
		$this->publish( 'cookie_policy', "Elenco:\n\n[cmplz-document type=\"cookie-statement\" region=\"eu\"]\n\n[evil]" );
		$this->as_visitor();

		$html = $this->plugin()->renderer()->render_document( 'cookie_policy', 'it' );
		$this->assertStringContainsString( 'COOKIE LIST', $html );
		$this->assertStringNotContainsString( 'EVIL', $html );
		$this->assertStringContainsString( '&#91;evil]', $html );

		$content = apply_filters( 'the_content', '<!-- wp:hive/document {"docId":"cookie_policy","lang":"it"} /-->' );
		$this->assertStringContainsString( 'COOKIE LIST', $content );
		$this->assertStringNotContainsString( 'EVIL', $content );

		remove_shortcode( 'cmplz-document' );
		remove_shortcode( 'evil' );
	}

	public function test_current_language_falls_back_to_the_default_language() {
		$this->publish( 'shipping_policy', '## Spedizioni', 'it' );
		$this->publish( 'shipping_policy', '## Shipping', 'en', Document::STATUS_DRAFT );
		$this->as_visitor();

		$this->assertStringContainsString( 'Spedizioni', $this->plugin()->renderer()->render_document( 'shipping_policy' ) );
		$this->assertSame( '', $this->plugin()->renderer()->render_document( 'shipping_policy', 'en' ), 'An explicit language does not fall back.' );
	}

	public function test_block_render_wraps_the_document() {
		$this->publish( 'refund_policy', 'Rimborsi entro 14 giorni.' );
		$this->as_visitor();
		$html = do_blocks( '<!-- wp:hive/document {"docId":"refund_policy","lang":"it"} /-->' );
		$this->assertStringContainsString( 'wp-block-hive-document', $html );
		$this->assertStringContainsString( 'Rimborsi entro 14 giorni.', $html );
		$this->assertSame( '', trim( do_blocks( '<!-- wp:hive/document {"docId":"nope"} /-->' ) ) );
	}

	public function test_preview_renders_any_markdown_with_markers() {
		$entry  = $this->plugin()->catalog()->get( 'legal_notice' );
		$result = $this->plugin()->renderer()->render_markdown( "P.IVA {{business.vat_id}}\n\n## Sede", $entry, 'it', true );
		$this->assertSame( array( 'business.vat_id' ), $result['missing'] );
		$this->assertStringContainsString( '[missing: business.vat_id]', $result['html'] );
		$this->assertStringContainsString( '<h2>Sede</h2>', $result['html'] );
	}
}
