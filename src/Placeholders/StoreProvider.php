<?php
/**
 * Placeholders of the store.* namespace (read-only, auto-detected).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Placeholders;

use Hive\Compliance\Profile\StoreFacts;

defined( 'ABSPATH' ) || exit;

/**
 * Store facts detected from WordPress and WooCommerce.
 */
final class StoreProvider implements Provider {

	/**
	 * Store facts.
	 *
	 * @var StoreFacts
	 */
	private StoreFacts $facts;

	/**
	 * Constructor.
	 *
	 * @param StoreFacts $facts Store facts.
	 */
	public function __construct( StoreFacts $facts ) {
		$this->facts = $facts;
	}

	/**
	 * Namespace label.
	 */
	public function label(): string {
		return __( 'Store facts (auto-detected)', 'hive-compliance' );
	}

	/**
	 * Keys and labels.
	 *
	 * @return array<string, string>
	 */
	public function keys(): array {
		return StoreFacts::labels();
	}

	/**
	 * Whether the key exists.
	 *
	 * @param string $key Key.
	 */
	public function is_known( string $key ): bool {
		return isset( $this->facts->all()[ $key ] );
	}

	/**
	 * Value of a key.
	 *
	 * @param string               $key     Key.
	 * @param array<string, mixed> $context Render context.
	 */
	public function value( string $key, array $context ): ?string {
		return $this->facts->text( $key );
	}

	/**
	 * The site URL is a URL.
	 *
	 * @param string $key Key.
	 */
	public function is_url( string $key ): bool {
		return 'site_url' === $key;
	}
}
