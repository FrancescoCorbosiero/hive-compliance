<?php
/**
 * Documents screen: list of documents and the Markdown editor.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\Catalog\Entry;
use Hive\Compliance\Document\Document;
use Hive\Compliance\Document\PostType;
use Hive\Compliance\Hooks;
use Hive\Compliance\I18n\Languages;
use Hive\Compliance\Lint\ContentLinter;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Editor with CodeMirror (Markdown), live preview through the render pipeline, content checks
 * and a sidebar: status and dates, required-elements checklist, placeholder picker, page
 * binding, changelog and revisions, language switcher.
 */
final class EditorScreen extends Screen {

	const ACTION_SAVE = 'hive_compliance_save_document';

	/**
	 * Largest document accepted, in bytes.
	 */
	const MAX_MARKDOWN = 1048576;

	/**
	 * Slug.
	 */
	public function slug(): string {
		return 'documents';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Documents', 'hive-compliance' );
	}

	/**
	 * Hooks the save handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
	}

	/**
	 * Enqueues CodeMirror (Markdown mode) and the editor script.
	 */
	public function enqueue(): void {
		list( $entry, $lang ) = $this->current();
		if ( null === $entry ) {
			return;
		}
		$code_editor = wp_enqueue_code_editor(
			array(
				'type'       => 'text/x-markdown',
				'codemirror' => array(
					'lineWrapping' => true,
					'lineNumbers'  => true,
				),
			)
		);
		wp_enqueue_script( 'hive-compliance-editor', HIVE_COMPLIANCE_URL . 'assets/js/editor.js', array( 'jquery', 'wp-api-fetch', 'wp-i18n' ), HIVE_COMPLIANCE_VERSION, true );
		wp_localize_script(
			'hive-compliance-editor',
			'hiveComplianceEditor',
			array(
				'codeEditor'  => $code_editor,
				'docId'       => $entry->id,
				'lang'        => $lang,
				'previewPath' => '/hive-compliance/v1/preview',
				'i18n'        => array(
					'noFindings'   => __( 'No problems found.', 'hive-compliance' ),
					'previewError' => __( 'Preview failed:', 'hive-compliance' ),
					'unsaved'      => __( 'You have unsaved changes.', 'hive-compliance' ),
				),
			)
		);
	}

	/**
	 * Prints the list or the editor.
	 */
	public function render(): void {
		list( $entry, $lang ) = $this->current();
		echo '<div class="wrap hive-wrap">';
		if ( null === $entry ) {
			$this->render_list();
		} else {
			$this->render_editor( $entry, $lang );
		}
		echo '</div>';
	}

	/**
	 * Saves a document from form input (separate from the handler so it can be tested).
	 *
	 * @param array<string, mixed> $input Unslashed form input.
	 * @return Document|WP_Error
	 */
	public function save_document( array $input ) {
		$entry = $this->plugin->catalog()->get( sanitize_key( (string) ( $input['doc'] ?? '' ) ) );
		$lang  = Languages::normalize_code( (string) ( $input['lang'] ?? '' ) );
		if ( null === $entry ) {
			return new WP_Error( 'hive_unknown_document', __( 'Unknown document.', 'hive-compliance' ) );
		}
		if ( ! $this->plugin->languages()->is_enabled( $lang ) ) {
			return new WP_Error( 'hive_invalid_language', __( 'This language is not enabled.', 'hive-compliance' ) );
		}
		$markdown = (string) ( $input['markdown'] ?? '' );
		if ( strlen( $markdown ) > self::MAX_MARKDOWN ) {
			return new WP_Error( 'hive_too_large', __( 'The document is too large.', 'hive-compliance' ) );
		}

		$ticked    = array_map( 'strval', (array) ( $input['checklist'] ?? array() ) );
		$checklist = array();
		foreach ( $entry->element_keys() as $key ) {
			$checklist[ $key ] = in_array( $key, $ticked, true );
		}

		$last_reviewed = PostType::sanitize_date( (string) ( $input['last_reviewed'] ?? '' ) );
		if ( ! empty( $input['mark_reviewed'] ) ) {
			$last_reviewed = current_time( 'Y-m-d' );
		}

		return $this->plugin->repository()->save(
			$entry->id,
			$lang,
			$markdown,
			array(
				'title'          => $entry->title( $lang ),
				'note'           => sanitize_text_field( (string) ( $input['note'] ?? '' ) ),
				'source'         => Document::SOURCE_MANUAL,
				'status'         => Document::STATUS_PUBLISHED === ( $input['status'] ?? '' ) ? Document::STATUS_PUBLISHED : Document::STATUS_DRAFT,
				'effective_date' => PostType::sanitize_date( (string) ( $input['effective_date'] ?? '' ) ),
				'last_reviewed'  => $last_reviewed,
				'checklist'      => $checklist,
			)
		);
	}

	/**
	 * Handles the form submission (admin-post.php).
	 */
	public function handle_save(): void {
		Request::require_capability();
		check_admin_referer( self::ACTION_SAVE );

		$input = array(
			'doc'            => sanitize_key( wp_unslash( $_POST['doc'] ?? '' ) ),
			'lang'           => sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) ),
			// Raw Markdown: never output unescaped; sanitised at render time (Parsedown safe mode + wp_kses_post).
			'markdown'       => wp_unslash( $_POST['markdown'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'status'         => sanitize_key( wp_unslash( $_POST['status'] ?? '' ) ),
			'effective_date' => sanitize_text_field( wp_unslash( $_POST['effective_date'] ?? '' ) ),
			'last_reviewed'  => sanitize_text_field( wp_unslash( $_POST['last_reviewed'] ?? '' ) ),
			'mark_reviewed'  => ! empty( $_POST['mark_reviewed'] ),
			'checklist'      => array_map( 'sanitize_key', (array) wp_unslash( $_POST['checklist'] ?? array() ) ),
			'note'           => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ),
		);

		$result = $this->save_document( $input );
		if ( is_wp_error( $result ) ) {
			Notices::add( 'error', $result->get_error_message() );
			Request::redirect( Request::page_url( 'documents' ) );
		}

		/* translators: %d: version number. */
		Notices::add( 'success', sprintf( __( 'Document saved (version %d).', 'hive-compliance' ), $result->version ) );
		$entry  = $this->plugin->catalog()->get( $result->doc_id );
		$errors = array_filter( $this->plugin->linter()->lint( $result->markdown, $entry, $result->lang ), static fn( $finding ) => $finding->is_error() );
		if ( $errors ) {
			Notices::add( 'warning', __( 'The document contains content that the checks reject (see Checks). It renders safely, but an import would refuse it.', 'hive-compliance' ) );
		}
		Request::redirect( Request::editor_url( $result->doc_id, $result->lang ) );
	}

	/**
	 * Entry and language requested in the URL.
	 *
	 * @return array{0: Entry|null, 1: string}
	 */
	private function current(): array {
		$doc_id = isset( $_GET['doc'] ) ? sanitize_key( wp_unslash( $_GET['doc'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$lang   = isset( $_GET['lang'] ) ? Languages::normalize_code( sanitize_text_field( wp_unslash( $_GET['lang'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$entry  = '' === $doc_id ? null : $this->plugin->catalog()->get( $doc_id );
		if ( ! $this->plugin->languages()->is_enabled( $lang ) ) {
			$lang = $this->plugin->languages()->default_language();
		}
		return array( $entry, $lang );
	}

	/**
	 * The documents list (full catalog toggle like the dashboard).
	 */
	private function render_list(): void {
		$full = isset( $_GET['view'] ) && 'all' === sanitize_key( wp_unslash( $_GET['view'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View toggle.
		$this->header( __( 'Documents', 'hive-compliance' ) );
		echo '<ul class="subsubsub">';
		printf( '<li><a href="%1$s"%2$s>%3$s</a> | </li>', esc_url( Request::page_url( 'documents' ) ), $full ? '' : ' class="current"', esc_html__( 'Applicable documents', 'hive-compliance' ) );
		printf( '<li><a href="%1$s"%2$s>%3$s</a></li>', esc_url( Request::page_url( 'documents', array( 'view' => 'all' ) ) ), $full ? ' class="current"' : '', esc_html__( 'Full catalog', 'hive-compliance' ) );
		echo '</ul>';
		$table = new DocumentsTable( ( new Overview( $this->plugin ) )->rows( ! $full ), $this->plugin->languages()->default_language() );
		$table->prepare_items();
		$table->display();
	}

	/**
	 * The editor.
	 *
	 * @param Entry  $entry Catalog entry.
	 * @param string $lang  Language.
	 */
	private function render_editor( Entry $entry, string $lang ): void {
		$document = $this->plugin->repository()->get( $entry->id, $lang );
		$preview  = $this->plugin->renderer()->render_markdown( $document->markdown, $entry, $lang, true );
		$findings = $this->plugin->linter()->lint( $document->markdown, $entry, $lang );

		$this->header( $entry->title( $lang ) . ' (' . $lang . ')', implode( ' · ', $entry->legal_refs ) );
		echo '<p class="hive-badges">';
		echo $this->badge( $entry->type ) . ' ' . $this->badge( $entry->group, 'muted' ) . ' ' . $this->status_badge( $document->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badges are escaped.
		echo ' ' . $this->badge( $document->version ? 'v' . $document->version : __( 'no version yet', 'hive-compliance' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped.
		if ( 'draft' === $entry->checklist_status ) {
			echo ' ' . $this->badge( __( 'checklist is a draft (best effort)', 'hive-compliance' ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped.
		}
		echo '</p>';
		$this->render_language_switcher( $entry, $lang );

		if ( $entry->is_internal() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Internal document: it is never published on the site. Keep it for your records and exports.', 'hive-compliance' ) . '</p></div>';
		}
		/** This action is documented in src/Hooks.php */
		do_action( Hooks::EDITOR_BEFORE_FORM, $entry, $document );

		echo '<div class="hive-editor">';
		echo '<div class="hive-editor__main">';
		$this->form_open( self::ACTION_SAVE, array( 'id' => 'hive-document-form' ) );
		printf( '<input type="hidden" name="doc" value="%s"><input type="hidden" name="lang" value="%s">', esc_attr( $entry->id ), esc_attr( $lang ) );
		printf( '<label class="screen-reader-text" for="hive-markdown">%s</label>', esc_html__( 'Markdown', 'hive-compliance' ) );
		printf( '<textarea id="hive-markdown" name="markdown" rows="24" class="large-text code">%s</textarea>', esc_textarea( $document->markdown ) );
		echo '</form>';

		echo '<div class="hive-panels">';
		echo '<div class="hive-panel"><h2>' . esc_html__( 'Preview', 'hive-compliance' ) . '</h2><div id="hive-preview" class="hive-preview">' . wp_kses_post( $preview['html'] ) . '</div></div>';
		echo '<div class="hive-panel"><h2>' . esc_html__( 'Checks', 'hive-compliance' ) . '</h2><div id="hive-findings">';
		$this->render_findings( $findings );
		echo '</div></div>';
		echo '</div>';
		echo '</div>';

		echo '<div class="hive-editor__side">';
		$this->render_save_box( $document );
		$this->render_checklist_box( $entry, $document );
		$this->render_binding_box( $entry, $lang );
		$this->render_placeholder_box( $lang );
		$this->render_changelog_box( $document );
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Language switcher.
	 *
	 * @param Entry  $entry Entry.
	 * @param string $lang  Current language.
	 */
	private function render_language_switcher( Entry $entry, string $lang ): void {
		$languages = $this->plugin->languages();
		echo '<p class="hive-language-switcher">' . esc_html__( 'Language:', 'hive-compliance' ) . ' ';
		foreach ( $languages->enabled() as $code ) {
			$document = $this->plugin->repository()->find( $entry->id, $code );
			$status   = null === $document ? Document::STATUS_EMPTY : $document->status;
			printf(
				'<a href="%1$s" class="hive-lang hive-lang--%2$s%3$s" title="%4$s">%5$s</a> ',
				esc_url( Request::editor_url( $entry->id, $code ) ),
				esc_attr( $status ),
				$code === $lang ? ' is-current' : '',
				esc_attr( $languages->label( $code ) . ' — ' . $status ),
				esc_html( $code )
			);
		}
		echo '</p>';
	}

	/**
	 * Findings list (also rebuilt by editor.js).
	 *
	 * @param \Hive\Compliance\Lint\Finding[] $findings Findings.
	 */
	private function render_findings( array $findings ): void {
		if ( ! $findings ) {
			echo '<p class="hive-ok">' . esc_html__( 'No problems found.', 'hive-compliance' ) . '</p>';
			return;
		}
		echo '<ul class="hive-findings">';
		foreach ( $findings as $finding ) {
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

	/**
	 * Status, dates, note and save button (inputs belong to the main form via form="").
	 *
	 * @param Document $document Document.
	 */
	private function render_save_box( Document $document ): void {
		$form = 'hive-document-form';
		echo '<div class="postbox hive-box"><h2 class="hndle">' . esc_html__( 'Publish', 'hive-compliance' ) . '</h2><div class="inside">';

		printf( '<p><label for="hive-status">%s</label><br><select id="hive-status" name="status" form="%s">', esc_html__( 'Status', 'hive-compliance' ), esc_attr( $form ) );
		foreach ( array(
			Document::STATUS_DRAFT     => __( 'Draft', 'hive-compliance' ),
			Document::STATUS_PUBLISHED => __( 'Published', 'hive-compliance' ),
		) as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( Document::STATUS_PUBLISHED === $document->status ? Document::STATUS_PUBLISHED : Document::STATUS_DRAFT, $value, false ), esc_html( $label ) );
		}
		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'Visitors only see published documents. An empty document is always "empty".', 'hive-compliance' ) . '</p>';

		printf( '<p><label for="hive-effective">%1$s</label><br><input type="date" id="hive-effective" name="effective_date" value="%2$s" form="%3$s"></p>', esc_html__( 'Effective date', 'hive-compliance' ), esc_attr( $document->effective_date ), esc_attr( $form ) );
		printf( '<p><label for="hive-reviewed">%1$s</label><br><input type="date" id="hive-reviewed" name="last_reviewed" value="%2$s" form="%3$s"></p>', esc_html__( 'Last reviewed', 'hive-compliance' ), esc_attr( $document->last_reviewed ), esc_attr( $form ) );
		printf( '<p><label><input type="checkbox" name="mark_reviewed" value="1" form="%1$s"> %2$s</label></p>', esc_attr( $form ), esc_html__( 'Mark as reviewed today', 'hive-compliance' ) );
		printf( '<p><label for="hive-note">%1$s</label><br><input type="text" id="hive-note" name="note" class="widefat" form="%2$s" placeholder="%3$s"></p>', esc_html__( 'Changelog note', 'hive-compliance' ), esc_attr( $form ), esc_attr__( 'What changed and why', 'hive-compliance' ) );

		printf( '<p><button type="submit" class="button button-primary button-large" form="%1$s">%2$s</button></p>', esc_attr( $form ), esc_html__( 'Save document', 'hive-compliance' ) );
		echo '</div></div>';
	}

	/**
	 * Required-elements checklist (ticked manually; the heading check helps).
	 *
	 * @param Entry    $entry    Entry.
	 * @param Document $document Document.
	 */
	private function render_checklist_box( Entry $entry, Document $document ): void {
		$keys = $entry->element_keys();
		/* translators: 1: ticked items, 2: total items. */
		$title = sprintf( __( 'Required elements (%1$d/%2$d)', 'hive-compliance' ), $document->checklist_done( $keys ), count( $keys ) );
		echo '<div class="postbox hive-box"><h2 class="hndle">' . esc_html( $title ) . '</h2><div class="inside">';
		if ( ! $keys ) {
			echo '<p class="hive-meta">' . esc_html__( 'No required elements listed.', 'hive-compliance' ) . '</p>';
		}
		echo '<ul class="hive-checklist">';
		foreach ( $entry->required_elements as $element ) {
			$heading = ContentLinter::has_heading( $document->markdown, $element['key'], $element['label'] );
			printf(
				'<li><label><input type="checkbox" name="checklist[]" value="%1$s" form="hive-document-form"%2$s> %3$s</label> %4$s<br><span class="hive-meta">%5$s</span></li>',
				esc_attr( $element['key'] ),
				checked( ! empty( $document->checklist[ $element['key'] ] ), true, false ),
				esc_html( $element['label'] ),
				$heading ? $this->badge( __( 'heading found', 'hive-compliance' ), 'ok' ) : $this->badge( __( 'no heading', 'hive-compliance' ), 'muted' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge is escaped.
				esc_html( '' !== $element['legal_ref'] ? $element['legal_ref'] : '—' )
			);
		}
		echo '</ul></div></div>';
	}

	/**
	 * Page binding box (page documents only): separate forms, outside the main form.
	 *
	 * @param Entry  $entry Entry.
	 * @param string $lang  Language.
	 */
	private function render_binding_box( Entry $entry, string $lang ): void {
		if ( ! $entry->is_page() ) {
			return;
		}
		echo '<div class="postbox hive-box"><h2 class="hndle">' . esc_html__( 'Page', 'hive-compliance' ) . '</h2><div class="inside">';
		$page_id = $this->plugin->bindings()->get( $entry->id, $lang );
		if ( $page_id ) {
			$problems = \Hive\Compliance\Render\ShellGuard::inspect( (string) get_post_field( 'post_content', $page_id ), $entry->id );
			printf(
				'<p><a href="%1$s">%2$s</a> %3$s · <a href="%4$s" target="_blank" rel="noopener">%5$s</a></p>',
				esc_url( (string) get_edit_post_link( $page_id ) ),
				esc_html( get_the_title( $page_id ) ),
				$this->badge( (string) get_post_status( $page_id ), 'publish' === get_post_status( $page_id ) ? 'ok' : 'muted' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped.
				esc_url( (string) get_permalink( $page_id ) ),
				esc_html__( 'View', 'hive-compliance' )
			);
			if ( $problems ) {
				echo '<p class="hive-finding hive-finding--warning">' . esc_html__( 'The page contains more than the document block. Keep the block only.', 'hive-compliance' ) . '</p>';
			}
			$this->form_open( BindingActions::ACTION_UNBIND );
			printf( '<input type="hidden" name="doc" value="%s"><input type="hidden" name="lang" value="%s">', esc_attr( $entry->id ), esc_attr( $lang ) );
			submit_button( __( 'Remove binding', 'hive-compliance' ), 'link-delete small', 'submit', false );
			echo '</form>';
		} else {
			/* translators: %s: links placeholder of this document. */
			echo '<p>' . esc_html( sprintf( __( 'Not bound to a page yet, so %s stays empty.', 'hive-compliance' ), '{{links.' . $entry->id . '}}' ) ) . '</p>';
			$this->form_open( BindingActions::ACTION_BIND );
			printf( '<input type="hidden" name="doc" value="%s"><input type="hidden" name="lang" value="%s"><input type="hidden" name="mode" value="create">', esc_attr( $entry->id ), esc_attr( $lang ) );
			submit_button( __( 'Create draft page', 'hive-compliance' ), 'secondary', 'submit', false );
			echo '</form>';

			$this->form_open( BindingActions::ACTION_BIND, array( 'class' => 'hive-assign' ) );
			printf( '<input type="hidden" name="doc" value="%s"><input type="hidden" name="lang" value="%s"><input type="hidden" name="mode" value="assign">', esc_attr( $entry->id ), esc_attr( $lang ) );
			printf( '<label for="hive-assign-page">%s</label> ', esc_html__( 'or assign an existing page:', 'hive-compliance' ) );
			wp_dropdown_pages(
				array(
					'name'             => 'page_id',
					'id'               => 'hive-assign-page',
					'post_status'      => array( 'publish', 'draft', 'private' ),
					'show_option_none' => esc_html__( '— Select —', 'hive-compliance' ),
				)
			);
			submit_button( __( 'Assign', 'hive-compliance' ), 'secondary small', 'submit', false );
			echo '</form>';
		}//end if
		echo '</div></div>';
	}

	/**
	 * Placeholder picker with current values.
	 *
	 * @param string $lang Language.
	 */
	private function render_placeholder_box( string $lang ): void {
		echo '<div class="postbox hive-box"><h2 class="hndle">' . esc_html__( 'Placeholders', 'hive-compliance' ) . '</h2><div class="inside hive-placeholders">';
		echo '<p class="hive-meta">' . esc_html__( 'Click to insert. Values are filled in when the page is shown; they are never written into the text.', 'hive-compliance' ) . '</p>';
		$resolver = $this->plugin->resolver();
		foreach ( $this->plugin->placeholders()->providers() as $prefix => $provider ) {
			echo '<details><summary>' . esc_html( $provider->label() ) . ' <code>' . esc_html( $prefix ) . '.*</code></summary><ul>';
			foreach ( $provider->keys() as $key => $label ) {
				$token = $prefix . '.' . $key;
				$value = $resolver->value( $token, array( 'lang' => $lang ) );
				printf(
					'<li><button type="button" class="button-link" data-hive-insert="%1$s">{{%2$s}}</button> <span class="hive-meta">%3$s</span> %4$s</li>',
					esc_attr( '{{' . $token . '}}' ),
					esc_html( $token ),
					esc_html( $label ),
					null === $value ? $this->badge( __( 'missing', 'hive-compliance' ), 'error' ) : '<span class="hive-value">' . esc_html( wp_html_excerpt( $value, 60, '…' ) ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped.
				);
			}
			echo '</ul></details>';
		}
		echo '</div></div>';
	}

	/**
	 * Changelog and revisions.
	 *
	 * @param Document $document Document.
	 */
	private function render_changelog_box( Document $document ): void {
		echo '<div class="postbox hive-box"><h2 class="hndle">' . esc_html__( 'Changelog', 'hive-compliance' ) . '</h2><div class="inside">';
		if ( ! $document->changelog ) {
			echo '<p class="hive-meta">' . esc_html__( 'No changes recorded yet.', 'hive-compliance' ) . '</p>';
		} else {
			echo '<ol class="hive-changelog" reversed>';
			foreach ( array_reverse( $document->changelog ) as $entry ) {
				$revision = (int) ( $entry['revision_id'] ?? 0 );
				printf(
					'<li><strong>v%1$d</strong> · %2$s · %3$s%4$s<br>%5$s</li>',
					(int) $entry['version'],
					esc_html( (string) $entry['date'] ),
					$this->badge( (string) $entry['source'], 'import' === $entry['source'] ? 'info' : 'muted' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped.
					$revision ? ' · <a href="' . esc_url( admin_url( 'revision.php?revision=' . $revision ) ) . '">' . esc_html__( 'revision', 'hive-compliance' ) . '</a>' : '',
					esc_html( '' !== (string) $entry['note'] ? (string) $entry['note'] : '—' )
				);
			}
			echo '</ol>';
		}
		echo '</div></div>';
	}
}
