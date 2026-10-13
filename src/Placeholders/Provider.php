<?php
/**
 * A placeholder namespace (business, store, links, custom, or a third-party one).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Placeholders;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the keys and values of one namespace. Register extra namespaces through
 * the Hooks::PLACEHOLDER_NAMESPACES filter.
 */
interface Provider {

	/**
	 * Human label of the namespace.
	 */
	public function label(): string;

	/**
	 * Known keys and their labels (used by the placeholder picker).
	 *
	 * @return array<string, string>
	 */
	public function keys(): array;

	/**
	 * Whether a key exists in this namespace.
	 *
	 * @param string $key Key.
	 */
	public function is_known( string $key ): bool;

	/**
	 * Raw value of a key; null or '' means missing.
	 *
	 * @param string               $key     Key.
	 * @param array<string, mixed> $context Render context (lang, doc_id).
	 */
	public function value( string $key, array $context ): ?string;

	/**
	 * Whether the value is a URL (URLs are not Markdown-escaped).
	 *
	 * @param string $key Key.
	 */
	public function is_url( string $key ): bool;
}
