<?php
/**
 * Full JSON backup: settings, business data, profile, page bindings, documents and metadata.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Export;

use Hive\Compliance\Hooks;
use Hive\Compliance\Plugin;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * For site migration and backup (not for LLMs). Restored by Import\JsonRestorer.
 */
final class JsonExporter {

	const FORMAT         = 'hive-compliance-backup';
	const FORMAT_VERSION = 1;

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
	 * Backup data.
	 *
	 * @param int|null $now Export timestamp (tests).
	 * @return array<string, mixed>
	 */
	public function data( ?int $now = null ): array {
		$plugin    = $this->plugin;
		$documents = array();
		foreach ( $plugin->repository()->all() as $document ) {
			$entry       = $plugin->catalog()->get( $document->doc_id );
			$documents[] = array(
				'doc_id'         => $document->doc_id,
				'lang'           => $document->lang,
				'title'          => null !== $entry ? $entry->title( $document->lang ) : (string) get_post_field( 'post_title', $document->post_id ),
				'markdown'       => $document->markdown,
				'version'        => $document->version,
				'status'         => $document->status,
				'last_reviewed'  => $document->last_reviewed,
				'effective_date' => $document->effective_date,
				'changelog'      => $document->changelog,
				'checklist'      => $document->checklist,
			);
		}

		$bindings = array();
		foreach ( $plugin->bindings()->all() as $doc_id => $languages ) {
			foreach ( $languages as $lang => $page_id ) {
				$page       = get_post( $page_id );
				$bindings[] = array(
					'doc_id'     => (string) $doc_id,
					'lang'       => (string) $lang,
					'page_id'    => (int) $page_id,
					'page_slug'  => $page instanceof WP_Post ? $page->post_name : '',
					'page_title' => $page instanceof WP_Post ? $page->post_title : '',
				);
			}
		}

		$data = array(
			'format'          => self::FORMAT,
			'format_version'  => self::FORMAT_VERSION,
			'plugin_version'  => HIVE_COMPLIANCE_VERSION,
			'catalog_version' => $plugin->catalog()->version(),
			'exported_at'     => gmdate( 'Y-m-d\TH:i:s\Z', $now ?? time() ),
			'site_url'        => home_url( '/' ),
			'settings'        => $plugin->settings()->all(),
			'business'        => $plugin->business()->get(),
			'profile'         => get_option( \Hive\Compliance\Profile\Profile::OPTION, null ),
			'page_bindings'   => $bindings,
			'documents'       => $documents,
		);

		/** This filter is documented in src/Hooks.php */
		return (array) apply_filters( Hooks::EXPORT_JSON, $data );
	}

	/**
	 * Backup as pretty-printed JSON.
	 *
	 * @param int|null $now Export timestamp (tests).
	 */
	public function export( ?int $now = null ): string {
		return (string) wp_json_encode( $this->data( $now ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}
}
