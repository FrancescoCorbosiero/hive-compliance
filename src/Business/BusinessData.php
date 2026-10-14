<?php
/**
 * Business data (option hive_business): the business.* and custom.* placeholders.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Business;

defined( 'ABSPATH' ) || exit;

/**
 * Stores, normalises and validates business data. Validation never blocks saving.
 */
final class BusinessData {

	const OPTION = 'hive_business';

	/**
	 * Fields that the legal notice cannot do without.
	 */
	const REQUIRED = array( 'legal_name', 'address', 'country', 'email', 'vat_id' );

	/**
	 * Legal form that also needs the owner's name and personal codice fiscale.
	 */
	const SOLE_TRADER = 'impresa_individuale';

	/**
	 * Social networks offered in the repeater (any other slug is allowed too).
	 */
	const NETWORKS = array( 'instagram', 'facebook', 'tiktok', 'youtube', 'linkedin', 'x', 'pinterest', 'threads', 'whatsapp', 'telegram' );

	/**
	 * Field definitions: key => {group, label, type}.
	 *
	 * @return array<string, array{group: string, label: string, type: string}>
	 */
	public static function fields(): array {
		$fields = array(
			'legal_name'        => array( 'identity', __( 'Legal name', 'hive-compliance' ), 'text' ),
			'trade_name'        => array( 'identity', __( 'Trade name (shop name)', 'hive-compliance' ), 'text' ),
			'legal_form'        => array( 'identity', __( 'Legal form', 'hive-compliance' ), 'legal_form' ),
			'legal_form_other'  => array( 'identity', __( 'Legal form, if other', 'hive-compliance' ), 'text' ),
			'owner_name'        => array( 'identity', __( 'Owner or legal representative', 'hive-compliance' ), 'text' ),
			'vat_id'            => array( 'registration', __( 'VAT number', 'hive-compliance' ), 'vat' ),
			'tax_code'          => array( 'registration', __( 'Tax code (codice fiscale)', 'hive-compliance' ), 'tax_code' ),
			'rea'               => array( 'registration', __( 'REA / business register number', 'hive-compliance' ), 'rea' ),
			'share_capital'     => array( 'registration', __( 'Share capital (paid up)', 'hive-compliance' ), 'text' ),
			'address'           => array( 'address', __( 'Registered office address', 'hive-compliance' ), 'textarea' ),
			'country'           => array( 'address', __( 'Country', 'hive-compliance' ), 'country' ),
			'email'             => array( 'contacts', __( 'E-mail', 'hive-compliance' ), 'email' ),
			'pec'               => array( 'contacts', __( 'Certified e-mail (PEC)', 'hive-compliance' ), 'email' ),
			'phone'             => array( 'contacts', __( 'Phone', 'hive-compliance' ), 'phone' ),
			'whatsapp'          => array( 'contacts', __( 'WhatsApp', 'hive-compliance' ), 'phone' ),
			'support_hours'     => array( 'contacts', __( 'Support hours', 'hive-compliance' ), 'text' ),
			'dpo_name'          => array( 'privacy', __( 'Data protection officer (DPO)', 'hive-compliance' ), 'text' ),
			'dpo_email'         => array( 'privacy', __( 'DPO e-mail', 'hive-compliance' ), 'email' ),
			'eu_representative' => array( 'privacy', __( 'EU representative (non-EU businesses)', 'hive-compliance' ), 'textarea' ),
		);

		$out = array();
		foreach ( $fields as $key => list( $group, $label, $type ) ) {
			$out[ $key ] = array(
				'group' => $group,
				'label' => $label,
				'type'  => $type,
			);
		}
		return $out;
	}

	/**
	 * Italian legal forms: key => label in the dropdown.
	 *
	 * @return array<string, string>
	 */
	public static function legal_forms(): array {
		return array(
			self::SOLE_TRADER => __( 'Impresa individuale (sole trader)', 'hive-compliance' ),
			'snc'             => __( 'S.n.c. – società in nome collettivo', 'hive-compliance' ),
			'sas'             => __( 'S.a.s. – società in accomandita semplice', 'hive-compliance' ),
			'srl'             => __( 'S.r.l. – società a responsabilità limitata', 'hive-compliance' ),
			'srls'            => __( 'S.r.l.s. – S.r.l. semplificata', 'hive-compliance' ),
			'spa'             => __( 'S.p.A. – società per azioni', 'hive-compliance' ),
			'other'           => __( 'Other (specify below)', 'hive-compliance' ),
		);
	}

	/**
	 * The legal form as written in documents ({{business.legal_form}}).
	 */
	public function legal_form_text(): string {
		$names = array(
			self::SOLE_TRADER => 'impresa individuale',
			'snc'             => 'S.n.c.',
			'sas'             => 'S.a.s.',
			'srl'             => 'S.r.l.',
			'srls'            => 'S.r.l.s.',
			'spa'             => 'S.p.A.',
		);
		$form  = $this->value( 'legal_form' );
		return $names[ $form ] ?? ( 'other' === $form ? $this->value( 'legal_form_other' ) : '' );
	}

	/**
	 * Required fields for the given data: the legal-notice basics, plus the owner's name and
	 * codice fiscale for an impresa individuale.
	 *
	 * @param array<string, mixed> $data Business data.
	 * @return string[]
	 */
	public static function required( array $data ): array {
		$required = self::REQUIRED;
		if ( self::SOLE_TRADER === ( $data['legal_form'] ?? '' ) ) {
			$required[] = 'owner_name';
			$required[] = 'tax_code';
		}
		return $required;
	}

	/**
	 * Group labels.
	 *
	 * @return array<string, string>
	 */
	public static function groups(): array {
		return array(
			'identity'     => __( 'Identity', 'hive-compliance' ),
			'registration' => __( 'Registration', 'hive-compliance' ),
			'address'      => __( 'Address', 'hive-compliance' ),
			'contacts'     => __( 'Contacts', 'hive-compliance' ),
			'privacy'      => __( 'Privacy roles', 'hive-compliance' ),
		);
	}

	/**
	 * Empty data set.
	 *
	 * @return array<string, mixed>
	 */
	public static function blank(): array {
		$blank = array_fill_keys( array_keys( self::fields() ), '' );

		$blank['social'] = array();
		$blank['custom'] = array();
		return $blank;
	}

	/**
	 * Stored data merged over the blank set.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::blank(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One scalar field.
	 *
	 * @param string $key Field key.
	 */
	public function value( string $key ): string {
		$data = $this->get();
		return is_scalar( $data[ $key ] ?? null ) ? (string) $data[ $key ] : '';
	}

	/**
	 * Social links keyed by network slug.
	 *
	 * @return array<string, string>
	 */
	public function social(): array {
		$out = array();
		foreach ( (array) $this->get()['social'] as $row ) {
			if ( ! empty( $row['network'] ) && ! empty( $row['url'] ) ) {
				$out[ (string) $row['network'] ] = (string) $row['url'];
			}
		}
		return $out;
	}

	/**
	 * Custom placeholders keyed by key.
	 *
	 * @return array<string, string>
	 */
	public function custom(): array {
		$out = array();
		foreach ( (array) $this->get()['custom'] as $row ) {
			if ( isset( $row['key'] ) && '' !== (string) $row['key'] ) {
				$out[ (string) $row['key'] ] = (string) ( $row['value'] ?? '' );
			}
		}
		return $out;
	}

	/**
	 * Normalises, validates and stores the data. Invalid values are kept (and reported).
	 *
	 * @param array<string, mixed> $input          Raw input.
	 * @param string               $seller_country Fallback country for VAT and phone checks.
	 * @return array<string, Check> Field => check result, for inline messages.
	 */
	public function save( array $input, string $seller_country = '' ): array {
		list( $data, $checks ) = $this->normalize( $input, $seller_country );
		update_option( self::OPTION, $data, false );
		return $checks;
	}

	/**
	 * Validation results of the stored data.
	 *
	 * @param string $seller_country Fallback country.
	 * @return array<string, Check>
	 */
	public function checks( string $seller_country = '' ): array {
		return $this->normalize( $this->get(), $seller_country )[1];
	}

	/**
	 * Problems to list on the dashboard: invalid values and missing required fields.
	 *
	 * @param string $seller_country Fallback country.
	 * @return array<string, array{field: string, label: string, severity: string, message: string}>
	 */
	public function issues( string $seller_country = '' ): array {
		$fields = self::fields();
		$data   = $this->get();
		$issues = array();
		foreach ( self::required( $data ) as $key ) {
			if ( '' === trim( (string) $data[ $key ] ) ) {
				$issues[ $key ] = array(
					'field'    => $key,
					'label'    => $fields[ $key ]['label'],
					'severity' => Check::ERROR,
					'message'  => in_array( $key, self::REQUIRED, true ) ? __( 'Missing.', 'hive-compliance' ) : __( 'Missing: required for an impresa individuale.', 'hive-compliance' ),
				);
			}
		}
		foreach ( $this->checks( $seller_country ) as $key => $check ) {
			if ( Check::OK !== $check->severity && ! isset( $issues[ $key ] ) ) {
				$issues[ $key ] = array(
					'field'    => $key,
					'label'    => $fields[ $key ]['label'] ?? $key,
					'severity' => $check->severity,
					'message'  => $check->message,
				);
			}
		}
		return $issues;
	}

	/**
	 * Sanitises, normalises and validates input.
	 *
	 * @param array<string, mixed> $input          Raw input.
	 * @param string               $seller_country Fallback country.
	 * @return array{0: array<string, mixed>, 1: array<string, Check>}
	 */
	private function normalize( array $input, string $seller_country ): array {
		$data    = self::blank();
		$checks  = array();
		$country = strtoupper( sanitize_text_field( (string) ( $input['country'] ?? '' ) ) );
		$country = preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
		$context = '' !== $country ? $country : strtoupper( $seller_country );

		foreach ( self::fields() as $key => $field ) {
			$raw = is_scalar( $input[ $key ] ?? null ) ? (string) $input[ $key ] : '';
			$raw = 'textarea' === $field['type'] ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );

			$check = match ( $field['type'] ) {
				'vat'        => Validator::vat_id( $raw, $context ),
				'tax_code'   => Validator::tax_code( $raw, $context ),
				'rea'        => Validator::rea( $raw, $context ),
				'email'      => 'pec' === $key ? Validator::pec( $raw ) : Validator::email( $raw ),
				'phone'      => Validator::phone( $raw, $context ),
				'country'    => Check::ok( $country ),
				'legal_form' => Validator::legal_form( $raw ),
				default      => Check::ok( trim( $raw ) ),
			};

			$data[ $key ] = $check->value;
			if ( Check::OK !== $check->severity ) {
				$checks[ $key ] = $check;
			}
		}

		// Free text saved before the dropdown existed is kept as "other".
		if ( 'other' === $data['legal_form'] && '' === $data['legal_form_other'] && ! isset( Validator::LEGAL_FORMS[ strtolower( trim( (string) ( $input['legal_form'] ?? '' ) ) ) ] ) ) {
			$data['legal_form_other'] = trim( sanitize_text_field( (string) ( $input['legal_form'] ?? '' ) ) );
		}
		$personal = 16 === strlen( $data['tax_code'] ) && Validator::is_valid_codice_fiscale( $data['tax_code'] );
		if ( self::SOLE_TRADER === $data['legal_form'] && '' !== $data['tax_code'] && ! $personal ) {
			$checks['tax_code'] = Check::error( $data['tax_code'], __( 'For an impresa individuale, enter the owner\'s personal codice fiscale (16 characters).', 'hive-compliance' ) );
		}

		foreach ( (array) ( $input['social'] ?? array() ) as $index => $row ) {
			$network = sanitize_key( (string) ( $row['network'] ?? '' ) );
			$raw_url = trim( (string) ( $row['url'] ?? '' ) );
			$url     = Validator::url( $raw_url );
			if ( '' === $network && '' === $raw_url ) {
				continue;
			}
			$data['social'][] = array(
				'network' => $network,
				'url'     => $url->value,
			);
			if ( $url->is_error() || '' === $network ) {
				$checks[ 'social_' . $index ] = $url->is_error() ? $url : Check::error( $url->value, __( 'Choose the network.', 'hive-compliance' ) );
			}
		}

		$seen = array();
		foreach ( (array) ( $input['custom'] ?? array() ) as $index => $row ) {
			$key   = trim( sanitize_text_field( (string) ( $row['key'] ?? '' ) ) );
			$value = sanitize_textarea_field( (string) ( $row['value'] ?? '' ) );
			if ( '' === $key && '' === $value ) {
				continue;
			}
			// Keys are used verbatim in {{custom.key}}: reject rather than silently rewrite them.
			if ( isset( $seen[ $key ] ) || ! preg_match( '/^[a-z0-9_]+$/', $key ) ) {
				$checks[ 'custom_' . $index ] = Check::error( $key, __( 'Custom keys must be unique and use only a-z, 0-9 and _.', 'hive-compliance' ) );
				continue;
			}
			$seen[ $key ]     = true;
			$data['custom'][] = array(
				'key'   => $key,
				'value' => $value,
			);
		}

		return array( $data, $checks );
	}
}
