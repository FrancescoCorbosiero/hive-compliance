<?php
/**
 * The Placeholders screen: inventory of every placeholder, inline editing, dashboard card.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Admin\PlaceholdersScreen;
use Hive\Compliance\Document\Document;
use Hive\Compliance\Placeholders\Inventory;

class PlaceholdersScreenTest extends Hive_TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->plugin()->settings()->update(
			array(
				'default_language'  => 'it',
				'enabled_languages' => array( 'it', 'en' ),
			)
		);
		$this->plugin()->profile()->save(
			array(
				'seller_country' => 'IT',
				'markets'        => array( 'IT' ),
				'customer_types' => array( 'b2c' ),
				'product_types'  => array( 'physical' ),
			)
		);
		$this->plugin()->business()->save(
			array(
				'legal_name' => 'Rossi Sneakers S.r.l.',
				'country'    => 'IT',
				'vat_id'     => '12345678901',
				'email'      => 'info@rossisneakers.it',
				'social'     => array(
					array(
						'network' => 'instagram',
						'url'     => 'https://instagram.com/rossisneakers',
					),
				),
				'custom'     => array(
					array(
						'key'   => 'return_address',
						'value' => 'Via Resi 2, Piacenza',
					),
				),
			)
		);
		$this->plugin()->repository()->save( 'terms_of_sale', 'it', "## Venditore {#seller_identity}\n\n{{business.legal_name}}, P.IVA {{business.vat_id}}, PEC {{business.pec}}. Resi: {{custom.return_address}} {{custom.typo}} {{shop.name}}. [Privacy]({{links.privacy_policy}})" );
		$this->plugin()->placeholders()->reset();
		$this->login_as( 'administrator' );
	}

	private function rows(): array {
		$out = array();
		foreach ( ( new Inventory( $this->plugin() ) )->rows( 'it' ) as $rows ) {
			foreach ( $rows as $row ) {
				$out[ $row['token'] ] = $row;
			}
		}
		return $out;
	}

	public function test_inventory_covers_every_namespace_with_value_status_and_usage() {
		$groups = ( new Inventory( $this->plugin() ) )->rows( 'it' );
		$this->assertSame( array( 'business', 'store', 'links', 'custom', 'unknown' ), array_keys( $groups ) );

		$rows = $this->rows();
		$this->assertSame( 'Rossi Sneakers S.r.l.', $rows['business.legal_name']['value'] );
		$this->assertSame( Inventory::OK, $rows['business.legal_name']['status'] );
		$this->assertSame( array( 'terms_of_sale' => 'Termini e condizioni di vendita' ), $rows['business.legal_name']['used_in'] );

		$this->assertSame( Inventory::INVALID, $rows['business.vat_id']['status'], 'Wrong check digit.' );
		$this->assertStringContainsString( 'partita IVA', $rows['business.vat_id']['message'] );
		$this->assertSame( Inventory::INVALID, $rows['business.address']['status'], 'Required and empty.' );
		$this->assertSame( Inventory::MISSING, $rows['business.pec']['status'] );
		$this->assertTrue( Inventory::needs_attention( $rows['business.pec'] ), 'Missing and used by a document.' );
		$this->assertFalse( Inventory::needs_attention( $rows['business.whatsapp'] ), 'Missing but unused: no action needed.' );
		$this->assertSame( 'https://instagram.com/rossisneakers', $rows['business.social_instagram']['value'] );
		$this->assertArrayNotHasKey( 'business.social_tiktok', $rows, 'Unset networks are not listed.' );

		$this->assertSame( '', $rows['store.site_url']['editable'] );
		$this->assertStringContainsString( 'options-general.php', $rows['store.site_url']['edit_url'] );

		$this->assertSame( 'link', $rows['links.privacy_policy']['editable'] );
		$this->assertSame( 'No page bound.', $rows['links.privacy_policy']['message'] );

		$this->assertSame( 'Via Resi 2, Piacenza', $rows['custom.return_address']['value'] );
		$this->assertSame( Inventory::UNKNOWN, $rows['custom.typo']['status'] );
		$this->assertSame( 'custom', $rows['custom.typo']['editable'], 'An undefined custom value can be defined in place.' );
		$this->assertSame( 'shop', $groups['unknown'][0]['namespace'], 'An unknown namespace gets its own group.' );
		$this->assertSame( 'shop.name', $groups['unknown'][0]['token'] );

		$summary = Inventory::summary( $groups );
		$this->assertGreaterThanOrEqual( 5, $summary['attention'] );
		$this->assertSame( 7, $summary['used'] );
	}

	public function test_screen_lists_tokens_with_copy_buttons_and_inputs() {
		$html = $this->render_screen( 'placeholders', array( 'lang' => 'it' ) );
		$this->assertStringContainsString( 'data-copy="{{business.vat_id}}"', $html );
		$this->assertStringContainsString( 'name="business[vat_id]" value="12345678901"', $html );
		$this->assertStringContainsString( 'name="custom[return_address]"', $html );
		$this->assertStringContainsString( 'name="links[privacy_policy]"', $html );
		$this->assertStringContainsString( 'name="custom_new[key]"', $html );
		$this->assertStringContainsString( '{{store.currency}}', $html );
		$this->assertStringContainsString( '{{shop.name}}', $html );
		$this->assertStringContainsString( 'value="hive_compliance_save_placeholders"', $html );
	}

	public function test_saving_updates_business_custom_values_and_page_links() {
		$page    = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$screen  = new PlaceholdersScreen( $this->plugin() );
		$notices = $screen->save(
			array(
				'business'   => array(
					'vat_id' => '01234567897',
					'pec'    => 'rossi@pec.it',
				),
				'custom'     => array(
					'return_address' => 'Via Nuova 3, Piacenza',
					'typo'           => '',
				),
				'custom_new' => array(
					'key'   => 'support_hours',
					'value' => 'Lun-Ven 9-18',
				),
				'links'      => array(
					'privacy_policy' => $page,
					'cookie_policy'  => 0,
				),
			),
			'it'
		);
		$this->assertSame( 'success', $notices[0][0] );

		$business = $this->plugin()->business();
		$this->assertSame( '01234567897', $business->value( 'vat_id' ) );
		$this->assertSame( 'rossi@pec.it', $business->value( 'pec' ) );
		$this->assertSame( 'Rossi Sneakers S.r.l.', $business->value( 'legal_name' ), 'Fields not posted are kept.' );
		$this->assertSame( array( 'instagram' => 'https://instagram.com/rossisneakers' ), $business->social(), 'Social links are kept.' );
		$this->assertSame(
			array(
				'return_address' => 'Via Nuova 3, Piacenza',
				'support_hours'  => 'Lun-Ven 9-18',
			),
			$business->custom(),
			'An undefined key posted empty is not created.'
		);
		$this->assertSame( $page, $this->plugin()->bindings()->get( 'privacy_policy', 'it' ) );
		$this->assertSame( 0, $this->plugin()->bindings()->get( 'cookie_policy', 'it' ) );

		// Removing a custom value, unbinding a page, and a page already bound elsewhere.
		$notices = $screen->save(
			array(
				'custom'        => array( 'return_address' => 'x' ),
				'custom_remove' => array( 'return_address' ),
				'links'         => array(
					'privacy_policy' => 0,
					'cookie_policy'  => $page,
				),
			),
			'it'
		);
		$this->assertSame( array(), $this->plugin()->business()->custom() );
		$this->assertSame( $page, $this->plugin()->bindings()->get( 'cookie_policy', 'it' ), 'Unbound first, then free to bind.' );
		$this->assertSame( 0, $this->plugin()->bindings()->get( 'privacy_policy', 'it' ) );

		$notices = $screen->save( array( 'links' => array( 'legal_notice' => $page ) ), 'it' );
		$this->assertSame( 'error', $notices[1][0], 'A page bound to another document is refused.' );
		$this->assertSame( 0, $this->plugin()->bindings()->get( 'legal_notice', 'it' ) );
	}

	public function test_dashboard_lists_placeholders_needing_attention() {
		$html = $this->render_screen( '' );
		$this->assertStringContainsString( '{{business.pec}}: missing, used in a document', $html );
		$this->assertStringContainsString( '{{custom.typo}}: Used in a document but not defined.', $html );
		$this->assertStringContainsString( 'page=hive-compliance-placeholders', $html );
	}
}
