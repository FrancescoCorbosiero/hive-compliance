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
		$this->header(
			$this->title(),
			__( 'Import a bundle returned by the model, or restore a JSON backup. Nothing changes until you accept it.', 'hive-compliance' ),
			sprintf( '<a class="button" href="%1$s">%2$s %3$s</a>', esc_url( Request::page_url( 'export' ) ), $this->icon( 'download' ), esc_html__( 'Export', 'hive-compliance' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
		);
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
		$max = (int) $this->plugin->settings()->get( 'max_import_kb' );
		echo '<div class="hive-layout"><div class="hive-layout__main">';
		$this->form_open( self::ACTION_UPLOAD, array( 'enctype' => 'multipart/form-data' ) );
		$this->panel_open( __( 'Bundle or backup', 'hive-compliance' ), array( 'icon' => 'upload' ) );
		printf(
			'<label class="hive-dropzone" for="hive-bundle-file" tabindex="0">%1$s<span class="hive-dropzone__title">%2$s</span><span class="hive-dropzone__hint">%3$s</span><span class="hive-dropzone__file" aria-live="polite"></span><input type="file" id="hive-bundle-file" name="bundle_file" accept=".md,.markdown,.txt,.json,text/markdown,text/plain,application/json"></label>',
			$this->icon( 'cloud-upload' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			esc_html__( 'Drop a file here or click to choose one', 'hive-compliance' ),
			/* translators: %d: size limit in KB. */
			esc_html( sprintf( __( '.md bundle or .json backup, up to %d KB.', 'hive-compliance' ), $max ) )
		);
		echo '<p class="hive-or">' . esc_html__( 'or', 'hive-compliance' ) . '</p>';
		printf(
			'<div class="hive-field"><label class="hive-field__label" for="hive-bundle-text">%1$s</label><textarea id="hive-bundle-text" name="bundle_text" rows="12" class="large-text code hive-paste" placeholder="# hive-compliance bundle …"></textarea></div>',
			esc_html__( 'Paste the bundle', 'hive-compliance' )
		);
		$this->panel_close( get_submit_button( __( 'Review the import', 'hive-compliance' ), 'primary', 'submit', false ) . '<span class="hive-meta">' . esc_html__( 'Opens a review: nothing is written yet.', 'hive-compliance' ) . '</span>' );
		echo '</form>';
		echo '</div><div class="hive-layout__side">';
		$this->panel_open( __( 'How it works', 'hive-compliance' ), array( 'icon' => 'info-outline' ) );
		echo '<ol style="margin:0 0 0 18px">';
		echo '<li>' . esc_html__( 'Export the bundle and hand it to an LLM.', 'hive-compliance' ) . '</li>';
		echo '<li>' . esc_html__( 'Import the file it returns. Every document is validated and shown as a side-by-side diff.', 'hive-compliance' ) . '</li>';
		echo '<li>' . esc_html__( 'Accept the documents you reviewed. Each one becomes a new version with a revision; the rest stay untouched.', 'hive-compliance' ) . '</li>';
		echo '</ol>';
		echo '<p class="hive-meta">' . esc_html__( 'A JSON backup made on the Export screen is recognised automatically and restored after a confirmation.', 'hive-compliance' ) . '</p>';
		$this->panel_close();
		echo '</div></div>';
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
		$changed = count( $groups[ BlockReport::CHANGED ] );
		$invalid = count( $groups[ BlockReport::INVALID ] );

		foreach ( $analysis['errors'] as $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}

		if ( ! empty( $session['name'] ) ) {
			echo '<p class="hive-meta">' . $this->icon( 'media-text' ) . ' ' . esc_html( (string) $session['name'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
		}
		echo '<div class="hive-stats">';
		echo $this->stat( __( 'Changed', 'hive-compliance' ), $changed, $changed ? 'info' : '', __( 'review and accept below', 'hive-compliance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in stat().
		echo $this->stat( __( 'With errors', 'hive-compliance' ), $invalid, $invalid ? 'error' : 'ok', $invalid ? __( 'cannot be applied', 'hive-compliance' ) : __( 'none', 'hive-compliance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in stat().
		echo $this->stat( __( 'Unchanged', 'hive-compliance' ), count( $groups[ BlockReport::UNCHANGED ] ), '', __( 'identical to the site', 'hive-compliance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in stat().
		echo '</div>';
		echo '<p class="hive-meta">' . esc_html__( 'Documents absent from the bundle are not touched. The context section of the bundle is ignored.', 'hive-compliance' ) . '</p>';

		// The discard form lives outside the apply form; its button sits in the action bar through form="…".
		$this->form_open(
			self::ACTION_DISCARD,
			array(
				'class' => 'hive-discard',
				'id'    => 'hive-discard-form',
			)
		);
		printf( '<input type="hidden" name="session" value="%s">', esc_attr( $token ) );
		echo '</form>';

		$this->form_open( self::ACTION_APPLY );
		printf( '<input type="hidden" name="session" value="%s">', esc_attr( $token ) );
		if ( $changed > 1 ) {
			echo $this->select_all( 'hive-import-reports' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select_all().
		}
		echo '<div id="hive-import-reports">';
		if ( ! $changed && ! $invalid ) {
			echo '<div class="hive-empty">' . $this->icon( 'yes-alt' ) . esc_html__( 'Nothing to apply: every document in the bundle matches the site.', 'hive-compliance' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
		}
		foreach ( array_merge( $groups[ BlockReport::CHANGED ], $groups[ BlockReport::INVALID ] ) as $report ) {
			$this->render_report( $report );
		}
		echo '</div>';

		if ( $groups[ BlockReport::UNCHANGED ] ) {
			/* translators: %d: number of unchanged documents. */
			printf( '<details class="hive-unchanged"><summary>%s</summary><ul class="ul-disc">', esc_html( sprintf( __( 'Unchanged documents (%d)', 'hive-compliance' ), count( $groups[ BlockReport::UNCHANGED ] ) ) ) );
			foreach ( $groups[ BlockReport::UNCHANGED ] as $report ) {
				printf( '<li>%1$s <code>%2$s</code></li>', esc_html( $this->report_title( $report ) ), esc_html( $report->key() ) );
			}
			echo '</ul></details>';
		}

		echo '<div class="hive-actionbar"><div class="hive-actionbar__group">';
		if ( $changed ) {
			printf(
				'<button type="submit" class="button button-primary">%1$s <span data-hive-count-checked="hive-import-reports" data-hive-count-template="(%2$s)"></span></button>',
				esc_html__( 'Apply accepted documents', 'hive-compliance' ),
				'%d'
			);
			echo '<label><input type="checkbox" name="publish_on_apply" value="1"> ' . esc_html__( 'Publish on apply', 'hive-compliance' ) . ' <span class="hive-meta">' . esc_html__( '(otherwise each document keeps its status)', 'hive-compliance' ) . '</span></label>';
		}
		if ( $changed > 1 ) {
			echo $this->select_all( 'hive-import-reports' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select_all().
		}
		echo '</div><div class="hive-actionbar__group">';
		printf( '<button type="submit" form="hive-discard-form" class="button-link button-link-delete">%s</button>', esc_html__( 'Discard this import', 'hive-compliance' ) );
		echo '</div></div>';
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
		printf( '<div class="hive-review-item%s">', BlockReport::INVALID === $status ? ' hive-review-item--invalid' : '' );
		printf( '<div class="hive-review-item__head"><h3>%1$s <code>%2$s</code></h3>', esc_html( $this->report_title( $report ) ), esc_html( $key ) );
		$badges = '';
		foreach ( array( Finding::ERROR, Finding::CONFLICT, Finding::WARNING ) as $severity ) {
			$count = count( array_filter( $report->findings, static fn( $finding ) => $finding->severity === $severity ) );
			if ( $count ) {
				$badges .= $this->badge( $count . ' ' . $severity, $severity );
			}
		}
		if ( '' === $badges && BlockReport::CHANGED === $status ) {
			$badges = $this->badge( __( 'clean', 'hive-compliance' ), 'ok' );
		}
		echo '<span class="hive-badges" style="margin:0">' . $badges . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
		echo '<div class="hive-review-item__body">';

		if ( $report->findings ) {
			echo '<ul class="hive-findings">';
			foreach ( $report->findings as $finding ) {
				echo $this->finding( $finding->severity, $finding->message, $finding->details ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in finding().
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
			echo '<p class="hive-review-item__note">' . $this->icon( 'edit' ) . ' ' . esc_html( sprintf( __( 'Changelog note: %s', 'hive-compliance' ), $note ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			echo '</div>';
			$label = $report->has( Finding::WARNING ) || $report->has( Finding::CONFLICT ) ? __( 'Accept this document (I reviewed the warnings)', 'hive-compliance' ) : __( 'Accept this document', 'hive-compliance' );
			printf( '<label class="hive-review-item__accept"><input type="checkbox" name="accept[]" value="%1$s"> <span>%2$s</span></label>', esc_attr( $key ), esc_html( $label ) );
		} else {
			echo $this->note( 'error', __( 'Cannot be applied: fix the errors in the bundle and import it again.', 'hive-compliance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in note().
			echo '</div>';
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

		echo '<div class="hive-layout"><div class="hive-layout__main">';
		/* translators: 1: site URL, 2: date. */
		$made = sprintf( __( 'Backup of %1$s made on %2$s', 'hive-compliance' ), $preview['site_url'], $preview['exported_at'] );
		$this->panel_open(
			__( 'Restore a JSON backup', 'hive-compliance' ),
			array(
				'icon'  => 'database-import',
				'flush' => true,
				'meta'  => '<span>' . esc_html( $made ) . '</span>',
			)
		);
		echo '<table class="widefat striped hive-restore-summary"><tbody>';
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
		$this->panel_close();

		$this->form_open(
			self::ACTION_DISCARD,
			array(
				'class' => 'hive-discard',
				'id'    => 'hive-discard-form',
			)
		);
		printf( '<input type="hidden" name="session" value="%s">', esc_attr( $token ) );
		echo '</form>';

		$this->form_open( self::ACTION_RESTORE );
		printf( '<input type="hidden" name="session" value="%s">', esc_attr( $token ) );
		echo '<label class="hive-check"><input type="checkbox" name="confirm" value="1" required> <span class="hive-check__text"><span>' . esc_html__( 'I understand that this replaces the business data, profile and settings, and saves the backup documents as new versions.', 'hive-compliance' ) . '</span></span></label>';
		echo '<div class="hive-actionbar"><div class="hive-actionbar__group">';
		submit_button( __( 'Restore backup', 'hive-compliance' ), 'primary', 'submit', false );
		echo '</div><div class="hive-actionbar__group">';
		printf( '<button type="submit" form="hive-discard-form" class="button-link button-link-delete">%s</button>', esc_html__( 'Discard', 'hive-compliance' ) );
		echo '</div></div>';
		echo '</form>';
		echo '</div><div class="hive-layout__side">';
		$this->panel_open( __( 'Before you restore', 'hive-compliance' ), array( 'icon' => 'info-outline' ) );
		echo '<p>' . esc_html__( 'Documents from the backup are saved as new versions, so the current text stays in the revision history. Business data, profile and settings are replaced outright.', 'hive-compliance' ) . '</p>';
		echo '<p class="hive-meta">' . esc_html__( 'Bound pages are matched by ID, then by slug. Bindings without a matching page are skipped.', 'hive-compliance' ) . '</p>';
		$this->panel_close();
		echo '</div></div>';
	}
}
