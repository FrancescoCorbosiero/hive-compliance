# Build: hive-compliance (WordPress / WooCommerce plugin) — Phase 1

## Context

Build a lightweight WordPress plugin, **hive-compliance**, that manages every legal/compliance document a WooCommerce shop needs (EU-first, international-ready).

The real product is a **round-trip with an LLM**. The admin exports all documents as **one Markdown file**, hands it to an LLM to update or complete, and imports the result back. The import goes through a per-document diff review before anything changes.

The admin UI exists to view, edit, review and approve. It is not a legal text generator.

Lessons from auditing three real shops built on the same template drive the design:
- Business data typos and inconsistencies across pages (an invalid 12-digit P.IVA, a malformed email, another shop's social links leaking in).
- Duplicate sources of truth (on-site pages *and* a third-party legal service).
- Dead `#` links between legal pages.
- Documents describing a tech stack different from the one actually installed.
- No versioning of the T&C in force at the time of an order.

Phase 1 must make these failures structurally hard.

## Hard constraints

- PHP 8.1+, WordPress 6.5+ (test on latest), WooCommerce latest, **HPOS-compatible** (declare compatibility). The plugin must also activate without WooCommerce; Woo-dependent features degrade gracefully.
- **No JS build step.** Use vanilla JS with `wp.element`/`wp.serverSideRender` globals where needed, plus WP admin CSS and the CodeMirror that ships with WordPress (`wp_enqueue_code_editor`).
- Dependencies:
  - The only allowed third-party library is **Parsedown + ParsedownExtra** (MIT), vendored in `/lib`.
  - Use WordPress built-ins otherwise: `wp_text_diff()` for diffs, WP revisions for history, `wp_kses` for sanitising.
  - Ask me before adding anything else.
- Coding standards and security:
  - WordPress Coding Standards, namespaced PHP (`Hive\Compliance\…`), autoloader, text domain `hive-compliance`, all strings translatable.
  - Custom capability `manage_hive_compliance`, granted to administrators on activation.
  - Nonces on every form and AJAX call, and a `permission_callback` on every REST route.
  - Sanitise all input; escape all output.
- Uninstall keeps data by default. A setting enables full removal on uninstall.

## Architecture decisions (already made — follow them)

### 1. Content is Markdown

- Store each document as Markdown in a CPT `hive_document`:
  - `public => false`, `show_ui => false` (custom admin screens);
  - `supports => ['revisions']`;
  - `post_content` holds the raw Markdown.
- Meta fields:
  - `_hive_doc_id` (catalog ID) and `_hive_lang` (BCP-47, e.g. `it`, `en`, `de`) — one post per (doc_id, lang);
  - `_hive_version` (int, increments on every applied change);
  - `_hive_status` (`empty` | `draft` | `published`);
  - `_hive_last_reviewed` (date), `_hive_effective_date` (date);
  - `_hive_changelog` (array of `{version, date, note, source: manual|import}`);
  - `_hive_checklist` (map of required-element key → bool, ticked manually in Phase 1).

### 2. Placeholders

- **Syntax and storage.** Placeholders use the syntax `{{namespace.key}}`. They are resolved **at render time only**, never written into stored content.
- **Namespaces:**
  - `business.*`: an option `hive_business` edited on a Business Data screen. Fields:
    - identity: legal_name, trade_name, legal_form, owner_name;
    - registration: vat_id, tax_code, rea, share_capital;
    - address, country;
    - contacts: email, pec, phone, whatsapp, support_hours;
    - privacy roles: dpo_name, dpo_email, eu_representative;
    - social URLs, as a repeater.
  - `store.*`: **read-only, auto-detected** from WordPress and WooCommerce. Includes site_url, store address, currency, selling countries, shipping zones and their countries, enabled payment gateways, guest checkout on/off, reviews on/off, account registration on/off.
  - `links.*`: URL of the WP page bound to each public document (e.g. `{{links.privacy_policy}}`). This kills dead `#` links.
  - `custom.*`: user-defined key/value pairs.
- **Validation** on save:
  - Italian P.IVA must be 11 digits with a valid check digit; implement the official algorithm.
  - Other EU VAT IDs: country prefix plus a basic per-country regex.
  - Email via `is_email`; phone normalised to E.164.
  - Show inline errors. Never block saving, but list unresolved or invalid fields on the dashboard.
- **Unknown or empty placeholders** render nothing for visitors. Admins see a visible `[missing: business.vat_id]` marker when logged in, and the dashboard counts them.

### 3. Pages are thin shells

- Each public document binds to one WP page. The page contains only the dynamic block `hive/document` (or the shortcode `[hive_document id="terms_of_sale" lang="it"]`).
- On the document screen, a **"Create / assign page"** action creates or binds the page; the binding is stored in an option.
- If a bound page's content is edited to contain more than the block, show an admin warning. One source of truth.
- Fragments render via `[hive_fragment id="frag_newsletter_consent"]` and a PHP helper `hive_fragment( $id, $lang = null ): string`.
- Documents of type `internal` **never** render on the front end. Shortcode and block refuse and output nothing.
- The render pipeline runs in this order: resolve placeholders → Parsedown → `wp_kses_post` → output.

### 4. Catalog registry is versioned data, not code

- `/catalog/catalog.json`, with a JSON Schema at `/catalog/catalog.schema.json`, validated on load. Each entry has:
  - `id`, `title` (per language), and `type` (`page` | `fragment` | `internal`);
  - `group` (`core` | `conditional` | `international` | `fragment` | `internal`);
  - `applies_when`: a rule over the store profile (see section 5);
  - `required_elements`: an array of `{key, label, legal_ref}`;
  - `placeholders_used`, an array of placeholder keys;
  - `legal_refs`, an array of strings;
  - `review_cadence_months`, plus `checklist_status` (`complete` | `draft`).
- Rule format: `{"always": true}` or `{"all"|"any": [{"field": "...", "op": "eq|in|includes|true", "value": ...}]}`.
- `catalog_version` lives at the top of the file. A future catalog update ships as a JSON change without a code change.

### 5. Store profile

A setup wizard (re-editable later) that stores the option `hive_profile`. Fields:
- **Seller:** seller_country; is_micro_enterprise.
- **Markets:** markets (multi: EU country codes, `UK`, `CH`, `US-<state>`, `CA`, `BR`, `JP`, `AU`, `other`).
- **Customers:** customer_types (`b2c`, `b2b`).
- **Products:** product_types (`physical`, `digital_content`, `services`, `subscriptions`).
- **Product flags:** `used_goods`, `refurbished`, `custom_made`, `age_restricted`, `food`, `cosmetics`, `electronics`, `batteries`, `dropshipping`, `non_eu_suppliers`.
- **Features:** `accounts`, `reviews`, `newsletter`, `abandoned_cart`, `loyalty_referral`, `gift_cards`, `contests`, `bnpl`, `marketplace_multivendor`, `ai_chatbot`, `physical_store_or_pickup`, `environmental_claims`.

Where Woo already knows an answer (e.g. selling countries, reviews enabled), pre-fill it and show the source. The dashboard shows only applicable documents. A toggle reveals the full catalog.

## The catalog (seed `catalog.json` with ALL of these IDs)

Write **complete `required_elements` for the core group, the EU conditional entries and all fragments**, written against the Italian/EU baseline. Mark international and internal entries `checklist_status: "draft"` with a best-effort list. **Do not write legal prose anywhere.**

**Core (always, for B2C):**
- `legal_notice` — business identity / Impressum / mentions légales.
- `privacy_policy`, `cookie_policy`.
- `terms_of_sale`.
- `withdrawal_policy`, `withdrawal_form`.
- `refund_policy`, `shipping_policy`.
- `legal_guarantee` — must include the EU harmonised legal-guarantee notice element, Reg. (EU) 2025/1960.
- `payment_methods`.
- `dispute_resolution` — ADR bodies; the EU ODR platform was discontinued on 20 Jul 2025, so the checklist must flag ODR links as an error.
- `contact_support`.

**Conditional (EU):**
- Guarantees and used goods: `commercial_guarantee`, `authenticity_guarantee`, `used_goods_notice`.
- Accessibility: `accessibility_statement` (EAA; not applicable if micro-enterprise and services only).
- Pricing and reviews: `pricing_discount_policy` (30-day prior lowest price), `review_policy` (how reviews are verified).
- Product-type terms: `subscription_terms`, `digital_content_terms`, `b2b_terms`.
- Marketplace: `marketplace_seller_terms`, `dsa_notice_action`.
- Promotions: `loyalty_terms`, `gift_card_terms`, `contest_rules`, `promo_terms`.
- Payments: `bnpl_disclosure` (Consumer Credit Directive 2023/2225, applies from 20 Nov 2026).
- Restricted goods: `age_restricted_policy`.
- Product safety: `product_safety_recalls` (GPSR).
- Take-back and packaging: `weee_takeback`, `battery_takeback`, `packaging_epr`.
- Claims and AI: `environmental_claims`, `ai_disclosure`.
- Cross-border and pickup: `import_duties_notice` (non-EU suppliers or markets), `local_pickup_terms`.

**International:**
- UK and Switzerland: `uk_privacy_addendum`, `uk_consumer_terms_addendum`, `ch_privacy_addendum`.
- US: `us_state_privacy_notice`, `us_notice_at_collection`, `us_do_not_sell_share`, `us_auto_renewal_disclosure`, `us_prop65`.
- Other markets: `ca_privacy_addendum`, `br_lgpd_addendum`, `jp_tokushoho`, `au_consumer_guarantee`.

**Fragments:**
- Checkout and account: `frag_checkout_terms_consent`, `frag_order_button_label`, `frag_account_privacy_notice`, `frag_contact_form_notice`.
- Consent and marketing: `frag_newsletter_consent`, `frag_cookie_banner_text`, `frag_email_unsubscribe_footer`, `frag_abandoned_cart_notice`, `frag_ca_casl_consent`.
- Withdrawal, with the Italian defaults "recedi dal contratto qui" / "Conferma recesso" noted in `legal_refs` (art. 54-bis Codice del Consumo, D.Lgs. 209/2025): `frag_withdrawal_button_label`, `frag_withdrawal_confirm_label`, `frag_withdrawal_receipt_email`.
- Email, digital and age: `frag_order_email_legal_snippet`, `frag_digital_content_waiver`, `frag_age_gate`.

**Internal (never published):**
- `int_ropa` (GDPR art. 30), `int_retention_schedule`, `int_processor_register`, `int_toms`.
- `int_lia`, `int_dpia`.
- `int_breach_procedure`, `int_breach_log`, `int_dsr_log`.

**Skeletons.** On first activation (and on "Generate skeleton"), create each applicable document as `draft`. The body contains only Markdown headings derived from `required_elements` and the relevant placeholders, with no prose.

## Export (the core feature)

The Export screen offers:
- Filters: document selection (all applicable / by group / individual), languages, and an include-internal toggle (on by default).
- **LLM bundle (.md)**: one file, structured exactly as follows.
  1. An instruction header, written verbatim:
     ```
     # hive-compliance bundle — instructions for the AI
     You are updating legal/compliance documents for the shop described below.
     Rules:
     1. Keep every `<!-- hive:begin … -->` / `<!-- hive:end … -->` delimiter exactly as is. Do not add, remove, rename or reorder them.
     2. Keep every `{{placeholder}}` token unchanged. Never replace a placeholder with a literal value. Do not invent new placeholders; if you need data that has no placeholder, write `TODO(data): <what is needed>`.
     3. Edit only the Markdown between the YAML front matter and the end delimiter, plus the `changelog_note` field.
     4. For every document you change, set `changelog_note` to one line describing what changed and why (cite the rule).
     5. Use the REQUIRED ELEMENTS checklist of each document; every element must be covered.
     6. No HTML, no scripts, no links except `{{links.*}}` placeholders and official authority URLs.
     7. Return the complete bundle, including documents you did not change.
     ```
  2. A **read-only context** section: the store profile; business data with *current values*, plus a note that these are facts for reasoning and are never imported; store facts (auto-detected); a detected-integrations list (see below); `catalog_version`; and the export timestamp.
  3. One block per document:
     ```
     <!-- hive:begin id="terms_of_sale" lang="it" type="page" version="7" base_hash="sha256:<hash of current markdown>" -->
     ---
     title: "Termini e condizioni di vendita"
     last_reviewed: 2026-10-13
     effective_date: 2026-07-09
     changelog_note: ""
     required_elements: [ ...keys with labels... ]
     ---
     <markdown body>
     <!-- hive:end id="terms_of_sale" -->
     ```
- **JSON backup (.json)**: everything, including business data, profile, page bindings, documents and metadata. This is for site migration and backup, not for LLMs.
- **Print view**: an admin page rendering the selected documents with a print stylesheet (headers, page breaks per document, the resolved placeholders). The user saves it as PDF from the browser. No PDF library in Phase 1.

**Detected integrations.** Map active plugins and settings to known services with a small PHP array (slug → service, role, likely data transfer outside the EU). Cover at least:
- payments: WooPayments/Stripe, PayPal, Klarna, Scalapay;
- Google: Google for WooCommerce;
- Meta Pixel / Facebook for WooCommerce;
- email: Mailchimp, MailPoet, Hustle;
- consent: Complianz, iubenda, CookieYes;
- reviews: Trustpilot;
- analytics: Koko Analytics, Site Kit;
- cart recovery: abandoned-cart plugins.

Unknown active plugins are listed by name.

## Import (diff review — never a blind overwrite)

1. Accept an upload (`.md` or `.json`, size-limited, MIME-checked) or a paste textarea.
2. Parse the `.md` bundle and validate each document block. Flag each as **error** (cannot apply) or **warning** (can apply after confirm):
   - Delimiter integrity: begin/end pairs, matching IDs, known catalog ID, known language → error.
   - `base_hash` ≠ hash of the current stored content → **conflict** warning (the document changed on the site after export). Show a three-way note.
   - A placeholder present in the current version but missing in the import → error. A new placeholder not in the known namespaces → error.
   - Any HTML tags, `<script>`, `javascript:` or `on*=` attributes → error. Literal URLs other than `{{links.*}}` → warning.
   - Empty `changelog_note` on a changed document → warning.
   - `TODO(data):` markers → warning, listed.
   - Required elements whose heading is missing → warning.
   - Business data or context sections in the bundle are ignored entirely.
3. The review screen lists each changed document with its validation badges and a side-by-side diff (`wp_text_diff`), plus an accept checkbox per document. Unchanged documents are listed as unchanged. Documents absent from the bundle are untouched.
4. Applying accepted documents works as follows:
   - each one saves as a new revision;
   - `_hive_version` increments;
   - the changelog entry is appended with `source: import`;
   - `last_reviewed` is set to today;
   - status stays `draft` unless the user ticks "publish on apply".
5. A `.json` import is a restore flow: preview counts, confirm, then apply. It never runs automatically.

**Acceptance test:** export → import the untouched file → the review screen shows zero changes.

## Admin UI (WordPress-native look)

Top-level menu **Hive Compliance** with these screens:
- **Dashboard**: a `WP_List_Table` of applicable documents with these columns:
  - title, type and group;
  - language coverage;
  - status;
  - version, last reviewed, and overdue review (from `review_cadence_months`);
  - checklist progress;
  - bound page;
  - missing placeholders.

  Above the table, cards show: business data issues, unbound public docs, and overdue reviews.
- **Documents**: an editor with CodeMirror (Markdown mode) and a live preview (AJAX render through the same pipeline). A sidebar holds the required-elements checklist, placeholder picker, changelog, revisions link, page binding, language switcher and status.
- **Business Data**, **Store Profile** (wizard), **Export**, **Import**, **Settings** (default language, enabled languages, uninstall behaviour). Store Profile shows the auto-detected store facts read-only.
- WPML/Polylang: if active, offer their languages. Otherwise use the plugin's own language list.

## Out of scope for Phase 1 (design for them, do not build)

- Art. 54-bis withdrawal function (My Account + guest form, durable-medium receipt, logs).
- Harmonised legal-guarantee notice block (official graphic).
- Injecting fragments into WooCommerce emails; linking each order to the T&C version in force (store `version` IDs stably so this can be added).
- Cross-page consistency checker (contradicting shipping times or phone numbers, `<noscript>` trackers, hardcoded review widgets).
- PDF library, direct LLM API calls, cookie scanning (embed the consent plugin's own cookie list via its shortcode inside `cookie_policy` instead).

Leave clear extension points: `do_action`/`apply_filters` hooks for render, export, import validation and integration detection.

## How to work

1. **Plan first.** Propose the file tree, data model, and REST/AJAX endpoints, then implement in this order, committing after each milestone with a short message:
   1. bootstrap, capability, CPT and meta;
   2. catalog loader with schema validation, plus the profile and applicability engine;
   3. business data and placeholders with validation;
   4. render pipeline, shortcode and block, page binding;
   5. dashboard and editor;
   6. export (bundle, JSON, print);
   7. import parser, validator and review;
   8. skeleton generation.
2. **Tests (PHPUnit, with a `wp-env` config):**
   - P.IVA checksum (valid/invalid samples) and placeholder resolution;
   - applicability rules;
   - bundle export → parse round-trip (byte-stable);
   - every import validation rule (one failing fixture each);
   - `internal` docs never render on the front end.
3. Run the tests and fix failures before finishing. Finish with a README covering install, the LLM round-trip workflow, the catalog update process and the extension hooks.
4. Do not write legal prose. Where wording is needed (labels, headings), use neutral placeholders or the catalog labels.
5. If anything in this spec is ambiguous or conflicts with WordPress or WooCommerce best practice, stop and ask instead of guessing.
