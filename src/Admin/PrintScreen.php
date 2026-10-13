<?php
/**
 * Print view: selected documents with resolved placeholders and a print stylesheet.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\Export\Selection;
use Hive\Compliance\I18n\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * A standalone page (no admin chrome) the browser saves as PDF. No PDF library.
 */
final class PrintScreen extends Screen {

	const NONCE = 'hive_compliance_print';

	/**
	 * Slug.
	 */
	public function slug(): string {
		return 'print';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Print view', 'hive-compliance' );
	}

	/**
	 * Hidden from the menu.
	 */
	public function in_menu(): bool {
		return false;
	}

	/**
	 * Outputs the page before the admin header is printed.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_output' ) );
	}

	/**
	 * Fallback when reached through the menu callback.
	 */
	public function render(): void {
		echo '<div class="wrap hive-wrap"><p>' . esc_html__( 'Open the print view from the Export screen.', 'hive-compliance' ) . '</p></div>';
	}

	/**
	 * Prints the standalone page and stops, when requested.
	 */
	public function maybe_output(): void {
		if ( ! isset( $_GET['page'] ) || 'hive-compliance-print' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing; the nonce is checked below.
			return;
		}
		Request::require_capability();
		check_admin_referer( self::NONCE );

		$selection = Selection::from_input(
			array(
				'mode'             => sanitize_key( wp_unslash( $_GET['mode'] ?? '' ) ),
				'groups'           => array_map( 'sanitize_key', (array) wp_unslash( $_GET['groups'] ?? array() ) ),
				'docs'             => array_map( 'sanitize_key', (array) wp_unslash( $_GET['docs'] ?? array() ) ),
				'langs'            => array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['langs'] ?? array() ) ),
				'include_internal' => ! empty( $_GET['include_internal'] ),
			),
			$this->plugin->languages()
		);

		nocache_headers();
		echo $this->html( $selection ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts and wp_kses_post output.
		exit;
	}

	/**
	 * Stylesheet tags of the standalone page (registered, then printed through WordPress).
	 */
	private function styles(): string {
		wp_register_style( 'hive-compliance-print', HIVE_COMPLIANCE_URL . 'assets/css/print.css', array(), HIVE_COMPLIANCE_VERSION );
		wp_register_style( 'hive-compliance-front', HIVE_COMPLIANCE_URL . 'assets/css/front.css', array(), HIVE_COMPLIANCE_VERSION );
		ob_start();
		wp_print_styles( array( 'hive-compliance-print', 'hive-compliance-front' ) );
		return (string) ob_get_clean();
	}

	/**
	 * The page HTML.
	 *
	 * @param Selection $selection Documents and languages.
	 */
	public function html( Selection $selection ): string {
		$plugin   = $this->plugin;
		$articles = array();
		foreach ( $selection->entries( $plugin->catalog(), $plugin->applicability() ) as $entry ) {
			foreach ( $selection->languages as $lang ) {
				$document = $plugin->repository()->find( $entry->id, $lang );
				if ( null === $document || $document->is_empty() ) {
					continue;
				}
				$rendered   = $plugin->renderer()->render_markdown( $document->markdown, $entry, $lang, true );
				$meta       = array(
					$lang,
					'v' . $document->version,
					/* translators: %s: date. */
					'' !== $document->effective_date ? sprintf( __( 'effective %s', 'hive-compliance' ), $document->effective_date ) : '',
					/* translators: %s: date. */
					'' !== $document->last_reviewed ? sprintf( __( 'reviewed %s', 'hive-compliance' ), $document->last_reviewed ) : '',
					$document->status,
					$entry->id,
				);
				$articles[] = sprintf(
					'<article class="hive-print-doc" lang="%1$s"><header><h1>%2$s</h1><p class="hive-print-meta">%3$s</p></header><div class="hive-print-body">%4$s</div></article>',
					esc_attr( $lang ),
					esc_html( $entry->title( $lang ) ),
					esc_html( implode( ' · ', array_filter( $meta ) ) ),
					wp_kses_post( $rendered['html'] )
				);
			}//end foreach
		}//end foreach

		$title = get_bloginfo( 'name' ) . ' — ' . __( 'Compliance documents', 'hive-compliance' );
		$html  = '<!DOCTYPE html><html lang="' . esc_attr( Languages::locale_to_code( (string) get_locale() ) ) . '"><head><meta charset="utf-8">';
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">';
		$html .= '<title>' . esc_html( $title ) . '</title>';
		$html .= $this->styles();
		$html .= '</head><body class="hive-print">';
		$html .= '<div class="hive-print-toolbar"><button type="button" onclick="window.print()">' . esc_html__( 'Print / Save as PDF', 'hive-compliance' ) . '</button> ';
		/* translators: %d: number of documents. */
		$html .= '<span>' . esc_html( sprintf( _n( '%d document', '%d documents', count( $articles ), 'hive-compliance' ), count( $articles ) ) ) . '</span></div>';
		$html .= $articles ? implode( '', $articles ) : '<p>' . esc_html__( 'No document with content matches the selection.', 'hive-compliance' ) . '</p>';
		$html .= '</body></html>';
		return $html;
	}
}
