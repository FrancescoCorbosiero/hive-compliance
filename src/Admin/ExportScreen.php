<?php
/**
 * Export screen: LLM bundle, JSON backup and print view.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\Catalog\Entry;
use Hive\Compliance\Export\BundleExporter;
use Hive\Compliance\Export\JsonExporter;
use Hive\Compliance\Export\Selection;
use Hive\Compliance\I18n\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Filters (documents, languages, internal toggle) and three outputs.
 */
final class ExportScreen extends Screen {

	const ACTION_EXPORT = 'hive_compliance_export';

	/**
	 * Slug.
	 */
	public function slug(): string {
		return 'export';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Export', 'hive-compliance' );
	}

	/**
	 * Hooks the export handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_EXPORT, array( $this, 'handle_export' ) );
	}

	/**
	 * Handles the form submission (admin-post.php): download or open the print view.
	 */
	public function handle_export(): void {
		Request::require_capability();
		check_admin_referer( self::ACTION_EXPORT );

		$selection = Selection::from_input(
			array(
				'mode'             => sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) ),
				'groups'           => array_map( 'sanitize_key', (array) wp_unslash( $_POST['groups'] ?? array() ) ),
				'docs'             => array_map( 'sanitize_key', (array) wp_unslash( $_POST['docs'] ?? array() ) ),
				'langs'            => array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['langs'] ?? array() ) ),
				'include_internal' => ! empty( $_POST['include_internal'] ),
			),
			$this->plugin->languages()
		);
		$format    = sanitize_key( wp_unslash( $_POST['format'] ?? 'bundle' ) );
		$stamp     = gmdate( 'Ymd-His' );
		$site      = sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( 'print' === $format ) {
			$query             = $selection->to_query();
			$query['_wpnonce'] = wp_create_nonce( PrintScreen::NONCE );
			Request::redirect( add_query_arg( array_merge( array( 'page' => 'hive-compliance-print' ), $query ), admin_url( 'admin.php' ) ) );
		}
		if ( 'json' === $format ) {
			$this->download( ( new JsonExporter( $this->plugin ) )->export(), 'application/json', "hive-compliance-backup-{$site}-{$stamp}.json" );
		}
		$this->download( ( new BundleExporter( $this->plugin ) )->export( $selection ), 'text/markdown', "hive-compliance-bundle-{$site}-{$stamp}.md" );
	}

	/**
	 * Prints the form.
	 */
	public function render(): void {
		$languages     = $this->plugin->languages();
		$applicability = $this->plugin->applicability();
		$lang          = Languages::locale_to_code( (string) get_user_locale() );
		$entries       = $this->plugin->catalog()->entries();
		$applicable    = count( array_filter( $entries, static fn( Entry $entry ) => $applicability->applies( $entry ) ) );
		$group_labels  = array(
			'core'          => __( 'Core', 'hive-compliance' ),
			'conditional'   => __( 'Conditional', 'hive-compliance' ),
			'international' => __( 'International', 'hive-compliance' ),
			'fragment'      => __( 'Fragments', 'hive-compliance' ),
			'internal'      => __( 'Internal', 'hive-compliance' ),
		);

		echo '<div class="wrap hive-wrap">';
		$this->header(
			$this->title(),
			__( 'Download all documents as one Markdown file for an LLM, a full JSON backup, or a printable view.', 'hive-compliance' ),
			sprintf( '<a class="button" href="%1$s">%2$s %3$s</a>', esc_url( Request::page_url( 'import' ) ), $this->icon( 'upload' ), esc_html__( 'Import', 'hive-compliance' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
		);
		$this->form_open( self::ACTION_EXPORT );
		echo '<div class="hive-layout hive-layout--wide-side"><div class="hive-layout__main">';

		$this->panel_open(
			__( 'Documents', 'hive-compliance' ),
			array(
				'icon'        => 'media-document',
				'description' => __( 'Which documents go into the bundle and the print view. The JSON backup always contains everything.', 'hive-compliance' ),
			)
		);

		echo '<div class="hive-mode">';
		$this->radio( 'mode', Selection::MODE_APPLICABLE, __( 'All applicable documents', 'hive-compliance' ), true, sprintf( /* translators: %d: number of documents. */ _n( '%d document', '%d documents', $applicable, 'hive-compliance' ), $applicable ) );
		echo '</div>';

		echo '<div class="hive-mode">';
		$this->radio( 'mode', Selection::MODE_GROUPS, __( 'By group', 'hive-compliance' ), false, __( 'every applicable document of the ticked groups', 'hive-compliance' ) );
		echo '<div class="hive-mode__body">' . $this->select_all( 'hive-export-groups', 'hive-mode-' . Selection::MODE_GROUPS ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select_all().
		echo '<div class="hive-checkgrid" id="hive-export-groups" data-hive-activates="hive-mode-' . esc_attr( Selection::MODE_GROUPS ) . '">';
		foreach ( Entry::GROUPS as $group ) {
			$count = count( array_filter( $entries, static fn( Entry $entry ) => $entry->group === $group && $applicability->applies( $entry ) ) );
			/* translators: %d: number of applicable documents in the group. */
			echo $this->choice( 'groups[]', $group, $group_labels[ $group ] ?? ucfirst( $group ), false, '<span class="hive-meta">' . esc_html( sprintf( _n( '%d applicable document', '%d applicable documents', $count, 'hive-compliance' ), $count ) ) . '</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in choice().
		}
		echo '</div></div></div>';

		echo '<div class="hive-mode">';
		$this->radio( 'mode', Selection::MODE_DOCUMENTS, __( 'Individual documents', 'hive-compliance' ), false, __( 'pick them one by one', 'hive-compliance' ) );
		echo '<div class="hive-mode__body">';
		echo $this->filter_input( 'hive-export-docs', __( 'Find a document', 'hive-compliance' ) ) . $this->select_all( 'hive-export-docs', 'hive-mode-' . Selection::MODE_DOCUMENTS ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helpers.
		echo '<div id="hive-export-docs" data-hive-activates="hive-mode-' . esc_attr( Selection::MODE_DOCUMENTS ) . '">';
		foreach ( Entry::GROUPS as $group ) {
			$in_group = array_filter( $entries, static fn( Entry $entry ) => $entry->group === $group );
			if ( ! $in_group ) {
				continue;
			}
			echo '<div data-hive-filter-group class="hive-export-group"><h3 class="hive-meta" style="text-transform:uppercase;letter-spacing:.04em;margin:12px 0 6px">' . esc_html( $group_labels[ $group ] ?? ucfirst( $group ) ) . '</h3><div class="hive-checkgrid hive-checkgrid--wide">';
			foreach ( $in_group as $entry ) {
				echo $this->choice( 'docs[]', $entry->id, $entry->title( $lang ), false, $applicability->applies( $entry ) ? '' : '<span class="hive-meta">' . esc_html__( 'not applicable to the current profile', 'hive-compliance' ) . '</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in choice().
			}
			echo '</div></div>';
		}
		echo '</div></div></div>';
		$this->panel_close();

		$this->panel_open( __( 'Languages', 'hive-compliance' ), array( 'icon' => 'translation' ) );
		if ( count( $languages->enabled() ) > 1 ) {
			echo $this->select_all( 'hive-export-langs' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select_all().
		}
		echo '<div class="hive-checkgrid" id="hive-export-langs">';
		foreach ( $languages->enabled() as $code ) {
			echo $this->choice( 'langs[]', $code, $code . ' — ' . $languages->label( $code ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in choice().
		}
		echo '</div>';
		$this->panel_close();

		$this->panel_open( __( 'Options', 'hive-compliance' ), array( 'icon' => 'admin-settings' ) );
		echo '<label class="hive-check"><input type="checkbox" name="include_internal" value="1" checked> <span class="hive-check__text"><span>' . esc_html__( 'Include internal documents', 'hive-compliance' ) . '</span><span class="hive-meta">' . esc_html__( 'Records of processing, procedures, logs. Never published on the site, useful for the LLM context.', 'hive-compliance' ) . '</span></span></label>';
		$this->panel_close();

		echo '</div><div class="hive-layout__side">';
		$this->panel_open( __( 'Format', 'hive-compliance' ), array( 'icon' => 'download' ) );
		echo '<div class="hive-formats">';
		$this->format( 'bundle', 'media-text', __( 'LLM bundle', 'hive-compliance' ), __( 'One Markdown file with instructions for the model, the context and every selected document. Hand it to an LLM, then import the result.', 'hive-compliance' ), __( 'Download bundle (.md)', 'hive-compliance' ), true );
		$this->format( 'json', 'database-export', __( 'JSON backup', 'hive-compliance' ), __( 'Everything: all documents, business data, profile, page bindings and settings. Restore it from the Import screen.', 'hive-compliance' ), __( 'Download backup (.json)', 'hive-compliance' ), false );
		$this->format( 'print', 'printer', __( 'Print view', 'hive-compliance' ), __( 'The selected documents with placeholders resolved, one per printed page. Save it as PDF from the browser.', 'hive-compliance' ), __( 'Open print view', 'hive-compliance' ), false, true );
		echo '</div>';
		$this->panel_close();
		echo '</div></div>';
		echo '</form></div>';
	}

	/**
	 * One output format card with its submit button.
	 *
	 * @param string $value   Format value.
	 * @param string $icon    Dashicon.
	 * @param string $title   Title.
	 * @param string $text    Description.
	 * @param string $button  Button label.
	 * @param bool   $primary Primary button.
	 * @param bool   $new_tab Open the result in a new tab.
	 */
	private function format( string $value, string $icon, string $title, string $text, string $button, bool $primary, bool $new_tab = false ): void {
		printf(
			'<div class="hive-format%1$s"><p class="hive-format__title">%2$s %3$s</p><p class="hive-format__text">%4$s</p><button type="submit" name="format" value="%5$s" class="button%6$s"%8$s>%7$s</button></div>',
			$primary ? ' hive-format--primary' : '',
			$this->icon( $icon ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			esc_html( $title ),
			esc_html( $text ),
			esc_attr( $value ),
			$primary ? ' button-primary' : '',
			esc_html( $button ),
			$new_tab ? ' formtarget="_blank"' : ''
		);
	}

	/**
	 * Radio button heading a mode block.
	 *
	 * @param string $name    Name.
	 * @param string $value   Value.
	 * @param string $label   Label.
	 * @param bool   $checked Checked.
	 * @param string $hint    Small text after the label.
	 */
	private function radio( string $name, string $value, string $label, bool $checked, string $hint = '' ): void {
		printf(
			'<label class="hive-mode__label"><input type="radio" id="hive-%1$s-%2$s" name="%1$s" value="%2$s"%3$s> <span>%4$s</span>%5$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $checked, true, false ),
			esc_html( $label ),
			'' !== $hint ? ' <span class="hive-meta">' . esc_html( $hint ) . '</span>' : ''
		);
	}

	/**
	 * Sends a file download and stops.
	 *
	 * @param string $content      File content.
	 * @param string $content_type MIME type.
	 * @param string $filename     File name.
	 */
	private function download( string $content, string $content_type, string $filename ): void {
		nocache_headers();
		header( 'Content-Type: ' . $content_type . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- File download, not HTML.
		exit;
	}
}
