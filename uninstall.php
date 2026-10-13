<?php
/**
 * Uninstall: keeps all data unless Settings → "Remove all data on uninstall" is enabled.
 *
 * Bound WordPress pages are ordinary site content and are left in place.
 *
 * @package Hive\Compliance
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the plugin data of the current site when the site opted in.
 */
function hive_compliance_uninstall_site(): void {
	$settings = get_option( 'hive_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['remove_data_on_uninstall'] ) ) {
		return;
	}

	$ids = get_posts(
		array(
			'post_type'        => 'hive_document',
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	);
	// Deleting a post also deletes its revisions.
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}

	foreach ( array( 'hive_business', 'hive_profile', 'hive_settings', 'hive_page_bindings', 'hive_compliance_db_version', 'hive_compliance_pending_skeletons', 'hive_compliance_catalog_cache' ) as $option ) {
		delete_option( $option );
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup of this plugin's transients.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_hive_compliance_' ) . '%', $wpdb->esc_like( '_transient_timeout_hive_compliance_' ) . '%' ) );

	foreach ( wp_roles()->role_objects as $role ) {
		$role->remove_cap( 'manage_hive_compliance' );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $hive_compliance_site_id ) {
		switch_to_blog( (int) $hive_compliance_site_id );
		hive_compliance_uninstall_site();
		restore_current_blog();
	}
} else {
	hive_compliance_uninstall_site();
}
