<?php
/**
 * Site-wide page settings a bound document can fill: the WooCommerce terms page and the
 * WordPress privacy policy page.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Knows which document maps to which site option and who may change it.
 */
final class SitePages {

	/**
	 * Page bindings.
	 *
	 * @var PageBindings
	 */
	private PageBindings $bindings;

	/**
	 * Constructor.
	 *
	 * @param PageBindings $bindings Page bindings.
	 */
	public function __construct( PageBindings $bindings ) {
		$this->bindings = $bindings;
	}

	/**
	 * The site role a document can take, or null (unknown document, WooCommerce missing).
	 *
	 * @param string $doc_id Catalog ID.
	 * @return array{option: string, capability: string, label: string, current_label: string}|null
	 */
	public function role( string $doc_id ): ?array {
		if ( 'terms_of_sale' === $doc_id && class_exists( 'WooCommerce' ) ) {
			return array(
				'option'        => 'woocommerce_terms_page_id',
				'capability'    => 'manage_woocommerce',
				'label'         => __( 'Use as the WooCommerce terms and conditions page', 'hive-compliance' ),
				'current_label' => __( 'This page is the WooCommerce terms and conditions page.', 'hive-compliance' ),
			);
		}
		if ( 'privacy_policy' === $doc_id ) {
			return array(
				'option'        => 'wp_page_for_privacy_policy',
				'capability'    => 'manage_privacy_options',
				'label'         => __( 'Use as the WordPress privacy policy page', 'hive-compliance' ),
				'current_label' => __( 'This page is the WordPress privacy policy page.', 'hive-compliance' ),
			);
		}
		return null;
	}

	/**
	 * Page currently set in the site option (0 when none).
	 *
	 * @param string $doc_id Catalog ID.
	 */
	public function current( string $doc_id ): int {
		$role = $this->role( $doc_id );
		return null === $role ? 0 : absint( get_option( $role['option'], 0 ) );
	}

	/**
	 * Whether the current user may change the site option of a document.
	 *
	 * @param string $doc_id Catalog ID.
	 */
	public function can_set( string $doc_id ): bool {
		$role = $this->role( $doc_id );
		return null !== $role && current_user_can( $role['capability'] );
	}

	/**
	 * Points the site option at the page bound to a document.
	 *
	 * @param string $doc_id Catalog ID.
	 * @param string $lang   Language of the binding.
	 * @return int The page ID set, or 0 when nothing changed (no role, no permission, no bound page).
	 */
	public function set( string $doc_id, string $lang ): int {
		$role    = $this->role( $doc_id );
		$page_id = $this->bindings->get( $doc_id, $lang );
		if ( null === $role || ! $page_id || ! $this->can_set( $doc_id ) ) {
			return 0;
		}
		update_option( $role['option'], $page_id );
		return $page_id;
	}
}
