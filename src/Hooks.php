<?php
/**
 * Names of the actions and filters this plugin exposes.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance;

defined( 'ABSPATH' ) || exit;

/**
 * Extension points. Each constant documents the arguments passed to callbacks.
 */
final class Hooks {

	// Rendering.

	/**
	 * Filter: Markdown of a document before placeholders are resolved.
	 * Args: string $markdown, Document $document, array $context.
	 */
	const RENDER_MARKDOWN = 'hive_compliance_render_markdown';

	/**
	 * Filter: value of one placeholder. Return null to treat it as missing.
	 * Args: string|null $value, string $namespace, string $key, array $context.
	 */
	const PLACEHOLDER_VALUE = 'hive_compliance_placeholder_value';

	/**
	 * Filter: placeholder namespaces and their providers.
	 * Args: array<string, Placeholders\Provider> $providers.
	 */
	const PLACEHOLDER_NAMESPACES = 'hive_compliance_placeholder_namespaces';

	/**
	 * Filter: final HTML of a rendered document or fragment.
	 * Args: string $html, Document $document, array $context.
	 */
	const RENDERED_HTML = 'hive_compliance_rendered_html';

	/**
	 * Filter: shortcode tags that may run inside a rendered document.
	 * Args: string[] $tags.
	 */
	const ALLOWED_SHORTCODES = 'hive_compliance_allowed_shortcodes';

	/**
	 * Filter: whether a public (non-internal) document may be shown to the current visitor.
	 * Internal documents never reach this filter.
	 * Args: bool $visible, Document $document, Catalog\Entry $entry.
	 */
	const DOCUMENT_VISIBLE = 'hive_compliance_document_visible';

	// Documents and catalog.

	/**
	 * Action: a document was saved and its content changed.
	 * Args: Document $document, Document|null $previous, string $source (manual|import).
	 */
	const DOCUMENT_SAVED = 'hive_compliance_document_saved';

	/**
	 * Action: printed above the document editor form (e.g. extra buttons).
	 * Args: Catalog\Entry $entry, Document $document.
	 */
	const EDITOR_BEFORE_FORM = 'hive_compliance_editor_before_form';

	/**
	 * Filter: absolute path of the catalog JSON file.
	 * Args: string $path.
	 */
	const CATALOG_PATH = 'hive_compliance_catalog_path';

	/**
	 * Filter: whether a catalog entry applies to the store profile.
	 * Args: bool $applies, Catalog\Entry $entry, array $profile_context.
	 */
	const DOCUMENT_APPLIES = 'hive_compliance_document_applies';

	/**
	 * Filter: auto-detected store facts (the store.* namespace).
	 * Args: array $facts.
	 */
	const STORE_FACTS = 'hive_compliance_store_facts';

	/**
	 * Filter: languages offered when neither WPML nor Polylang is active.
	 * Args: array<string, string> $languages BCP-47 code => label.
	 */
	const LANGUAGES = 'hive_compliance_languages';

	/**
	 * Filter: content lint findings for a document (shared by the editor and the importer).
	 * Args: Lint\Finding[] $findings, string $markdown, Catalog\Entry $entry, string $lang.
	 */
	const LINT_FINDINGS = 'hive_compliance_lint_findings';

	// Export.

	/**
	 * Filter: read-only context data written into the LLM bundle.
	 * Args: array $context.
	 */
	const EXPORT_CONTEXT = 'hive_compliance_export_context';

	/**
	 * Filter: the complete LLM bundle string.
	 * Args: string $bundle, array $blocks (id, lang, type, version, base_hash, front_matter, body),
	 * array $selection (mode, groups, docs, langs, include_internal).
	 */
	const EXPORT_BUNDLE = 'hive_compliance_export_bundle';

	/**
	 * Filter: data of the JSON backup.
	 * Args: array $data.
	 */
	const EXPORT_JSON = 'hive_compliance_export_json';

	// Import.

	/**
	 * Filter: validation findings of one imported document block.
	 * Add your own Lint\Finding objects to extend validation (Finding::ERROR blocks the document).
	 * Args: Lint\Finding[] $findings, Import\ParsedBlock $block, Document|null $current.
	 */
	const IMPORT_FINDINGS = 'hive_compliance_import_findings';

	/**
	 * Action: before an accepted imported document is applied.
	 * Args: Import\ParsedBlock $block, Document|null $current.
	 */
	const BEFORE_APPLY_IMPORT = 'hive_compliance_before_apply_import';

	/**
	 * Action: after an accepted imported document was applied.
	 * Args: Document $document, Import\ParsedBlock $block.
	 */
	const AFTER_APPLY_IMPORT = 'hive_compliance_after_apply_import';

	// Integrations.

	/**
	 * Filter: known plugin slug => service map used for integration detection.
	 * Args: array $map.
	 */
	const INTEGRATIONS_MAP = 'hive_compliance_integrations_map';

	/**
	 * Filter: the detected integrations list.
	 * Args: array $integrations.
	 */
	const DETECTED_INTEGRATIONS = 'hive_compliance_detected_integrations';
}
