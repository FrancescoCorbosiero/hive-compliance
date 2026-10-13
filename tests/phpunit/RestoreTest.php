<?php
/**
 * JSON backup restore and upload checks.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Export\JsonExporter;
use Hive\Compliance\Import\ImportSession;
use Hive\Compliance\Import\JsonRestorer;
use Hive\Compliance\Import\Upload;
use Hive\Compliance\Render\PageManager;

class RestoreTest extends Hive_TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->plugin()->settings()->update(
			array(
				'default_language'  => 'it',
				'enabled_languages' => array( 'it', 'en' ),
			)
		);
		$this->login_as( 'administrator' );
	}

	public function test_restore_previews_then_restores_everything() {
		$repo = $this->plugin()->repository();
		$repo->save( 'legal_notice', 'it', '## Note v1' );
		$repo->save( 'legal_notice', 'it', '## Note v2', array( 'checklist' => array( 'identity' => true ) ) );
		$repo->save( 'cookie_policy', 'en', '## Cookies' );
		$this->plugin()->business()->save( array( 'legal_name' => 'Rossi S.r.l.' ) );
		$this->plugin()->profile()->save( array( 'markets' => array( 'IT', 'FR' ) ) );
		$page_id = $this->plugin()->page_manager()->create( 'legal_notice', 'it' );
		wp_update_post(
			array(
				'ID'        => $page_id,
				'post_name' => 'note-legali',
			)
		);
		$backup = ( new JsonExporter( $this->plugin() ) )->export();

		// The site moves on: one document changes, one is deleted, data is overwritten, the page is re-created.
		$repo->save( 'legal_notice', 'it', '## Note v3 (site)' );
		wp_delete_post( $repo->get( 'cookie_policy', 'en' )->post_id, true );
		$repo->flush();
		$this->plugin()->business()->save( array( 'legal_name' => 'Other' ) );
		wp_delete_post( $page_id, true );
		$new_page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'note-legali',
				'post_status' => 'publish',
			)
		);

		$restorer = new JsonRestorer( $this->plugin() );
		$data     = $restorer->decode( $backup );
		$this->assertIsArray( $data );
		$preview = $restorer->preview( $data );
		$this->assertSame( 1, $preview['documents_new'] );
		$this->assertSame( 1, $preview['documents_updated'] );
		$this->assertSame( 1, $preview['bindings_matched'] );
		$this->assertTrue( $preview['business'] );

		$result = $restorer->restore( $data );
		$this->assertSame( 2, $result['documents'] );
		$this->assertSame( 1, $result['bindings'] );
		$repo->flush();

		$notice = $repo->get( 'legal_notice', 'it' );
		$this->assertSame( '## Note v2', $notice->markdown );
		$this->assertSame( 4, $notice->version, 'Versions never go backwards: v3 on the site, so the restore is v4.' );
		$this->assertTrue( $notice->checklist['identity'] );
		$changelog = $notice->changelog;
		$this->assertSame( 'import', end( $changelog )['source'] );

		$cookies = $repo->get( 'cookie_policy', 'en' );
		$this->assertSame( '## Cookies', $cookies->markdown );
		$this->assertSame( 1, $cookies->version );

		$this->assertSame( 'Rossi S.r.l.', $this->plugin()->business()->value( 'legal_name' ) );
		$this->assertEqualsCanonicalizing( array( 'IT', 'FR' ), $this->plugin()->profile()->get()['markets'] );
		$this->assertSame( $new_page, $this->plugin()->bindings()->get( 'legal_notice', 'it' ), 'Bindings are re-matched by slug.' );
	}

	public function test_restore_keeps_backup_numbering_for_new_documents() {
		$data   = array(
			'format'         => JsonExporter::FORMAT,
			'format_version' => 1,
			'documents'      => array(
				array(
					'doc_id'    => 'privacy_policy',
					'lang'      => 'it',
					'markdown'  => '## Privacy v7',
					'version'   => 7,
					'status'    => 'published',
					'changelog' => array(
						array(
							'version'     => 6,
							'date'        => '2026-01-01',
							'note'        => 'old',
							'source'      => 'manual',
							'revision_id' => 99,
						),
					),
				),
				array(
					'doc_id'   => 'not_in_catalog',
					'lang'     => 'it',
					'markdown' => 'x',
				),
			),
		);
		$result = ( new JsonRestorer( $this->plugin() ) )->restore( $data );
		$this->assertSame( 1, $result['documents'] );
		$this->assertCount( 1, $result['skipped'] );

		$doc = $this->plugin()->repository()->get( 'privacy_policy', 'it' );
		$this->assertSame( 7, $doc->version );
		$this->assertSame( 'published', $doc->status );
		$this->assertSame( array( 6, 7 ), array_column( $doc->changelog, 'version' ) );
		$this->assertSame( 0, $doc->changelog[0]['revision_id'], 'Revision links from another site are cleared.' );
		$this->assertGreaterThan( 0, $doc->changelog[1]['revision_id'] );
	}

	public function test_decode_rejects_other_json() {
		$restorer = new JsonRestorer( $this->plugin() );
		$this->assertWPError( $restorer->decode( '{"hello": "world"}' ) );
		$this->assertWPError( $restorer->decode( 'not json' ) );
		$this->assertWPError( $restorer->decode( '{"format": "hive-compliance-backup", "format_version": 99}' ) );
	}

	public function test_upload_checks() {
		$this->assertWPError( Upload::from_text( '   ', 1024 ) );
		$this->assertWPError( Upload::from_text( str_repeat( 'a', 2048 ), 1024 ) );
		$this->assertWPError( Upload::from_text( "caf\xE9", 1024 ), 'Invalid UTF-8.' );
		$this->assertSame( ImportSession::KIND_JSON, Upload::from_text( ' {"format": "x"}', 1024 )['kind'] );
		$this->assertSame( ImportSession::KIND_BUNDLE, Upload::from_text( '# hive-compliance bundle', 1024 )['kind'] );

		$dir = get_temp_dir();
		file_put_contents( $dir . 'hive-bundle.md', '# hive-compliance bundle' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertIsArray( Upload::from_path( $dir . 'hive-bundle.md', 'bundle.md', 24, 1024 ) );
		$this->assertWPError( Upload::from_path( $dir . 'hive-bundle.md', 'bundle.php', 24, 1024 ), 'Extension.' );
		$this->assertWPError( Upload::from_path( $dir . 'hive-bundle.md', 'bundle.md', 4096, 1024 ), 'Reported size.' );

		file_put_contents( $dir . 'hive-image.md', "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertWPError( Upload::from_path( $dir . 'hive-image.md', 'image.md', 70, 1024 ), 'MIME check rejects binary content.' );
		wp_delete_file( $dir . 'hive-bundle.md' );
		wp_delete_file( $dir . 'hive-image.md' );
	}

	public function test_import_sessions_belong_to_their_user() {
		$token = ImportSession::create( 'content', ImportSession::KIND_BUNDLE );
		$this->assertSame( 'content', ImportSession::get( $token )['content'] );
		$this->login_as( 'administrator' );
		$this->assertNull( ImportSession::get( $token ), 'Another user cannot read the session.' );
		$this->assertNull( ImportSession::get( '../../etc' ) );
	}
}
