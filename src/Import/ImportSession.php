<?php
/**
 * Keeps an uploaded bundle between the review and the apply step.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the raw upload in a transient owned by the uploading user. The bundle is parsed
 * and validated again on review and on apply, against the site state at that moment.
 */
final class ImportSession {

	const PREFIX = 'hive_compliance_import_';
	const TTL    = HOUR_IN_SECONDS;

	const KIND_BUNDLE = 'bundle';
	const KIND_JSON   = 'json';

	/**
	 * Stores content and returns the session token.
	 *
	 * @param string $content Uploaded text.
	 * @param string $kind    bundle|json.
	 * @param string $name    Original file name ('' for pasted text).
	 */
	public static function create( string $content, string $kind, string $name = '' ): string {
		$token = strtolower( wp_generate_password( 20, false, false ) );
		set_transient(
			self::PREFIX . $token,
			array(
				'user_id' => get_current_user_id(),
				'kind'    => $kind,
				'name'    => $name,
				'content' => $content,
				'created' => time(),
			),
			self::TTL
		);
		return $token;
	}

	/**
	 * Session data, or null when missing, expired or owned by another user.
	 *
	 * @param string $token Token.
	 * @return array{user_id: int, kind: string, name: string, content: string, created: int}|null
	 */
	public static function get( string $token ): ?array {
		if ( ! preg_match( '/^[a-z0-9]{20}$/', $token ) ) {
			return null;
		}
		$data = get_transient( self::PREFIX . $token );
		if ( ! is_array( $data ) || (int) ( $data['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return null;
		}
		return $data;
	}

	/**
	 * Deletes a session.
	 *
	 * @param string $token Token.
	 */
	public static function delete( string $token ): void {
		if ( preg_match( '/^[a-z0-9]{20}$/', $token ) ) {
			delete_transient( self::PREFIX . $token );
		}
	}
}
