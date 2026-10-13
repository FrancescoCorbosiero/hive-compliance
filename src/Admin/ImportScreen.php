<?php
/**
 * Import screen: upload or paste, per-document diff review, apply; JSON restore.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\I18n\Languages;
use Hive\Compliance\Import\BlockReport;
use Hive\Compliance\Import\Importer;
use Hive\Compliance\Import\ImportSession;
use Hive\Compliance\Import\JsonRestorer;
use Hive\Compliance\Import\Upload;
use Hive\Compliance\Lint\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * Never a blind overwrite: every changed document is reviewed and accepted one by one.
 */
final class ImportScreen extends Screen {

	const ACTION_UPLOAD  = 'hive_compliance_import_upload';
	const ACTION_APPLY   = 'hive_compliance_import_apply';
	const ACTION_RESTORE = 'hive_compliance_restore_apply';
	const ACTION_DISCARD = 'hive_compliance_import_discard';

	/**
	 * Slug.
	 */
	public function slug(): string {
		return 'import';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Import', 'hive-compliance' );
	}

	/**
	 * Hooks the handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_UPLOAD, array( $this, 'handle_upload' ) );
		add_action( 'admin_post_' . self::ACTION_APPLY, array( $this, 'handle_apply' ) );
		add_action( 'admin_post_' . self::ACTION_RESTORE, array( $this, 'handle_restore' ) );
		add_action( 'admin_post_' . self::ACTION_DISCARD, array( $this, 'handle_discard' ) );
	}

	/**
	 * Largest accepted import, in bytes.
	 */
	private function max_bytes(): int {
		return (int) $this->plugin->settings()->get( 'max_import_kb' ) * 1024;
	}

	/**
	 * Handles the upload or paste: stores the content and opens the review.
	 */
	public function handle_upload(): void {
		Request::require_capability();
		check_admin_referer( self::ACTION_UPLOAD );

		$file = isset( $_FILES['bundle_file'] ) && is_array( $_FILES['bundle_file'] ) ? $_FILES['bundle_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked in Upload::from_file().
		if ( isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) {
			$result = Upload::from_file( $file, $this->max_bytes() );
		} else {
			// Raw text: parsed and validated, never output unescaped.
			$result = Upload::from_text( (string) wp_unslash( $_POST['bundle_text'] ?? '' ), $this->max_bytes() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( is_wp_error( $result ) ) {
			Notices::add( 'error', $result->get_error_message() );
			Request::redirect( Request::page_url( 'import' ) );
		}
		$token = ImportSession::create( $result['content'], $result['kind'], $result['name'] );
		Request::redirect( Request::page_url( 'import', array( 'session' => $token ) ) );
	}

	/**
	 * Applies the accepted documents.
	 */
	public function handle_apply(): void {
		Request::require_capability();
		check_admin_referer( self::ACTION_APPLY );

		$token   = sanitize_key( wp_unslash( $_POST['session'] ?? '' ) );
		$session = ImportSession::get( $token );
		if ( null === $session || ImportSession::KIND_BUNDLE !== $session['kind'] ) {
			Notices::add( 'error', __( 'The import session expired. Upload the bundle again.', 'hive-compliance' ) );
			Request::redirect( Request::page_url( 'import' ) );
		}

		$accepted = array_values( array_filter( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['accept'] ?? array() ) ), array( $this, 'is_key' ) ) );
		$hashes   = isset( $_POST['seen_hash'] ) && is_array( $_POST['seen_hash'] ) ? map_deep( wp_unslash( $_POST['seen_hash'] ), 'sanitize_text_field' ) : array();
		if ( ! $accepted ) {
			Notices::add( 'warning', __( 'No document was accepted: nothing was changed.', 'hive-compliance' ) );
			Request::redirect( Request::page_url( 'import', array( 'session' => $token ) ) );
		}

		$result = ( new Importer( $this->plugin ) )->apply( $session['content'], $accepted, $hashes, ! empty( $_POST['publish_on_apply'] ) );
		if ( $result['applied'] ) {
			/* translators: %d: number of documents. */
			Notices::add( 'success', sprintf( _n( '%d document updated (new revision and version).', '%d documents updated (new revisions and versions).', count( $result['applied'] ), 'hive-compliance' ), count( $result['applied'] ) ) );
		}
		foreach ( $result['skipped'] as $key => $reason ) {
			/* translators: 1: document and language, 2: reason. */
			Notices::add( 'warning', sprintf( __( 'Not applied: %1$s, because %2$s.', 'hive-compliance' ), $key, $reason ) );
		}
		ImportSession::delete( $token );
		Request::redirect( Request::page_url() );
	}

	/**
	 * Restores a JSON backup after confirmation.
	 */
	public function handle_restore(): void {
		Request::require_capability();
		check_admin_referer( self::ACTION_RESTORE );

		$token   = sanitize_key( wp_unslash( $_POST['session'] ?? '' ) );
		$session = ImportSession::get( $token );
		$data    = null === $session ? null : ( new JsonRestorer( $this->plugin ) )->decode( $session['content'] );
		if ( null === $data || is_wp_error( $data ) || empty( $_POST['confirm'] ) ) {
			Notices::add( 'error', is_wp_error( $data ) ? $data->get_error_message() : __( 'Confirm the restore first.', 'hive-compliance' ) );
			Request::redirect( Request::page_url( 'import', null === $session ? array() : array( 'session' => $token ) ) );
		}

		$result = ( new JsonRestorer( $this->plugin ) )->restore( $data );
		/* translators: 1: number of documents, 2: number of page bindings. */
		Notices::add( 'success', sprintf( __( 'Backup restored: %1$d documents, %2$d page bindings.', 'hive-compliance' ), $result['documents'], $result['bindings'] ) );
		if ( $result['skipped'] ) {
			Notices::add( 'warning', __( 'Skipped:', 'hive-compliance' ) . ' ' . implode( '; ', $result['skipped'] ) );
		}
		ImportSession::delete( $token );
		Request::redirect( Request::page_url() );
	}

	/**
	 * Discards an import session.
	 */
	public function handle_discard(): void {
		Request::require_capability();
		check_admin_referer( self::ACTION_DISCARD );
		ImportSession::delete( sanitize_key( wp_unslash( $_POST['session'] ?? '' ) ) );
		Notices::add( 'info', __( 'Import discarded. Nothing was changed.', 'hive-compliance' ) );
		Request::redirect( Request::page_url( 'import' ) );
	}

	/**
	 * Prints the current step.
	 */
	public function render(): void {
		$token   = isset( $_GET['session'] ) ? sanitize_key( wp_unslash( $_GET['session'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Session token; owner checked.
		$session = '' === $token ? null : ImportSession::get( $token );

		echo '<div class="wrap hive-wrap">';
		$this->header( $this->title(), __( 'Import a bundle returned by the model, or restore a JSON backup. Nothing changes until you accept it.', 'hive-compliance' ) );
		if ( '' !== $token && null === $session ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The import session expired. Upload the bundle again.', 'hive-compliance' ) . '</p></div>';
		}
		if ( null === $session ) {
			$this->render_upload();
		} elseif ( ImportSession::KIND_JSON === $session['kind'] ) {
			$this->render_restore( $token, $session );
		} else {
			$this->render_review( $token, $session );
		}
		echo '</div>';
	}

	/**
	 * Whether a value is a block key ("id|lang").
	 *
	 * @param string $key Key.
	 */
	public function is_key( string $key ): bool {
		return 1 === preg_match( '/^[a-z0-9_]+\|[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,4})?$/', $key );
	}

	/**
	 * Upload / paste form.
	 */
	private function render_upload(): void {
		$this->form_open( self::ACTION_UPLOAD, array( 'enctype' => 'multipart/form-data' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="hive-bundle-file">%1$s</label></th><td><input type="file" id="hive-bundle-file" name="bundle_file" accept=".md,.markdown,.txt,.json,text/markdown,text/plain,application/json"><p class="description">%2$s</p></td></tr>',
			esc_html__( 'Upload a file', 'hive-compliance' ),
			/* translators: %d: size limit in KB. */
			esc_html( sprintf( __( '.md bundle or .json backup, up to %d KB.', 'hive-compliance' ), (int) $this->plugin->settings()->get( 'max_import_kb' ) ) )
		);
		printf(
			'<tr><th scope="row"><label for="hive-bundle-text">%1$s</label></th><td><textarea id="hive-bundle-text" name="bundle_text" rows="14" class="large-text code" placeholder="# hive-compliance bundle …"></textarea></td></tr>',
			esc_html__( 'or paste the bundle', 'hive-compliance' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Review the import', 'hive-compliance' ) );
		echo '</form>';
	}

	/**
	 * Review of a bundle.
	 *
	 * @param string               $token   Session token.
	 * @param array<string, mixed> $session Session.
	 */
	private function render_review( string $token, array $session ): void {
		$analysis = ( new Importer( $this->plugin ) )->analyze( (string) $session['content'] );
		$groups   = array(
			BlockReport::CHANGED   => array(),
			BlockReport::INVALID   => array(),
			BlockReport::UNCHANGED => array(),
		);
		foreach ( $analysis['reports'] as $report ) {
			$groups[ $report->status() ][] = $report;
		}

		foreach ( $analysis['errors'] as $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: changed, 2: with errors, 3: unchanged. */
					__( '%1$d changed, %2$d with errors, %3$d unchanged. Documents absent from the bundle are not touched.', 'hive-compliance' ),
					count( $groups[ BlockReport::CHANGED ] ),
					count( $groups[ BlockReport::INVALID ] ),
					count( $groups[ BlockReport::UNCHANGED ] )
				)
			)
		);

		$this->form_open( self::ACTION_APPLY );
		printf( '<input type="hidden" name="session" value="%s">', esc_attr( $token ) );
		foreach ( array_merge( $groups[ BlockReport::CHANGED ], $groups[ BlockReport::INVALID ] ) as $report ) {
			$this->render_report( $report );
		}

		if ( $groups[ BlockReport::UNCHANGED ] ) {
			echo '<h2>' . esc_html__( 'Unchanged', 'hive-compliance' ) . '</h2><ul class="ul-disc">';
			foreach ( $groups[ BlockReport::UNCHANGED ] as $report ) {
				printf( '<li>%1$s <code>%2$s</code></li>', esc_html( $this->report_title( $report ) ), esc_html( $report->key() ) );
			}
			echo '</ul>';
		}

		if ( $groups[ BlockReport::CHANGED ] ) {
			echo '<p><label><input type="checkbox" name="publish_on_apply" value="1"> ' . esc_html__( 'Publish on apply (otherwise each document keeps its current status)', 'hive-compliance' ) . '</label></p>';
			submit_button( __( 'Apply accepted documents', 'hive-compliance' ), 'primary', 'submit', false );
		}
		echo '</form>';

		$this->form_open( self::ACTION_DISCARD, array( 'class' => 'hive-discard' ) );
		printf( '<input type="hidden" name="session" value="%s">', esc_attr( $token ) );
		submit_button( __( 'Discard this import', 'hive-compliance' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * One changed or invalid document.
	 *
	 * @param BlockReport $report Report.
	 */
	private function render_report( BlockReport $report ): void {
		$key    = $report->key();
		$status = $report->status();
		echo '<div class="hive-review-item">';
		printf( '<h3>%1$s <code>%2$s</code> ', esc_html( $this->report_title( $report ) ), esc_html( $key ) );
		foreach ( array( Finding::ERROR, Finding::CONFLICT, Finding::WARNING ) as $severity ) {
			$count = count( array_filter( $report->findings, static fn( $finding ) => $finding->severity === $severity ) );
			if ( $count ) {
				echo $this->badge( $count . ' ' . $severity, $severity ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped.
			}
		}
		echo '</h3>';

		if ( $report->findings ) {
			echo '<ul class="hive-findings">';
			foreach ( $report->findings as $finding ) {
				printf( '<li class="hive-finding hive-finding--%1$s"><strong>%2$s</strong> %3$s', esc_attr( $finding->severity ), esc_html( $finding->severity ), esc_html( $finding->message ) );
				if ( $finding->details ) {
					echo '<ul>';
					foreach ( $finding->details as $detail ) {
						echo '<li><code>' . esc_html( $detail ) . '</code></li>';
					}
					echo '</ul>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}

		if ( $report->has_rule( 'base_hash_conflict' ) ) {
			echo '<p><strong>' . esc_html__( 'Three-way note:', 'hive-compliance' ) . '</strong> ' . esc_html__( 'the bundle was exported from an older version. Below: what changed on the site since the export (export → site), then what the import changes (site → import).', 'hive-compliance' ) . '</p>';
			if ( null === $report->base_markdown ) {
				echo '<p class="hive-meta">' . esc_html__( 'The exported version was not found among the revisions.', 'hive-compliance' ) . '</p>';
			} else {
				$diff = wp_text_diff(
					$report->base_markdown,
					null === $report->current ? '' : $report->current->markdown,
					array(
						'title_left'      => __( 'Exported (base)', 'hive-compliance' ),
						'title_right'     => __( 'On the site now', 'hive-compliance' ),
						'show_split_view' => true,
					)
				);
				echo $diff; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_text_diff escapes the texts.
			}
		}

		if ( null !== $report->entry && $report->changed ) {
			$diff = wp_text_diff(
				null === $report->current ? '' : $report->current->markdown,
				$report->markdown,
				array(
					'title_left'      => __( 'On the site', 'hive-compliance' ),
					'title_right'     => __( 'In the import', 'hive-compliance' ),
					'show_split_view' => true,
				)
			);
			echo $diff; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_text_diff escapes the texts.
		}

		if ( BlockReport::CHANGED === $status ) {
			printf( '<input type="hidden" name="seen_hash[%1$s]" value="%2$s">', esc_attr( $key ), esc_attr( $report->current_hash() ) );
			$note = '' !== $report->block->changelog_note() ? $report->block->changelog_note() : __( '(empty)', 'hive-compliance' );
			/* translators: %s: changelog note. */
			echo '<p class="hive-meta">' . esc_html( sprintf( __( 'Changelog note: %s', 'hive-compliance' ), $note ) ) . '</p>';
			$label = $report->has( Finding::WARNING ) || $report->has( Finding::CONFLICT ) ? __( 'Accept this document (I reviewed the warnings)', 'hive-compliance' ) : __( 'Accept this document', 'hive-compliance' );
			printf( '<p><label><input type="checkbox" name="accept[]" value="%1$s"> <strong>%2$s</strong></label></p>', esc_attr( $key ), esc_html( $label ) );
		} else {
			echo '<p class="hive-finding hive-finding--error">' . esc_html__( 'Cannot be applied: fix the errors in the bundle and import it again.', 'hive-compliance' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Title of a report's document.
	 *
	 * @param BlockReport $report Report.
	 */
	private function report_title( BlockReport $report ): string {
		$lang = Languages::locale_to_code( (string) get_user_locale() );
		$name = null === $report->entry ? $report->block->id() : $report->entry->title( $lang );
		return $name . ' (' . $report->block->lang() . ')';
	}

	/**
	 * JSON restore preview and confirmation.
	 *
	 * @param string               $token   Session token.
	 * @param array<string, mixed> $session Session.
	 */
	private function render_restore( string $token, array $session ): void {
		$restorer = new JsonRestorer( $this->plugin );
		$data     = $restorer->decode( (string) $session['content'] );
		if ( is_wp_error( $data ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $data->get_error_message() ) . '</p></div>';
			$this->render_upload();
			return;
		}
		$preview = $restorer->preview( $data );

		echo '<h2>' . esc_html__( 'Restore a JSON backup', 'hive-compliance' ) . '</h2>';
		/* translators: 1: site URL, 2: date. */
		echo '<p>' . esc_html( sprintf( __( 'Backup of %1$s made on %2$s.', 'hive-compliance' ), $preview['site_url'], $preview['exported_at'] ) ) . '</p>';
		echo '<table class="widefat striped" style="max-width:640px"><tbody>';
		$rows = array(
			__( 'New documents', 'hive-compliance' )       => $preview['documents_new'],
			__( 'Documents replaced (as new revisions)', 'hive-compliance' ) => $preview['documents_updated'],
			__( 'Documents unchanged', 'hive-compliance' ) => $preview['documents_unchanged'],
			__( 'Documents skipped (unknown ID or language)', 'hive-compliance' ) => $preview['documents_skipped'],
			__( 'Page bindings matched', 'hive-compliance' ) => $preview['bindings_matched'],
			__( 'Page bindings without a matching page', 'hive-compliance' ) => $preview['bindings_unmatched'],
			__( 'Business data replaced', 'hive-compliance' ) => $preview['business'] ? __( 'yes', 'hive-compliance' ) : __( 'no', 'hive-compliance' ),
			__( 'Store profile replaced', 'hive-compliance' ) => $preview['profile'] ? __( 'yes', 'hive-compliance' ) : __( 'no', 'hive-compliance' ),
			__( 'Settings replaced', 'hive-compliance' )   => $preview['settings'] ? __( 'yes', 'hive-compliance' ) : __( 'no', 'hive-compliance' ),
		);
		foreach ( $rows as $label => $value ) {
			printf( '<tr><td>%1$s</td><td>%2$s</td></tr>', esc_html( $label ), esc_html( (string) $value ) );
		}
		echo '</tbody></table>';

		$this->form_open( self::ACTION_RESTORE );
		printf( '<input type="hidden" name="session" value="%s">', esc_attr( $token ) );
		echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'I understand that this replaces the business data, profile and settings, and saves the backup documents as new versions.', 'hive-compliance' ) . '</label></p>';
		submit_button( __( 'Restore backup', 'hive-compliance' ), 'primary', 'submit', false );
		echo '</form>';

		$this->form_open( self::ACTION_DISCARD, array( 'class' => 'hive-discard' ) );
		printf( '<input type="hidden" name="session" value="%s">', esc_attr( $token ) );
		submit_button( __( 'Discard', 'hive-compliance' ), 'secondary', 'submit', false );
		echo '</form>';
	}
}
