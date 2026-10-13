<?php
/**
 * Loads and saves documents (one hive_document post per doc_id + language).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Document;

use Hive\Compliance\Hooks;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The only class that writes hive_document posts.
 *
 * Saves keep the Markdown byte-exact: WordPress' content filters (kses, entity conversion,
 * tag balancing) would rewrite Markdown such as "a < b & c". The content is never output
 * raw; every output path goes through the render pipeline (Parsedown safe mode + wp_kses_post).
 */
final class Repository {

	const CACHE_GROUP = 'hive_compliance';

	/**
	 * All stored documents, indexed [doc_id][lang], loaded lazily.
	 *
	 * @var array<string, array<string, Document>>|null
	 */
	private ?array $index = null;

	/**
	 * Markdown forced into the post being written by save() (see guard_content()).
	 *
	 * @var string|null
	 */
	private ?string $pending_markdown = null;

	/**
	 * Hooks needed by the repository.
	 */
	public function register(): void {
		add_filter( 'wp_insert_post_data', array( $this, 'guard_content' ), PHP_INT_MAX );
		add_action( 'wp_restore_post_revision', array( $this, 'on_revision_restored' ), 20, 2 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ) );
	}

	/**
	 * A document, or a blank one when it is not stored yet.
	 *
	 * @param string $doc_id Catalog ID.
	 * @param string $lang   Language code.
	 */
	public function get( string $doc_id, string $lang ): Document {
		return $this->find( $doc_id, $lang ) ?? Document::blank( $doc_id, $lang );
	}

	/**
	 * A stored document or null.
	 *
	 * @param string $doc_id Catalog ID.
	 * @param string $lang   Language code.
	 */
	public function find( string $doc_id, string $lang ): ?Document {
		if ( null !== $this->index ) {
			return $this->index[ $doc_id ][ $lang ] ?? null;
		}
		$post_id = $this->find_post_id( $doc_id, $lang );
		if ( ! $post_id ) {
			return null;
		}
		$post = get_post( $post_id );
		return $post instanceof WP_Post && PostType::NAME === $post->post_type ? $this->hydrate( $post ) : null;
	}

	/**
	 * Document stored in a given post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function find_by_post( int $post_id ): ?Document {
		$post = get_post( $post_id );
		return $post instanceof WP_Post && PostType::NAME === $post->post_type ? $this->hydrate( $post ) : null;
	}

	/**
	 * All stored documents.
	 *
	 * @return Document[]
	 */
	public function all(): array {
		$out = array();
		foreach ( $this->index() as $languages ) {
			foreach ( $languages as $document ) {
				$out[] = $document;
			}
		}
		return $out;
	}

	/**
	 * Stored languages of one document.
	 *
	 * @param string $doc_id Catalog ID.
	 * @return array<string, Document> lang => document.
	 */
	public function languages_of( string $doc_id ): array {
		return $this->index()[ $doc_id ] ?? array();
	}

	/**
	 * Saves a document. The version increments and a changelog entry is appended only when
	 * the Markdown changes; status, dates and checklist can change without a new version.
	 *
	 * @param string               $doc_id   Catalog ID.
	 * @param string               $lang     Language code.
	 * @param string               $markdown New Markdown (normalised here).
	 * @param array<string, mixed> $args {
	 *     Optional.
	 *
	 *     @type string $title          Post title (catalog title).
	 *     @type string $note           Changelog note.
	 *     @type string $source         manual|import.
	 *     @type string $status         draft|published. Default: unchanged (draft for new documents).
	 *     @type string $last_reviewed  Y-m-d.
	 *     @type string $effective_date Y-m-d.
	 *     @type array  $checklist      Element key => bool.
	 * }
	 * @return Document|WP_Error The saved document.
	 */
	public function save( string $doc_id, string $lang, string $markdown, array $args = array() ) {
		$doc_id = sanitize_key( $doc_id );
		if ( '' === $doc_id || '' === $lang ) {
			return new WP_Error( 'hive_invalid_document', __( 'Invalid document or language.', 'hive-compliance' ) );
		}

		$markdown = Markdown::normalize( $markdown );
		$previous = $this->find( $doc_id, $lang );
		$changed  = ( null === $previous ? '' : $previous->markdown ) !== $markdown;
		$source   = Document::SOURCE_IMPORT === ( $args['source'] ?? '' ) ? Document::SOURCE_IMPORT : Document::SOURCE_MANUAL;

		$status = isset( $args['status'] ) ? PostType::sanitize_status( $args['status'] ) : ( $previous ? $previous->status : Document::STATUS_DRAFT );
		if ( '' === $markdown ) {
			$status = Document::STATUS_EMPTY;
		} elseif ( Document::STATUS_EMPTY === $status ) {
			$status = Document::STATUS_DRAFT;
		}

		$post_id = $previous ? $previous->post_id : 0;
		if ( ! $post_id || $changed || isset( $args['title'] ) ) {
			$post_id = $this->write_post( $post_id, $doc_id, $lang, $markdown, (string) ( $args['title'] ?? '' ) );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
		}

		$version   = $previous ? $previous->version : 0;
		$changelog = $previous ? $previous->changelog : array();
		if ( $changed ) {
			$version     = $this->max_version( $previous ) + 1;
			$changelog[] = array(
				'version'     => $version,
				'date'        => current_time( 'Y-m-d' ),
				'time'        => time(),
				'note'        => sanitize_text_field( (string) ( $args['note'] ?? '' ) ),
				'source'      => $source,
				'revision_id' => $this->latest_revision_id( $post_id, $markdown ),
				'user_id'     => get_current_user_id(),
			);
		}

		$meta = array(
			PostType::META_DOC_ID         => $doc_id,
			PostType::META_LANG           => $lang,
			PostType::META_VERSION        => $version,
			PostType::META_STATUS         => $status,
			PostType::META_CHANGELOG      => $changelog,
			PostType::META_LAST_REVIEWED  => array_key_exists( 'last_reviewed', $args ) ? (string) $args['last_reviewed'] : ( $previous ? $previous->last_reviewed : '' ),
			PostType::META_EFFECTIVE_DATE => array_key_exists( 'effective_date', $args ) ? (string) $args['effective_date'] : ( $previous ? $previous->effective_date : '' ),
			PostType::META_CHECKLIST      => array_key_exists( 'checklist', $args ) ? (array) $args['checklist'] : ( $previous ? $previous->checklist : array() ),
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}

		$this->flush();
		wp_cache_set( $this->cache_key( $doc_id, $lang ), $post_id, self::CACHE_GROUP );

		$document = $this->find_by_post( $post_id );
		if ( null === $document ) {
			return new WP_Error( 'hive_save_failed', __( 'The document could not be saved.', 'hive-compliance' ) );
		}

		if ( $changed ) {
			/** This action is documented in src/Hooks.php */
			do_action( Hooks::DOCUMENT_SAVED, $document, $previous, $source );
		}
		return $document;
	}

	/**
	 * Restores a document from a JSON backup record.
	 *
	 * The content is saved as a new revision with source "import". Version numbers never go
	 * backwards on this site: a restored text gets max(next version, backup version). For a
	 * document that did not exist here, the backup's changelog history is kept (revision links
	 * point to the other site, so they are cleared).
	 *
	 * @param array<string, mixed> $record Backup record (doc_id, lang, markdown, version, status, dates, changelog, checklist).
	 * @param string               $title  Post title.
	 * @return Document|WP_Error
	 */
	public function restore( array $record, string $title ) {
		$doc_id  = sanitize_key( (string) ( $record['doc_id'] ?? '' ) );
		$lang    = \Hive\Compliance\I18n\Languages::normalize_code( (string) ( $record['lang'] ?? '' ) );
		$version = absint( $record['version'] ?? 0 );
		$current = $this->find( $doc_id, $lang );

		$document = $this->save(
			$doc_id,
			$lang,
			(string) ( $record['markdown'] ?? '' ),
			array(
				'title'          => $title,
				/* translators: %d: version number in the backup. */
				'note'           => sprintf( __( 'Restored from a JSON backup (backup version %d).', 'hive-compliance' ), $version ),
				'source'         => Document::SOURCE_IMPORT,
				'status'         => PostType::sanitize_status( $record['status'] ?? Document::STATUS_DRAFT ),
				'last_reviewed'  => PostType::sanitize_date( $record['last_reviewed'] ?? '' ),
				'effective_date' => PostType::sanitize_date( $record['effective_date'] ?? '' ),
				'checklist'      => PostType::sanitize_checklist( $record['checklist'] ?? array() ),
			)
		);
		if ( is_wp_error( $document ) || $document->is_empty() || $version <= $document->version ) {
			return $document;
		}

		// Keep the backup's numbering (the restored text becomes $version).
		$changelog = $document->changelog;
		$latest    = array_pop( $changelog );
		if ( null === $current ) {
			$history   = array_filter(
				PostType::sanitize_changelog( $record['changelog'] ?? array() ),
				static fn( array $entry ) => $entry['version'] < $version
			);
			$changelog = array_map(
				static function ( array $entry ): array {
					$entry['revision_id'] = 0;
					return $entry;
				},
				array_values( $history )
			);
		}
		if ( is_array( $latest ) ) {
			$latest['version'] = $version;
			$changelog[]       = $latest;
		}
		update_post_meta( $document->post_id, PostType::META_VERSION, $version );
		update_post_meta( $document->post_id, PostType::META_CHANGELOG, wp_slash( $changelog ) );
		$this->flush();
		return $this->find_by_post( $document->post_id ) ?? $document;
	}

	/**
	 * Updates status, dates or checklist without touching the content or version.
	 *
	 * @param string               $doc_id Catalog ID.
	 * @param string               $lang   Language code.
	 * @param array<string, mixed> $args   Keys: status, last_reviewed, effective_date, checklist.
	 * @return Document|WP_Error
	 */
	public function update_meta( string $doc_id, string $lang, array $args ) {
		$document = $this->find( $doc_id, $lang );
		if ( null === $document ) {
			return new WP_Error( 'hive_not_found', __( 'Document not found.', 'hive-compliance' ) );
		}
		return $this->save( $doc_id, $lang, $document->markdown, $args );
	}

	/**
	 * The revision whose content has a given hash (used for the three-way conflict note).
	 *
	 * @param Document $document Document.
	 * @param string   $hash     "sha256:..." hash.
	 */
	public function find_revision_by_hash( Document $document, string $hash ): ?WP_Post {
		if ( ! $document->exists() ) {
			return null;
		}
		foreach ( wp_get_post_revisions( $document->post_id, array( 'order' => 'DESC' ) ) as $revision ) {
			if ( Markdown::hash( Markdown::normalize( $revision->post_content ) ) === $hash ) {
				return $revision;
			}
		}
		return null;
	}

	/**
	 * Content of a given version, read from its revision.
	 *
	 * @param Document $document Document.
	 * @param int      $version  Version number.
	 */
	public function version_markdown( Document $document, int $version ): ?string {
		if ( $version === $document->version ) {
			return $document->markdown;
		}
		return $this->recorded_markdown( $document, $version );
	}

	/**
	 * Content of a version as recorded in the revision its changelog entry points to.
	 *
	 * @param Document $document Document.
	 * @param int      $version  Version number.
	 */
	private function recorded_markdown( Document $document, int $version ): ?string {
		$entry = $document->changelog_entry( $version );
		if ( ! $entry || empty( $entry['revision_id'] ) ) {
			return null;
		}
		$revision = get_post( (int) $entry['revision_id'] );
		return $revision instanceof WP_Post ? Markdown::normalize( $revision->post_content ) : null;
	}

	/**
	 * Forgets cached documents (after writes).
	 */
	public function flush(): void {
		$this->index = null;
	}

	/**
	 * Keeps the Markdown byte-exact while save() writes the post and its revision.
	 * Hooked to wp_insert_post_data at the latest priority.
	 *
	 * @param array<string, mixed> $data Slashed post data about to be written.
	 * @return array<string, mixed>
	 */
	public function guard_content( $data ) {
		if ( null === $this->pending_markdown || ! is_array( $data ) ) {
			return $data;
		}
		$type = $data['post_type'] ?? '';
		if ( PostType::NAME === $type || ( 'revision' === $type && PostType::NAME === get_post_type( (int) ( $data['post_parent'] ?? 0 ) ) ) ) {
			$data['post_content'] = wp_slash( $this->pending_markdown );
		}
		return $data;
	}

	/**
	 * Restoring a revision from the core revisions screen is a manual change:
	 * the content is written back byte-exact, the version increments and the changelog records it.
	 *
	 * @param int $post_id     Post ID.
	 * @param int $revision_id Restored revision ID.
	 */
	public function on_revision_restored( $post_id, $revision_id ): void {
		$post_id  = (int) $post_id;
		$revision = get_post( (int) $revision_id );
		if ( PostType::NAME !== get_post_type( $post_id ) || ! $revision instanceof WP_Post ) {
			return;
		}

		$this->flush();
		$document = $this->find_by_post( $post_id );
		if ( null === $document ) {
			return;
		}

		$restored = Markdown::normalize( $revision->post_content );
		$before   = $this->recorded_markdown( $document, $this->max_version( $document ) );

		// The core restore ran the content through the save filters; write the exact text back.
		if ( $document->markdown !== $restored ) {
			$this->write_post( $post_id, $document->doc_id, $document->lang, $restored, '' );
		}
		if ( null !== $before && $before === $restored ) {
			$this->flush();
			return;
		}

		$version     = $this->max_version( $document ) + 1;
		$changelog   = $document->changelog;
		$changelog[] = array(
			'version'     => $version,
			'date'        => current_time( 'Y-m-d' ),
			'time'        => time(),
			/* translators: %s: date and time of the restored revision. */
			'note'        => sprintf( __( 'Restored the revision of %s.', 'hive-compliance' ), $revision->post_modified ),
			'source'      => Document::SOURCE_MANUAL,
			'revision_id' => $this->latest_revision_id( $post_id, $restored ),
			'user_id'     => get_current_user_id(),
		);
		update_post_meta( $post_id, PostType::META_VERSION, $version );
		update_post_meta( $post_id, PostType::META_CHANGELOG, wp_slash( $changelog ) );
		$this->flush();
	}

	/**
	 * Drops the cached post ID when a document post is deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_delete( $post_id ): void {
		if ( PostType::NAME !== get_post_type( (int) $post_id ) ) {
			return;
		}
		$doc_id = (string) get_post_meta( (int) $post_id, PostType::META_DOC_ID, true );
		$lang   = (string) get_post_meta( (int) $post_id, PostType::META_LANG, true );
		wp_cache_delete( $this->cache_key( $doc_id, $lang ), self::CACHE_GROUP );
		$this->flush();
	}

	/**
	 * Inserts or updates the post, keeping the Markdown byte-exact (also in its revision).
	 *
	 * @param int    $post_id  Existing post ID or 0.
	 * @param string $doc_id   Catalog ID.
	 * @param string $lang     Language code.
	 * @param string $markdown Normalised Markdown.
	 * @param string $title    Title; '' keeps the current one.
	 * @return int|WP_Error Post ID.
	 */
	private function write_post( int $post_id, string $doc_id, string $lang, string $markdown, string $title ) {
		$postarr = array(
			'post_type'    => PostType::NAME,
			'post_status'  => 'private',
			'post_content' => $markdown,
		);
		if ( '' !== $title || ! $post_id ) {
			$postarr['post_title'] = '' !== $title ? $title : $doc_id . ' (' . $lang . ')';
		}

		$this->pending_markdown = $markdown;
		try {
			if ( $post_id ) {
				$postarr['ID'] = $post_id;
				$result        = wp_update_post( wp_slash( $postarr ), true );
			} else {
				$postarr['post_name'] = sanitize_title( $doc_id . '-' . $lang );
				$result               = wp_insert_post( wp_slash( $postarr ), true );
				if ( ! is_wp_error( $result ) && '' !== $markdown ) {
					// Core only snapshots revisions on updates; keep the first version too.
					wp_save_post_revision( (int) $result );
				}
			}
		} finally {
			$this->pending_markdown = null;
		}

		return is_wp_error( $result ) ? $result : (int) $result;
	}

	/**
	 * ID of the newest revision, if its content matches the given Markdown.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $markdown Expected content.
	 */
	private function latest_revision_id( int $post_id, string $markdown ): int {
		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'numberposts' => 1,
				'orderby'     => 'ID',
				'order'       => 'DESC',
			)
		);
		$revision  = reset( $revisions );
		if ( $revision instanceof WP_Post && Markdown::normalize( $revision->post_content ) === $markdown ) {
			return (int) $revision->ID;
		}
		return 0;
	}

	/**
	 * Highest version ever recorded (current meta or changelog), so versions never repeat.
	 *
	 * @param Document|null $document Document.
	 */
	private function max_version( ?Document $document ): int {
		if ( null === $document ) {
			return 0;
		}
		$max = $document->version;
		foreach ( $document->changelog as $entry ) {
			$max = max( $max, (int) ( $entry['version'] ?? 0 ) );
		}
		return $max;
	}

	/**
	 * Post ID for (doc_id, lang), cached.
	 *
	 * @param string $doc_id Catalog ID.
	 * @param string $lang   Language code.
	 */
	private function find_post_id( string $doc_id, string $lang ): int {
		$key    = $this->cache_key( $doc_id, $lang );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false !== $cached && (int) $cached > 0 ) {
			return (int) $cached;
		}

		$ids = get_posts(
			array(
				'post_type'        => PostType::NAME,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One small indexed lookup per document; the result is cached.
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'   => PostType::META_DOC_ID,
						'value' => $doc_id,
					),
					array(
						'key'   => PostType::META_LANG,
						'value' => $lang,
					),
				),
			)
		);

		$post_id = $ids ? (int) $ids[0] : 0;
		if ( $post_id ) {
			wp_cache_set( $key, $post_id, self::CACHE_GROUP );
		}
		return $post_id;
	}

	/**
	 * Loads every stored document once per request.
	 *
	 * @return array<string, array<string, Document>>
	 */
	private function index(): array {
		if ( null !== $this->index ) {
			return $this->index;
		}

		$posts = get_posts(
			array(
				'post_type'        => PostType::NAME,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);

		$index = array();
		foreach ( $posts as $post ) {
			$document = $this->hydrate( $post );
			if ( '' === $document->doc_id || '' === $document->lang || isset( $index[ $document->doc_id ][ $document->lang ] ) ) {
				continue;
			}
			$index[ $document->doc_id ][ $document->lang ] = $document;
		}
		$this->index = $index;
		return $index;
	}

	/**
	 * Builds a Document from a post.
	 *
	 * @param WP_Post $post Post.
	 */
	private function hydrate( WP_Post $post ): Document {
		$id       = (int) $post->ID;
		$markdown = Markdown::normalize( (string) $post->post_content );
		$status   = PostType::sanitize_status( get_post_meta( $id, PostType::META_STATUS, true ) );
		if ( '' === $markdown ) {
			$status = Document::STATUS_EMPTY;
		}
		$changelog = get_post_meta( $id, PostType::META_CHANGELOG, true );
		$checklist = get_post_meta( $id, PostType::META_CHECKLIST, true );

		return new Document(
			$id,
			(string) get_post_meta( $id, PostType::META_DOC_ID, true ),
			(string) get_post_meta( $id, PostType::META_LANG, true ),
			$markdown,
			(int) get_post_meta( $id, PostType::META_VERSION, true ),
			$status,
			(string) get_post_meta( $id, PostType::META_LAST_REVIEWED, true ),
			(string) get_post_meta( $id, PostType::META_EFFECTIVE_DATE, true ),
			is_array( $changelog ) ? array_values( $changelog ) : array(),
			is_array( $checklist ) ? $checklist : array(),
			(string) $post->post_modified_gmt
		);
	}

	/**
	 * Object cache key.
	 *
	 * @param string $doc_id Catalog ID.
	 * @param string $lang   Language code.
	 */
	private function cache_key( string $doc_id, string $lang ): string {
		return 'post_id:' . $doc_id . ':' . $lang;
	}
}
