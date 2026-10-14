<?php
/**
 * Business details in widgets, menus and template parts compared with Business Data.
 *
 * @package Hive\Compliance
 */

class IdentityScanTest extends Hive_TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->plugin()->business()->save(
			array(
				'legal_name' => 'Rossi Sneakers S.r.l.',
				'country'    => 'IT',
				'vat_id'     => '01234567897',
				'email'      => 'info@rossisneakers.it',
				'pec'        => 'rossisneakers@pec.it',
			)
		);
	}

	/**
	 * Details of the identity warning ([] when there is none).
	 */
	private function mismatches(): array {
		foreach ( $this->plugin()->store_checks()->warnings() as $warning ) {
			if ( 'identity_mismatch' === $warning['id'] ) {
				return $warning['details'];
			}
		}
		return array();
	}

	public function test_widgets_and_menus_with_other_details_are_flagged() {
		update_option(
			'sidebars_widgets',
			array(
				'footer-1'            => array( 'text-2', 'block-3' ),
				'wp_inactive_widgets' => array( 'text-9' ),
				'array_version'       => 3,
			)
		);
		update_option(
			'widget_text',
			array(
				2 => array(
					'title' => 'Contatti',
					'text'  => 'Rossi Sneakers – P.IVA IT 12345678903 – PEC: vecchia@pec.it – <a href="mailto:ordini@oldshop.it">scrivici</a>',
				),
				9 => array( 'text' => 'P.IVA 99999999999 (inactive)' ),
			)
		);
		update_option( 'widget_block', array( 3 => array( 'content' => '<!-- wp:paragraph --><p>Partita IVA: 01234567897 · <a href="mailto:info@rossisneakers.it">info@rossisneakers.it</a> · PEC rossisneakers@pec.it</p><!-- /wp:paragraph -->' ) ) );

		$menu = wp_create_nav_menu( 'Footer' );
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Assistenza',
				'menu-item-url'    => 'mailto:help@example-shop.com',
				'menu-item-status' => 'publish',
			)
		);

		$found = $this->mismatches();
		$text  = implode( "\n", $found );
		$this->assertStringContainsString( 'VAT number IT 12345678903 (Business Data: 01234567897)', $text );
		$this->assertStringContainsString( 'PEC vecchia@pec.it (Business Data: rossisneakers@pec.it)', $text );
		$this->assertStringContainsString( 'e-mail ordini@oldshop.it (not in Business Data)', $text );
		$this->assertStringContainsString( 'Menu “Footer”: e-mail help@example-shop.com', $text );
		$this->assertStringNotContainsString( '99999999999', $text, 'Inactive widgets are ignored.' );
		$this->assertStringNotContainsString( 'info@rossisneakers.it', $text, 'Matching values are fine.' );
		$this->assertStringNotContainsString( '(block-3)', $text, 'The block widget matches Business Data.' );
	}

	public function test_footer_template_part_is_scanned() {
		self::factory()->post->create(
			array(
				'post_type'    => 'wp_template_part',
				'post_name'    => 'footer',
				'post_title'   => 'Footer',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>P.IVA 11111111115</p><!-- /wp:paragraph -->',
			)
		);
		$this->assertStringContainsString( 'Template part “Footer”: VAT number 11111111115', implode( "\n", $this->mismatches() ) );
	}

	public function test_nothing_to_report_when_everything_matches() {
		update_option( 'sidebars_widgets', array( 'footer-1' => array( 'text-2' ) ) );
		update_option( 'widget_text', array( 2 => array( 'text' => 'P.IVA IT01234567897 · info@rossisneakers.it · PEC: rossisneakers@pec.it' ) ) );
		$this->assertSame( array(), $this->mismatches() );
	}
}
