<?php
/**
 * Business data validation: P.IVA checksum, codice fiscale, EU VAT, phone, e-mail.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Business\BusinessData;
use Hive\Compliance\Business\Check;
use Hive\Compliance\Business\Validator;

class ValidatorTest extends Hive_TestCase {

	/**
	 * Valid numbers computed with the official check-digit algorithm.
	 */
	public function valid_partite_iva(): array {
		return array( array( '01234567897' ), array( '12345678903' ), array( '00743110157' ), array( '02154320960' ), array( '10234567898' ) );
	}

	/**
	 * @dataProvider valid_partite_iva
	 */
	public function test_valid_partita_iva( string $value ) {
		$this->assertTrue( Validator::is_valid_partita_iva( $value ) );
		$this->assertFalse( Validator::vat_id( $value, 'IT' )->is_error() );
		$this->assertFalse( Validator::vat_id( 'IT ' . $value, 'DE' )->is_error() );
	}

	public function invalid_partite_iva(): array {
		return array(
			'wrong check digit'      => array( '01234567890' ),
			'wrong check digit 2'    => array( '12345678901' ),
			'10 digits'              => array( '1234567890' ),
			'12 digits (audit case)' => array( '123456789012' ),
			'letter'                 => array( '0123456789A' ),
			'all zeros'              => array( '00000000000' ),
		);
	}

	/**
	 * @dataProvider invalid_partite_iva
	 */
	public function test_invalid_partita_iva( string $value ) {
		$this->assertFalse( Validator::is_valid_partita_iva( $value ) );
		$this->assertTrue( Validator::vat_id( $value, 'IT' )->is_error() );
	}

	public function test_codice_fiscale() {
		$this->assertTrue( Validator::is_valid_codice_fiscale( 'RSSMRA80A01H501U' ) );
		$this->assertTrue( Validator::is_valid_codice_fiscale( 'bnclra85m41f205c' ) );
		$this->assertTrue( Validator::is_valid_codice_fiscale( '01234567897' ) );
		$this->assertFalse( Validator::is_valid_codice_fiscale( 'RSSMRA80A01H501X' ) );
		$this->assertFalse( Validator::is_valid_codice_fiscale( 'RSSMRA80A01H50' ) );
		$this->assertTrue( Validator::tax_code( 'RSSMRA80A01H501X', 'IT' )->is_error() );
		$this->assertFalse( Validator::tax_code( 'anything', 'DE' )->is_error() );
	}

	public function test_eu_vat_formats_by_prefix() {
		$valid = array( 'DE123456789', 'FR12345678901', 'ESX1234567X', 'NL123456789B01', 'ATU12345678', 'EL123456789', 'GR123456789', 'SE123456789001', 'CHE-123.456.789 MWST', 'GB123456789' );
		foreach ( $valid as $vat ) {
			$this->assertSame( Check::OK, Validator::vat_id( $vat, 'IT' )->severity, $vat );
		}
		foreach ( array( 'DE12345678', 'FR1234', 'NL123456789', 'ATU1234567', 'IT123' ) as $vat ) {
			$this->assertTrue( Validator::vat_id( $vat, 'IT' )->is_error(), $vat );
		}
		$this->assertSame( 'EL123456789', Validator::vat_id( 'GR 123 456 789', 'IT' )->value );
		$this->assertSame( Check::WARNING, Validator::vat_id( '12-3456789', 'US' )->severity );
	}

	public function test_phone_is_normalised_to_e164() {
		$this->assertSame( '+390212345678', Validator::phone( '02 1234 5678', 'IT' )->value );
		$this->assertSame( '+393331234567', Validator::phone( '333 123 4567', 'IT' )->value );
		$this->assertSame( '+393331234567', Validator::phone( '0039 333-123-4567', 'IT' )->value );
		$this->assertSame( '+4930123456', Validator::phone( '030 123456', 'DE' )->value );
		$this->assertSame( '+441234567890', Validator::phone( '+44 (0)1234 567890', 'IT' )->value );
		$this->assertTrue( Validator::phone( '12', 'IT' )->is_error() );
		$this->assertTrue( Validator::phone( '0212345678', 'XX' )->is_error() );
	}

	public function test_email_and_rea() {
		$this->assertFalse( Validator::email( 'Info@Shop.it' )->is_error() );
		$this->assertSame( 'info@shop.it', Validator::email( 'Info@Shop.it' )->value );
		$this->assertTrue( Validator::email( 'info@@shop' )->is_error() );
		$this->assertTrue( Validator::email( 'info shop.it' )->is_error() );
		$this->assertSame( 'MI-1234567', Validator::rea( 'mi 1234567', 'IT' )->value );
		$this->assertTrue( Validator::rea( '1234567', 'IT' )->is_error() );
	}

	public function test_saving_never_blocks_and_reports_issues() {
		$business = $this->plugin()->business();
		$checks   = $business->save(
			array(
				'legal_name' => 'Rossi & Figli S.r.l.',
				'vat_id'     => '123456789012',
				'email'      => 'info@@rossi',
				'phone'      => '02 1234 5678',
				'country'    => 'IT',
				'social'     => array(
					array(
						'network' => 'instagram',
						'url'     => 'https://instagram.com/rossi',
					),
					array(
						'network' => 'facebook',
						'url'     => 'javascript:alert(1)',
					),
				),
				'custom'     => array(
					array(
						'key'   => 'return_days',
						'value' => '30',
					),
					array(
						'key'   => 'Bad Key!',
						'value' => 'x',
					),
				),
			)
		);

		$this->assertArrayHasKey( 'vat_id', $checks );
		$this->assertArrayHasKey( 'email', $checks );
		$this->assertArrayHasKey( 'social_1', $checks );
		$this->assertSame( '123456789012', $business->value( 'vat_id' ), 'Invalid values are still saved.' );
		$this->assertSame( '+390212345678', $business->value( 'phone' ) );
		$this->assertSame( array( 'return_days' => '30' ), $business->custom() );
		$this->assertSame( array( 'instagram' => 'https://instagram.com/rossi' ), $business->social() );

		$issues = $business->issues();
		$this->assertArrayHasKey( 'vat_id', $issues );
		$this->assertArrayHasKey( 'address', $issues, 'Missing required field is reported.' );
		$this->assertArrayNotHasKey( 'legal_name', $issues );
		$this->assertArrayHasKey( 'legal_name', BusinessData::fields() );
	}
}
