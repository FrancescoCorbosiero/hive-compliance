<?php
/**
 * One-time admin notices shown after a redirect.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Stores notices per user in a short-lived transient and prints them on the next admin page.
 */
final class Notices {

	const TYPES = array( 'success', 'error', 'warning', 'info' );

	/**
	 * Hooks the output.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Queues a notice for the current user.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Plain-text message.
	 */
	public static function add( string $type, string $message ): void {
		$key       = self::key();
		$notices   = get_transient( $key );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'type'    => in_array( $type, self::TYPES, true ) ? $type : 'info',
			'message' => $message,
		);
		set_transient( $key, $notices, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Prints and clears the queued notices.
	 */
	public function render(): void {
		$key     = self::key();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) || ! $notices ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( (string) $notice['type'] ),
				esc_html( (string) $notice['message'] )
			);
		}
	}

	/**
	 * Transient key of the current user.
	 */
	private static function key(): string {
		return 'hive_compliance_notices_' . get_current_user_id();
	}
}
