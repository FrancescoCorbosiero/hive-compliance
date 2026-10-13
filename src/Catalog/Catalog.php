<?php
/**
 * Loads catalog.json and validates it against catalog.schema.json.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Catalog;

use Hive\Compliance\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * The versioned document registry. Data, not code: shipping a new catalog_version is a JSON change.
 */
final class Catalog {

	const CACHE_OPTION = 'hive_compliance_catalog_cache';

	/**
	 * Path of catalog.json.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Path of catalog.schema.json.
	 *
	 * @var string
	 */
	private string $schema_path;

	/**
	 * Entries keyed by ID, in file order; null until loaded.
	 *
	 * @var array<string, Entry>|null
	 */
	private ?array $entries = null;

	/**
	 * Catalog version.
	 *
	 * @var string
	 */
	private string $version = '';

	/**
	 * Load or validation errors.
	 *
	 * @var string[]
	 */
	private array $errors = array();

	/**
	 * Constructor.
	 *
	 * @param string $path        Catalog file (filterable through Hooks::CATALOG_PATH).
	 * @param string $schema_path Schema file.
	 */
	public function __construct( string $path, string $schema_path ) {
		$this->path        = $path;
		$this->schema_path = $schema_path;
	}

	/**
	 * Whether the catalog loaded and validated.
	 */
	public function is_valid(): bool {
		$this->load();
		return array() === $this->errors;
	}

	/**
	 * Load and validation errors.
	 *
	 * @return string[]
	 */
	public function errors(): array {
		$this->load();
		return $this->errors;
	}

	/**
	 * The catalog_version string.
	 */
	public function version(): string {
		$this->load();
		return $this->version;
	}

	/**
	 * All entries keyed by ID (empty when the catalog is invalid).
	 *
	 * @return array<string, Entry>
	 */
	public function entries(): array {
		$this->load();
		return (array) $this->entries;
	}

	/**
	 * One entry.
	 *
	 * @param string $id Catalog ID.
	 */
	public function get( string $id ): ?Entry {
		$this->load();
		return $this->entries[ $id ] ?? null;
	}

	/**
	 * Entries of one group.
	 *
	 * @param string $group Group name.
	 * @return array<string, Entry>
	 */
	public function group( string $group ): array {
		return array_filter(
			$this->entries(),
			static fn( Entry $entry ) => $entry->group === $group
		);
	}

	/**
	 * Entries that render as pages.
	 *
	 * @return array<string, Entry>
	 */
	public function pages(): array {
		return array_filter(
			$this->entries(),
			static fn( Entry $entry ) => $entry->is_page()
		);
	}

	/**
	 * Forgets the loaded catalog (tests, catalog updates).
	 */
	public function reset(): void {
		$this->entries = null;
		$this->version = '';
		$this->errors  = array();
	}

	/**
	 * Loads, validates and indexes the catalog once.
	 */
	private function load(): void {
		if ( null !== $this->entries ) {
			return;
		}
		$this->entries = array();

		/** This filter is documented in src/Hooks.php */
		$path = (string) apply_filters( Hooks::CATALOG_PATH, $this->path );

		$json   = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$schema = is_readable( $this->schema_path ) ? (string) file_get_contents( $this->schema_path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( '' === $json || '' === $schema ) {
			$this->errors = array( sprintf( 'Catalog or schema file not readable: %s', $path ) );
			return;
		}

		$data = json_decode( $json );
		if ( null === $data ) {
			$this->errors = array( 'catalog.json is not valid JSON: ' . json_last_error_msg() );
			return;
		}

		$this->errors = $this->cached_validation( $json, $schema, $data );
		if ( $this->errors ) {
			return;
		}

		$array         = json_decode( $json, true );
		$this->version = (string) $array['catalog_version'];
		foreach ( $array['documents'] as $document ) {
			$this->entries[ $document['id'] ] = Entry::from_array( $document );
		}
	}

	/**
	 * Validation result, cached by content hash so unchanged files are validated once.
	 *
	 * @param string $json   Catalog JSON.
	 * @param string $schema Schema JSON.
	 * @param mixed  $data   Decoded catalog (objects).
	 * @return string[]
	 */
	private function cached_validation( string $json, string $schema, $data ): array {
		$hash   = hash( 'sha256', $json . "\0" . $schema . "\0" . HIVE_COMPLIANCE_VERSION );
		$cached = get_option( self::CACHE_OPTION );
		if ( is_array( $cached ) && ( $cached['hash'] ?? '' ) === $hash ) {
			return (array) $cached['errors'];
		}

		$errors = $this->validate( $data, json_decode( $schema ) );
		update_option(
			self::CACHE_OPTION,
			array(
				'hash'   => $hash,
				'errors' => $errors,
			),
			false
		);
		return $errors;
	}

	/**
	 * Schema validation plus rules a schema cannot express.
	 *
	 * @param mixed $data   Decoded catalog.
	 * @param mixed $schema Decoded schema.
	 * @return string[]
	 */
	public function validate( $data, $schema ): array {
		if ( ! $schema instanceof \stdClass ) {
			return array( 'catalog.schema.json is not valid JSON.' );
		}
		$errors = ( new SchemaValidator() )->validate( $data, $schema );
		if ( $errors ) {
			return $errors;
		}

		$seen = array();
		foreach ( $data->documents as $index => $document ) {
			$where = '/documents/' . $index . ' (' . $document->id . ')';
			if ( isset( $seen[ $document->id ] ) ) {
				$errors[] = $where . ': duplicate id';
			}
			$seen[ $document->id ] = true;
			$errors                = array_merge( $errors, $this->document_errors( $document, $where ) );
		}
		return $errors;
	}

	/**
	 * Per-document rules: group/type consistency, unique element keys, compilable lint patterns.
	 *
	 * @param \stdClass $document Decoded document.
	 * @param string    $where    Location used in messages.
	 * @return string[]
	 */
	private function document_errors( \stdClass $document, string $where ): array {
		$errors        = array();
		$expected_type = array(
			'fragment' => Entry::TYPE_FRAGMENT,
			'internal' => Entry::TYPE_INTERNAL,
		)[ $document->group ] ?? Entry::TYPE_PAGE;
		if ( $document->type !== $expected_type ) {
			$errors[] = $where . sprintf( ': group "%s" requires type "%s"', $document->group, $expected_type );
		}

		$keys = array();
		foreach ( $document->required_elements as $element ) {
			if ( isset( $keys[ $element->key ] ) ) {
				$errors[] = $where . sprintf( ': duplicate required element key "%s"', $element->key );
			}
			$keys[ $element->key ] = true;
		}

		foreach ( $document->lint ?? array() as $rule ) {
			if ( false === @preg_match( '~' . str_replace( '~', '\~', $rule->pattern ) . '~iu', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Testing whether the pattern compiles.
				$errors[] = $where . sprintf( ': lint rule "%s" has an invalid pattern', $rule->id );
			}
		}
		return $errors;
	}
}
