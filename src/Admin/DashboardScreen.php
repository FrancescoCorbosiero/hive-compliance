<?php
/**
 * Dashboard: setup steps, status tiles, attention cards and the applicable documents.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\Catalog\Entry;
use Hive\Compliance\Document\Document;
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
		$applies  = array_filter( $rows, static fn( $row ) => $row['applies'] );
		$cards    = $overview->cards( $applies );

		echo '<div class="wrap hive-wrap">';
		$this->header(
			__( 'Hive Compliance', 'hive-compliance' ),
			__( 'Legal and compliance documents of this shop.', 'hive-compliance' ),
			sprintf(
				'<a class="button" href="%1$s">%2$s %3$s</a> <a class="button" href="%4$s">%5$s %6$s</a>',
				esc_url( Request::page_url( 'export' ) ),
				$this->icon( 'download' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
				esc_html__( 'Export', 'hive-compliance' ),
				esc_url( Request::page_url( 'import' ) ),
				$this->icon( 'upload' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
				esc_html__( 'Import', 'hive-compliance' )
			)
		);

		$this->render_onboarding( $cards, $applies );
		$this->render_stats( $applies, $cards );
		$this->render_cards( $cards );

		echo '<div class="hive-toolbar">';
		$this->render_view_toggle( $full, count( $applies ), count( $this->plugin->catalog()->entries() ) );
		$this->render_skeleton_form();
		echo '</div>';

		$table = new DocumentsTable( $rows, $this->plugin->languages()->default_language() );
		$table->prepare_items();
		$table->display();
		echo '</div>';
	}

	/**
	 * Getting-started strip, shown until the profile is saved and the business data is complete.
	 *
	 * @param array<string, mixed>                $cards Overview::cards().
	 * @param array<string, array<string, mixed>> $rows  Applicable rows.
	 */
	private function render_onboarding( array $cards, array $rows ): void {
		$profile_done  = $this->plugin->profile()->is_saved();
		$business_done = ! $cards['business'];
		if ( $profile_done && $business_done ) {
			return;
		}
		$published = count( array_filter( $rows, static fn( $row ) => Document::STATUS_PUBLISHED === $row['status'] ) );

		$steps = array(
			array(
				'done'  => $profile_done,
				'title' => __( 'Describe the store', 'hive-compliance' ),
				'text'  => __( 'The store profile decides which documents apply. Review the pre-filled answers.', 'hive-compliance' ),
				'url'   => Request::page_url( 'profile' ),
				'cta'   => $profile_done ? __( 'Edit profile', 'hive-compliance' ) : __( 'Open the store profile', 'hive-compliance' ),
			),
			array(
				'done'  => $business_done,
				'title' => __( 'Fill in the business data', 'hive-compliance' ),
				'text'  => __( 'Legal name, VAT number, address and contacts feed every document.', 'hive-compliance' ),
				'url'   => Request::page_url( 'business' ),
				'cta'   => $business_done ? __( 'Edit business data', 'hive-compliance' ) : __( 'Complete business data', 'hive-compliance' ),
			),
			array(
				'done'  => $published > 0,
				'title' => __( 'Write and publish the documents', 'hive-compliance' ),
				'text'  => __( 'Edit the skeletons here, or export them for an LLM and import the result.', 'hive-compliance' ),
				'url'   => Request::page_url( 'export' ),
				'cta'   => __( 'Export for an LLM', 'hive-compliance' ),
			),
		);

		echo '<div class="hive-onboarding" aria-label="' . esc_attr__( 'Getting started', 'hive-compliance' ) . '">';
		$primary = true;
		foreach ( $steps as $i => $step ) {
			printf(
				'<div class="hive-onboarding__step%1$s"><span class="hive-onboarding__num">%2$s</span><div><span class="hive-onboarding__title">%3$s</span><span class="hive-onboarding__text">%4$s</span><a class="button%5$s" href="%6$s">%7$s</a></div></div>',
				$step['done'] ? ' is-done' : '',
				$step['done'] ? $this->icon( 'yes' ) : (int) $i + 1, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
				esc_html( $step['title'] ),
				esc_html( $step['text'] ),
				! $step['done'] && $primary ? ' button-primary' : ' button-secondary',
				esc_url( $step['url'] ),
				esc_html( $step['cta'] )
			);
			if ( ! $step['done'] ) {
				$primary = false;
			}
		}
		echo '</div>';
	}

	/**
	 * Status tiles.
	 *
	 * @param array<string, array<string, mixed>> $rows  Applicable rows.
	 * @param array<string, mixed>                $cards Overview::cards().
	 */
	private function render_stats( array $rows, array $cards ): void {
		$by_status = array(
			Document::STATUS_PUBLISHED => 0,
			Document::STATUS_DRAFT     => 0,
			Document::STATUS_EMPTY     => 0,
		);
		foreach ( $rows as $row ) {
			$status = (string) $row['status'];
			if ( isset( $by_status[ $status ] ) ) {
				++$by_status[ $status ];
			}
		}
		$issues = count( $cards['business'] ) + count( $cards['unbound'] ) + count( $cards['overdue'] ) + count( $cards['live_drafts'] ) + count( $cards['dirty_pages'] ) + count( $cards['catalog_errors'] ) + count( $cards['store_checks'] ) + count( $cards['placeholders'] );
		$total  = count( $rows );

		echo '<div class="hive-stats">';
		echo $this->stat( __( 'Applicable documents', 'hive-compliance' ), $total, 'info', __( 'from the store profile', 'hive-compliance' ), Request::page_url( 'documents' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in stat().
		echo $this->stat( __( 'Published', 'hive-compliance' ), $by_status[ Document::STATUS_PUBLISHED ], 'ok', $total ? sprintf( /* translators: %d: percentage. */ __( '%d%% of applicable', 'hive-compliance' ), (int) round( 100 * $by_status[ Document::STATUS_PUBLISHED ] / $total ) ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in stat().
		echo $this->stat( __( 'Drafts', 'hive-compliance' ), $by_status[ Document::STATUS_DRAFT ], $by_status[ Document::STATUS_DRAFT ] ? 'warning' : '', __( 'not visible to visitors', 'hive-compliance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in stat().
		echo $this->stat( __( 'Empty', 'hive-compliance' ), $by_status[ Document::STATUS_EMPTY ], $by_status[ Document::STATUS_EMPTY ] ? 'error' : '', __( 'no content yet', 'hive-compliance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in stat().
		echo $this->stat( __( 'Open issues', 'hive-compliance' ), $issues, $issues ? 'warning' : 'ok', $issues ? __( 'see the cards below', 'hive-compliance' ) : __( 'all clear', 'hive-compliance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in stat().
		echo '</div>';
	}

	/**
	 * The attention cards.
	 *
	 * @param array<string, mixed> $cards Overview::cards().
	 */
	private function render_cards( array $cards ): void {
		echo '<div class="hive-cards">';

		if ( $cards['catalog_errors'] ) {
			$this->card( __( 'Catalog errors', 'hive-compliance' ), 'error', 'warning', array_map( 'strval', $cards['catalog_errors'] ), '' );
		}

		$business = array();
		foreach ( $cards['business'] as $issue ) {
			$business[] = $issue['label'] . ': ' . $issue['message'];
		}
		$this->card( __( 'Business data issues', 'hive-compliance' ), $business ? 'error' : 'ok', 'building', $business, Request::page_url( 'business' ) );

		$this->card( __( 'Public documents without a page', 'hive-compliance' ), $cards['unbound'] ? 'warning' : 'ok', 'admin-links', $this->titles( $cards['unbound'] ), Request::page_url( 'placeholders' ), __( 'Bind pages', 'hive-compliance' ) );
		$this->card( __( 'Reviews due', 'hive-compliance' ), $cards['overdue'] ? 'warning' : 'ok', 'calendar-alt', $this->titles( $cards['overdue'] ), '' );

		$pages = array_merge(
			array_map( static fn( $title ) => sprintf( /* translators: %s: document title. */ __( '%s: page is live but the document is not published', 'hive-compliance' ), $title ), $this->titles( $cards['live_drafts'] ) ),
			array_map( static fn( $title ) => sprintf( /* translators: %s: document title. */ __( '%s: page has extra content', 'hive-compliance' ), $title ), $this->titles( $cards['dirty_pages'] ) )
		);
		$this->card( __( 'Bound pages', 'hive-compliance' ), $pages ? 'warning' : 'ok', 'admin-page', $pages, '' );

		$this->card( __( 'Placeholders', 'hive-compliance' ), $cards['placeholders'] ? 'warning' : 'ok', 'editor-code', $cards['placeholders'], Request::page_url( 'placeholders' ) );

		$checks = array_map( static fn( $warning ) => $warning['message'] . ( $warning['details'] ? ' — ' . implode( ', ', $warning['details'] ) : '' ), $cards['store_checks'] );
		$this->card( __( 'Store checks', 'hive-compliance' ), $checks ? 'warning' : 'ok', 'store', $checks, '' );

		echo '</div>';
	}

	/**
	 * One card.
	 *
	 * @param string   $title    Title.
	 * @param string   $kind     ok|warning|error.
	 * @param string   $icon     Dashicon.
	 * @param string[] $items    Items.
	 * @param string   $link     Optional "fix" link.
	 * @param string   $link_text Link text.
	 */
	private function card( string $title, string $kind, string $icon, array $items, string $link, string $link_text = '' ): void {
		printf(
			'<div class="hive-card hive-card--%1$s"><div class="hive-card__head"><span class="hive-card__icon">%2$s</span><h2>%3$s</h2><span class="hive-card__count">%4$d</span></div><div class="hive-card__body">',
			esc_attr( $kind ),
			$this->icon( $icon ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			esc_html( $title ),
			count( $items )
		);
		if ( ! $items ) {
			echo '<p class="hive-card__nothing">' . $this->icon( 'yes-alt' ) . esc_html__( 'Nothing to do.', 'hive-compliance' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
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
		echo '</div>';
		if ( '' !== $link && $items ) {
			printf( '<div class="hive-card__foot"><a href="%1$s">%2$s →</a></div>', esc_url( $link ), esc_html( '' !== $link_text ? $link_text : __( 'Fix it', 'hive-compliance' ) ) );
		}
		echo '</div>';
	}

	/**
	 * Bulk skeleton generation for empty applicable documents.
	 */
	private function render_skeleton_form(): void {
		$this->form_open( SkeletonActions::ACTION_ALL, array( 'class' => 'hive-skeleton-form' ) );
		echo '<label for="hive-skeleton-scope" class="screen-reader-text">' . esc_html__( 'Languages', 'hive-compliance' ) . '</label>';
		echo '<select id="hive-skeleton-scope" name="scope"><option value="default">' . esc_html__( 'Default language', 'hive-compliance' ) . '</option><option value="all">' . esc_html__( 'All enabled languages', 'hive-compliance' ) . '</option></select> ';
		submit_button( __( 'Generate skeletons for empty documents', 'hive-compliance' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Applicable only / full catalog toggle.
	 *
	 * @param bool $full       Whether the full catalog is shown.
	 * @param int  $applicable Number of applicable documents.
	 * @param int  $all        Number of catalog entries.
	 */
	private function render_view_toggle( bool $full, int $applicable, int $all ): void {
		echo '<nav class="hive-segmented" aria-label="' . esc_attr__( 'Documents shown', 'hive-compliance' ) . '">';
		printf( '<a href="%1$s"%2$s>%3$s <span class="hive-count">%4$d</span></a>', esc_url( Request::page_url() ), $full ? '' : ' class="is-current" aria-current="page"', esc_html__( 'Applicable documents', 'hive-compliance' ), (int) $applicable );
		printf( '<a href="%1$s"%2$s>%3$s <span class="hive-count">%4$d</span></a>', esc_url( Request::page_url( '', array( 'view' => 'all' ) ) ), $full ? ' class="is-current" aria-current="page"' : '', esc_html__( 'Full catalog', 'hive-compliance' ), (int) $all );
		echo '</nav>';
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
