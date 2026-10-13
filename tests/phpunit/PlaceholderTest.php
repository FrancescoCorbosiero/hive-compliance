<?php
/**
 * Placeholder resolution at render time.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Hooks;
use Hive\Compliance\Placeholders\Resolver;
use Hive\Compliance\Placeholders\Syntax;

class PlaceholderTest extends Hive_TestCase {

	private function resolver(): Resolver {
		$this->plugin()->placeholders()->reset();
		$this->plugin()->store_facts()->reset();
		return $this->plugin()->resolver();
	}

	private function context( string $lang = 'it' ): array {
		return array(
			'lang'   => $lang,
			'doc_id' => 'terms_of_sale',
		);
	}

	public function test_business_values_resolve_and_are_markdown_escaped() {
		$this->plugin()->business()->save(
			array(
				'legal_name' => 'Rossi_Shop *Best* [x] S.r.l.',
				'country'    => 'IT',
				'address'    => "Via Roma 1\n20100 Milano",
			)
		);
		$resolution = $this->resolver()->resolve( 'Seller: {{business.legal_name}}, {{business.address}} ({{business.country}})', $this->context() );

		$this->assertSame( 'Seller: Rossi\_Shop \*Best\* \[x\] S.r.l., Via Roma 1, 20100 Milano (Italy)', $resolution->markdown );
		$this->assertSame( array(), $resolution->missing );
		$this->assertSame( array( 'business.legal_name', 'business.address', 'business.country' ), $resolution->used );
	}

	public function test_missing_and_unknown_values_become_sentinels() {
		$resolution = $this->resolver()->resolve( 'VAT {{business.vat_id}} / {{business.vat_id}} / {{foo.bar}} / {{business.nope}}', $this->context() );
		$this->assertSame( array( 'business.vat_id', 'foo.bar', 'business.nope' ), $resolution->missing );
		$this->assertStringNotContainsString( '{{', $resolution->markdown );
		$this->assertSame( 4, substr_count( $resolution->markdown, Resolver::SENTINEL_OPEN ) );
	}

	public function test_missing_values_render_nothing_for_visitors_and_a_marker_for_managers() {
		$html = '<p>VAT: ' . Resolver::SENTINEL_OPEN . '0' . Resolver::SENTINEL_CLOSE . '</p>';
		$this->assertSame( '<p>VAT: </p>', Resolver::finalize( $html, array( 'business.vat_id' ), false ) );
		$this->assertSame( '<p>VAT: <mark class="hive-missing">[missing: business.vat_id]</mark></p>', Resolver::finalize( $html, array( 'business.vat_id' ), true ) );
	}

	public function test_links_with_missing_urls_are_unwrapped() {
		$html = '<p>See <a href="' . Resolver::SENTINEL_OPEN . '0' . Resolver::SENTINEL_CLOSE . '">the privacy policy</a>.</p>';
		$this->assertSame( '<p>See the privacy policy.</p>', Resolver::finalize( $html, array( 'links.privacy_policy' ), false ) );
		$this->assertStringContainsString( 'the privacy policy<mark class="hive-missing">[missing: links.privacy_policy]</mark>', Resolver::finalize( $html, array( 'links.privacy_policy' ), true ) );
		$this->assertStringNotContainsString( '<a', Resolver::finalize( $html, array( 'links.privacy_policy' ), true ) );
	}

	public function test_links_resolve_only_to_published_bound_pages() {
		$bindings = $this->plugin()->bindings();
		$resolver = $this->resolver();
		$this->assertNull( $resolver->value( 'links.privacy_policy', $this->context() ) );

		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$bindings->set( 'privacy_policy', 'it', $draft );
		$this->assertNull( $resolver->value( 'links.privacy_policy', $this->context() ), 'Draft pages are dead links for visitors.' );

		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$bindings->set( 'privacy_policy', 'it', $page );
		$this->assertSame( get_permalink( $page ), $resolver->value( 'links.privacy_policy', $this->context() ) );

		$this->plugin()->settings()->update(
			array(
				'default_language'  => 'it',
				'enabled_languages' => array( 'it', 'en' ),
			)
		);
		$this->assertSame( get_permalink( $page ), $resolver->value( 'links.privacy_policy', $this->context( 'en' ) ), 'Falls back to the default language.' );
		$this->assertNull( $resolver->value( 'links.privacy_policy_x', $this->context() ) );
		$this->assertNull( $resolver->value( 'links.frag_age_gate', $this->context() ), 'Fragments have no page.' );
	}

	public function test_custom_and_store_values() {
		$this->plugin()->business()->save(
			array(
				'custom' => array(
					array(
						'key'   => 'return_days',
						'value' => '30',
					),
				),
			)
		);
		$resolver = $this->resolver();
		$this->assertSame( '30', $resolver->value( 'custom.return_days', $this->context() ) );
		$this->assertNull( $resolver->value( 'custom.unknown', $this->context() ) );
		$this->assertSame( home_url( '/' ), $resolver->value( 'store.site_url', $this->context() ) );
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->assertNull( $resolver->value( 'store.currency', $this->context() ), 'Shop facts are empty without WooCommerce.' );
		}
	}

	public function test_value_filter_can_supply_values() {
		$filter = static fn( $value, $ns, $key ) => 'business' === $ns && 'pec' === $key ? 'pec@example.it' : $value;
		add_filter( Hooks::PLACEHOLDER_VALUE, $filter, 10, 3 );
		$value  = $this->resolver()->value( 'business.pec', $this->context() );
		remove_filter( Hooks::PLACEHOLDER_VALUE, $filter, 10 );
		$this->assertSame( 'pec@example.it', $value );
	}

	public function test_text_and_url_escaping() {
		$this->assertSame( '\# Not a heading', Resolver::escape_text( '# Not a heading' ) );
		$this->assertSame( '1\. Not a list', Resolver::escape_text( '1. Not a list' ) );
		$this->assertSame( '\- dash', Resolver::escape_text( '- dash' ) );
		$this->assertSame( 'a\\\\b', Resolver::escape_text( 'a\\b' ) );
		$this->assertSame( 'https://example.com/a%20b%28c%29', Resolver::escape_url( 'https://example.com/a b(c)' ) );
		$this->assertSame( '', Resolver::escape_url( 'javascript:alert(1)' ) );
	}

	public function test_syntax_finds_tokens_and_malformed_placeholders() {
		$markdown = "{{business.vat_id}} {{ business.vat_id }} {{Business.Vat}}\n{{vat_id}} {{links.terms_of_sale}} {{business.email";
		$this->assertSame( array( 'business.vat_id', 'links.terms_of_sale' ), Syntax::tokens( $markdown ) );
		$this->assertSame( array( '{{ business.vat_id }}', '{{Business.Vat}}', '{{vat_id}}', '{{business.email' ), Syntax::malformed( $markdown ) );
	}

	public function test_resolution_never_touches_stored_content() {
		$this->login_as( 'administrator' );
		$doc = $this->plugin()->repository()->save( 'legal_notice', 'it', 'P.IVA {{business.vat_id}}' );
		$this->resolver()->resolve( $doc->markdown, $this->context() );
		$this->plugin()->repository()->flush();
		$this->assertSame( 'P.IVA {{business.vat_id}}', $this->plugin()->repository()->get( 'legal_notice', 'it' )->markdown );
	}
}
