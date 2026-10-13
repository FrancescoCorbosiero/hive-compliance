<?php
/**
 * Guards and helpers for admin form handlers.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Every admin-post handler starts with Request::require_capability() and check_admin_referer().
 */
final class Request {

	/**
	 * Dies unless the current user can manage compliance documents.
	 */
	public static function require_capability(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage compliance documents.', 'hive-compliance' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirects to an admin URL and stops.
	 *
	 * @param string $url Destination.
	 */
	public static function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * URL of a plugin admin page.
	 *
	 * @param string               $page Page slug suffix ('' for the dashboard, 'documents', 'export', ...).
	 * @param array<string, mixed> $args Extra query arguments.
	 */
	public static function page_url( string $page = '', array $args = array() ): string {
		$slug = '' === $page ? 'hive-compliance' : 'hive-compliance-' . $page;
		return add_query_arg( array_merge( array( 'page' => $slug ), array_map( 'rawurlencode', array_map( 'strval', $args ) ) ), admin_url( 'admin.php' ) );
	}

	/**
	 * URL of the document editor.
	 *
	 * @param string $doc_id Catalog ID.
	 * @param string $lang   Language code.
	 */
	public static function editor_url( string $doc_id, string $lang ): string {
		return self::page_url(
			'documents',
			array(
				'doc'  => $doc_id,
				'lang' => $lang,
			)
		);
	}
}
