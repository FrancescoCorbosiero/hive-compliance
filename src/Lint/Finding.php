<?php
/**
 * One validation finding.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Lint;

defined( 'ABSPATH' ) || exit;

/**
 * A rule hit: errors block an import, warnings need a confirmation.
 */
final class Finding implements \JsonSerializable {

	const ERROR    = 'error';
	const WARNING  = 'warning';
	const CONFLICT = 'conflict';

	/**
	 * Constructor.
	 *
	 * @param string   $rule     Rule ID (e.g. html_tag, base_hash_conflict).
	 * @param string   $severity error|warning|conflict.
	 * @param string   $message  Human message.
	 * @param string[] $details  Offending snippets or items (TODO list, missing headings...).
	 */
	public function __construct(
		public readonly string $rule,
		public readonly string $severity,
		public readonly string $message,
		public readonly array $details = array()
	) {}

	/**
	 * Whether the finding blocks applying.
	 */
	public function is_error(): bool {
		return self::ERROR === $this->severity;
	}

	/**
	 * JSON form (REST responses, import sessions).
	 *
	 * @return array{rule: string, severity: string, message: string, details: string[]}
	 */
	public function jsonSerialize(): array {
		return array(
			'rule'     => $this->rule,
			'severity' => $this->severity,
			'message'  => $this->message,
			'details'  => $this->details,
		);
	}

	/**
	 * Rebuilds a finding from its JSON form.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	public static function from_array( array $data ): self {
		return new self( (string) ( $data['rule'] ?? '' ), (string) ( $data['severity'] ?? self::WARNING ), (string) ( $data['message'] ?? '' ), array_map( 'strval', (array) ( $data['details'] ?? array() ) ) );
	}
}
