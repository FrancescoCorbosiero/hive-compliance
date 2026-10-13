<?php
/**
 * Plugin settings (option hive_settings).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance;

use Hive\Compliance\I18n\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, sanitises and stores the plugin settings.
 */
final class Settings {

	const OPTION = 'hive_settings';

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		$language = Languages::locale_to_code( (string) get_locale() );

		return array(
			'default_language'         => $language,
			'enabled_languages'        => array( $language ),
			'remove_data_on_uninstall' => false,
			'max_import_kb'            => 2048,
		);
	}

	/**
	 * All settings, stored values merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( $this->defaults(), $stored );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( string $key ) {
		$all = $this->all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Sanitises and stores settings.
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @return array<string, mixed> Stored values.
	 */
	public function update( array $input ): array {
		$clean = $this->sanitize( array_merge( $this->all(), $input ) );
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * Sanitises a settings array.
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input ): array {
		$defaults = $this->defaults();

		$enabled = array();
		foreach ( (array) ( $input['enabled_languages'] ?? array() ) as $code ) {
			$code = Languages::normalize_code( (string) $code );
			if ( '' !== $code && ! in_array( $code, $enabled, true ) ) {
				$enabled[] = $code;
			}
		}

		$default_language = Languages::normalize_code( (string) ( $input['default_language'] ?? '' ) );
		if ( '' === $default_language ) {
			$default_language = $enabled[0] ?? $defaults['default_language'];
		}
		if ( ! in_array( $default_language, $enabled, true ) ) {
			array_unshift( $enabled, $default_language );
		}

		$max_kb = absint( $input['max_import_kb'] ?? $defaults['max_import_kb'] );

		return array(
			'default_language'         => $default_language,
			'enabled_languages'        => $enabled,
			'remove_data_on_uninstall' => ! empty( $input['remove_data_on_uninstall'] ),
			'max_import_kb'            => max( 64, min( 20480, $max_kb ) ),
		);
	}
}
