# Hive Compliance

Hive Compliance manages the legal and compliance documents of a WooCommerce shop (EU-first, international-ready) as Markdown inside WordPress.

The main workflow is a **round-trip with an LLM**:

1. You export every document as one Markdown file.
2. A model updates or completes the file.
3. You import it back through a per-document diff review.

Nothing changes on the site until you accept it.

The plugin does not write legal text. It provides:

- the catalog of documents a shop may need, with the elements each must cover;
- the facts about the business and the store;
- placeholders, so business data lives in one place;
- validation that catches the usual mistakes: invalid VAT numbers, dead `#` links, stray HTML, dropped placeholders, and links to the discontinued EU ODR platform.

> The required elements and legal references in the catalog are a starting checklist written against the Italian/EU baseline. They are not legal advice. International and internal entries are marked `draft`. Have the documents reviewed by a professional.

- [Requirements](#requirements)
- [Install](#install)
- [The LLM round-trip](#the-llm-round-trip)
- [Documents on the site](#documents-on-the-site)
- [Updating the catalog](#updating-the-catalog)
- [Extension hooks](#extension-hooks)
- [Development](#development)

## Requirements

- PHP 8.1+ and WordPress 6.5+.
- WooCommerce is optional (8.0+, tested with 11.2.1).
  - The plugin declares compatibility with HPOS and with the cart and checkout blocks.
  - Without WooCommerce, the shop facts in `store.*` are empty. The store profile is then pre-filled from the site settings only.
- WPML or Polylang are optional. If one is active, its languages are offered; otherwise the plugin uses its own list.
- There is no build step and no runtime Composer dependency. Parsedown 1.8.0 and ParsedownExtra 0.9.0 (MIT) are vendored in `lib/`.

## Install

1. Put the plugin in `wp-content/plugins/hive-compliance` (or upload it as a zip) and activate it. The plugin does not need `vendor/`, which holds development tools only.
2. Activation grants the `manage_hive_compliance` capability to administrators. Every screen, form and REST route checks this capability, so you can give it to another role (for example a shop manager) with any role editor.
3. On the first admin page load after the first activation, the plugin creates a draft **skeleton** for every applicable document in the default language. Reactivating the plugin never regenerates skeletons. A skeleton has no prose:
   - Pages and internal documents get one heading per required element, in the document's language, with its `{#key}` anchor, followed by that element's placeholders.
   - Fragments get only their placeholders, on one line. Fragments without placeholders, such as button labels, have no skeleton.
4. Work through the **Hive Compliance** menu:
   - **Settings:** default language, enabled languages, largest import file, uninstall behaviour, and **custom integrations**. Custom integrations cover in-house plugins and services that cannot be detected (name, role, data sent, recipient, transfer outside the EU). They appear in the export context next to the detected integrations.
   - **Business Data:** identity, registration, address, contacts, privacy roles, social links and custom values.
     - Values are checked inline: the Italian partita IVA check digit, the codice fiscale, EU VAT formats, e-mail, and phone numbers (normalised to E.164).
     - The legal form is a list of Italian forms (impresa individuale, S.n.c., S.a.s., S.r.l., S.r.l.s., S.p.A., other). An impresa individuale also needs the owner's name and personal codice fiscale (16 characters, with a valid check character).
     - A PEC on a common webmail domain (Gmail, Yahoo, Hotmail, Outlook, Libero, iCloud) gets a warning.
     - Saving is never blocked. The dashboard lists whatever is still invalid or missing.
   - **Store Profile:** a wizard with seller, markets, customers, products, product flags and features.
     - Before the first save, answers are pre-filled from WooCommerce and the active plugins, with their source shown.
     - After saving, a step warns when your answer differs from what WooCommerce says.
     - The summary step shows the auto-detected store facts.
   - **Dashboard:** the applicable documents.
     - Columns: language coverage, status, version, review dates, checklist progress, bound page and missing placeholders.
     - Cards above the table: business-data issues, public documents without a page, reviews due, and bound pages that are live while their document is unpublished or that hold extra content.
     - A **Store checks** card warns about:
       - WooCommerce selling to all countries while the shipping zones cover fewer;
       - payment surcharges set in Pay for Payment, which art. 62 Codice del Consumo prohibits for consumers (discounts are allowed);
       - an active Klarna or Scalapay while the profile's "Buy now, pay later" feature is off;
       - profile markets with no shipping zone;
       - a VAT number, PEC or e-mail address in the widgets, menus or header/footer template parts that differs from Business Data. This is a simple text scan of every active widget area, every menu, and the template parts named header or footer.
     - The **Full catalog** view lists every entry. **Generate skeletons for empty documents** covers newly enabled languages and newly applicable documents.
   - **Placeholders:** every `{{placeholder}}` in one table per namespace (`business`, `store`, `links`, `custom`, plus any added through the filter). For each one it shows:
     - a **Copy** button for the token;
     - the current value, per language;
     - a status: set, missing, invalid (with the validation message), or unknown (used in a document but defined nowhere);
     - the documents that use it, linked to the editor;
     - where the value comes from, with a link to that setting.

     Business values, custom values and page links can be changed right there; the business values go through the same checks as on Business Data. Store facts are read-only and link to the WordPress or WooCommerce screen that sets them. Search and a "Need attention" filter narrow the list, and the dashboard has a **Placeholders** card listing the ones that need action.
   - **Documents:** the editor.
     - CodeMirror for Markdown, with a live preview through the real render pipeline and the content checks.
     - A sidebar with the required-elements checklist, placeholder picker, changelog (linked to WordPress revisions), page binding, language switcher and status.
5. For each public document, click **Create draft page** in the editor sidebar, or assign an existing page. Publish the page when the document is ready.
   - Once the terms of sale are bound, the sidebar offers to make that page the WooCommerce terms and conditions page.
   - Once the privacy policy is bound, it offers to make that page the WordPress privacy policy page.
   - Each offer needs the capability that owns the setting (`manage_woocommerce` or `manage_privacy_options`) in addition to `manage_hive_compliance`.

Every screen uses the WordPress admin look with a few shared components (`assets/css/admin.css`, `assets/js/admin.js`, no build step):

- Dropdowns with many options (countries, pages, languages) become a **filterable combobox**: type to narrow the list, use the arrow keys and Enter to pick. The native `<select>` stays in the form, so saving works without JavaScript.
- Long checkbox lists (markets, US states, languages, the 73 documents on the Export screen) have a **search field**, **Select all / Select none** and a live count.
- The dashboard opens with a getting-started strip (profile, business data, documents), status tiles and one card per kind of issue, each linking to where it is fixed.
- The editor shows **Preview** and **Checks** as tabs under the Markdown, flags unsaved changes, and saves with Ctrl+S.
- Long forms (Business Data, Placeholders, Settings, import review) keep their Save button in a bar pinned to the bottom of the window.

Deleting the plugin keeps all data, unless **Settings → Remove all documents, revisions and settings when the plugin is deleted** is on. Bound pages are ordinary pages and are never deleted.

## The LLM round-trip

### 1. Export

Go to **Hive Compliance → Export**. Choose:

- the documents: all applicable, by group, or individually;
- the languages;
- whether to include internal documents (on by default).

Then click **Download LLM bundle (.md)**. The file has three parts:

1. **Instructions for the model**, written verbatim at the top. In short: keep every delimiter and placeholder, use no HTML, write `TODO(data): …` where data is missing, set a `changelog_note` for every change, cover every required element, and return the complete bundle. Heading text may be rewritten in the document's language as long as its `{#key}` anchor is kept. When the bundle leaves out some applicable documents, the header adds: "This bundle contains N of M applicable documents. Documents not included stay unchanged."
2. **Read-only context** between `<!-- hive:context begin -->` and `<!-- hive:context end -->`:
   - the store profile;
   - the business data with current values (facts for reasoning, never imported);
   - the auto-detected store facts;
   - the detected integrations (active plugins mapped to services, their role, what data they receive and from whom where known, and whether data likely leaves the EU), plus the custom integrations from Settings. WP Mail SMTP is reported with the mailer it is configured to use;
   - the catalog version and the export time.
3. **One block per document and language**:

   ```
   <!-- hive:begin id="terms_of_sale" lang="it" type="page" version="7" base_hash="sha256:…" -->
   ---
   title: "Termini e condizioni di vendita"
   last_reviewed: 2026-10-13
   effective_date: 2026-07-09
   changelog_note: ""
   required_elements: ["seller_identity: … [D.Lgs. 206/2005, art. 49(1)(b)]", …]
   ---
   <markdown body>
   <!-- hive:end id="terms_of_sale" -->
   ```

### 2. Ask the model

Give the file to the model with your request and ask for the whole file back. For example:

- "Complete the empty sections of every document."
- "Update the withdrawal documents for art. 54-bis Codice del Consumo and cite the article in each changelog_note."

### 3. Import and review

Go to **Hive Compliance → Import** and upload the returned `.md` file, or paste it. Each block is validated:

| Severity | Findings |
| --- | --- |
| **Error** (cannot be applied) | <ul><li>Broken or mismatched delimiters, an unknown document ID or language, or a duplicate block for the same document and language.</li><li>Malformed front matter.</li><li>A placeholder that is in the site version but missing from the import.</li><li>An unknown placeholder namespace, or a malformed `{{…}}`.</li><li>Any HTML tag, `<script>`, `javascript:` or `on*=` attribute.</li><li>Catalog lint errors, such as a link to the discontinued EU ODR platform.</li></ul> |
| **Warning** (applied after you tick *I reviewed the warnings*) | <ul><li>A `base_hash` conflict: the document changed on the site after the export. A three-way note shows the site changes (export → site) and the import changes (site → import).</li><li>Literal URLs other than `{{links.*}}`.</li><li>An empty `changelog_note` on a changed document.</li><li>`TODO(data):` markers, which are listed.</li><li>Required elements without a heading.</li><li>An unknown key in a known namespace.</li><li>Shortcodes that are not allow-listed.</li></ul> |

The review screen shows each changed document with its badges, a side-by-side diff and an accept checkbox. With more than one document, **Select all** (above the list and next to the Apply button) ticks every document that can be applied; documents with errors have no checkbox and stay out. Unchanged documents are collapsed into a list. Documents absent from the bundle are not touched, and the context section is ignored.

If you re-import an untouched export, the review shows zero changes. The export and the parser are byte-stable, and a test enforces this.

### 4. Apply

**Apply accepted documents** does the following for each accepted document:

- saves it as a new WordPress revision;
- increases `version` by one;
- appends a changelog entry with `source: import`, the `changelog_note` and the revision ID;
- sets `last_reviewed` to today.

The status stays as it was, unless you tick **Publish on apply**: drafts stay drafts and published documents stay published.

Every block is validated again when you apply. A document that changed on the site during the review is skipped, with a notice.

### JSON backup and restore

**Download JSON backup (.json)** contains everything: documents with their metadata, business data, store profile, settings and page bindings. It is meant for migration and backup, not for LLMs.

Importing a `.json` file never applies anything on its own:

- It first shows counts, and applies only after an explicit confirmation.
- Restored documents are saved as new versions, so version numbers never go backwards.
- Page bindings are matched by page ID, then by slug.

### Print view

**Open print view** renders the selected documents with placeholders resolved and one document per printed page. Use the browser to save it as PDF.

## Documents on the site

| Where | How |
| --- | --- |
| Page | The **Hive document** block (`hive/document`), or `[hive_document id="terms_of_sale" lang="it"]`. `lang` is optional. Without it, the current language is used, falling back to the default language. |
| Fragment | `[hive_fragment id="frag_newsletter_consent"]`, or `hive_fragment( 'frag_newsletter_consent' )` in PHP. A fragment that renders as a single paragraph is unwrapped, so it works as a label or button text. |
| Business identity (footer) | The **Business identity** block (`hive/business-identity`), or `[hive_business]`. It prints "trade name — legal name, legal form, address, P.IVA …, REA …, PEC …, E-mail …, Tel. …" from Business Data. An empty field is left out together with its label. Limit the fields with `fields="trade_name,vat_id"` or `exclude="phone"` (checkboxes in the block). Labels follow `lang` or the current language. `[hive_business field="vat_id"]` prints a single value. |
| Legal links (footer) | The **Legal links** block (`hive/legal-links`), or `[hive_legal_links group="legal"]` / `group="assistance"`; without a group, every page document. It lists links to the bound pages, in catalog order, titled with the document titles. A document is skipped unless both the page and the document are published. Each page entry's `link_group` in the catalog decides its list. |

- **Pages are thin shells.** A bound page should contain only the block. If a bound page holds anything else, the block editor, the classic editor and the dashboard warn about it.
- **Visibility.**
  - Visitors see `published` documents only. An empty placeholder renders as nothing for them, and a link whose URL is empty is unwrapped to plain text.
  - Users with `manage_hive_compliance` also see drafts, with a banner. Empty placeholders show to them as `[missing: business.vat_id]`.
  - Documents of type `internal` never render, for anyone.
- **Render pipeline.** Placeholders → ParsedownExtra (safe mode) → missing-value markers → `wp_kses_post` → allow-listed shortcodes → the `hive_compliance_rendered_html` filter.
- **Shortcodes inside documents.** Only the cookie-list shortcodes of consent plugins run:
  - Complianz: `cmplz-document`, `cmplz-cookies`, `cmplz-manage-consent`, `cmplz-revoke-link`;
  - iubenda: `iub-cookie-policy`, `iub-pp`, `iub-tc`;
  - CookieYes: `cookie_audit`, `cky_audit_table`;
  - Cookiebot: `cookiebot_declaration`.

  Any other shortcode prints as plain text. These tag names change between plugin versions, so check yours and adjust the list with `hive_compliance_allowed_shortcodes`.

In a theme, keep a fallback for when the fragment is not published yet:

```php
echo wp_kses_post( hive_fragment( 'frag_newsletter_consent' ) ?: __( 'Subscribe to the newsletter', 'my-theme' ) );
```

### Placeholders

Placeholders use the syntax `{{namespace.key}}`. They are resolved when the page renders and are never replaced in the stored text.

| Namespace | Source |
| --- | --- |
| `business.*` | The Business Data screen: `legal_name`, `vat_id`, `rea`, `address`, `email`, `pec`, `phone`, `dpo_email`, and so on. Social links are `business.social_<network>`. |
| `store.*` | Read-only, auto-detected: `site_url`, `site_name`, `store_address`, `store_country`, `currency`, `selling_countries`, `shipping_countries`, `shipping_zones`, `payment_gateways`, `guest_checkout`, `reviews_enabled`, `account_registration`. Selling locations are named in words ("all countries", "all countries except …") or as a list of countries. Shipping countries come from the shipping zones that have an enabled method. Payment methods are listed once each by title. |
| `links.<doc_id>` | The URL of the published page bound to that document, in the language being rendered or else in the default language. |
| `custom.*` | Your own keys and values, entered on the Business Data screen. |

The placeholder picker in the editor lists every key with its current value.

## Updating the catalog

`catalog/catalog.json` is the registry of documents. Each entry has:

- `id`, titles per language, `type` and `group`;
- the applicability rule `applies_when`;
- `required_elements`, each with `key`, `label` (per language, like `title`: `{"en": …, "it": …}`), `legal_ref` and optional `placeholders`;
- for page documents, `link_group` (`legal` or `assistance`), which decides the footer link list they appear in;
- `placeholders_used` and `legal_refs`;
- `review_cadence_months` and `checklist_status`;
- optional `lint` rules.

The catalog is data, so an update is a JSON change with no code change.

1. Edit `catalog/catalog.json`.
   - To keep a site-specific catalog outside the plugin, return its path from the `hive_compliance_catalog_path` filter.
   - A rule is `{"always": true}`, `{"all": […]}`, `{"any": […]}` or `{"not": …}`, and rules nest.
   - A condition is `{"field": …, "op": …, "value": …}`, where `op` is `eq`, `in`, `includes` or `true`.
   - The fields are the profile answers plus three derived ones: `market_regions`, `sells_outside_eu` and `seller_in_eu`.
2. Bump `catalog_version` (`x.y.z`). Exports show it, and skeleton changelog notes record it.
3. The catalog is validated on load against `catalog/catalog.schema.json`. The plugin also checks what a schema cannot express: unique IDs and element keys, matching group and type, and lint patterns that compile. The result is cached by file hash. An invalid catalog is never partly loaded: the dashboard shows a **Catalog errors** card instead.
4. Run the catalog tests: `vendor/bin/phpunit --filter 'Catalog|Schema|Skeleton'` (see [Development](#development)).
5. Expect these effects on existing documents:
   - **A new entry** appears on the dashboard wherever its rule applies. **Generate skeletons for empty documents** creates its draft.
   - **A new required element** shows a "missing heading" warning on existing documents until one of their headings carries its `{#key}` anchor. Headings are matched by anchor only, so their text can be rewritten in any language. The next bundle lists the element under `required_elements`, in the document's language, so the model can write it.
   - **Give every label in Italian and English** at least. A missing language falls back to English.
   - **Never rename or remove an `id` or element `key` that is in use.** Documents are stored by ID, the importer rejects unknown IDs, and headings are matched by key. To retire an entry, make its rule never match: `"applies_when": {"not": {"always": true}}`.
   - **A lint rule** has the shape `{"id", "pattern", "severity", "message"}`. The pattern is a case-insensitive PCRE without delimiters. An `error` blocks the import; a `warning` shows in the editor and in the review.

## Extension hooks

The hook names are constants of `Hive\Compliance\Hooks` (`src/Hooks.php`), each documented with its arguments.

| Hook | Type | Arguments | Purpose |
| --- | --- | --- | --- |
| `hive_compliance_render_markdown` | filter | `string $markdown, Document $document, array $context` | Markdown before placeholders are resolved. |
| `hive_compliance_placeholder_value` | filter | `?string $value, string $namespace, string $key, array $context` | Override one value; `null` means missing. |
| `hive_compliance_placeholder_namespaces` | filter | `array<string, Provider> $providers` | Add a placeholder namespace. |
| `hive_compliance_rendered_html` | filter | `string $html, Document $document, array $context` | Final HTML of a document or fragment. |
| `hive_compliance_allowed_shortcodes` | filter | `string[] $tags` | Shortcodes that may run inside documents. |
| `hive_compliance_document_visible` | filter | `bool $visible, Document $document, Entry $entry` | Hide a public document from some visitors. Internal documents never reach this filter. |
| `hive_compliance_business_identity` | filter | `array<string, string> $parts, string[] $fields, string $lang` | Parts of the identity line, keyed by field: change labels or order, or drop parts. |
| `hive_compliance_legal_links` | filter | `array $links ({id, title, url}), string $group, string $lang` | Reorder or relabel the legal links, or remove some. |
| `hive_compliance_document_saved` | action | `Document $document, ?Document $previous, string $source` | The content of a document changed (`manual` or `import`). |
| `hive_compliance_editor_before_form` | action | `Entry $entry, Document $document` | Output above the editor form, such as extra buttons. |
| `hive_compliance_catalog_path` | filter | `string $path` | Use another catalog file. |
| `hive_compliance_document_applies` | filter | `bool $applies, Entry $entry, array $profile_context` | Override applicability. |
| `hive_compliance_store_facts` | filter | `array $facts` | Add or correct `store.*` facts. |
| `hive_compliance_languages` | filter | `array<string, string> $languages` | The plugin's own language list, used when neither WPML nor Polylang is active. |
| `hive_compliance_lint_findings` | filter | `Lint\Finding[] $findings, string $markdown, Entry $entry, string $lang` | Content checks shared by the editor and the importer. |
| `hive_compliance_export_context` | filter | `array $context` | Read-only context written into the bundle. |
| `hive_compliance_export_bundle` | filter | `string $bundle, array $blocks, array $selection` | The complete bundle. |
| `hive_compliance_export_json` | filter | `array $data` | The JSON backup. |
| `hive_compliance_import_findings` | filter | `Lint\Finding[] $findings, ParsedBlock $block, ?Document $current` | Import validation of one block. A `Finding::ERROR` blocks it. |
| `hive_compliance_before_apply_import` | action | `ParsedBlock $block, ?Document $current` | Before an accepted document is saved. |
| `hive_compliance_after_apply_import` | action | `Document $document, ParsedBlock $block` | After it was saved. |
| `hive_compliance_integrations_map` | filter | `array $map` | Map more plugin slugs to services, roles and EU-transfer flags. |
| `hive_compliance_detected_integrations` | filter | `array $integrations` | The detected-integrations list. |

To add a placeholder namespace, implement `Hive\Compliance\Placeholders\Provider` and register it:

```php
use Hive\Compliance\Placeholders\Provider;

final class My_Courier_Placeholders implements Provider {
	public function label(): string { return 'Courier'; }
	public function keys(): array { return array( 'name' => 'Courier name' ); }
	public function is_known( string $key ): bool { return 'name' === $key; }
	public function value( string $key, array $context ): ?string { return 'name' === $key ? (string) get_option( 'my_courier_name', '' ) : null; }
	public function is_url( string $key ): bool { return false; }
}

add_filter( 'hive_compliance_placeholder_namespaces', static function ( array $providers ) {
	$providers['courier'] = new My_Courier_Placeholders(); // {{courier.name}}
	return $providers;
} );
```

To add a content check, which runs in the editor and blocks imports when it is an error:

```php
use Hive\Compliance\Lint\Finding;

add_filter( 'hive_compliance_lint_findings', static function ( array $findings, string $markdown ) {
	if ( false !== stripos( $markdown, 'lorem ipsum' ) ) {
		$findings[] = new Finding( 'lorem_ipsum', Finding::ERROR, 'Filler text left in the document.' );
	}
	return $findings;
}, 10, 2 );
```

## Development

```bash
composer install   # dev tools only: PHPUnit 9.6, PHPUnit Polyfills, WordPress Coding Standards
composer lint      # phpcs with the WordPress ruleset (phpcs.xml.dist)
composer test      # PHPUnit; needs the WordPress test library (WP_TESTS_DIR)
```

### Tests with wp-env (Docker)

`.wp-env.json` sets up the latest WordPress on PHP 8.1, with this plugin and the latest WooCommerce:

```bash
npx @wordpress/env start
npx @wordpress/env run tests-wordpress --env-cwd=wp-content/plugins/hive-compliance vendor/bin/phpunit
# The same suite with WooCommerce loaded:
npx @wordpress/env run tests-wordpress --env-cwd=wp-content/plugins/hive-compliance env HIVE_TESTS_WITH_WOOCOMMERCE=1 vendor/bin/phpunit
```

### Tests without Docker

1. Get the WordPress PHPUnit library: the `tests/phpunit` folder of [wordpress-develop](https://github.com/WordPress/wordpress-develop), with a `wp-tests-config.php` pointing at an empty database.
2. Run:

   ```bash
   WP_TESTS_DIR=/path/to/wordpress-develop/tests/phpunit vendor/bin/phpunit
   ```

3. To include WooCommerce, put it in the `wp-content/plugins` folder of the WordPress that `wp-tests-config.php` points to, and add `HIVE_TESTS_WITH_WOOCOMMERCE=1`. The bootstrap then installs WooCommerce's tables the way WooCommerce's own tests do.

The suite has 146 tests. It covers:

- the partita IVA check digit, codice fiscale, VAT, phone and URL validation;
- placeholder resolution and escaping;
- the applicability rules;
- the export → parse round-trip (byte-stable);
- one failing fixture for every import validation rule (`tests/fixtures/import/`);
- internal documents never rendering;
- the visibility of drafts;
- page binding, skeletons and JSON restore;
- the admin screens.

Three of the tests run only when WooCommerce is loaded: store facts, profile detection, and the warning for answers that differ from WooCommerce.

### Phase 1 limits

Not built in Phase 1:

- the art. 54-bis withdrawal function;
- the harmonised legal-guarantee notice block;
- fragments injected into WooCommerce e-mails;
- the T&C version in force per order;
- a cross-page consistency checker;
- PDF generation, direct LLM API calls and cookie scanning.

Each version is linked to its WordPress revision in the changelog, so the T&C in force for a given order can be looked up later.

## Licence

GPL-2.0-or-later. Parsedown and ParsedownExtra are MIT-licensed; see `lib/`.
