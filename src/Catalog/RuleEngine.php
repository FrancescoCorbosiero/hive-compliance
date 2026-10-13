<?php
/**
 * Evaluates catalog applies_when rules against the store profile.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Rule format:
 *   {"always": true}
 *   {"all": [conditions]} | {"any": [conditions]} | {"not": condition}
 * A condition is a nested rule or a comparison {"field": "...", "op": "...", "value": ...}:
 *   eq       field equals value (scalars).
 *   in       field (or, for list fields, any of its items) is one of the values.
 *   includes list field contains the value (all of them when value is a list).
 *   true     field is truthy.
 * Unknown shapes evaluate to false, so a malformed rule never makes a document apply.
 */
final class RuleEngine {

	/**
	 * Evaluates a rule.
	 *
	 * @param array<string, mixed> $rule    Rule.
	 * @param array<string, mixed> $context Profile context (see Profile::context()).
	 */
	public function evaluate( array $rule, array $context ): bool {
		if ( array_key_exists( 'always', $rule ) ) {
			return true === $rule['always'];
		}
		if ( isset( $rule['all'] ) && is_array( $rule['all'] ) ) {
			foreach ( $rule['all'] as $condition ) {
				if ( ! $this->condition( (array) $condition, $context ) ) {
					return false;
				}
			}
			return array() !== $rule['all'];
		}
		if ( isset( $rule['any'] ) && is_array( $rule['any'] ) ) {
			foreach ( $rule['any'] as $condition ) {
				if ( $this->condition( (array) $condition, $context ) ) {
					return true;
				}
			}
			return false;
		}
		if ( isset( $rule['not'] ) && is_array( $rule['not'] ) ) {
			return ! $this->condition( $rule['not'], $context );
		}
		return false;
	}

	/**
	 * Evaluates a condition (comparison or nested rule).
	 *
	 * @param array<string, mixed> $condition Condition.
	 * @param array<string, mixed> $context   Profile context.
	 */
	private function condition( array $condition, array $context ): bool {
		if ( isset( $condition['field'], $condition['op'] ) ) {
			return $this->compare( $condition, $context );
		}
		return $this->evaluate( $condition, $context );
	}

	/**
	 * Evaluates a comparison.
	 *
	 * @param array<string, mixed> $condition Comparison.
	 * @param array<string, mixed> $context   Profile context.
	 */
	private function compare( array $condition, array $context ): bool {
		$actual = $context[ (string) $condition['field'] ] ?? null;
		$value  = $condition['value'] ?? null;

		switch ( $condition['op'] ) {
			case 'eq':
				return ! is_array( $actual ) && ! is_array( $value ) && $this->same( $actual, $value );
			case 'in':
				return $this->any_same( is_array( $actual ) ? $actual : array( $actual ), (array) $value );
			case 'includes':
				return $this->includes_all( $actual, (array) $value );
			case 'true':
				return ! empty( $actual );
		}
		return false;
	}

	/**
	 * Whether any item equals any candidate.
	 *
	 * @param array<int, mixed> $items      Items.
	 * @param array<int, mixed> $candidates Candidates.
	 */
	private function any_same( array $items, array $candidates ): bool {
		foreach ( $items as $item ) {
			foreach ( $candidates as $candidate ) {
				if ( $this->same( $item, $candidate ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether a list field contains every needle (and there is at least one needle).
	 *
	 * @param mixed             $actual  Field value.
	 * @param array<int, mixed> $needles Values that must all be present.
	 */
	private function includes_all( $actual, array $needles ): bool {
		if ( ! is_array( $actual ) || array() === $needles ) {
			return false;
		}
		foreach ( $needles as $needle ) {
			if ( ! $this->any_same( $actual, array( $needle ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Scalar equality: booleans compare as booleans, everything else as strings.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 */
	private function same( $a, $b ): bool {
		if ( is_bool( $a ) || is_bool( $b ) ) {
			return is_bool( $a ) && is_bool( $b ) && $a === $b;
		}
		if ( null === $a || null === $b ) {
			return false;
		}
		return (string) $a === (string) $b;
	}
}
