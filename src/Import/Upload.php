<?php
/**
 * Accepts an uploaded or pasted bundle / backup.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Import;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Size limit, extension and MIME checks, UTF-8 check. Files are read, never stored.
 */
final class Upload {

	const EXTENSIONS = array( 'md', 'markdown', 'txt', 'json' );
	const MIME_TYPES = array( 'text/plain', 'text/markdown', 'text/x-markdown', 'application/json', 'text/json', 'application/x-empty' );

	/**
	 * Reads an uploaded file ($_FILES entry).
	 *
	 * @param array<string, mixed> $file      $_FILES entry.
	 * @param int                  $max_bytes Size limit.
	 * @return array{content: string, kind: string, name: string}|WP_Error
	 */
	public static function from_file( array $file, int $max_bytes ) {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error( 'hive_upload_failed', UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ? __( 'The file is too large.', 'hive-compliance' ) : __( 'The upload failed.', 'hive-compliance' ) );
		}
		$path = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $path || ! is_uploaded_file( $path ) ) {
			return new WP_Error( 'hive_upload_failed', __( 'The upload failed.', 'hive-compliance' ) );
		}
		return self::from_path( $path, sanitize_file_name( (string) ( $file['name'] ?? '' ) ), (int) ( $file['size'] ?? 0 ), $max_bytes );
	}

	/**
	 * Reads and checks a local file.
	 *
	 * @param string $path      File path.
	 * @param string $name      Original file name.
	 * @param int    $size      Reported size.
	 * @param int    $max_bytes Size limit.
	 * @return array{content: string, kind: string, name: string}|WP_Error
	 */
	public static function from_path( string $path, string $name, int $size, int $max_bytes ) {
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, self::EXTENSIONS, true ) ) {
			return new WP_Error( 'hive_upload_type', __( 'Upload a .md bundle or a .json backup.', 'hive-compliance' ) );
		}
		$actual = (int) filesize( $path );
		if ( max( $size, $actual ) > $max_bytes ) {
			return new WP_Error( 'hive_upload_size', __( 'The file is larger than the import limit (see Settings).', 'hive-compliance' ) );
		}
		$mime = function_exists( 'finfo_open' ) ? (string) ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $path ) : 'text/plain';
		if ( ! in_array( $mime, self::MIME_TYPES, true ) ) {
			/* translators: %s: detected MIME type. */
			return new WP_Error( 'hive_upload_mime', sprintf( __( 'Unexpected file content (%s): only text files are accepted.', 'hive-compliance' ), $mime ) );
		}
		$content = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Uploaded temporary file.
		return self::from_text( $content, $max_bytes, $name, 'json' === $extension ? ImportSession::KIND_JSON : '' );
	}

	/**
	 * Checks pasted or read text.
	 *
	 * @param string $content   Text.
	 * @param int    $max_bytes Size limit.
	 * @param string $name      File name ('' when pasted).
	 * @param string $kind      Forced kind, or '' to detect it.
	 * @return array{content: string, kind: string, name: string}|WP_Error
	 */
	public static function from_text( string $content, int $max_bytes, string $name = '', string $kind = '' ) {
		if ( '' === trim( $content ) ) {
			return new WP_Error( 'hive_upload_empty', __( 'Nothing to import.', 'hive-compliance' ) );
		}
		if ( strlen( $content ) > $max_bytes ) {
			return new WP_Error( 'hive_upload_size', __( 'The content is larger than the import limit (see Settings).', 'hive-compliance' ) );
		}
		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			return new WP_Error( 'hive_upload_encoding', __( 'The content must be UTF-8 text.', 'hive-compliance' ) );
		}
		if ( '' === $kind ) {
			$kind = 0 === strpos( ltrim( $content ), '{' ) ? ImportSession::KIND_JSON : ImportSession::KIND_BUNDLE;
		}
		return array(
			'content' => $content,
			'kind'    => $kind,
			'name'    => $name,
		);
	}
}
