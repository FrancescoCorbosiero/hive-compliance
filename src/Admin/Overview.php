<?php
/**
 * Status of every catalog entry for the dashboard and the documents list.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\Catalog\Entry;
use Hive\Compliance\Document\Document;
use Hive\Compliance\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the rows and the cards data. Pure data, no output.
 */
final class Overview {

	/**
	 * Plugin services.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin services.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * One row per catalog entry.
	 *
	 * @param bool $applicable_only Only entries that apply to the store profile.
	 * @return array<string, array<string, mixed>> Keyed by document ID.
	 */
	public function rows( bool $applicable_only = true ): array {
		$applicability = $this->plugin->applicability();
		$context       = $this->plugin->profile()->context();
		$languages     = $this->plugin->languages();
		$default       = $languages->default_language();
		$audit         = $this->plugin->shell_guard()->audit();

		$rows = array();
		foreach ( $this->plugin->catalog()->entries() as $id => $entry ) {
			$applies = $applicability->applies( $entry, $context );
			if ( $applicable_only && ! $applies ) {
				continue;
			}
			$rows[ $id ] = $this->row( $entry, $applies, $languages->enabled(), $default, $audit[ $id ] ?? array() );
		}
		return $rows;
	}

	/**
	 * Cards above the table.
	 *
	 * @param array<string, array<string, mixed>> $rows Rows of applicable entries.
	 * @return array{business: array<string, array<string, string>>, unbound: Entry[], overdue: Entry[], live_drafts: Entry[], dirty_pages: Entry[], catalog_errors: string[]}
	 */
	public function cards( array $rows ): array {
		$cards = array(
			'business'       => $this->plugin->business()->issues( $this->plugin->seller_country() ),
			'unbound'        => array(),
			'overdue'        => array(),
			'live_drafts'    => array(),
			'dirty_pages'    => array(),
			'catalog_errors' => $this->plugin->catalog()->errors(),
		);
		foreach ( $rows as $row ) {
			$entry = $row['entry'];
			if ( $entry->is_page() && null === $row['page'] ) {
				$cards['unbound'][] = $entry;
			}
			if ( $row['overdue'] ) {
				$cards['overdue'][] = $entry;
			}
			if ( null !== $row['page'] && 'publish' === $row['page']['status'] && Document::STATUS_PUBLISHED !== $row['status'] ) {
				$cards['live_drafts'][] = $entry;
			}
			if ( null !== $row['page'] && $row['page']['problems'] ) {
				$cards['dirty_pages'][] = $entry;
			}
		}
		return $cards;
	}

	/**
	 * Whether a document is overdue for review (never reviewed counts as overdue once it has content).
	 *
	 * @param Document|null $document Document.
	 * @param int           $months   Review cadence.
	 * @param int|null      $now      Timestamp (tests).
	 */
	public static function is_overdue( ?Document $document, int $months, ?int $now = null ): bool {
		if ( null === $document || $document->is_empty() ) {
			return false;
		}
		if ( '' === $document->last_reviewed ) {
			return true;
		}
		$due = strtotime( $document->last_reviewed . ' +' . $months . ' months' );
		return false !== $due && $due < ( $now ?? time() );
	}

	/**
	 * Row of one entry.
	 *
	 * @param Entry                $entry     Entry.
	 * @param bool                 $applies   Whether it applies.
	 * @param string[]             $languages Enabled languages.
	 * @param string               $fallback  Default language.
	 * @param array<string, mixed> $pages     Shell audit of this document (lang => result).
	 * @return array<string, mixed>
	 */
	private function row( Entry $entry, bool $applies, array $languages, string $fallback, array $pages ): array {
		$repository = $this->plugin->repository();
		$resolver   = $this->plugin->resolver();
		$bindings   = $this->plugin->bindings();

		$coverage = array();
		$missing  = array();
		foreach ( $languages as $lang ) {
			$document          = $repository->find( $entry->id, $lang );
			$coverage[ $lang ] = null === $document ? Document::STATUS_EMPTY : $document->status;
			if ( null !== $document && ! $document->is_empty() ) {
				$tokens  = $resolver->missing(
					$document->markdown,
					array(
						'lang'   => $lang,
						'doc_id' => $entry->id,
					)
				);
				$missing = array_merge( $missing, $tokens );
			}
		}

		$primary = $repository->find( $entry->id, $fallback );
		$page    = null;
		$page_id = $entry->is_page() ? $bindings->get( $entry->id, $fallback ) : 0;
		if ( $page_id ) {
			$page = array(
				'page_id'  => $page_id,
				'status'   => (string) get_post_status( $page_id ),
				'problems' => $pages[ $fallback ]['problems'] ?? array(),
			);
		}

		$keys = $entry->element_keys();
		return array(
			'entry'           => $entry,
			'applies'         => $applies,
			'document'        => $primary,
			'status'          => null === $primary ? Document::STATUS_EMPTY : $primary->status,
			'version'         => null === $primary ? 0 : $primary->version,
			'last_reviewed'   => null === $primary ? '' : $primary->last_reviewed,
			'overdue'         => self::is_overdue( $primary, $entry->review_cadence_months ),
			'coverage'        => $coverage,
			'checklist_done'  => null === $primary ? 0 : $primary->checklist_done( $keys ),
			'checklist_total' => count( $keys ),
			'page'            => $page,
			'missing'         => array_values( array_unique( $missing ) ),
		);
	}
}
