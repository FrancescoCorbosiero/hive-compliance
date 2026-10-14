<?php
/**
 * Documents list table (dashboard and documents screen).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\Catalog\Entry;
use Hive\Compliance\Document\Document;
use Hive\Compliance\I18n\Languages;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Columns: title (type, group), languages, status, version, review, checklist, page, missing placeholders.
 */
final class DocumentsTable extends \WP_List_Table {

	/**
	 * Rows from Overview::rows().
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows;

	/**
	 * Default language.
	 *
	 * @var string
	 */
	private string $default_language;

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>> $rows             Rows.
	 * @param string                              $default_language Default language.
	 */
	public function __construct( array $rows, string $default_language ) {
		parent::__construct(
			array(
				'singular' => 'hive-document',
				'plural'   => 'hive-documents',
				'ajax'     => false,
			)
		);
		$this->rows             = $rows;
		$this->default_language = $default_language;
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'title'     => __( 'Document', 'hive-compliance' ),
			'languages' => __( 'Languages', 'hive-compliance' ),
			'status'    => __( 'Status', 'hive-compliance' ),
			'version'   => __( 'Version', 'hive-compliance' ),
			'reviewed'  => __( 'Last reviewed', 'hive-compliance' ),
			'checklist' => __( 'Checklist', 'hive-compliance' ),
			'page'      => __( 'Bound page', 'hive-compliance' ),
			'missing'   => __( 'Missing placeholders', 'hive-compliance' ),
		);
	}

	/**
	 * Loads the rows.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'title' );
		$this->items           = array_values( $this->rows );
	}

	/**
	 * Message when the table is empty.
	 */
	public function no_items() {
		esc_html_e( 'No documents apply to the current store profile.', 'hive-compliance' );
	}

	/**
	 * Title column with type and group.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_title( $item ) {
		$entry = $this->entry( $item );
		$url   = Request::editor_url( $entry->id, $this->default_language );
		$types = array(
			Entry::TYPE_PAGE     => __( 'Page', 'hive-compliance' ),
			Entry::TYPE_FRAGMENT => __( 'Fragment', 'hive-compliance' ),
			Entry::TYPE_INTERNAL => __( 'Internal', 'hive-compliance' ),
		);
		$out   = sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( $url ), esc_html( $entry->title( Languages::locale_to_code( (string) get_user_locale() ) ) ) );
		$out  .= sprintf(
			'<div class="hive-doc-meta"><span class="hive-badge hive-badge--%1$s hive-badge--plain">%2$s</span><span class="hive-badge hive-badge--muted hive-badge--plain">%3$s</span><code>%4$s</code></div>',
			esc_attr( $entry->type ),
			esc_html( $types[ $entry->type ] ?? $entry->type ),
			esc_html( ucfirst( $entry->group ) ),
			esc_html( $entry->id )
		);
		if ( ! $item['applies'] ) {
			$out .= '<div class="hive-meta">' . esc_html__( 'Not applicable to the current profile', 'hive-compliance' ) . '</div>';
		}
		return $out . $this->row_actions( array( 'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Edit', 'hive-compliance' ) ) ) );
	}

	/**
	 * Language coverage.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_languages( $item ) {
		$entry  = $this->entry( $item );
		$labels = array(
			Document::STATUS_EMPTY     => __( 'empty', 'hive-compliance' ),
			Document::STATUS_DRAFT     => __( 'draft', 'hive-compliance' ),
			Document::STATUS_PUBLISHED => __( 'published', 'hive-compliance' ),
		);
		$out    = array();
		foreach ( (array) $item['coverage'] as $lang => $status ) {
			$out[] = sprintf(
				'<a class="hive-lang hive-lang--%1$s" href="%2$s" title="%3$s">%4$s</a>',
				esc_attr( $status ),
				esc_url( Request::editor_url( $entry->id, (string) $lang ) ),
				esc_attr( $lang . ': ' . ( $labels[ $status ] ?? $status ) ),
				esc_html( (string) $lang )
			);
		}
		return '<span class="hive-langs">' . implode( '', $out ) . '</span>';
	}

	/**
	 * Status of the default-language document.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_status( $item ) {
		$labels = array(
			Document::STATUS_EMPTY     => __( 'Empty', 'hive-compliance' ),
			Document::STATUS_DRAFT     => __( 'Draft', 'hive-compliance' ),
			Document::STATUS_PUBLISHED => __( 'Published', 'hive-compliance' ),
		);
		$status = (string) $item['status'];
		return sprintf( '<span class="hive-badge hive-badge--%1$s">%2$s</span>', esc_attr( $status ), esc_html( $labels[ $status ] ?? $status ) );
	}

	/**
	 * Version.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_version( $item ) {
		return $item['version'] ? esc_html( 'v' . (int) $item['version'] ) : '<span class="hive-dash">—</span>';
	}

	/**
	 * Last review date and overdue flag.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_reviewed( $item ) {
		$out = '' !== $item['last_reviewed'] ? esc_html( mysql2date( get_option( 'date_format' ), (string) $item['last_reviewed'] ) ) : '<span class="hive-dash">—</span>';
		if ( $item['overdue'] ) {
			$out .= '<br><span class="hive-badge hive-badge--warning">' . esc_html__( 'Review due', 'hive-compliance' ) . '</span>';
		}
		return $out;
	}

	/**
	 * Checklist progress.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_checklist( $item ) {
		$total = (int) $item['checklist_total'];
		if ( 0 === $total ) {
			return '<span class="hive-dash">—</span>';
		}
		$done = (int) $item['checklist_done'];
		return sprintf(
			'<span class="hive-progress%5$s" title="%1$s"><span style="width:%2$d%%"></span></span> <span class="hive-meta">%3$d/%4$d</span>',
			esc_attr( sprintf( '%d/%d', $done, $total ) ),
			(int) round( 100 * $done / $total ),
			$done,
			$total,
			$done >= $total ? ' hive-progress--full' : ''
		);
	}

	/**
	 * Bound page.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_page( $item ) {
		$entry = $this->entry( $item );
		if ( ! $entry->is_page() ) {
			return '<span class="hive-meta">' . esc_html( $entry->is_internal() ? __( 'Never published', 'hive-compliance' ) : __( 'Fragment', 'hive-compliance' ) ) . '</span>';
		}
		if ( null === $item['page'] ) {
			return '<span class="hive-badge hive-badge--warning">' . esc_html__( 'Not bound', 'hive-compliance' ) . '</span>';
		}
		$page_id = (int) $item['page']['page_id'];
		$out     = sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_edit_post_link( $page_id ) ), esc_html( get_the_title( $page_id ) ) );
		if ( 'publish' !== $item['page']['status'] ) {
			$out .= ' <span class="hive-badge hive-badge--muted">' . esc_html( (string) $item['page']['status'] ) . '</span>';
		}
		if ( $item['page']['problems'] ) {
			$out .= ' <span class="hive-badge hive-badge--error" title="' . esc_attr( implode( ', ', $item['page']['problems'] ) ) . '">' . esc_html__( 'Extra content', 'hive-compliance' ) . '</span>';
		}
		return $out;
	}

	/**
	 * Missing placeholders count.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_missing( $item ) {
		$missing = (array) $item['missing'];
		if ( ! $missing ) {
			return '<span class="hive-dash">0</span>';
		}
		return sprintf( '<span class="hive-badge hive-badge--error" title="%1$s">%2$d</span>', esc_attr( implode( ', ', $missing ) ), count( $missing ) );
	}

	/**
	 * Entry of a row.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	private function entry( array $item ): Entry {
		return $item['entry'];
	}
}
