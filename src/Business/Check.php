<?php
/**
 * Result of one field validation.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Business;

defined( 'ABSPATH' ) || exit;

/**
 * Normalised value plus an optional error or warning message.
 */
final class Check {

	const OK      = 'ok';
	const WARNING = 'warning';
	const ERROR   = 'error';

	/**
	 * Constructor.
	 *
	 * @param string $value    Normalised value (or the raw value when it could not be normalised).
	 * @param string $severity ok|warning|error.
	 * @param string $message  Message for warnings and errors.
	 */
	public function __construct(
		public readonly string $value,
		public readonly string $severity = self::OK,
		public readonly string $message = ''
	) {}

	/**
	 * Valid value.
	 *
	 * @param string $value Normalised value.
	 */
	public static function ok( string $value ): self {
		return new self( $value );
	}

	/**
	 * Value saved with a warning.
	 *
	 * @param string $value   Value.
	 * @param string $message Message.
	 */
	public static function warning( string $value, string $message ): self {
		return new self( $value, self::WARNING, $message );
	}

	/**
	 * Invalid value (still saved; listed on the dashboard).
	 *
	 * @param string $value   Value.
	 * @param string $message Message.
	 */
	public static function error( string $value, string $message ): self {
		return new self( $value, self::ERROR, $message );
	}

	/**
	 * Whether the value is invalid.
	 */
	public function is_error(): bool {
		return self::ERROR === $this->severity;
	}
}
