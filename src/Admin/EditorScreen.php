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
		wp_enqueue_script( 'hive-compliance-editor', HIVE_COMPLIANCE_URL . 'assets/js/editor.js', array( 'jquery', 'wp-api-fetch', 'wp-i18n', 'hive-compliance-admin' ), HIVE_COMPLIANCE_VERSION, true );
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
					'checking'     => __( 'Checking…', 'hive-compliance' ),
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
		$rows = ( new Overview( $this->plugin ) )->rows( ! $full );
		$this->header(
			__( 'Documents', 'hive-compliance' ),
			__( 'Every document of the catalog, with its status per language. Open one to edit it.', 'hive-compliance' ),
			sprintf( '<a class="button" href="%1$s">%2$s %3$s</a>', esc_url( Request::page_url( 'export' ) ), $this->icon( 'download' ), esc_html__( 'Export', 'hive-compliance' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
		);
		echo '<div class="hive-toolbar"><nav class="hive-segmented" aria-label="' . esc_attr__( 'Documents shown', 'hive-compliance' ) . '">';
		printf( '<a href="%1$s"%2$s>%3$s</a>', esc_url( Request::page_url( 'documents' ) ), $full ? '' : ' class="is-current" aria-current="page"', esc_html__( 'Applicable documents', 'hive-compliance' ) );
		printf( '<a href="%1$s"%2$s>%3$s <span class="hive-count">%4$d</span></a>', esc_url( Request::page_url( 'documents', array( 'view' => 'all' ) ) ), $full ? ' class="is-current" aria-current="page"' : '', esc_html__( 'Full catalog', 'hive-compliance' ), count( $this->plugin->catalog()->entries() ) );
		echo '</nav></div>';
		$table = new DocumentsTable( $rows, $this->plugin->languages()->default_language() );
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
		$types    = array(
			Entry::TYPE_PAGE     => __( 'Page', 'hive-compliance' ),
			Entry::TYPE_FRAGMENT => __( 'Fragment', 'hive-compliance' ),
			Entry::TYPE_INTERNAL => __( 'Internal', 'hive-compliance' ),
		);

		$actions  = sprintf( '<a class="button" href="%1$s">%2$s %3$s</a>', esc_url( Request::page_url( 'documents' ) ), $this->icon( 'arrow-left-alt' ), esc_html__( 'All documents', 'hive-compliance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
		$actions .= $this->language_switcher( $entry, $lang );
		$this->header( $entry->title( $lang ), implode( ' · ', $entry->legal_refs ), $actions );

		echo '<p class="hive-badges">';
		echo $this->badge( $types[ $entry->type ] ?? $entry->type, $entry->type, true ) . $this->badge( ucfirst( $entry->group ), 'muted', true ) . $this->status_badge( $document->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badges are escaped.
		echo $this->badge( $document->version ? 'v' . $document->version : __( 'no version yet', 'hive-compliance' ), 'muted', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped.
		echo '<code class="hive-token">' . esc_html( $entry->id ) . '</code>';
		if ( 'draft' === $entry->checklist_status ) {
			echo $this->badge( __( 'checklist is a draft (best effort)', 'hive-compliance' ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped.
		}
		echo '</p>';

		if ( $entry->is_internal() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Internal document: it is never published on the site. Keep it for your records and exports.', 'hive-compliance' ) . '</p></div>';
		}

		echo '<div class="hive-editor">';
		echo '<div class="hive-editor__main">';

		/** This action is documented in src/Hooks.php */
		do_action( Hooks::EDITOR_BEFORE_FORM, $entry, $document );

		$this->panel_open(
			__( 'Markdown', 'hive-compliance' ),
			array(
				'icon'  => 'editor-code',
				'class' => 'hive-editor-panel',
				'flush' => true,
				'meta'  => '<span class="hive-unsaved">' . esc_html__( 'Unsaved changes', 'hive-compliance' ) . '</span><span class="hive-meta">' . esc_html__( 'Ctrl+S saves', 'hive-compliance' ) . '</span>',
			)
		);
		$this->form_open( self::ACTION_SAVE, array( 'id' => 'hive-document-form' ) );
		printf( '<input type="hidden" name="doc" value="%s"><input type="hidden" name="lang" value="%s">', esc_attr( $entry->id ), esc_attr( $lang ) );
		printf( '<label class="screen-reader-text" for="hive-markdown">%s</label>', esc_html__( 'Markdown', 'hive-compliance' ) );
		printf( '<textarea id="hive-markdown" name="markdown" rows="24" class="large-text code">%s</textarea>', esc_textarea( $document->markdown ) );
		echo '</form>';
		$this->panel_close();

		$errors = count( array_filter( $findings, static fn( $finding ) => $finding->is_error() ) );
		echo '<section class="hive-panel hive-tabs" data-hive-tabs>';
		echo '<ul class="hive-tabs__list" role="tablist">';
		printf( '<li role="presentation"><button type="button" class="hive-tabs__tab" id="hive-tab-preview" data-hive-tab="hive-preview-panel" aria-selected="true">%1$s %2$s</button></li>', $this->icon( 'visibility' ), esc_html__( 'Preview', 'hive-compliance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
		printf(
			'<li role="presentation"><button type="button" class="hive-tabs__tab" id="hive-tab-checks" data-hive-tab="hive-checks-panel" aria-selected="false">%1$s %2$s <span class="hive-badge hive-badge--%3$s hive-badge--plain" id="hive-findings-count">%4$d</span></button></li>',
			$this->icon( 'yes-alt' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			esc_html__( 'Checks', 'hive-compliance' ),
			esc_attr( $findings ? ( $errors ? 'error' : 'warning' ) : 'ok' ),
			count( $findings )
		);
		echo '</ul>';
		echo '<div class="hive-tabs__panel" id="hive-preview-panel"><h2>' . esc_html__( 'Preview', 'hive-compliance' ) . '</h2><div id="hive-preview" class="hive-preview">' . wp_kses_post( $preview['html'] ) . '</div></div>';
		echo '<div class="hive-tabs__panel" id="hive-checks-panel"><h2>' . esc_html__( 'Checks', 'hive-compliance' ) . '</h2><div id="hive-findings">';
		$this->render_findings( $findings );
		echo '</div></div>';
		echo '</section>';
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
	 * Language switcher (segmented chips, one per enabled language).
	 *
	 * @param Entry  $entry Entry.
	 * @param string $lang  Current language.
	 */
	private function language_switcher( Entry $entry, string $lang ): string {
		$languages = $this->plugin->languages();
		$labels    = array(
			Document::STATUS_EMPTY     => __( 'empty', 'hive-compliance' ),
			Document::STATUS_DRAFT     => __( 'draft', 'hive-compliance' ),
			Document::STATUS_PUBLISHED => __( 'published', 'hive-compliance' ),
		);
		$html      = '<nav class="hive-langs hive-language-switcher" aria-label="' . esc_attr__( 'Language', 'hive-compliance' ) . '">';
		foreach ( $languages->enabled() as $code ) {
			$document = $this->plugin->repository()->find( $entry->id, $code );
			$status   = null === $document ? Document::STATUS_EMPTY : $document->status;
			$html    .= sprintf(
				'<a href="%1$s" class="hive-lang hive-lang--%2$s%3$s" title="%4$s"%6$s>%5$s</a>',
				esc_url( Request::editor_url( $entry->id, $code ) ),
				esc_attr( $status ),
				$code === $lang ? ' is-current' : '',
				esc_attr( $languages->label( $code ) . ' — ' . ( $labels[ $status ] ?? $status ) ),
				esc_html( $code ),
				$code === $lang ? ' aria-current="page"' : ''
			);
		}
		return $html . '</nav>';
	}

	/**
	 * Findings list (also rebuilt by editor.js).
	 *
	 * @param \Hive\Compliance\Lint\Finding[] $findings Findings.
	 */
	private function render_findings( array $findings ): void {
		if ( ! $findings ) {
			echo '<p class="hive-ok">' . $this->icon( 'yes-alt' ) . ' ' . esc_html__( 'No problems found.', 'hive-compliance' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			return;
		}
		echo '<ul class="hive-findings">';
		foreach ( $findings as $finding ) {
			echo $this->finding( $finding->severity, $finding->message, $finding->details ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in finding().
		}
		echo '</ul>';
	}

	/**
	 * Status, dates, note and save button (inputs belong to the main form via form="").
	 *
	 * @param Document $document Document.
	 */
	private function render_save_box( Document $document ): void {
		$form   = 'hive-document-form';
		$status = Document::STATUS_PUBLISHED === $document->status ? Document::STATUS_PUBLISHED : Document::STATUS_DRAFT;
		$this->panel_open( __( 'Publish', 'hive-compliance' ), array( 'icon' => 'cloud-upload' ) );

		echo '<div class="hive-field"><span class="hive-field__label" id="hive-status-label">' . esc_html__( 'Status', 'hive-compliance' ) . '</span>';
		echo '<div class="hive-segmented" role="radiogroup" aria-labelledby="hive-status-label">';
		foreach ( array(
			Document::STATUS_DRAFT     => __( 'Draft', 'hive-compliance' ),
			Document::STATUS_PUBLISHED => __( 'Published', 'hive-compliance' ),
		) as $value => $label ) {
			printf( '<label><input type="radio" name="status" value="%1$s" form="%2$s"%3$s> %4$s</label>', esc_attr( $value ), esc_attr( $form ), checked( $status, $value, false ), esc_html( $label ) );
		}
		echo '</div>';
		echo '<span class="hive-field__hint">' . esc_html__( 'Visitors only see published documents. An empty document is always "empty".', 'hive-compliance' ) . '</span></div>';

		echo '<div class="hive-fields--two">';
		printf( '<div class="hive-field"><label class="hive-field__label" for="hive-effective">%1$s</label><input type="date" id="hive-effective" name="effective_date" value="%2$s" form="%3$s"></div>', esc_html__( 'Effective date', 'hive-compliance' ), esc_attr( $document->effective_date ), esc_attr( $form ) );
		printf( '<div class="hive-field"><label class="hive-field__label" for="hive-reviewed">%1$s</label><input type="date" id="hive-reviewed" name="last_reviewed" value="%2$s" form="%3$s"></div>', esc_html__( 'Last reviewed', 'hive-compliance' ), esc_attr( $document->last_reviewed ), esc_attr( $form ) );
		echo '</div>';
		printf( '<div class="hive-field"><label><input type="checkbox" name="mark_reviewed" value="1" form="%1$s"> %2$s</label></div>', esc_attr( $form ), esc_html__( 'Mark as reviewed today', 'hive-compliance' ) );
		printf( '<div class="hive-field"><label class="hive-field__label" for="hive-note">%1$s</label><input type="text" id="hive-note" name="note" form="%2$s" placeholder="%3$s"></div>', esc_html__( 'Changelog note', 'hive-compliance' ), esc_attr( $form ), esc_attr__( 'What changed and why', 'hive-compliance' ) );

		printf( '<p><button type="submit" class="button button-primary button-large" form="%1$s">%2$s</button></p>', esc_attr( $form ), esc_html__( 'Save document', 'hive-compliance' ) );
		if ( $document->version ) {
			/* translators: %d: version number. */
			echo '<p class="hive-meta">' . esc_html( sprintf( __( 'Saving a changed text creates version %d.', 'hive-compliance' ), $document->version + 1 ) ) . '</p>';
		}
		$this->panel_close();
	}

	/**
	 * Required-elements checklist (ticked manually; the heading check helps).
	 *
	 * @param Entry    $entry    Entry.
	 * @param Document $document Document.
	 */
	private function render_checklist_box( Entry $entry, Document $document ): void {
		$keys  = $entry->element_keys();
		$done  = $document->checklist_done( $keys );
		$total = count( $keys );
		$this->panel_open(
			__( 'Required elements', 'hive-compliance' ),
			array(
				'icon' => 'list-view',
				'meta' => $total ? $this->progress( $done, $total ) . '<span>' . esc_html( $done . '/' . $total ) . '</span>' : '',
			)
		);
		if ( ! $keys ) {
			echo '<p class="hive-meta">' . esc_html__( 'No required elements listed.', 'hive-compliance' ) . '</p>';
		}
		if ( count( $keys ) > 1 ) {
			echo $this->select_all( 'hive-checklist' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select_all().
		}
		echo '<ul class="hive-checklist" id="hive-checklist">';
		foreach ( $entry->required_elements as $element ) {
			$heading = ContentLinter::has_heading( $document->markdown, $element['key'] );
			$id      = 'hive-element-' . $element['key'];
			printf(
				'<li><input type="checkbox" id="%1$s" name="checklist[]" value="%2$s" form="hive-document-form"%3$s><div class="hive-checklist__text"><label for="%1$s">%4$s</label><span class="hive-checklist__ref">%6$s</span></div><span class="hive-checklist__state">%5$s</span></li>',
				esc_attr( $id ),
				esc_attr( $element['key'] ),
				checked( ! empty( $document->checklist[ $element['key'] ] ), true, false ),
				esc_html( Entry::element_label( $element, $document->lang ) ),
				$heading ? $this->badge( __( 'heading found', 'hive-compliance' ), 'ok' ) : $this->badge( __( 'no heading', 'hive-compliance' ), 'muted' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge is escaped.
				esc_html( '' !== $element['legal_ref'] ? $element['legal_ref'] : '—' )
			);
		}
		echo '</ul>';
		$this->panel_close();
	}

	/**
	 * Offer to make the bound page the WooCommerce terms page or the WordPress privacy page.
	 *
	 * @param Entry  $entry   Entry.
	 * @param string $lang    Language.
	 * @param int    $page_id Bound page.
	 */
	private function render_site_page_offer( Entry $entry, string $lang, int $page_id ): void {
		$site_pages = $this->plugin->site_pages();
		$role       = $site_pages->role( $entry->id );
		if ( null === $role ) {
			return;
		}
		$current = $site_pages->current( $entry->id );
		if ( $current === $page_id ) {
			echo '<p class="hive-ok">' . $this->icon( 'yes-alt' ) . ' ' . esc_html( $role['current_label'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			return;
		}
		if ( ! $site_pages->can_set( $entry->id ) ) {
			return;
		}
		$this->form_open( BindingActions::ACTION_SITE_PAGE, array( 'class' => 'hive-site-page' ) );
		printf( '<input type="hidden" name="doc" value="%s"><input type="hidden" name="lang" value="%s">', esc_attr( $entry->id ), esc_attr( $lang ) );
		echo '<p>';
		submit_button( $role['label'], 'secondary small', 'submit', false );
		if ( $current && get_post( $current ) ) {
			/* translators: %s: title of the page currently set. */
			echo '<br><span class="hive-meta">' . esc_html( sprintf( __( 'Currently: %s', 'hive-compliance' ), get_the_title( $current ) ) ) . '</span>';
		}
		echo '</p></form>';
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
		$page_id = $this->plugin->bindings()->get( $entry->id, $lang );
		$this->panel_open(
			__( 'Page', 'hive-compliance' ),
			array(
				'icon' => 'admin-page',
				'meta' => $page_id ? $this->badge( __( 'bound', 'hive-compliance' ), 'ok' ) : $this->badge( __( 'not bound', 'hive-compliance' ), 'warning' ),
			)
		);
		if ( $page_id ) {
			$problems = \Hive\Compliance\Render\ShellGuard::inspect( (string) get_post_field( 'post_content', $page_id ), $entry->id );
			$status   = (string) get_post_status( $page_id );
			printf(
				'<p class="hive-page-binding"><a href="%1$s">%2$s</a> %3$s <a href="%4$s" target="_blank" rel="noopener" class="hive-meta">%5$s ↗</a></p>',
				esc_url( (string) get_edit_post_link( $page_id ) ),
				esc_html( get_the_title( $page_id ) ),
				$this->badge( $status, 'publish' === $status ? 'ok' : 'muted' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped.
				esc_url( (string) get_permalink( $page_id ) ),
				esc_html__( 'View', 'hive-compliance' )
			);
			if ( $problems ) {
				echo $this->note( 'warning', __( 'The page contains more than the document block. Keep the block only.', 'hive-compliance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in note().
			}
			$this->render_site_page_offer( $entry, $lang, $page_id );
			$this->form_open( BindingActions::ACTION_UNBIND, array( 'class' => 'hive-side-actions' ) );
			printf( '<input type="hidden" name="doc" value="%s"><input type="hidden" name="lang" value="%s">', esc_attr( $entry->id ), esc_attr( $lang ) );
			echo '<span class="hive-meta">' . esc_html__( 'The page itself is never deleted.', 'hive-compliance' ) . '</span>';
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
			printf( '<label class="hive-field__label" for="hive-assign-page">%s</label>', esc_html__( 'or assign an existing page', 'hive-compliance' ) );
			$select = $this->select(
				'hive-assign-page',
				'page_id',
				$this->page_options(),
				'',
				array(
					'placeholder'       => __( '— Select a page —', 'hive-compliance' ),
					'placeholder_value' => '0',
				)
			);
			echo $select; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select().
			submit_button( __( 'Assign', 'hive-compliance' ), 'secondary small', 'submit', false );
			echo '</form>';
		}//end if
		$this->panel_close();
	}

	/**
	 * Placeholder picker with current values and a search field.
	 *
	 * @param string $lang Language.
	 */
	private function render_placeholder_box( string $lang ): void {
		$this->panel_open(
			__( 'Placeholders', 'hive-compliance' ),
			array(
				'icon'       => 'editor-code',
				'body_class' => 'hive-placeholders',
				'meta'       => sprintf( '<a href="%1$s">%2$s</a>', esc_url( Request::page_url( 'placeholders', array( 'lang' => $lang ) ) ), esc_html__( 'Manage all', 'hive-compliance' ) ),
			)
		);
		echo '<p class="hive-meta">' . esc_html__( 'Click to insert at the cursor. Values are filled in when the page is shown; they are never written into the text.', 'hive-compliance' ) . '</p>';
		echo $this->filter_input( 'hive-placeholder-picker', __( 'Search placeholders', 'hive-compliance' ), 'li' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in filter_input().
		echo '<div id="hive-placeholder-picker">';
		$resolver = $this->plugin->resolver();
		foreach ( $this->plugin->placeholders()->providers() as $prefix => $provider ) {
			echo '<details data-hive-filter-group><summary>' . esc_html( $provider->label() ) . ' <code>' . esc_html( $prefix ) . '.*</code></summary><ul>';
			foreach ( $provider->keys() as $key => $label ) {
				$token = $prefix . '.' . $key;
				$value = $resolver->value( $token, array( 'lang' => $lang ) );
				printf(
					'<li data-hive-search="%5$s"><button type="button" class="button-link" data-hive-insert="%1$s">{{%2$s}}</button> <span class="hive-meta">%3$s</span> %4$s</li>',
					esc_attr( '{{' . $token . '}}' ),
					esc_html( $token ),
					esc_html( $label ),
					null === $value ? '<span class="hive-placeholders__value hive-placeholders__value--missing">' . esc_html__( 'missing', 'hive-compliance' ) . '</span>' : '<span class="hive-placeholders__value">' . esc_html( wp_html_excerpt( $value, 60, '…' ) ) . '</span>',
					esc_attr( $token . ' ' . $label . ' ' . (string) $value )
				);
			}
			echo '</ul></details>';
		}
		echo '</div>';
		$this->panel_close();
	}

	/**
	 * Changelog and revisions.
	 *
	 * @param Document $document Document.
	 */
	private function render_changelog_box( Document $document ): void {
		$this->panel_open( __( 'Changelog', 'hive-compliance' ), array( 'icon' => 'backup' ) );
		if ( ! $document->changelog ) {
			echo '<p class="hive-meta">' . esc_html__( 'No changes recorded yet.', 'hive-compliance' ) . '</p>';
		} else {
			echo '<ol class="hive-timeline" reversed>';
			foreach ( array_reverse( $document->changelog ) as $entry ) {
				$revision = (int) ( $entry['revision_id'] ?? 0 );
				printf(
					'<li><span class="hive-timeline__head"><strong>v%1$d</strong><span class="hive-meta">%2$s</span>%3$s%4$s</span><span class="hive-timeline__note">%5$s</span></li>',
					(int) $entry['version'],
					esc_html( (string) $entry['date'] ),
					$this->badge( (string) $entry['source'], 'import' === $entry['source'] ? 'info' : 'muted', true ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped.
					$revision ? '<a href="' . esc_url( admin_url( 'revision.php?revision=' . $revision ) ) . '">' . esc_html__( 'revision', 'hive-compliance' ) . '</a>' : '',
					esc_html( '' !== (string) $entry['note'] ? (string) $entry['note'] : '—' )
				);
			}
			echo '</ol>';
		}
		$this->panel_close();
	}
}
