<?php
/**
 * [hive_business], [hive_legal_links], their blocks, and the site page settings.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Document\Document;
use Hive\Compliance\Hooks;
use Hive\Compliance\Render\BusinessIdentity;

class SiteElementsTest extends Hive_TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->plugin()->settings()->update(
			array(
				'default_language'  => 'it',
				'enabled_languages' => array( 'it', 'en' ),
			)
		);
		$this->plugin()->business()->save(
			array(
				'trade_name' => 'Shoes Clothing Store',
				'legal_name' => 'Rossi Sneakers S.r.l.',
				'legal_form' => 'srl',
				'address'    => "Via Roma 1\n29121 Piacenza (PC)",
				'country'    => 'IT',
				'vat_id'     => '01234567897',
				'rea'        => 'PC-123456',
				'email'      => 'info@rossisneakers.it',
				'phone'      => '0523 123456',
			)
		);
	}

	/**
	 * Binds a published page to a document and sets the document status.
	 */
	private function publish( string $doc_id, string $status = Document::STATUS_PUBLISHED, string $page_status = 'publish' ): int {
		$this->plugin()->repository()->save( $doc_id, 'it', 'Testo.', array( 'status' => $status ) );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => $page_status,
				'post_title'  => $doc_id,
			)
		);
		$this->plugin()->bindings()->set( $doc_id, 'it', $page );
		$this->plugin()->repository()->flush();
		return $page;
	}

	public function test_identity_line_skips_empty_fields_and_their_labels() {
		$line = do_shortcode( '[hive_business]' );
		$this->assertSame( '<span class="hive-business-identity">Shoes Clothing Store — Rossi Sneakers S.r.l., Via Roma 1, 29121 Piacenza (PC), P.IVA 01234567897, REA PC-123456, E-mail info@rossisneakers.it, Tel. +390523123456</span>', $line );
		$this->assertStringNotContainsString( 'PEC', $line, 'No PEC: no "PEC" label either.' );

		$this->plugin()->business()->save(
			array(
				'legal_name' => 'Mario Rossi',
				'legal_form' => 'impresa_individuale',
				'pec'        => 'rossi@pec.it',
				'country'    => 'IT',
			)
		);
		$this->assertSame( '<span class="hive-business-identity">Mario Rossi, impresa individuale, PEC rossi@pec.it</span>', do_shortcode( '[hive_business]' ), 'Without a trade name there is no dash.' );

		$this->plugin()->business()->save( array() );
		$this->assertSame( '', do_shortcode( '[hive_business]' ), 'Nothing to show, nothing printed.' );
	}

	public function test_fields_are_chosen_with_attributes() {
		$this->assertSame( '01234567897', do_shortcode( '[hive_business field="vat_id"]' ) );
		$this->assertSame( '', do_shortcode( '[hive_business field="pec"]' ) );
		$this->assertSame( '', do_shortcode( '[hive_business field="no_such_field"]' ) );
		$this->assertSame( 'S.r.l.', do_shortcode( '[hive_business field="legal_form"]' ) );
		$this->assertSame( '<span class="hive-business-identity">Rossi Sneakers S.r.l., P.IVA 01234567897</span>', do_shortcode( '[hive_business fields="legal_name,vat_id"]' ) );
		$this->assertStringNotContainsString( 'Tel.', do_shortcode( '[hive_business exclude="phone,address"]' ) );
		$this->assertStringContainsString( 'VAT no. 01234567897', do_shortcode( '[hive_business fields="vat_id,phone" lang="en"]' ) );
		$this->assertStringContainsString( 'Phone +390523123456', do_shortcode( '[hive_business fields="vat_id,phone" lang="en"]' ) );

		$this->assertSame( array( 'vat_id', 'email' ), BusinessIdentity::fields_from( 'email, vat_id, bogus' ), 'Output order is fixed.' );
	}

	public function test_identity_is_escaped_and_filterable() {
		$this->plugin()->business()->save(
			array(
				'trade_name' => '<script>x</script>Shop & Co',
				'legal_name' => 'Rossi S.r.l.',
			)
		);
		$this->assertStringContainsString( 'Shop &amp; Co', do_shortcode( '[hive_business fields="trade_name"]' ) );
		$this->assertStringNotContainsString( '<script>', do_shortcode( '[hive_business]' ) );

		$filter = static function ( $parts ) {
			$parts['legal_name'] = 'Ragione sociale: ' . $parts['legal_name'];
			return $parts;
		};
		add_filter( Hooks::BUSINESS_IDENTITY, $filter );
		$this->assertStringContainsString( 'Ragione sociale: Rossi S.r.l.', do_shortcode( '[hive_business]' ) );
		remove_filter( Hooks::BUSINESS_IDENTITY, $filter );
	}

	public function test_business_identity_block_renders_the_chosen_fields() {
		$html = render_block(
			array(
				'blockName'    => 'hive/business-identity',
				'attrs'        => array( 'fields' => array( 'trade_name', 'vat_id' ) ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertStringContainsString( 'wp-block-hive-business-identity', $html );
		$this->assertStringContainsString( 'Shoes Clothing Store — P.IVA 01234567897', $html );
		$this->assertStringNotContainsString( 'REA', $html );
	}

	public function test_legal_links_list_published_bound_pages_in_catalog_order() {
		$privacy = $this->publish( 'privacy_policy' );
		$notice  = $this->publish( 'legal_notice' );
		$this->publish( 'cookie_policy', Document::STATUS_DRAFT );
		$this->publish( 'terms_of_sale', Document::STATUS_PUBLISHED, 'draft' );
		$this->plugin()->repository()->save( 'dispute_resolution', 'it', 'Testo.', array( 'status' => Document::STATUS_PUBLISHED ) );
		$shipping = $this->publish( 'shipping_policy' );

		$links = $this->plugin()->legal_links()->links( 'legal', 'it' );
		$this->assertSame( array( 'legal_notice', 'privacy_policy' ), array_column( $links, 'id' ), 'Draft document, draft page and unbound document are skipped; catalog order.' );
		$this->assertSame( 'Note legali', $links[0]['title'] );
		$this->assertSame( get_permalink( $notice ), $links[0]['url'] );

		$html = do_shortcode( '[hive_legal_links group="legal"]' );
		$this->assertSame( '<ul class="hive-legal-links hive-legal-links--legal"><li><a href="' . esc_url( get_permalink( $notice ) ) . '">Note legali</a></li><li><a href="' . esc_url( get_permalink( $privacy ) ) . '">Informativa sulla privacy</a></li></ul>', $html );

		$this->assertSame( array( 'shipping_policy' ), array_column( $this->plugin()->legal_links()->links( 'assistance', 'it' ), 'id' ) );
		$this->assertSame( array( 'legal_notice', 'privacy_policy', 'shipping_policy' ), array_column( $this->plugin()->legal_links()->links( '', 'it' ), 'id' ), 'No group: every page.' );
		$this->assertStringContainsString( get_permalink( $shipping ), do_shortcode( '[hive_legal_links group="assistance"]' ) );
	}

	public function test_legal_links_filter_reorders_and_relabels() {
		$this->publish( 'privacy_policy' );
		$this->publish( 'legal_notice' );
		$filter = static function ( array $links ) {
			$links = array_reverse( $links );
			foreach ( $links as &$link ) {
				if ( 'legal_notice' === $link['id'] ) {
					$link['title'] = 'Chi siamo';
				}
			}
			return $links;
		};
		add_filter( Hooks::LEGAL_LINKS, $filter );
		$links = $this->plugin()->legal_links()->links( 'legal', 'it' );
		remove_filter( Hooks::LEGAL_LINKS, $filter );
		$this->assertSame( array( 'privacy_policy', 'legal_notice' ), array_column( $links, 'id' ) );
		$this->assertSame( 'Chi siamo', $links[1]['title'] );

		$html = render_block(
			array(
				'blockName'    => 'hive/legal-links',
				'attrs'        => array( 'group' => 'legal' ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertStringContainsString( '<nav ', $html );
		$this->assertStringContainsString( 'hive-legal-links--legal', $html );
	}

	public function test_bound_privacy_page_can_become_the_wordpress_privacy_page() {
		$this->login_as( 'administrator' );
		$page = $this->publish( 'privacy_policy' );
		update_option( 'wp_page_for_privacy_policy', 0 );

		$html = $this->render_screen( 'documents', array( 'doc' => 'privacy_policy', 'lang' => 'it' ) );
		$this->assertStringContainsString( 'value="hive_compliance_set_site_page"', $html );
		$this->assertStringContainsString( 'Use as the WordPress privacy policy page', $html );

		$this->assertSame( $page, $this->plugin()->site_pages()->set( 'privacy_policy', 'it' ) );
		$this->assertSame( $page, (int) get_option( 'wp_page_for_privacy_policy' ) );
		$html = $this->render_screen( 'documents', array( 'doc' => 'privacy_policy', 'lang' => 'it' ) );
		$this->assertStringContainsString( 'This page is the WordPress privacy policy page.', $html );

		$this->assertNull( $this->plugin()->site_pages()->role( 'cookie_policy' ) );
		$this->assertSame( 0, $this->plugin()->site_pages()->set( 'cookie_policy', 'it' ) );
	}

	public function test_site_pages_need_the_capability_that_owns_the_setting() {
		$user = $this->login_as( 'editor' );
		get_user_by( 'id', $user )->add_cap( 'manage_hive_compliance' );
		$this->publish( 'privacy_policy' );
		update_option( 'wp_page_for_privacy_policy', 0 );

		$this->assertFalse( $this->plugin()->site_pages()->can_set( 'privacy_policy' ) );
		$this->assertSame( 0, $this->plugin()->site_pages()->set( 'privacy_policy', 'it' ) );
		$this->assertSame( 0, (int) get_option( 'wp_page_for_privacy_policy' ) );
		$this->assertStringNotContainsString( 'hive_compliance_set_site_page', $this->render_screen( 'documents', array( 'doc' => 'privacy_policy', 'lang' => 'it' ) ) );
	}

	public function test_terms_page_offer_follows_woocommerce() {
		$role = $this->plugin()->site_pages()->role( 'terms_of_sale' );
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->assertNull( $role, 'No WooCommerce, no terms page setting.' );
			return;
		}
		$this->login_as( 'administrator' );
		$page = $this->publish( 'terms_of_sale' );
		$this->assertSame( 'woocommerce_terms_page_id', $role['option'] );
		$this->assertSame( $page, $this->plugin()->site_pages()->set( 'terms_of_sale', 'it' ) );
		$this->assertSame( $page, (int) get_option( 'woocommerce_terms_page_id' ) );
	}
}
