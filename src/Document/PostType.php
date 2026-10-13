<?php
/**
 * The hive_document custom post type and its meta.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Document;

use Hive\Compliance\I18n\Languages;
use Hive\Compliance\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the CPT (no UI of its own) and the meta keys.
 */
final class PostType {

	const NAME = 'hive_document';

	const META_DOC_ID         = '_hive_doc_id';
	const META_LANG           = '_hive_lang';
	const META_VERSION        = '_hive_version';
	const META_STATUS         = '_hive_status';
	const META_LAST_REVIEWED  = '_hive_last_reviewed';
	const META_EFFECTIVE_DATE = '_hive_effective_date';
	const META_CHANGELOG      = '_hive_changelog';
	const META_CHECKLIST      = '_hive_checklist';

	/**
	 * Registers the post type, its meta and related filters. Runs on init.
	 */
	public function register(): void {
		register_post_type(
			self::NAME,
			array(
				'labels'              => array(
					'name'          => __( 'Compliance documents', 'hive-compliance' ),
					'singular_name' => __( 'Compliance document', 'hive-compliance' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'hierarchical'        => false,
				'supports'            => array( 'revisions' ),
				'capability_type'     => array( 'hive_document', 'hive_documents' ),
				'map_meta_cap'        => true,
				'capabilities'        => self::capabilities(),
			)
		);

		$this->register_meta();

		// Legal texts: keep every revision so each version's text stays retrievable.
		add_filter( 'wp_' . self::NAME . '_revisions_to_keep', array( $this, 'revisions_to_keep' ) );
		add_filter( 'get_edit_post_link', array( $this, 'edit_link' ), 10, 2 );
	}

	/**
	 * Primitive capabilities of the post type, all mapped to manage_hive_compliance.
	 *
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		$cap  = Plugin::CAPABILITY;
		$caps = array();
		foreach ( array( 'edit_posts', 'edit_others_posts', 'edit_private_posts', 'edit_published_posts', 'publish_posts', 'read_private_posts', 'read', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'create_posts' ) as $primitive ) {
			$caps[ $primitive ] = $cap;
		}
		return $caps;
	}

	/**
	 * Registers the meta keys with sanitisation and authorisation.
	 */
	private function register_meta(): void {
		$auth = static function (): bool {
			return current_user_can( Plugin::CAPABILITY );
		};

		$keys = array(
			self::META_DOC_ID         => array( 'string', 'sanitize_key' ),
			self::META_LANG           => array( 'string', array( Languages::class, 'normalize_code' ) ),
			self::META_VERSION        => array( 'integer', 'absint' ),
			self::META_STATUS         => array( 'string', array( self::class, 'sanitize_status' ) ),
			self::META_LAST_REVIEWED  => array( 'string', array( self::class, 'sanitize_date' ) ),
			self::META_EFFECTIVE_DATE => array( 'string', array( self::class, 'sanitize_date' ) ),
			self::META_CHANGELOG      => array( 'array', array( self::class, 'sanitize_changelog' ) ),
			self::META_CHECKLIST      => array( 'object', array( self::class, 'sanitize_checklist' ) ),
		);

		foreach ( $keys as $key => list( $type, $sanitize ) ) {
			register_post_meta(
				self::NAME,
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => $sanitize,
					'auth_callback'     => $auth,
				)
			);
		}
	}

	/**
	 * Unlimited revisions for this post type.
	 *
	 * @return int
	 */
	public function revisions_to_keep(): int {
		return -1;
	}

	/**
	 * Points "edit" links (used by the core revisions screen) to the plugin's editor.
	 *
	 * @param string|null $link    Default link.
	 * @param int         $post_id Post ID.
	 * @return string|null
	 */
	public function edit_link( $link, $post_id ) {
		if ( self::NAME !== get_post_type( (int) $post_id ) ) {
			return $link;
		}
		return add_query_arg(
			array(
				'page' => 'hive-compliance-documents',
				'doc'  => rawurlencode( (string) get_post_meta( (int) $post_id, self::META_DOC_ID, true ) ),
				'lang' => rawurlencode( (string) get_post_meta( (int) $post_id, self::META_LANG, true ) ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Sanitises a status value.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_status( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, array( Document::STATUS_EMPTY, Document::STATUS_DRAFT, Document::STATUS_PUBLISHED ), true ) ? $value : Document::STATUS_DRAFT;
	}

	/**
	 * Sanitises a Y-m-d date; returns '' when invalid.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_date( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Sanitises the changelog list.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_changelog( $value ): array {
		$clean = array();
		foreach ( is_array( $value ) ? $value : array() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$clean[] = array(
				'version'     => absint( $entry['version'] ?? 0 ),
				'date'        => self::sanitize_date( $entry['date'] ?? '' ),
				'time'        => absint( $entry['time'] ?? 0 ),
				'note'        => sanitize_text_field( (string) ( $entry['note'] ?? '' ) ),
				'source'      => Document::SOURCE_IMPORT === ( $entry['source'] ?? '' ) ? Document::SOURCE_IMPORT : Document::SOURCE_MANUAL,
				'revision_id' => absint( $entry['revision_id'] ?? 0 ),
				'user_id'     => absint( $entry['user_id'] ?? 0 ),
			);
		}
		return $clean;
	}

	/**
	 * Sanitises the checklist map.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, bool>
	 */
	public static function sanitize_checklist( $value ): array {
		$clean = array();
		foreach ( is_array( $value ) ? $value : array() as $key => $ticked ) {
			$key = sanitize_key( (string) $key );
			if ( '' !== $key ) {
				$clean[ $key ] = (bool) $ticked;
			}
		}
		return $clean;
	}
}
