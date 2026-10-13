<?php
/**
 * Page binding and the thin-shell guard.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Render\PageManager;
use Hive\Compliance\Render\ShellGuard;

class PageBindingTest extends Hive_TestCase {

	public function test_create_makes_a_draft_shell_page_and_binds_it() {
		$this->login_as( 'administrator' );
		$page_id = $this->plugin()->page_manager()->create( 'privacy_policy', 'it' );

		$this->assertIsInt( $page_id );
		$page = get_post( $page_id );
		$this->assertSame( 'draft', $page->post_status );
		$this->assertSame( 'Informativa sulla privacy', $page->post_title );
		$this->assertSame( PageManager::shell_content( 'privacy_policy', 'it' ), $page->post_content );
		$this->assertSame( $page_id, $this->plugin()->bindings()->get( 'privacy_policy', 'it' ) );
		$this->assertSame( array(), ShellGuard::inspect( $page->post_content, 'privacy_policy' ) );
	}

	public function test_only_page_documents_can_be_bound() {
		$this->assertWPError( $this->plugin()->page_manager()->create( 'frag_age_gate', 'it' ) );
		$this->assertWPError( $this->plugin()->page_manager()->create( 'int_ropa', 'it' ) );
		$this->assertWPError( $this->plugin()->page_manager()->create( 'unknown_doc', 'it' ) );
	}

	public function test_assign_reports_extra_content_and_refuses_taken_pages() {
		$page_id  = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => "<!-- wp:hive/document {\"docId\":\"terms_of_sale\"} /-->\n\n<!-- wp:paragraph --><p>Old terms pasted here</p><!-- /wp:paragraph -->",
			)
		);
		$problems = $this->plugin()->page_manager()->assign( 'terms_of_sale', 'it', $page_id );
		$this->assertSame( array( ShellGuard::PROBLEM_EXTRA_CONTENT ), $problems );
		$this->assertSame( $page_id, $this->plugin()->bindings()->get( 'terms_of_sale', 'it' ) );

		$this->assertWPError( $this->plugin()->page_manager()->assign( 'privacy_policy', 'it', $page_id ) );
		$this->assertWPError( $this->plugin()->page_manager()->assign( 'privacy_policy', 'it', 999999 ) );
	}

	public function test_shell_guard_classifies_page_content() {
		$this->assertSame( array(), ShellGuard::inspect( '<!-- wp:hive/document {"docId":"cookie_policy"} /-->', 'cookie_policy' ) );
		$this->assertSame( array(), ShellGuard::inspect( "<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->\n<!-- wp:hive/document {\"docId\":\"cookie_policy\"} /-->", 'cookie_policy' ) );
		$this->assertSame( array(), ShellGuard::inspect( '[hive_document id="cookie_policy" lang="it"]', 'cookie_policy' ), 'Classic content with the shortcode only.' );
		$this->assertSame( array(), ShellGuard::inspect( "<!-- wp:shortcode -->\n[hive_document id=\"cookie_policy\"]\n<!-- /wp:shortcode -->", 'cookie_policy' ) );

		$this->assertSame( array( ShellGuard::PROBLEM_EXTRA_CONTENT ), ShellGuard::inspect( "[hive_document id=\"cookie_policy\"]\n\nWe use cookies.", 'cookie_policy' ) );
		$this->assertSame( array( ShellGuard::PROBLEM_OTHER_DOCUMENT ), ShellGuard::inspect( '<!-- wp:hive/document {"docId":"privacy_policy"} /-->', 'cookie_policy' ) );
		$this->assertSame( array( ShellGuard::PROBLEM_EXTRA_CONTENT, ShellGuard::PROBLEM_MISSING_BLOCK ), ShellGuard::inspect( '<p>Full legal text</p>', 'cookie_policy' ) );
	}

	public function test_audit_lists_bound_pages_with_problems() {
		$clean = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => PageManager::shell_content( 'legal_notice', 'it' ),
			)
		);
		$dirty = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => PageManager::shell_content( 'cookie_policy', 'it' ) . '<p>Extra</p>',
			)
		);
		$this->plugin()->bindings()->set( 'legal_notice', 'it', $clean );
		$this->plugin()->bindings()->set( 'cookie_policy', 'it', $dirty );

		$audit = $this->plugin()->shell_guard()->audit();
		$this->assertSame( array(), $audit['legal_notice']['it']['problems'] );
		$this->assertSame( array( ShellGuard::PROBLEM_EXTRA_CONTENT ), $audit['cookie_policy']['it']['problems'] );
	}

	public function test_unbind_keeps_the_page() {
		$this->login_as( 'administrator' );
		$page_id = $this->plugin()->page_manager()->create( 'shipping_policy', 'it' );
		$this->plugin()->page_manager()->unbind( 'shipping_policy', 'it' );
		$this->assertSame( 0, $this->plugin()->bindings()->get( 'shipping_policy', 'it' ) );
		$this->assertNotNull( get_post( $page_id ) );
	}
}
