<?php
/**
 * Restores a JSON backup (site migration and backup). Never runs automatically.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Import;

use Hive\Compliance\Export\JsonExporter;
use Hive\Compliance\I18n\Languages;
use Hive\Compliance\Plugin;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Preview counts first, then restore after confirmation: settings, business data, profile,
 * documents (as new revisions) and page bindings (matched by ID, then by slug).
 */
final class JsonRestorer {

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
	 * Decodes and checks a backup.
	 *
	 * @param string $json JSON text.
	 * @return array<string, mixed>|WP_Error
	 */
	public function decode( string $json ) {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || JsonExporter::FORMAT !== ( $data['format'] ?? '' ) ) {
			return new WP_Error( 'hive_backup_format', __( 'This is not a Hive Compliance JSON backup.', 'hive-compliance' ) );
		}
		if ( (int) ( $data['format_version'] ?? 0 ) > JsonExporter::FORMAT_VERSION ) {
			return new WP_Error( 'hive_backup_version', __( 'The backup was made by a newer version of the plugin.', 'hive-compliance' ) );
		}
		return $data;
	}

	/**
	 * What a restore would do.
	 *
	 * @param array<string, mixed> $data Decoded backup.
	 * @return array<string, int|bool|string>
	 */
	public function preview( array $data ): array {
		$counts    = array(
			'documents_new'       => 0,
			'documents_updated'   => 0,
			'documents_unchanged' => 0,
			'documents_skipped'   => 0,
			'bindings_matched'    => 0,
			'bindings_unmatched'  => 0,
			'business'            => ! empty( $data['business'] ),
			'profile'             => ! empty( $data['profile'] ),
			'settings'            => ! empty( $data['settings'] ),
			'site_url'            => (string) ( $data['site_url'] ?? '' ),
			'exported_at'         => (string) ( $data['exported_at'] ?? '' ),
		);
		$languages = $this->languages_after_restore( $data );
		foreach ( (array) ( $data['documents'] ?? array() ) as $record ) {
			$doc_id = sanitize_key( (string) ( $record['doc_id'] ?? '' ) );
			$lang   = Languages::normalize_code( (string) ( $record['lang'] ?? '' ) );
			if ( null === $this->plugin->catalog()->get( $doc_id ) || ! in_array( $lang, $languages, true ) ) {
				++$counts['documents_skipped'];
				continue;
			}
			$current = $this->plugin->repository()->find( $doc_id, $lang );
			if ( null === $current ) {
				++$counts['documents_new'];
			} elseif ( \Hive\Compliance\Document\Markdown::normalize( (string) ( $record['markdown'] ?? '' ) ) === $current->markdown ) {
				++$counts['documents_unchanged'];
			} else {
				++$counts['documents_updated'];
			}
		}
		foreach ( (array) ( $data['page_bindings'] ?? array() ) as $binding ) {
			null === $this->match_page( (array) $binding ) ? ++$counts['bindings_unmatched'] : ++$counts['bindings_matched'];
		}
		return $counts;
	}

	/**
	 * Restores a backup.
	 *
	 * @param array<string, mixed> $data Decoded backup.
	 * @return array{documents: int, bindings: int, skipped: string[]}
	 */
	public function restore( array $data ): array {
		$plugin  = $this->plugin;
		$skipped = array();

		if ( ! empty( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$plugin->settings()->update( $data['settings'] );
		}
		if ( ! empty( $data['business'] ) && is_array( $data['business'] ) ) {
			$plugin->business()->save( $data['business'] );
		}
		if ( ! empty( $data['profile'] ) && is_array( $data['profile'] ) ) {
			$plugin->profile()->save( $data['profile'] );
		}

		$documents = 0;
		foreach ( (array) ( $data['documents'] ?? array() ) as $record ) {
			$record = (array) $record;
			$entry  = $plugin->catalog()->get( sanitize_key( (string) ( $record['doc_id'] ?? '' ) ) );
			$lang   = Languages::normalize_code( (string) ( $record['lang'] ?? '' ) );
			if ( null === $entry || ! $plugin->languages()->is_enabled( $lang ) ) {
				$skipped[] = sprintf( '%s (%s)', (string) ( $record['doc_id'] ?? '?' ), $lang );
				continue;
			}
			$result = $plugin->repository()->restore( $record, $entry->title( $lang ) );
			if ( is_wp_error( $result ) ) {
				$skipped[] = sprintf( '%s (%s): %s', $entry->id, $lang, $result->get_error_message() );
			} else {
				++$documents;
			}
		}

		$bindings = 0;
		foreach ( (array) ( $data['page_bindings'] ?? array() ) as $binding ) {
			$binding = (array) $binding;
			$page_id = $this->match_page( $binding );
			if ( null === $page_id ) {
				$skipped[] = sprintf( '%s (%s): %s', (string) ( $binding['doc_id'] ?? '?' ), (string) ( $binding['lang'] ?? '?' ), __( 'page not found', 'hive-compliance' ) );
				continue;
			}
			$plugin->bindings()->set( sanitize_key( (string) $binding['doc_id'] ), Languages::normalize_code( (string) $binding['lang'] ), $page_id );
			++$bindings;
		}

		return array(
			'documents' => $documents,
			'bindings'  => $bindings,
			'skipped'   => $skipped,
		);
	}

	/**
	 * Languages enabled once the backup's settings are restored.
	 *
	 * @param array<string, mixed> $data Backup.
	 * @return string[]
	 */
	private function languages_after_restore( array $data ): array {
		if ( Languages::PROVIDER_OWN !== $this->plugin->languages()->provider() || empty( $data['settings']['enabled_languages'] ) ) {
			return $this->plugin->languages()->enabled();
		}
		$settings = $this->plugin->settings()->sanitize( array_merge( $this->plugin->settings()->all(), (array) $data['settings'] ) );
		return (array) $settings['enabled_languages'];
	}

	/**
	 * This site's page for a backup binding: same ID and slug, else same slug.
	 *
	 * @param array<string, mixed> $binding Backup binding.
	 */
	private function match_page( array $binding ): ?int {
		$page_id = absint( $binding['page_id'] ?? 0 );
		$slug    = sanitize_title( (string) ( $binding['page_slug'] ?? '' ) );
		$page    = $page_id ? get_post( $page_id ) : null;
		if ( $page instanceof WP_Post && 'page' === $page->post_type && 'trash' !== $page->post_status && ( '' === $slug || $page->post_name === $slug ) ) {
			return $page_id;
		}
		if ( '' !== $slug ) {
			$found = get_page_by_path( $slug );
			if ( $found instanceof WP_Post && 'trash' !== $found->post_status ) {
				return (int) $found->ID;
			}
		}
		return null;
	}
}
