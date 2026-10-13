<?php
/**
 * Which catalog entries apply to this shop.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Catalog;

use Hive\Compliance\Hooks;
use Hive\Compliance\Profile\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Combines the catalog, the rule engine and the store profile.
 */
final class Applicability {

	/**
	 * Catalog.
	 *
	 * @var Catalog
	 */
	private Catalog $catalog;

	/**
	 * Rule engine.
	 *
	 * @var RuleEngine
	 */
	private RuleEngine $rules;

	/**
	 * Store profile.
	 *
	 * @var Profile
	 */
	private Profile $profile;

	/**
	 * Constructor.
	 *
	 * @param Catalog    $catalog Catalog.
	 * @param RuleEngine $rules   Rule engine.
	 * @param Profile    $profile Store profile.
	 */
	public function __construct( Catalog $catalog, RuleEngine $rules, Profile $profile ) {
		$this->catalog = $catalog;
		$this->rules   = $rules;
		$this->profile = $profile;
	}

	/**
	 * Whether an entry applies.
	 *
	 * @param Entry                     $entry   Catalog entry.
	 * @param array<string, mixed>|null $context Profile context; defaults to the stored profile.
	 */
	public function applies( Entry $entry, ?array $context = null ): bool {
		$context = $context ?? $this->profile->context();
		$applies = $this->rules->evaluate( $entry->applies_when, $context );

		/** This filter is documented in src/Hooks.php */
		return (bool) apply_filters( Hooks::DOCUMENT_APPLIES, $applies, $entry, $context );
	}

	/**
	 * Applicable entries, in catalog order.
	 *
	 * @param array<string, mixed>|null $context Profile context.
	 * @return array<string, Entry>
	 */
	public function applicable( ?array $context = null ): array {
		$context = $context ?? $this->profile->context();
		return array_filter(
			$this->catalog->entries(),
			fn( Entry $entry ) => $this->applies( $entry, $context )
		);
	}
}
