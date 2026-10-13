<?php
/**
 * Dashboard data, editor saving, profile wizard, REST preview and screen rendering.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Admin\EditorScreen;
use Hive\Compliance\Admin\Overview;
use Hive\Compliance\Admin\ProfileScreen;
use Hive\Compliance\Document\Document;
use Hive\Compliance\Profile\Profile;
use Hive\Compliance\Render\PageManager;

class AdminTest extends Hive_TestCase {

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

	public function test_overview_rows_and_cards() {
		$repo = $this->plugin()->repository();
		$repo->save(
			'legal_notice',
			'it',
			"## Identità {#identity}\n\n{{business.vat_id}}",
			array(
				'status'        => Document::STATUS_PUBLISHED,
				'last_reviewed' => gmdate( 'Y-m-d', strtotime( '-2 years' ) ),
				'checklist'     => array(
					'identity' => true,
					'register' => true,
				),
			)
		);
		$repo->save( 'cookie_policy', 'it', '## Cookie', array( 'last_reviewed' => gmdate( 'Y-m-d' ) ) );
		$live = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => PageManager::shell_content( 'cookie_policy', 'it' ) . '<p>Extra</p>',
			)
		);
		$this->plugin()->bindings()->set( 'cookie_policy', 'it', $live );
		$repo->flush();

		$overview = new Overview( $this->plugin() );
		$rows     = $overview->rows();
		$this->assertArrayHasKey( 'legal_notice', $rows );
		$this->assertArrayNotHasKey( 'b2b_terms', $rows, 'Only applicable documents by default.' );
		$this->assertArrayHasKey( 'b2b_terms', $overview->rows( false ) );

		$notice = $rows['legal_notice'];
		$this->assertSame( Document::STATUS_PUBLISHED, $notice['status'] );
		$this->assertTrue( $notice['overdue'] );
		$this->assertSame( 2, $notice['checklist_done'] );
		$this->assertSame( array( 'business.vat_id' ), $notice['missing'] );
		$this->assertSame( Document::STATUS_EMPTY, $notice['coverage']['en'] );
		$this->assertFalse( $rows['cookie_policy']['overdue'] );

		$cards   = $overview->cards( $rows );
		$unbound = array_map( static fn( $entry ) => $entry->id, $cards['unbound'] );
		$this->assertContains( 'legal_notice', $unbound );
		$this->assertNotContains( 'cookie_policy', $unbound );
		$this->assertContains( 'legal_notice', array_map( static fn( $entry ) => $entry->id, $cards['overdue'] ) );
		$this->assertContains( 'cookie_policy', array_map( static fn( $entry ) => $entry->id, $cards['live_drafts'] ) );
		$this->assertContains( 'cookie_policy', array_map( static fn( $entry ) => $entry->id, $cards['dirty_pages'] ) );
		$this->assertArrayHasKey( 'legal_name', $cards['business'] );
	}

	public function test_overdue_rules() {
		$doc = static fn( string $reviewed, string $markdown = 'x', string $status = 'published' ) => new Document( 1, 'a', 'it', $markdown, 1, $status, $reviewed, '', array(), array() );
		$now = strtotime( '2026-10-13' );
		$this->assertFalse( Overview::is_overdue( null, 12, $now ) );
		$this->assertFalse( Overview::is_overdue( $doc( '', '' ), 12, $now ), 'Empty documents are never overdue.' );
		$this->assertFalse( Overview::is_overdue( $doc( '', 'x', 'draft' ), 12, $now ), 'Drafts are not in force.' );
		$this->assertTrue( Overview::is_overdue( $doc( '' ), 12, $now ), 'Never reviewed.' );
		$this->assertTrue( Overview::is_overdue( $doc( '2025-10-12' ), 12, $now ) );
		$this->assertFalse( Overview::is_overdue( $doc( '2025-10-14' ), 12, $now ) );
	}

	public function test_editor_save_filters_checklist_and_marks_review() {
		$screen = new EditorScreen( $this->plugin() );
		$doc    = $screen->save_document(
			array(
				'doc'           => 'withdrawal_form',
				'lang'          => 'it',
				'markdown'      => '## Destinatario {#addressee}',
				'status'        => 'published',
				'checklist'     => array( 'addressee', 'not_a_key' ),
				'mark_reviewed' => true,
				'note'          => 'Prima stesura',
			)
		);
		$this->assertInstanceOf( Document::class, $doc );
		$this->assertTrue( $doc->checklist['addressee'] );
		$this->assertFalse( $doc->checklist['statement'] );
		$this->assertArrayNotHasKey( 'not_a_key', $doc->checklist );
		$this->assertSame( current_time( 'Y-m-d' ), $doc->last_reviewed );
		$this->assertSame( Document::STATUS_PUBLISHED, $doc->status );
		$this->assertSame( 'Modulo di recesso tipo', get_post_field( 'post_title', $doc->post_id ) );

		$this->assertWPError( $screen->save_document( array( 'doc' => 'withdrawal_form', 'lang' => 'de', 'markdown' => 'x' ) ) );
		$this->assertWPError( $screen->save_document( array( 'doc' => 'nope', 'lang' => 'it', 'markdown' => 'x' ) ) );
	}

	public function test_profile_wizard_merges_one_step_at_a_time() {
		$profile = $this->plugin()->profile()->get();
		$merged  = ProfileScreen::merge_step( $profile, 'markets', array( 'markets_eu' => array( 'IT', 'FR' ), 'markets_us' => array( 'US-CA' ) ) );
		$this->assertSame( array( 'IT', 'FR', 'US-CA' ), $merged['markets'] );
		$this->assertSame( $profile['customer_types'], $merged['customer_types'], 'Other steps are untouched.' );

		$done = ProfileScreen::merge_step( $merged, 'features', array( 'features' => array( 'reviews' ) ) );
		$this->assertTrue( $done['completed'] );
		$this->assertSame( array(), ProfileScreen::merge_step( $done, 'flags', array() )['product_flags'] );
	}

	public function test_profile_shows_where_answers_come_from_until_saved() {
		delete_option( Profile::OPTION );
		$this->assertStringContainsString( 'Pre-filled from:', $this->render_screen( 'profile', array( 'step' => 'seller' ) ) );

		$this->plugin()->profile()->save(
			array(
				'seller_country' => 'IT',
				'markets'        => array( 'IT' ),
			)
		);
		$this->assertStringNotContainsString( 'Pre-filled from:', $this->render_screen( 'profile', array( 'step' => 'seller' ) ), 'Saved answers are the merchant\'s own.' );
	}

	public function test_rest_preview_requires_the_capability_and_renders() {
		$request = new WP_REST_Request( 'POST', '/hive-compliance/v1/preview' );
		$request->set_body_params(
			array(
				'doc_id'   => 'legal_notice',
				'lang'     => 'it',
				'markdown' => "## Sede\n\n{{business.vat_id}} <b>x</b>",
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertStringContainsString( '<h2>Sede</h2>', $data['html'] );
		$this->assertSame( array( 'business.vat_id' ), $data['missing'] );
		$this->assertContains( 'html_tag', array_column( $data['findings'], 'rule' ) );

		$this->login_as( 'editor' );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );

		$this->login_as( 'administrator' );
		$unknown = new WP_REST_Request( 'POST', '/hive-compliance/v1/preview' );
		$unknown->set_body_params(
			array(
				'doc_id'   => 'nope',
				'lang'     => 'it',
				'markdown' => 'x',
			)
		);
		$this->assertSame( 404, rest_get_server()->dispatch( $unknown )->get_status() );
	}

	public function test_every_screen_renders() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'toplevel_page_hive-compliance' );
		$this->plugin()->repository()->save( 'privacy_policy', 'it', "## Titolare {#controller_identity}\n\n{{business.legal_name}}" );

		$requests = array(
			array( '', array() ),
			array( '', array( 'view' => 'all' ) ),
			array( 'documents', array() ),
			array( 'documents', array( 'doc' => 'privacy_policy', 'lang' => 'it' ) ),
			array( 'documents', array( 'doc' => 'int_ropa', 'lang' => 'en' ) ),
			array( 'business', array() ),
			array( 'profile', array() ),
			array( 'profile', array( 'step' => 'markets' ) ),
			array( 'profile', array( 'step' => 'features' ) ),
			array( 'profile', array( 'step' => 'summary' ) ),
			array( 'settings', array() ),
		);
		foreach ( $this->plugin()->screens() as $screen ) {
			foreach ( $requests as list( $slug, $query ) ) {
				if ( $slug !== $screen->slug() ) {
					continue;
				}
				$_GET = $query;
				ob_start();
				$screen->render();
				$html = (string) ob_get_clean();
				$this->assertStringContainsString( 'class="wrap hive-wrap"', $html, $slug . wp_json_encode( $query ) );
			}
		}
		$_GET = array();
	}
}
