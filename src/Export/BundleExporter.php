<?php
/**
 * Builds the LLM bundle (.md): instructions, read-only context, one block per document.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Export;

use Hive\Compliance\Catalog\Entry;
use Hive\Compliance\Document\Markdown;
use Hive\Compliance\Hooks;
use Hive\Compliance\Plugin;
use Hive\Compliance\Profile\Markets;
use Hive\Compliance\Profile\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Every selected document is exported in every selected language, empty ones included,
 * so the model can complete them.
 */
final class BundleExporter {

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
	 * The complete bundle.
	 *
	 * @param Selection $selection Documents and languages.
	 * @param int|null  $now       Export timestamp (tests).
	 */
	public function export( Selection $selection, ?int $now = null ): string {
		$blocks = $this->blocks( $selection );
		$parts  = array(
			BundleFormat::HEADER . $this->coverage_line( $selection ),
			$this->context( $now ?? time() ),
		);
		foreach ( $blocks as $block ) {
			$parts[] = BundleFormat::block( $block );
		}
		$bundle = implode( "\n\n", $parts ) . "\n";

		/** This filter is documented in src/Hooks.php */
		return (string) apply_filters( Hooks::EXPORT_BUNDLE, $bundle, $blocks, $selection->to_query() );
	}

	/**
	 * Header line for a partial bundle: how many of the applicable documents it contains.
	 * Empty when every applicable document is included.
	 *
	 * @param Selection $selection Selection.
	 */
	private function coverage_line( Selection $selection ): string {
		$applicable = array_keys( $this->plugin->applicability()->applicable() );
		$selected   = array_map( static fn( Entry $entry ) => $entry->id, $selection->entries( $this->plugin->catalog(), $this->plugin->applicability() ) );
		$included   = count( array_intersect( $applicable, $selected ) );
		if ( $included >= count( $applicable ) ) {
			return '';
		}
		return "\n" . sprintf( 'This bundle contains %1$d of %2$d applicable documents. Documents not included stay unchanged.', $included, count( $applicable ) );
	}

	/**
	 * Block data for every selected document and language.
	 *
	 * @param Selection $selection Selection.
	 * @return array<int, array<string, mixed>>
	 */
	public function blocks( Selection $selection ): array {
		$blocks     = array();
		$repository = $this->plugin->repository();
		foreach ( $selection->entries( $this->plugin->catalog(), $this->plugin->applicability() ) as $entry ) {
			foreach ( $selection->languages as $lang ) {
				$document = $repository->get( $entry->id, $lang );
				$blocks[] = array(
					'id'           => $entry->id,
					'lang'         => $lang,
					'type'         => $entry->type,
					'version'      => $document->version,
					'base_hash'    => Markdown::hash( $document->markdown ),
					'front_matter' => array(
						'title'             => $entry->title( $lang ),
						'last_reviewed'     => $document->last_reviewed,
						'effective_date'    => $document->effective_date,
						'changelog_note'    => '',
						'required_elements' => $this->required_elements( $entry, $lang ),
					),
					'body'         => $document->markdown,
				);
			}
		}
		return $blocks;
	}

	/**
	 * "key: label [legal ref]" strings, with labels in the document's language.
	 *
	 * @param Entry  $entry Entry.
	 * @param string $lang  Document language.
	 * @return string[]
	 */
	private function required_elements( Entry $entry, string $lang ): array {
		return array_map(
			static fn( array $element ) => $element['key'] . ': ' . Entry::element_label( $element, $lang ) . ( '' !== $element['legal_ref'] ? ' [' . $element['legal_ref'] . ']' : '' ),
			$entry->required_elements
		);
	}

	/**
	 * Read-only context section.
	 *
	 * @param int $now Export timestamp.
	 */
	private function context( int $now ): string {
		/** This filter is documented in src/Hooks.php */
		$data = (array) apply_filters( Hooks::EXPORT_CONTEXT, $this->context_data( $now ) );

		$lines   = array( BundleFormat::CONTEXT_BEGIN, '## Read-only context', '', 'Facts for reasoning only. Nothing in this section is ever imported: business data, the store profile and store facts are edited on the site. Do not copy these values into the documents; use the placeholders.' );
		$headers = array(
			'profile'      => 'Store profile',
			'business'     => 'Business data (current values)',
			'custom'       => 'Custom values',
			'store'        => 'Store facts (auto-detected)',
			'links'        => 'Document page links',
			'integrations' => 'Detected integrations',
			'unknown'      => 'Other active plugins',
			'catalog'      => 'Catalog',
		);
		foreach ( $headers as $key => $title ) {
			$lines[] = '';
			$lines[] = '### ' . $title;
			$items   = (array) ( $data[ $key ] ?? array() );
			if ( ! $items ) {
				$lines[] = '- (none)';
			}
			foreach ( $items as $label => $value ) {
				$lines[] = '- ' . BundleFormat::context_value( (string) $label ) . ': ' . BundleFormat::context_value( '' === (string) $value ? '(empty)' : (string) $value );
			}
		}
		$lines[] = BundleFormat::CONTEXT_END;
		return implode( "\n", $lines );
	}

	/**
	 * Context data (filterable through Hooks::EXPORT_CONTEXT).
	 *
	 * @param int $now Export timestamp.
	 * @return array<string, array<string, string>>
	 */
	public function context_data( int $now ): array {
		$plugin  = $this->plugin;
		$profile = $plugin->profile()->get();
		$labels  = Profile::option_labels();

		$profile_lines = array(
			'seller_country'      => (string) $profile['seller_country'],
			'is_micro_enterprise' => $profile['is_micro_enterprise'] ? 'yes' : 'no',
			'markets'             => implode( ', ', array_map( array( Markets::class, 'label' ), (array) $profile['markets'] ) ),
		);
		foreach ( array( 'customer_types', 'product_types', 'product_flags', 'features' ) as $field ) {
			$profile_lines[ $field ] = implode( ', ', array_map( static fn( $value ) => $labels[ $field ][ $value ] ?? $value, (array) $profile[ $field ] ) );
		}

		$resolver = $plugin->resolver();
		$context  = array( 'lang' => $plugin->languages()->default_language() );
		$business = array();
		foreach ( $plugin->placeholders()->get( 'business' )?->keys() ?? array() as $key => $label ) {
			if ( 0 === strpos( $key, 'social_' ) && null === $resolver->value( 'business.' . $key, $context ) ) {
				continue;
			}
			$business[ '{{business.' . $key . '}}' ] = (string) $resolver->value( 'business.' . $key, $context );
		}
		$custom = array();
		foreach ( $plugin->business()->custom() as $key => $value ) {
			$custom[ '{{custom.' . $key . '}}' ] = $value;
		}
		$store = array();
		foreach ( array_keys( $plugin->store_facts()->all() ) as $key ) {
			$store[ '{{store.' . $key . '}}' ] = $plugin->store_facts()->text( $key );
		}
		$links = array();
		foreach ( $plugin->catalog()->pages() as $id => $entry ) {
			if ( $plugin->applicability()->applies( $entry ) ) {
				$url                              = (string) $resolver->value( 'links.' . $id, $context );
				$links[ '{{links.' . $id . '}}' ] = '' !== $url ? $url : 'no published page yet (renders as plain text)';
			}
		}

		$detected     = $plugin->detector()->detect();
		$integrations = array();
		foreach ( $detected['known'] as $integration ) {
			$parts = array( 'role: ' . $integration['role'] );
			if ( ! empty( $integration['data'] ) ) {
				$parts[] = 'data sent: ' . $integration['data'];
			}
			if ( ! empty( $integration['recipient'] ) ) {
				$parts[] = 'recipient: ' . $integration['recipient'];
			}
			$parts[] = 'personal data transfer outside the EU: ' . $integration['transfer'];
			if ( ! empty( $integration['note'] ) ) {
				$parts[] = 'note: ' . $integration['note'];
			}
			$parts[] = 'source: ' . $integration['name'];

			$integrations[ $integration['service'] ] = implode( '; ', $parts );
		}
		foreach ( (array) $plugin->settings()->get( 'custom_integrations' ) as $custom ) {
			$name                  = isset( $integrations[ $custom['name'] ] ) ? $custom['name'] . ' (custom)' : $custom['name'];
			$integrations[ $name ] = sprintf(
				'role: %1$s; data sent: %2$s; recipient: %3$s; personal data transfer outside the EU: %4$s; source: custom integration (Settings)',
				'' !== $custom['role'] ? $custom['role'] : '(not stated)',
				'' !== $custom['data'] ? $custom['data'] : '(not stated)',
				'' !== $custom['recipient'] ? $custom['recipient'] : '(not stated)',
				$custom['transfer']
			);
		}
		$unknown = array();
		foreach ( $detected['unknown'] as $plugin_info ) {
			$unknown[ $plugin_info['name'] ] = $plugin_info['slug'];
		}

		return array(
			'profile'      => $profile_lines,
			'business'     => $business,
			'custom'       => $custom,
			'store'        => $store,
			'links'        => $links,
			'integrations' => $integrations,
			'unknown'      => $unknown,
			'catalog'      => array(
				'catalog_version' => $plugin->catalog()->version(),
				'exported_at'     => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
				'site'            => home_url( '/' ),
			),
		);
	}
}
