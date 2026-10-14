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

		echo '<div class="wrap hive-wrap">';
		$this->header( $this->title(), __( 'Download all documents as one Markdown file for an LLM, a full JSON backup, or a printable view.', 'hive-compliance' ) );
		$this->form_open( self::ACTION_EXPORT );

		echo '<h2>' . esc_html__( 'Documents', 'hive-compliance' ) . '</h2><fieldset>';
		$this->radio( 'mode', Selection::MODE_APPLICABLE, __( 'All applicable documents', 'hive-compliance' ), true );
		$this->radio( 'mode', Selection::MODE_GROUPS, __( 'By group:', 'hive-compliance' ), false );
		echo '<div class="hive-indent">' . $this->select_all( 'hive-export-groups', 'hive-mode-' . Selection::MODE_GROUPS ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select_all().
		echo '<div class="hive-checkgrid hive-indent" id="hive-export-groups" data-hive-activates="hive-mode-' . esc_attr( Selection::MODE_GROUPS ) . '">';
		foreach ( Entry::GROUPS as $group ) {
			printf( '<label class="hive-check"><input type="checkbox" name="groups[]" value="%1$s"> %2$s</label>', esc_attr( $group ), esc_html( ucfirst( $group ) ) );
		}
		echo '</div>';
		$this->radio( 'mode', Selection::MODE_DOCUMENTS, __( 'Individual documents:', 'hive-compliance' ), false );
		echo '<details class="hive-indent"><summary>' . esc_html__( 'Choose documents', 'hive-compliance' ) . '</summary>' . $this->select_all( 'hive-export-docs', 'hive-mode-' . Selection::MODE_DOCUMENTS ) . '<div class="hive-checkgrid" id="hive-export-docs" data-hive-activates="hive-mode-' . esc_attr( Selection::MODE_DOCUMENTS ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select_all().
		foreach ( $this->plugin->catalog()->entries() as $entry ) {
			printf(
				'<label class="hive-check"><input type="checkbox" name="docs[]" value="%1$s"> %2$s%3$s</label>',
				esc_attr( $entry->id ),
				esc_html( $entry->title( $lang ) ),
				$applicability->applies( $entry ) ? '' : ' <span class="hive-meta">(' . esc_html__( 'not applicable', 'hive-compliance' ) . ')</span>'
			);
		}
		echo '</div></details></fieldset>';

		echo '<h2>' . esc_html__( 'Languages', 'hive-compliance' ) . '</h2>';
		if ( count( $languages->enabled() ) > 2 ) {
			echo $this->select_all( 'hive-export-langs' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select_all().
		}
		echo '<div class="hive-checkgrid" id="hive-export-langs">';
		foreach ( $languages->enabled() as $code ) {
			printf( '<label class="hive-check"><input type="checkbox" name="langs[]" value="%1$s" checked> %2$s</label>', esc_attr( $code ), esc_html( $code . ' — ' . $languages->label( $code ) ) );
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'Options', 'hive-compliance' ) . '</h2>';
		echo '<p><label><input type="checkbox" name="include_internal" value="1" checked> ' . esc_html__( 'Include internal documents (records of processing, procedures, logs)', 'hive-compliance' ) . '</label></p>';

		echo '<p class="submit">';
		printf( '<button type="submit" name="format" value="bundle" class="button button-primary">%s</button> ', esc_html__( 'Download LLM bundle (.md)', 'hive-compliance' ) );
		printf( '<button type="submit" name="format" value="json" class="button">%s</button> ', esc_html__( 'Download JSON backup (.json)', 'hive-compliance' ) );
		printf( '<button type="submit" name="format" value="print" class="button" formtarget="_blank">%s</button>', esc_html__( 'Open print view', 'hive-compliance' ) );
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'The JSON backup always contains everything (all documents, business data, profile, page bindings and settings); the filters apply to the bundle and the print view.', 'hive-compliance' ) . '</p>';
		echo '</form></div>';
	}

	/**
	 * Radio button.
	 *
	 * @param string $name    Name.
	 * @param string $value   Value.
	 * @param string $label   Label.
	 * @param bool   $checked Checked.
	 */
	private function radio( string $name, string $value, string $label, bool $checked ): void {
		printf( '<p><label><input type="radio" id="hive-%1$s-%2$s" name="%1$s" value="%2$s"%3$s> %4$s</label></p>', esc_attr( $name ), esc_attr( $value ), checked( $checked, true, false ), esc_html( $label ) );
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
