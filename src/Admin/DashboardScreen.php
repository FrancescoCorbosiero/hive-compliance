<?php
/**
 * Dashboard: cards and the applicable documents.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\Catalog\Entry;
use Hive\Compliance\I18n\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Business data issues, unbound public documents, overdue reviews, page problems, then the table.
 */
final class DashboardScreen extends Screen {

	/**
	 * Slug.
	 */
	public function slug(): string {
		return '';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Dashboard', 'hive-compliance' );
	}

	/**
	 * Prints the dashboard.
	 */
	public function render(): void {
		$full     = isset( $_GET['view'] ) && 'all' === sanitize_key( wp_unslash( $_GET['view'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View toggle.
		$overview = new Overview( $this->plugin );
		$rows     = $overview->rows( ! $full );
		$cards    = $overview->cards( $full ? array_filter( $rows, static fn( $row ) => $row['applies'] ) : $rows );

		echo '<div class="wrap hive-wrap">';
		$this->header( __( 'Hive Compliance', 'hive-compliance' ), __( 'Legal and compliance documents of this shop.', 'hive-compliance' ) );

		if ( ! $this->plugin->profile()->is_saved() ) {
			printf(
				'<div class="notice notice-info inline"><p>%1$s <a class="button button-primary" href="%2$s">%3$s</a></p></div>',
				esc_html__( 'The store profile decides which documents apply. Review the pre-filled answers first.', 'hive-compliance' ),
				esc_url( Request::page_url( 'profile' ) ),
				esc_html__( 'Open the store profile', 'hive-compliance' )
			);
		}

		$this->render_cards( $cards );

		$this->render_skeleton_form();
		$this->render_view_toggle( $full );
		$table = new DocumentsTable( $rows, $this->plugin->languages()->default_language() );
		$table->prepare_items();
		$table->display();
		echo '</div>';
	}

	/**
	 * The cards.
	 *
	 * @param array<string, mixed> $cards Overview::cards().
	 */
	private function render_cards( array $cards ): void {
		echo '<div class="hive-cards">';

		if ( $cards['catalog_errors'] ) {
			$this->card( __( 'Catalog errors', 'hive-compliance' ), 'error', array_map( 'strval', $cards['catalog_errors'] ), '' );
		}

		$business = array();
		foreach ( $cards['business'] as $issue ) {
			$business[] = $issue['label'] . ': ' . $issue['message'];
		}
		$this->card( __( 'Business data issues', 'hive-compliance' ), $business ? 'error' : 'ok', $business, Request::page_url( 'business' ) );

		$this->card( __( 'Public documents without a page', 'hive-compliance' ), $cards['unbound'] ? 'warning' : 'ok', $this->titles( $cards['unbound'] ), '' );
		$this->card( __( 'Reviews due', 'hive-compliance' ), $cards['overdue'] ? 'warning' : 'ok', $this->titles( $cards['overdue'] ), '' );

		$pages = array_merge(
			array_map( static fn( $title ) => sprintf( /* translators: %s: document title. */ __( '%s: page is live but the document is not published', 'hive-compliance' ), $title ), $this->titles( $cards['live_drafts'] ) ),
			array_map( static fn( $title ) => sprintf( /* translators: %s: document title. */ __( '%s: page has extra content', 'hive-compliance' ), $title ), $this->titles( $cards['dirty_pages'] ) )
		);
		$this->card( __( 'Bound pages', 'hive-compliance' ), $pages ? 'warning' : 'ok', $pages, '' );

		echo '</div>';
	}

	/**
	 * One card.
	 *
	 * @param string   $title Title.
	 * @param string   $kind  ok|warning|error.
	 * @param string[] $items Items.
	 * @param string   $link  Optional "fix" link.
	 */
	private function card( string $title, string $kind, array $items, string $link ): void {
		printf( '<div class="hive-card hive-card--%1$s"><h2>%2$s <span class="hive-count">%3$d</span></h2>', esc_attr( $kind ), esc_html( $title ), count( $items ) );
		if ( ! $items ) {
			echo '<p class="hive-meta">' . esc_html__( 'Nothing to do.', 'hive-compliance' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( array_slice( $items, 0, 8 ) as $item ) {
				echo '<li>' . esc_html( $item ) . '</li>';
			}
			if ( count( $items ) > 8 ) {
				/* translators: %d: number of further items. */
				echo '<li class="hive-meta">' . esc_html( sprintf( __( '…and %d more', 'hive-compliance' ), count( $items ) - 8 ) ) . '</li>';
			}
			echo '</ul>';
		}
		if ( '' !== $link && $items ) {
			printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $link ), esc_html__( 'Fix it', 'hive-compliance' ) );
		}
		echo '</div>';
	}

	/**
	 * Bulk skeleton generation for empty applicable documents.
	 */
	private function render_skeleton_form(): void {
		$this->form_open( SkeletonActions::ACTION_ALL, array( 'class' => 'hive-skeleton-form alignright' ) );
		echo '<label for="hive-skeleton-scope" class="screen-reader-text">' . esc_html__( 'Languages', 'hive-compliance' ) . '</label>';
		echo '<select id="hive-skeleton-scope" name="scope"><option value="default">' . esc_html__( 'Default language', 'hive-compliance' ) . '</option><option value="all">' . esc_html__( 'All enabled languages', 'hive-compliance' ) . '</option></select> ';
		submit_button( __( 'Generate skeletons for empty documents', 'hive-compliance' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Applicable only / full catalog toggle.
	 *
	 * @param bool $full Whether the full catalog is shown.
	 */
	private function render_view_toggle( bool $full ): void {
		echo '<ul class="subsubsub">';
		printf( '<li><a href="%1$s"%2$s>%3$s</a> | </li>', esc_url( Request::page_url() ), $full ? '' : ' class="current" aria-current="page"', esc_html__( 'Applicable documents', 'hive-compliance' ) );
		printf( '<li><a href="%1$s"%2$s>%3$s</a></li>', esc_url( Request::page_url( '', array( 'view' => 'all' ) ) ), $full ? ' class="current" aria-current="page"' : '', esc_html__( 'Full catalog', 'hive-compliance' ) );
		echo '</ul>';
	}

	/**
	 * Titles of entries in the user's language.
	 *
	 * @param Entry[] $entries Entries.
	 * @return string[]
	 */
	private function titles( array $entries ): array {
		$lang = Languages::locale_to_code( (string) get_user_locale() );
		return array_map( static fn( Entry $entry ) => $entry->title( $lang ), $entries );
	}
}
