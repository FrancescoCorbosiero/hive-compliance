<?php
/**
 * Placeholder namespaces.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Placeholders;

use Hive\Compliance\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Namespace => provider map (business, store, links, custom), extendable by filter.
 */
final class Registry {

	/**
	 * Built-in providers.
	 *
	 * @var array<string, Provider>
	 */
	private array $builtin;

	/**
	 * Filtered providers, built once per request.
	 *
	 * @var array<string, Provider>|null
	 */
	private ?array $providers = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, Provider> $builtin Built-in providers keyed by namespace.
	 */
	public function __construct( array $builtin ) {
		$this->builtin = $builtin;
	}

	/**
	 * All providers keyed by namespace.
	 *
	 * @return array<string, Provider>
	 */
	public function providers(): array {
		if ( null === $this->providers ) {
			/** This filter is documented in src/Hooks.php */
			$filtered        = (array) apply_filters( Hooks::PLACEHOLDER_NAMESPACES, $this->builtin );
			$this->providers = array_filter(
				$filtered,
				static fn( $provider, $prefix ) => $provider instanceof Provider && preg_match( '/^[a-z][a-z0-9_]*$/', (string) $prefix ),
				ARRAY_FILTER_USE_BOTH
			);
		}
		return $this->providers;
	}

	/**
	 * Provider of a namespace.
	 *
	 * @param string $prefix Namespace.
	 */
	public function get( string $prefix ): ?Provider {
		return $this->providers()[ $prefix ] ?? null;
	}

	/**
	 * Whether the namespace exists.
	 *
	 * @param string $prefix Namespace.
	 */
	public function has_namespace( string $prefix ): bool {
		return null !== $this->get( $prefix );
	}

	/**
	 * Whether "ns.key" is a known placeholder.
	 *
	 * @param string $token Token without braces.
	 */
	public function is_known( string $token ): bool {
		list( $prefix, $key ) = Syntax::split( $token );
		$provider             = $this->get( $prefix );
		return null !== $provider && $provider->is_known( $key );
	}

	/**
	 * Forgets the filtered providers.
	 */
	public function reset(): void {
		$this->providers = null;
	}
}
