<?php
/**
 * Business data: legal form, sole-trader requirements, PEC check; profile wording.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Business\BusinessData;
use Hive\Compliance\Business\Check;
use Hive\Compliance\Business\Validator;

class BusinessDataTest extends Hive_TestCase {

	const BASE = array(
		'legal_name' => 'Rossi Sneakers',
		'address'    => 'Via Roma 1, 29121 Piacenza',
		'country'    => 'IT',
		'email'      => 'info@rossisneakers.it',
		'vat_id'     => '01234567897',
	);

	private function resolve( string $token ): ?string {
		$this->plugin()->placeholders()->reset();
		return $this->plugin()->resolver()->value( $token, array( 'lang' => 'it' ) );
	}

	public function test_legal_form_is_a_choice_with_a_display_text() {
		$this->assertSame( 'srl', Validator::legal_form( 'S.r.l.' )->value );
		$this->assertSame( 'srls', Validator::legal_form( 'srls' )->value );
		$this->assertSame( 'impresa_individuale', Validator::legal_form( 'Ditta individuale' )->value );
		$this->assertSame( 'spa', Validator::legal_form( 'S.p.A.' )->value );
		$this->assertSame( 'other', Validator::legal_form( 'Società cooperativa' )->value );
		$this->assertSame( '', Validator::legal_form( '' )->value );
		$this->assertSame( array( 'impresa_individuale', 'snc', 'sas', 'srl', 'srls', 'spa', 'other' ), array_keys( BusinessData::legal_forms() ) );

		$this->plugin()->business()->save( array_merge( self::BASE, array( 'legal_form' => 'srl' ) ) );
		$this->assertSame( 'S.r.l.', $this->resolve( 'business.legal_form' ) );

		// Free text from before the dropdown is kept as "other".
		$this->plugin()->business()->save( array_merge( self::BASE, array( 'legal_form' => 'Società cooperativa' ) ) );
		$this->assertSame( 'other', $this->plugin()->business()->value( 'legal_form' ) );
		$this->assertSame( 'Società cooperativa', $this->plugin()->business()->value( 'legal_form_other' ) );
		$this->assertSame( 'Società cooperativa', $this->resolve( 'business.legal_form' ) );
	}

	public function test_sole_trader_needs_owner_name_and_personal_tax_code() {
		$business = $this->plugin()->business();
		$business->save( array_merge( self::BASE, array( 'legal_form' => 'srl' ) ) );
		$this->assertSame( array(), $business->issues( 'IT' ), 'Owner and tax code are optional for a company.' );

		$business->save( array_merge( self::BASE, array( 'legal_form' => 'impresa_individuale' ) ) );
		$issues = $business->issues( 'IT' );
		$this->assertSame( array( 'owner_name', 'tax_code' ), array_keys( $issues ) );
		$this->assertStringContainsString( 'impresa individuale', $issues['tax_code']['message'] );

		// The company-style 11-digit code is not enough: the owner's 16-character code is needed.
		$checks = $business->save(
			array_merge(
				self::BASE,
				array(
					'legal_form' => 'impresa_individuale',
					'owner_name' => 'Mario Rossi',
					'tax_code'   => '01234567897',
				)
			)
		);
		$this->assertTrue( $checks['tax_code']->is_error() );
		$this->assertStringContainsString( '16', $checks['tax_code']->message );

		$checks = $business->save(
			array_merge(
				self::BASE,
				array(
					'legal_form' => 'impresa_individuale',
					'owner_name' => 'Mario Rossi',
					'tax_code'   => 'RSSMRA85T10A562X',
				)
			)
		);
		$this->assertTrue( $checks['tax_code']->is_error(), 'Wrong check character.' );

		$business->save(
			array_merge(
				self::BASE,
				array(
					'legal_form' => 'impresa_individuale',
					'owner_name' => 'Mario Rossi',
					'tax_code'   => 'rssmra85t10a562s',
				)
			)
		);
		$this->assertSame( array(), $business->issues( 'IT' ) );
		$this->assertSame( 'RSSMRA85T10A562S', $business->value( 'tax_code' ) );
	}

	public function test_pec_on_a_webmail_domain_is_a_warning() {
		foreach ( array( 'rossi@gmail.com', 'rossi@yahoo.it', 'rossi@hotmail.it', 'rossi@outlook.com', 'rossi@libero.it', 'rossi@icloud.com' ) as $address ) {
			$check = Validator::pec( $address );
			$this->assertSame( Check::WARNING, $check->severity, $address );
			$this->assertSame( $address, $check->value, 'Saved anyway.' );
		}
		foreach ( array( 'rossi@pec.it', 'rossi@legalmail.it', 'rossi@pec.libero.it', '' ) as $address ) {
			$this->assertSame( Check::OK, Validator::pec( $address )->severity, $address );
		}
		$this->assertSame( Check::ERROR, Validator::pec( 'not an address' )->severity );

		$checks = $this->plugin()->business()->save( array_merge( self::BASE, array( 'pec' => 'rossi@gmail.com' ) ) );
		$this->assertSame( Check::WARNING, $checks['pec']->severity );
		$this->assertSame( 'rossi@gmail.com', $this->plugin()->business()->value( 'pec' ) );
		$this->assertSame( Check::OK, Validator::email( 'rossi@gmail.com' )->severity, 'Only the PEC field warns.' );
	}

	public function test_business_screen_offers_the_legal_form_dropdown() {
		$this->login_as( 'administrator' );
		$this->plugin()->business()->save( array_merge( self::BASE, array( 'legal_form' => 'impresa_individuale' ) ) );
		$html = $this->render_screen( 'business' );
		$this->assertMatchesRegularExpression( '~<select id="hive-business-legal_form" name="business\[legal_form\]">~', $html );
		$this->assertStringContainsString( '<option value="impresa_individuale" selected=\'selected\'>', $html );
		$this->assertStringContainsString( 'Required for an impresa individuale.', $html );
	}

	public function test_profile_explains_the_micro_enterprise_definition() {
		$this->login_as( 'administrator' );
		$html = $this->render_screen( 'profile', array( 'step' => 'seller' ) );
		$this->assertStringContainsString( 'fewer than 10 staff, and annual turnover or balance sheet total up to €2 million', $html );
		$this->assertStringContainsString( 'aria-describedby="hive-micro-definition"', $html );
	}
}
