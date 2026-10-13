<?php
/**
 * Document storage: CPT, capability, versions, changelog, revisions.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Document\Document;
use Hive\Compliance\Document\Markdown;
use Hive\Compliance\Document\PostType;
use Hive\Compliance\Plugin;

class RepositoryTest extends Hive_TestCase {

	public function test_post_type_is_private_with_revisions_and_no_ui() {
		$type = get_post_type_object( PostType::NAME );
		$this->assertNotNull( $type );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->show_ui );
		$this->assertFalse( $type->show_in_rest );
		$this->assertTrue( post_type_supports( PostType::NAME, 'revisions' ) );
	}

	public function test_administrators_get_the_capability_and_editors_do_not() {
		$this->assertTrue( get_role( 'administrator' )->has_cap( Plugin::CAPABILITY ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Plugin::CAPABILITY ) );

		$this->login_as( 'administrator' );
		$doc = $this->plugin()->repository()->save( 'terms_of_sale', 'it', '## Test' );
		$this->assertTrue( current_user_can( 'edit_post', $doc->post_id ) );

		$this->login_as( 'editor' );
		$this->assertFalse( current_user_can( 'edit_post', $doc->post_id ) );
		$this->assertFalse( current_user_can( 'read_post', $doc->post_id ) );
	}

	public function test_first_save_is_version_one_with_changelog_and_revision() {
		$this->login_as( 'administrator' );
		$doc = $this->plugin()->repository()->save(
			'privacy_policy',
			'en',
			"## Controller\n\nText",
			array(
				'note'  => 'Initial skeleton',
				'title' => 'Privacy policy',
			)
		);

		$this->assertInstanceOf( Document::class, $doc );
		$this->assertSame( 1, $doc->version );
		$this->assertSame( Document::STATUS_DRAFT, $doc->status );
		$this->assertCount( 1, $doc->changelog );
		$this->assertSame( 'manual', $doc->changelog[0]['source'] );
		$this->assertSame( 'Initial skeleton', $doc->changelog[0]['note'] );
		$this->assertGreaterThan( 0, $doc->changelog[0]['revision_id'] );
		$this->assertSame( "## Controller\n\nText", get_post( $doc->changelog[0]['revision_id'] )->post_content );
		$this->assertSame( 'private', get_post_status( $doc->post_id ) );
	}

	public function test_unchanged_content_does_not_bump_the_version() {
		$this->login_as( 'administrator' );
		$repo = $this->plugin()->repository();
		$repo->save( 'cookie_policy', 'it', '## A' );
		$doc = $repo->save( 'cookie_policy', 'it', "## A\n\n\n", array( 'status' => Document::STATUS_PUBLISHED ) );

		$this->assertSame( 1, $doc->version );
		$this->assertCount( 1, $doc->changelog );
		$this->assertSame( Document::STATUS_PUBLISHED, $doc->status );
	}

	public function test_changed_content_bumps_version_and_appends_changelog() {
		$this->login_as( 'administrator' );
		$repo = $this->plugin()->repository();
		$repo->save( 'shipping_policy', 'it', '## A' );
		$doc = $repo->save(
			'shipping_policy',
			'it',
			'## B',
			array(
				'source' => 'import',
				'note'   => 'Updated times',
			)
		);

		$this->assertSame( 2, $doc->version );
		$this->assertCount( 2, $doc->changelog );
		$this->assertSame( 'import', $doc->changelog[1]['source'] );
		$this->assertSame( '## A', $repo->version_markdown( $doc, 1 ) );
		$this->assertSame( '## B', $repo->version_markdown( $doc, 2 ) );
	}

	public function test_markdown_is_stored_byte_exact_even_with_kses_active() {
		$this->login_as( 'administrator' );
		kses_init_filters(); // Simulate a user without unfiltered_html.
		$markdown = "## Prezzi & tempi\n\nSe a < b e c > d: \\*non enfasi\\* &#128; <!-- x -->\n\n- {{business.legal_name}}";
		try {
			$doc = $this->plugin()->repository()->save( 'terms_of_sale', 'it', $markdown );
		} finally {
			kses_remove_filters();
		}

		$this->assertSame( $markdown, $doc->markdown );
		$this->assertSame( $markdown, get_post( $doc->post_id )->post_content );
		$this->assertSame( $markdown, get_post( $doc->changelog[0]['revision_id'] )->post_content );
	}

	public function test_empty_content_has_empty_status_and_no_version() {
		$this->login_as( 'administrator' );
		$doc = $this->plugin()->repository()->save( 'legal_notice', 'it', "  \n\n", array( 'status' => Document::STATUS_PUBLISHED ) );
		$this->assertSame( Document::STATUS_EMPTY, $doc->status );
		$this->assertSame( 0, $doc->version );
		$this->assertFalse( $doc->is_published() );
	}

	public function test_restoring_a_revision_is_a_new_version() {
		$this->login_as( 'administrator' );
		$repo = $this->plugin()->repository();
		$v1   = $repo->save( 'refund_policy', 'it', '## One' );
		$repo->save( 'refund_policy', 'it', '## Two' );

		wp_restore_post_revision( $v1->changelog[0]['revision_id'] );
		$repo->flush();
		$doc = $repo->get( 'refund_policy', 'it' );

		$this->assertSame( '## One', $doc->markdown );
		$this->assertSame( 3, $doc->version );
		$this->assertSame( 'manual', $doc->changelog[2]['source'] );
	}

	public function test_markdown_normalisation_is_idempotent() {
		$raw  = "\xEF\xBB\xBF\r\n\r\n## Title\r\nLine  \r\n\r\n";
		$once = Markdown::normalize( $raw );
		$this->assertSame( "## Title\nLine", $once );
		$this->assertSame( $once, Markdown::normalize( $once ) );
		$this->assertSame( 'sha256:' . hash( 'sha256', $once ), Markdown::hash( $once ) );
	}
}
