<?php
/**
 * Placeholders of the custom.* namespace: user-defined key/value pairs.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Placeholders;

use Hive\Compliance\Business\BusinessData;

defined( 'ABSPATH' ) || exit;

/**
 * Values defined in the Custom section of the Business Data screen.
 */
final class CustomProvider implements Provider {

	/**
	 * Business data (holds the custom pairs).
	 *
	 * @var BusinessData
	 */
	private BusinessData $business;

	/**
	 * Constructor.
	 *
	 * @param BusinessData $business Business data.
	 */
	public function __construct( BusinessData $business ) {
		$this->business = $business;
	}

	/**
	 * Namespace label.
	 */
	public function label(): string {
		return __( 'Custom values', 'hive-compliance' );
	}

	/**
	 * Defined keys (the key doubles as label).
	 *
	 * @return array<string, string>
	 */
	public function keys(): array {
		$keys = array_keys( $this->business->custom() );
		return array_combine( $keys, $keys );
	}

	/**
	 * Whether the key is defined.
	 *
	 * @param string $key Key.
	 */
	public function is_known( string $key ): bool {
		return array_key_exists( $key, $this->business->custom() );
	}

	/**
	 * Value of a key.
	 *
	 * @param string               $key     Key.
	 * @param array<string, mixed> $context Render context.
	 */
	public function value( string $key, array $context ): ?string {
		return $this->business->custom()[ $key ] ?? null;
	}

	/**
	 * Custom values are text.
	 *
	 * @param string $key Key.
	 */
	public function is_url( string $key ): bool {
		return false;
	}
}
