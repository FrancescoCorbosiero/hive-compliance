# hive-compliance — Phase 1 implementation plan

This is the "plan first" step from the spec (`hive-compliance-claude-code-prompt.md`):
file tree, data model, endpoints and the decisions that fill gaps in the spec.

## File tree

As built (the plan's tree, updated after implementation).

```
hive-compliance.php            Plugin header, constants, autoloader, bootstrap, HPOS + blocks compatibility
uninstall.php                  Keeps data unless Settings → "remove all data on uninstall"
composer.json                  require-dev only (tests / standards); no runtime dependencies
phpunit.xml.dist  phpcs.xml.dist  .wp-env.json
catalog/
  catalog.json                 Versioned registry (catalog_version at the top)
  catalog.schema.json          JSON Schema, validated on load
lib/                           Parsedown 1.8.0 + ParsedownExtra 0.9.0 (MIT), namespaced, see lib/README.md
includes/functions.php         Global helper hive_fragment( $id, $lang = null ): string
src/                           PSR-4 Hive\Compliance\ → src/
  Plugin.php  Installer.php  Settings.php  Hooks.php  Autoloader.php
  Document/     PostType.php  Document.php  Repository.php  Markdown.php (normalise + hash)
  Catalog/      Catalog.php  Entry.php  SchemaValidator.php  RuleEngine.php  Applicability.php
  Profile/      Profile.php  StoreFacts.php  Markets.php
  Business/     BusinessData.php  Validator.php (P.IVA, codice fiscale, EU VAT, REA, e-mail, E.164, URL)  Check.php
  Placeholders/ Syntax.php  Registry.php  Resolver.php  Resolution.php  Provider.php (+ Business, Store, Links, Custom providers)
  Render/       Renderer.php  MarkdownRenderer.php  Shortcodes.php  Block.php  PageBindings.php  PageManager.php  ShellGuard.php
                SiteElements.php (footer shortcodes and blocks)  BusinessIdentity.php  LegalLinks.php  SitePages.php
  Checks/       StoreChecks.php (dashboard "Store checks")  IdentityScan.php (widgets, menus, template parts)
  I18n/         Languages.php (WPML / Polylang / own list)
  Integrations/ Detector.php (slug → service, role, transfer outside the EU)
  Lint/         ContentLinter.php (rules shared by the editor and the importer)  Finding.php
  Export/       BundleFormat.php  BundleExporter.php  JsonExporter.php  Selection.php
  Import/       BundleParser.php  ParsedBlock.php  BundleValidator.php  BlockReport.php  Importer.php
                ImportSession.php  Upload.php  JsonRestorer.php
  Skeleton/     Generator.php
  Rest/         Controller.php
  Admin/        Menu.php  Screen.php  Request.php  Notices.php  Overview.php  DocumentsTable.php (WP_List_Table)
                DashboardScreen.php  EditorScreen.php  BusinessScreen.php  ProfileScreen.php  ExportScreen.php
                ImportScreen.php  SettingsScreen.php  PrintScreen.php  BindingActions.php  SkeletonActions.php
blocks/document/ business-identity/ legal-links/   block.json  index.js  index.asset.php (hand-written; no build step)
assets/css/  admin.css  print.css  front.css
assets/js/   editor.js  page-guard.js  repeater.js
tests/       bootstrap.php, TestCase.php, phpunit/*Test.php, fixtures/import/*.md (one failing fixture per rule)
```

## Data model

**CPT `hive_document`.** `public => false`, `show_ui => false`, `show_in_rest => false`, `supports => ['revisions']`, `can_export => false`, `delete_with_user => false`. Every primitive capability maps to `manage_hive_compliance`. `post_content` holds the raw Markdown.

Markdown is normalised on save: LF line endings, no BOM, no leading or trailing blank lines. This keeps hashes and round-trips byte-stable. During our own saves, kses is bypassed so `&` and `<` in Markdown are not mangled; safety comes from the output pipeline instead. Revisions are kept without limit for this post type, so the text of every version stays retrievable.

Post meta:

| Key | Content |
| --- | --- |
| `_hive_doc_id`, `_hive_lang` | One post per (doc_id, lang). |
| `_hive_version` | int. Increments only when the Markdown changes; status or date changes do not bump it. |
| `_hive_status` | `empty` / `draft` / `published`. |
| `_hive_last_reviewed`, `_hive_effective_date` | `Y-m-d`. |
| `_hive_changelog` | List of `{version, date, note, source: manual\|import, revision_id}`. `revision_id` maps each version to its WP revision, so a later "T&C version in force per order" feature can look the text up. |
| `_hive_checklist` | `{element_key: bool}`. |

Options:

| Option | Content |
| --- | --- |
| `hive_business` | Identity, registration, address and country, contacts, privacy roles, `social[]` (`{network, url}`), `custom[]` (`{key, value}`). The `custom.*` placeholders are edited on the Business Data screen. |
| `hive_profile` | Wizard answers. Unsaved fields are pre-filled from WooCommerce, with their source shown. |
| `hive_settings` | `default_language`, `enabled_languages`, `remove_data_on_uninstall`, `max_import_kb`. |
| `hive_page_bindings` | `{doc_id: {lang: page_id}}`. |
| `hive_compliance_db_version` | Schema version, for upgrades. |

Import sessions live in a per-user transient for 1 hour, keyed by a random token.

## Placeholders

The namespaces are `business.*`, `store.*` (read-only and auto-detected), `links.<page_doc_id>` and `custom.*`. Social links are exposed as `business.social_<network>`.

Resolution happens before Parsedown:

- Text values are Markdown-escaped.
- URL values are passed through `esc_url_raw`.
- A missing or empty value becomes an internal sentinel. After Parsedown, the sentinel becomes nothing for visitors, or a `<mark>[missing: ns.key]</mark>` marker for users with `manage_hive_compliance`.
- A link whose URL is missing is unwrapped to plain text for visitors, so no dead `#` links are rendered.

`links.*` resolves to the page bound for the language being rendered, then falls back to the default language.

## Rendering

The pipeline runs: resolve placeholders → ParsedownExtra (safe mode, markup escaped) → missing-marker post-processing → `wp_kses_post` → allow-listed shortcodes → output. Filters wrap each step.

- `[hive_document]`, the `hive/document` block and `hive_fragment()` all go through `Renderer`.
- `internal` documents return `''` from every entry point, for every user.
- A fragment that renders to a single paragraph is unwrapped, so it works as a label or button text.

## Catalog

`catalog.json` holds `catalog_version` and `documents[]`. Each entry has `id`, `title{lang}`, `type`, `group`, `applies_when`, `required_elements[{key, label, legal_ref, placeholders?}]`, `placeholders_used`, `legal_refs`, `review_cadence_months`, `checklist_status`, and an optional `lint[{pattern, severity, message}]`.

The catalog is validated against the schema with a small built-in JSON-Schema validator. The result is cached by file hash.

These extensions to the spec are additive:

- **Nested rules and `not`.** They are needed to express EAA ("not micro-enterprise + services only").
- **Per-element `placeholders`.** Skeletons use them to put placeholders under the right heading.
- **`lint`.** The `dispute_resolution` ODR-link error is expressed as data.

## Endpoints

REST (`hive-compliance/v1`). Every route has a `permission_callback` requiring `manage_hive_compliance`, and uses cookie auth with `X-WP-Nonce`.

- `POST /preview` takes `{doc_id, lang, markdown}` and returns `{html, missing[], findings[]}`. It powers the editor's live preview and lint panel.
- The block editor uses core's `/wp/v2/block-renderer/hive/document`, through ServerSideRender. Our `render_callback` still enforces the internal and draft rules.

`admin-post.php` actions. Each has its own nonce and a capability check, and redirects back with a notice.

- `hive_compliance_save_document`
- `hive_compliance_generate_skeleton` (one document), `hive_compliance_generate_skeletons` (every empty applicable document)
- `hive_compliance_bind_page`, `hive_compliance_unbind_page`
- `hive_compliance_save_business`, `hive_compliance_save_profile`, `hive_compliance_save_settings`
- `hive_compliance_export` (bundle or JSON download)
- `hive_compliance_import_upload` (a `.md` bundle goes to the review, a `.json` backup to the restore preview)
- `hive_compliance_import_apply`, `hive_compliance_restore_apply`, `hive_compliance_import_discard`

The print view is `admin.php?page=hive-compliance-print&…&_wpnonce=…`. It is a standalone HTML page with a print stylesheet.

## Export bundle

The instruction header is written verbatim. The read-only context sits between `<!-- hive:context begin -->` and `<!-- hive:context end -->` and is ignored by the parser. Document blocks follow the spec's format exactly.

Each block is serialised as `begin \n --- \n front-matter --- \n body \n end`. The parser strips exactly that framing. This makes `serialize(parse(bundle)) === bundle`, and makes each parsed body identical to the stored Markdown.

## Import validation

These rules are reported per document:

| Severity | Rules |
| --- | --- |
| **error** | Delimiter pairs, ID match, unknown ID or language, duplicate (id, lang), front matter malformed, a placeholder dropped vs. current, unknown namespace, malformed `{{…}}`, HTML tags, `javascript:`, `on*=`, catalog lint errors (ODR link). |
| **warning** | `base_hash` conflict (with three-way note: the base revision is found by hash, and the diffs shown are base→site and site→import), literal URLs, empty `changelog_note` on change, `TODO(data):` (listed), missing required-element headings, unknown key in a known namespace, non-allow-listed shortcode. |

A heading counts for a required element only if it carries the `{#key}` anchor (the skeletons add these). Its text may be rewritten in the document's language, and rule 3 of the bundle header says so. Fragments have no heading check.

## Decisions confirmed with the product owner

- **Draft documents.** Visitors see only `published` documents. Admins see a draft with a "not visible to visitors" banner. The dashboard flags pages whose document is still a draft.
- **Import status.** Applying an import never changes status by itself. Drafts stay drafts unless "publish on apply" is ticked; published documents stay published and the accepted text goes live.
- **Dev dependencies.** Composer `require-dev` only: phpunit/phpunit, yoast/phpunit-polyfills, wp-coding-standards/wpcs. Nothing extra ships with the plugin.
- **Shortcodes inside documents.** Only allow-listed consent-plugin cookie-list shortcodes run (Complianz, iubenda, CookieYes; extendable through `hive_compliance_allowed_shortcodes`). Any other shortcode prints as plain text, and the importer warns about it.

## Decisions taken without asking

These fill gaps in the spec; each is easy to change.

- Version bumps only when the content changes.
- Restoring a WP revision counts as a manual change: version +1 and a changelog entry.
- Bound pages are created as WP **drafts**. The plugin never publishes a page by itself.
- The JSON restore overwrites business data, profile, settings and documents. Version numbers never go backwards; the version becomes max(current + 1, backup). Page bindings are re-matched by slug when the IDs differ.
- Fragments are short labels and texts, so the "missing heading" check does not apply to them.
- "Review due" applies only to published documents. A draft is not in force, so freshly generated skeletons are not flagged.
- The integration detector was built with the store profile (milestone 2), because the profile pre-fills features from it.
- The store profile is pre-filled from WooCommerce until the first save. After that, the answers are the merchant's own. A step warns when an answer differs from what WooCommerce reports (seller country, selling locations), and fallback guesses such as the site language never warn.
- `wp-env` needs Docker. The container used to build this has none. The suite was run there against WordPress 7.1.3 and MariaDB installed locally, on PHP 8.3 and on PHP 8.1 (the minimum), both without WooCommerce and with WooCommerce 11.2.1 loaded (taken from its GitHub release). With WooCommerce loaded, the test bootstrap installs WooCommerce's tables the way WooCommerce's own tests do. `.wp-env.json` is shipped for local use.
- The admin UI was also checked in a browser against a real site, with and without WooCommerce 11.2.1 and with HPOS enabled. WooCommerce lists the plugin as compatible with HPOS and the cart and checkout blocks.

## Milestones (one commit each)

1. Bootstrap, capability, CPT and meta
2. Catalog loader with schema validation, plus the profile and applicability engine
3. Business data and placeholders with validation
4. Render pipeline, shortcode and block, page binding
5. Dashboard and editor
6. Export (bundle, JSON, print)
7. Import parser, validator and review
8. Skeleton generation, then tests run green and README
