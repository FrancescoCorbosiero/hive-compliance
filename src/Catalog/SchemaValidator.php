<?php
/**
 * Minimal JSON Schema validator (the draft 2020-12 subset used by catalog.schema.json).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Catalog;

use stdClass;

defined( 'ABSPATH' ) || exit;

/**
 * Validates data decoded with json_decode( $json ) (objects as stdClass, not arrays).
 *
 * Supported keywords: type, enum, const, properties, required, additionalProperties,
 * propertyNames, minProperties, items, minItems, uniqueItems, pattern, minLength,
 * minimum, maximum, oneOf, anyOf, $ref (local "#/..." pointers only).
 */
final class SchemaValidator {

	/**
	 * Root schema, used to resolve $ref.
	 *
	 * @var stdClass
	 */
	private stdClass $root;

	/**
	 * Collected errors ("/json/pointer: message").
	 *
	 * @var string[]
	 */
	private array $errors = array();

	/**
	 * Validates data against a schema.
	 *
	 * @param mixed    $data   Decoded JSON data.
	 * @param stdClass $schema Decoded JSON schema.
	 * @return string[] Errors; empty when valid.
	 */
	public function validate( $data, stdClass $schema ): array {
		$this->root   = $schema;
		$this->errors = array();
		$this->check( $data, $schema, '' );
		return $this->errors;
	}

	/**
	 * Validates one value against one (sub)schema, appending errors.
	 *
	 * @param mixed    $data   Value.
	 * @param stdClass $schema Schema.
	 * @param string   $path   JSON pointer of the value.
	 */
	private function check( $data, stdClass $schema, string $path ): void {
		if ( isset( $schema->{'$ref'} ) ) {
			$this->check( $data, $this->resolve( (string) $schema->{'$ref'} ), $path );
			return;
		}

		if ( isset( $schema->type ) && ! $this->is_type( $data, (array) $schema->type ) ) {
			$this->error( $path, sprintf( 'must be of type %s', implode( '|', (array) $schema->type ) ) );
			return;
		}
		if ( isset( $schema->enum ) && ! $this->in_enum( $data, (array) $schema->enum ) ) {
			$this->error( $path, 'must be one of ' . wp_json_encode( $schema->enum ) );
		}
		if ( property_exists( $schema, 'const' ) && ! $this->equals( $data, $schema->const ) ) {
			$this->error( $path, 'must equal ' . wp_json_encode( $schema->const ) );
		}

		if ( is_string( $data ) ) {
			$this->check_string( $data, $schema, $path );
		} elseif ( is_int( $data ) || is_float( $data ) ) {
			$this->check_number( $data, $schema, $path );
		} elseif ( $data instanceof stdClass ) {
			$this->check_object( $data, $schema, $path );
		} elseif ( is_array( $data ) ) {
			$this->check_array( $data, $schema, $path );
		}

		if ( isset( $schema->oneOf ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JSON Schema keyword.
			$this->check_one_of( $data, (array) $schema->oneOf, $path ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JSON Schema keyword.
		}
		if ( isset( $schema->anyOf ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JSON Schema keyword.
			$matched = false;
			foreach ( (array) $schema->anyOf as $option ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JSON Schema keyword.
				if ( $this->passes( $data, $option, $path ) ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				$this->error( $path, 'does not match any allowed shape' );
			}
		}
	}

	/**
	 * String keywords.
	 *
	 * @param string   $data   Value.
	 * @param stdClass $schema Schema.
	 * @param string   $path   Pointer.
	 */
	private function check_string( string $data, stdClass $schema, string $path ): void {
		if ( isset( $schema->minLength ) && mb_strlen( $data, 'UTF-8' ) < (int) $schema->minLength ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JSON Schema keyword.
			$this->error( $path, 'is too short' );
		}
		if ( isset( $schema->pattern ) && ! preg_match( '~' . str_replace( '~', '\~', (string) $schema->pattern ) . '~u', $data ) ) {
			$this->error( $path, sprintf( 'does not match pattern %s', $schema->pattern ) );
		}
	}

	/**
	 * Number keywords.
	 *
	 * @param int|float $data   Value.
	 * @param stdClass  $schema Schema.
	 * @param string    $path   Pointer.
	 */
	private function check_number( $data, stdClass $schema, string $path ): void {
		if ( isset( $schema->minimum ) && $data < $schema->minimum ) {
			$this->error( $path, sprintf( 'must be >= %s', $schema->minimum ) );
		}
		if ( isset( $schema->maximum ) && $data > $schema->maximum ) {
			$this->error( $path, sprintf( 'must be <= %s', $schema->maximum ) );
		}
	}

	/**
	 * Object keywords.
	 *
	 * @param stdClass $data   Value.
	 * @param stdClass $schema Schema.
	 * @param string   $path   Pointer.
	 */
	private function check_object( stdClass $data, stdClass $schema, string $path ): void {
		$values = get_object_vars( $data );

		foreach ( (array) ( $schema->required ?? array() ) as $required ) {
			if ( ! array_key_exists( $required, $values ) ) {
				$this->error( $path, sprintf( 'is missing required property "%s"', $required ) );
			}
		}
		if ( isset( $schema->minProperties ) && count( $values ) < (int) $schema->minProperties ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JSON Schema keyword.
			$this->error( $path, 'has too few properties' );
		}

		$properties = isset( $schema->properties ) ? get_object_vars( $schema->properties ) : array();
		foreach ( $values as $name => $value ) {
			$child = $path . '/' . $name;
			if ( isset( $schema->propertyNames ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JSON Schema keyword.
				$this->check( (string) $name, $schema->propertyNames, $child ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JSON Schema keyword.
			}
			if ( isset( $properties[ $name ] ) ) {
				$this->check( $value, $properties[ $name ], $child );
				continue;
			}
			$additional = $schema->additionalProperties ?? true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JSON Schema keyword.
			if ( false === $additional ) {
				$this->error( $path, sprintf( 'has unknown property "%s"', $name ) );
			} elseif ( $additional instanceof stdClass ) {
				$this->check( $value, $additional, $child );
			}
		}
	}

	/**
	 * Array keywords.
	 *
	 * @param array<int, mixed> $data   Value.
	 * @param stdClass          $schema Schema.
	 * @param string            $path   Pointer.
	 */
	private function check_array( array $data, stdClass $schema, string $path ): void {
		if ( isset( $schema->minItems ) && count( $data ) < (int) $schema->minItems ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JSON Schema keyword.
			$this->error( $path, sprintf( 'must have at least %d items', (int) $schema->minItems ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JSON Schema keyword.
		}
		if ( ! empty( $schema->uniqueItems ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- JSON Schema keyword.
			$seen = array();
			foreach ( $data as $item ) {
				$key = (string) wp_json_encode( $item );
				if ( isset( $seen[ $key ] ) ) {
					$this->error( $path, sprintf( 'has duplicate item %s', $key ) );
				}
				$seen[ $key ] = true;
			}
		}
		if ( isset( $schema->items ) && $schema->items instanceof stdClass ) {
			foreach ( $data as $index => $item ) {
				$this->check( $item, $schema->items, $path . '/' . $index );
			}
		}
	}

	/**
	 * Exactly one option must match.
	 *
	 * @param mixed      $data    Value.
	 * @param stdClass[] $options Candidate schemas.
	 * @param string     $path    Pointer.
	 */
	private function check_one_of( $data, array $options, string $path ): void {
		$matches = 0;
		foreach ( $options as $option ) {
			if ( $this->passes( $data, $option, $path ) ) {
				++$matches;
			}
		}
		if ( 1 !== $matches ) {
			$this->error( $path, 0 === $matches ? 'does not match any allowed shape' : 'matches more than one allowed shape' );
		}
	}

	/**
	 * Whether a value validates against a schema, without recording errors.
	 *
	 * @param mixed    $data   Value.
	 * @param stdClass $schema Schema.
	 * @param string   $path   Pointer.
	 */
	private function passes( $data, stdClass $schema, string $path ): bool {
		$saved        = $this->errors;
		$this->errors = array();
		$this->check( $data, $schema, $path );
		$ok           = array() === $this->errors;
		$this->errors = $saved;
		return $ok;
	}

	/**
	 * Resolves a local "#/a/b" reference.
	 *
	 * @param string $ref Reference.
	 */
	private function resolve( string $ref ): stdClass {
		$node = $this->root;
		foreach ( array_slice( explode( '/', $ref ), 1 ) as $segment ) {
			$segment = str_replace( array( '~1', '~0' ), array( '/', '~' ), $segment );
			if ( ! $node instanceof stdClass || ! isset( $node->$segment ) ) {
				return new stdClass();
			}
			$node = $node->$segment;
		}
		return $node instanceof stdClass ? $node : new stdClass();
	}

	/**
	 * JSON type check.
	 *
	 * @param mixed    $data  Value.
	 * @param string[] $types Allowed JSON types.
	 */
	private function is_type( $data, array $types ): bool {
		foreach ( $types as $type ) {
			$ok = match ( $type ) {
				'object'  => $data instanceof stdClass,
				'array'   => is_array( $data ) && array_is_list( $data ),
				'string'  => is_string( $data ),
				'integer' => is_int( $data ) || ( is_float( $data ) && floor( $data ) === $data ),
				'number'  => is_int( $data ) || is_float( $data ),
				'boolean' => is_bool( $data ),
				'null'    => null === $data,
				default   => false,
			};
			if ( $ok ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Enum membership with JSON equality.
	 *
	 * @param mixed             $data    Value.
	 * @param array<int, mixed> $allowed Allowed values.
	 */
	private function in_enum( $data, array $allowed ): bool {
		foreach ( $allowed as $candidate ) {
			if ( $this->equals( $data, $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * JSON equality.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 */
	private function equals( $a, $b ): bool {
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}

	/**
	 * Records an error.
	 *
	 * @param string $path    Pointer.
	 * @param string $message Message.
	 */
	private function error( string $path, string $message ): void {
		$this->errors[] = ( '' === $path ? '/' : $path ) . ': ' . $message;
	}
}
