<?php
/**
 * Placeholders of the business.* namespace.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Placeholders;

use Hive\Compliance\Business\BusinessData;
use Hive\Compliance\Profile\Markets;

defined( 'ABSPATH' ) || exit;

/**
 * Business Data screen values, plus social links as business.social_<network>.
 */
final class BusinessProvider implements Provider {

	/**
	 * Business data.
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
		return __( 'Business data', 'hive-compliance' );
	}

	/**
	 * Keys and labels.
	 *
	 * @return array<string, string>
	 */
	public function keys(): array {
		$keys = array_map(
			static fn( $field ) => $field['label'],
			BusinessData::fields()
		);
		foreach ( array_unique( array_merge( BusinessData::NETWORKS, array_keys( $this->business->social() ) ) ) as $network ) {
			/* translators: %s: social network slug. */
			$keys[ 'social_' . $network ] = sprintf( __( 'Social link: %s', 'hive-compliance' ), $network );
		}
		return $keys;
	}

	/**
	 * Whether the key exists.
	 *
	 * @param string $key Key.
	 */
	public function is_known( string $key ): bool {
		return isset( BusinessData::fields()[ $key ] ) || 1 === preg_match( '/^social_[a-z0-9_]+$/', $key );
	}

	/**
	 * Value of a key.
	 *
	 * @param string               $key     Key.
	 * @param array<string, mixed> $context Render context.
	 */
	public function value( string $key, array $context ): ?string {
		if ( 0 === strpos( $key, 'social_' ) ) {
			return $this->business->social()[ substr( $key, 7 ) ] ?? null;
		}
		if ( 'legal_form' === $key ) {
			return $this->business->legal_form_text();
		}
		$value = $this->business->value( $key );
		if ( 'country' === $key && '' !== $value ) {
			return Markets::country_name( $value );
		}
		return $value;
	}

	/**
	 * Social links are URLs.
	 *
	 * @param string $key Key.
	 */
	public function is_url( string $key ): bool {
		return 0 === strpos( $key, 'social_' );
	}
}
