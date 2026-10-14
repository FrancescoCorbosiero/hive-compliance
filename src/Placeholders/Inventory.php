<?php
/**
 * Every placeholder with its current value, status, source and usage (the Placeholders screen).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Placeholders;

use Hive\Compliance\Business\BusinessData;
use Hive\Compliance\Business\Check;
use Hive\Compliance\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds one row per placeholder, grouped by namespace. A row says what the value is now,
 * whether it is fine, which documents use it, where it comes from and how to change it.
 */
final class Inventory {

	const OK      = 'ok';
	const MISSING = 'missing';
	const INVALID = 'invalid';
	const WARNING = 'warning';
	const UNKNOWN = 'unknown';

	/**
	 * Where each store fact is set (admin URL path).
	 */
	const STORE_SETTINGS = array(
		'site_url'             => 'options-general.php',
		'site_name'            => 'options-general.php',
		'store_address'        => 'admin.php?page=wc-settings&tab=general',
		'store_country'        => 'admin.php?page=wc-settings&tab=general',
		'currency'             => 'admin.php?page=wc-settings&tab=general',
		'selling_countries'    => 'admin.php?page=wc-settings&tab=general',
		'shipping_countries'   => 'admin.php?page=wc-settings&tab=shipping',
		'shipping_zones'       => 'admin.php?page=wc-settings&tab=shipping',
		'payment_gateways'     => 'admin.php?page=wc-settings&tab=checkout',
		'guest_checkout'       => 'admin.php?page=wc-settings&tab=account',
		'account_registration' => 'admin.php?page=wc-settings&tab=account',
		'reviews_enabled'      => 'admin.php?page=wc-settings&tab=products',
	);

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
	 * Rows grouped by namespace (business, store, links, custom, then any added namespace).
	 *
	 * Row: token, namespace, key, label, value (resolved text, '' when missing), status,
	 * message, editable (business|custom|link|''), source, edit_url, used_in (doc ID => title).
	 *
	 * @param string $lang Language of the values and of the documents scanned for usage.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function rows( string $lang ): array {
		$usage   = $this->usage( $lang );
		$context = array( 'lang' => $lang );
		$groups  = array(
			'business' => $this->business_rows( $context ),
			'store'    => $this->store_rows( $context ),
			'links'    => $this->link_rows( $context, $lang ),
			'custom'   => $this->custom_rows( $context ),
		);

		foreach ( $this->plugin->placeholders()->providers() as $prefix => $provider ) {
			if ( isset( $groups[ $prefix ] ) ) {
				continue;
			}
			$groups[ $prefix ] = array();
			foreach ( $provider->keys() as $key => $label ) {
				$value               = (string) $this->plugin->resolver()->value( $prefix . '.' . $key, $context );
				$groups[ $prefix ][] = $this->row( $prefix, (string) $key, (string) $label, $value, '' === $value ? self::MISSING : self::OK, '', '', $provider->label(), '' );
			}
		}

		// Tokens the documents use that no namespace knows ({{custom.typo}}, {{shop.name}}).
		$registry = $this->plugin->placeholders();
		foreach ( array_keys( $usage ) as $token ) {
			list( $prefix, $key ) = Syntax::split( $token );
			if ( ! $registry->is_known( $token ) ) {
				$groups[ $registry->has_namespace( $prefix ) ? $prefix : 'unknown' ][] = $this->row( $prefix, $key, '', '', self::UNKNOWN, __( 'Used in a document but not defined.', 'hive-compliance' ), 'custom' === $prefix ? 'custom' : '', '', '' );
			}
		}

		foreach ( $groups as $prefix => $rows ) {
			foreach ( $rows as $index => $row ) {
				$groups[ $prefix ][ $index ]['used_in'] = $usage[ $row['token'] ] ?? array();
			}
		}

		// Pages of documents that do not apply to this shop only matter once bound or used.
		$groups['links'] = array_values( array_filter( $groups['links'], static fn( $row ) => $row['applies'] || $row['page_id'] || $row['used_in'] ) );
		return array_filter( $groups );
	}

	/**
	 * Counts per status over all rows; "missing" counts only placeholders used in a document.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $groups Rows.
	 * @return array<string, int>
	 */
	public static function summary( array $groups ): array {
		$counts = array(
			'total'     => 0,
			'set'       => 0,
			'used'      => 0,
			'attention' => 0,
		);
		foreach ( $groups as $rows ) {
			foreach ( $rows as $row ) {
				++$counts['total'];
				if ( '' !== $row['value'] ) {
					++$counts['set'];
				}
				if ( $row['used_in'] ) {
					++$counts['used'];
				}
				if ( self::needs_attention( $row ) ) {
					++$counts['attention'];
				}
			}
		}
		return $counts;
	}

	/**
	 * Whether a row needs action: invalid or unknown, or missing while a document uses it.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	public static function needs_attention( array $row ): bool {
		return in_array( $row['status'], array( self::INVALID, self::UNKNOWN ), true ) || ( self::MISSING === $row['status'] && $row['used_in'] );
	}

	/**
	 * Rows of business.*, with the Business Data validation results.
	 *
	 * @param array<string, mixed> $context Render context.
	 * @return array<int, array<string, mixed>>
	 */
	private function business_rows( array $context ): array {
		$business = $this->plugin->business();
		$checks   = $business->checks( $this->plugin->seller_country() );
		$required = BusinessData::required( $business->get() );
		$fields   = BusinessData::fields();
		$provider = $this->plugin->placeholders()->get( 'business' );
		$rows     = array();
		foreach ( null !== $provider ? $provider->keys() : array() as $key => $label ) {
			$key    = (string) $key;
			$social = 0 === strpos( $key, 'social_' );
			$value  = (string) $this->plugin->resolver()->value( 'business.' . $key, $context );
			if ( $social && '' === $value ) {
				continue;
				// Unused networks would only add noise; add them on the Business Data screen.
			}
			$check   = $checks[ $key ] ?? null;
			$status  = '' === $value ? self::MISSING : self::OK;
			$message = '';
			if ( null !== $check && Check::OK !== $check->severity ) {
				$status  = $check->is_error() ? self::INVALID : self::WARNING;
				$message = $check->message;
			} elseif ( '' === $value && in_array( $key, $required, true ) ) {
				$status  = self::INVALID;
				$message = __( 'Required.', 'hive-compliance' );
			}
			$editable = ! $social && isset( $fields[ $key ] ) ? 'business' : '';
			$rows[]   = $this->row( 'business', $key, (string) $label, $value, $status, $message, $editable, __( 'Business Data', 'hive-compliance' ), $this->page_url( 'business' ) . ( $social ? '' : '#hive-business-' . $key ) );
		}//end foreach
		return $rows;
	}

	/**
	 * Rows of store.*: read-only, with the WordPress or WooCommerce screen that sets each fact.
	 *
	 * @param array<string, mixed> $context Render context.
	 * @return array<int, array<string, mixed>>
	 */
	private function store_rows( array $context ): array {
		$woo  = class_exists( 'WooCommerce' );
		$rows = array();
		foreach ( $this->plugin->store_facts()->all() as $key => $fact ) {
			$value = (string) $this->plugin->resolver()->value( 'store.' . $key, $context );
			$url   = self::STORE_SETTINGS[ $key ] ?? '';
			if ( ! $woo && 0 === strpos( $url, 'admin.php?page=wc-' ) ) {
				$url = '';
			}
			$rows[] = $this->row( 'store', (string) $key, (string) $fact['label'], $value, '' === $value ? self::MISSING : self::OK, '', '', (string) $fact['source'], '' !== $url ? admin_url( $url ) : '' );
		}
		return $rows;
	}

	/**
	 * Rows of links.*: the published page bound to each page document, assignable here.
	 *
	 * @param array<string, mixed> $context Render context.
	 * @param string               $lang    Language.
	 * @return array<int, array<string, mixed>>
	 */
	private function link_rows( array $context, string $lang ): array {
		$bindings = $this->plugin->bindings();
		$rows     = array();
		foreach ( $this->plugin->catalog()->pages() as $id => $entry ) {
			$value   = (string) $this->plugin->resolver()->value( 'links.' . $id, $context );
			$page_id = $bindings->get( $id, $lang );
			$message = '';
			if ( '' === $value ) {
				$message = $page_id ? __( 'The bound page is not published.', 'hive-compliance' ) : __( 'No page bound.', 'hive-compliance' );
			} elseif ( ! $page_id ) {
				$message = __( 'Uses the page of another language.', 'hive-compliance' );
			}
			$row            = $this->row(
				'links',
				$id,
				$entry->title( $lang ),
				$value,
				'' === $value ? self::MISSING : self::OK,
				$message,
				'link',
				__( 'Page binding', 'hive-compliance' ),
				$this->page_url(
					'documents',
					array(
						'doc'  => $id,
						'lang' => $lang,
					)
				)
			);
			$row['page_id'] = $page_id;
			$row['applies'] = $this->plugin->applicability()->applies( $entry );
			$rows[]         = $row;
		}//end foreach
		return $rows;
	}

	/**
	 * Rows of custom.* (your own key/value pairs).
	 *
	 * @param array<string, mixed> $context Render context.
	 * @return array<int, array<string, mixed>>
	 */
	private function custom_rows( array $context ): array {
		$rows = array();
		foreach ( $this->plugin->business()->custom() as $key => $raw ) {
			$value  = (string) $this->plugin->resolver()->value( 'custom.' . $key, $context );
			$rows[] = $this->row( 'custom', (string) $key, '', $value, '' === $value ? self::MISSING : self::OK, '', 'custom', __( 'Custom values', 'hive-compliance' ), $this->page_url( 'business' ) );
		}
		return $rows;
	}

	/**
	 * Documents of a language that use each token.
	 *
	 * @param string $lang Language.
	 * @return array<string, array<string, string>> token => [doc ID => title]
	 */
	private function usage( string $lang ): array {
		$usage = array();
		foreach ( $this->plugin->repository()->all() as $document ) {
			if ( $document->lang !== $lang || $document->is_empty() ) {
				continue;
			}
			$entry = $this->plugin->catalog()->get( $document->doc_id );
			foreach ( Syntax::tokens( $document->markdown ) as $token ) {
				$usage[ $token ][ $document->doc_id ] = null !== $entry ? $entry->title( $lang ) : $document->doc_id;
			}
		}
		ksort( $usage );
		return $usage;
	}

	/**
	 * Builds a row.
	 *
	 * @param string $prefix   Namespace.
	 * @param string $key      Key.
	 * @param string $label    Label.
	 * @param string $value    Resolved value.
	 * @param string $status   Status.
	 * @param string $message  Explanation.
	 * @param string $editable business|custom|link|''.
	 * @param string $source   Where the value comes from.
	 * @param string $edit_url Where to change it.
	 * @return array<string, mixed>
	 */
	private function row( string $prefix, string $key, string $label, string $value, string $status, string $message, string $editable, string $source, string $edit_url ): array {
		return array(
			'token'     => $prefix . '.' . $key,
			'namespace' => $prefix,
			'key'       => $key,
			'label'     => $label,
			'value'     => $value,
			'status'    => $status,
			'message'   => $message,
			'editable'  => $editable,
			'source'    => $source,
			'edit_url'  => $edit_url,
			'used_in'   => array(),
		);
	}

	/**
	 * URL of a plugin screen.
	 *
	 * @param string               $slug Screen slug.
	 * @param array<string, mixed> $args Query arguments.
	 */
	private function page_url( string $slug, array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=hive-compliance-' . $slug ) );
	}
}
