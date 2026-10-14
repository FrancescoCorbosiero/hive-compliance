<?php
/**
 * Shortcodes and blocks for theme areas: [hive_business], [hive_legal_links],
 * hive/business-identity and hive/legal-links.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Render;

use Hive\Compliance\I18n\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the footer/header elements built from Business Data and the page bindings.
 */
final class SiteElements {

	/**
	 * Identity line.
	 *
	 * @var BusinessIdentity
	 */
	private BusinessIdentity $identity;

	/**
	 * Legal links.
	 *
	 * @var LegalLinks
	 */
	private LegalLinks $links;

	/**
	 * Languages.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Editor script handles generated from block.json.
	 *
	 * @var string[]
	 */
	private array $editor_handles = array();

	/**
	 * Constructor.
	 *
	 * @param BusinessIdentity $identity  Identity line.
	 * @param LegalLinks       $links     Legal links.
	 * @param Languages        $languages Languages.
	 */
	public function __construct( BusinessIdentity $identity, LegalLinks $links, Languages $languages ) {
		$this->identity  = $identity;
		$this->links     = $links;
		$this->languages = $languages;
	}

	/**
	 * Registers the shortcodes and block types (on init).
	 */
	public function register(): void {
		add_shortcode( 'hive_business', array( $this, 'business_shortcode' ) );
		add_shortcode( 'hive_legal_links', array( $this, 'links_shortcode' ) );

		$blocks = array(
			'business-identity' => array( $this, 'render_identity_block' ),
			'legal-links'       => array( $this, 'render_links_block' ),
		);
		foreach ( $blocks as $dir => $callback ) {
			$type = register_block_type( HIVE_COMPLIANCE_DIR . 'blocks/' . $dir, array( 'render_callback' => $callback ) );
			if ( $type && ! empty( $type->editor_script_handles[0] ) ) {
				$this->editor_handles[] = (string) $type->editor_script_handles[0];
			}
		}
		if ( $this->editor_handles ) {
			add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
		}
	}

	/**
	 * [hive_business field="vat_id"] prints one field; [hive_business] prints the identity line,
	 * optionally limited with fields="trade_name,vat_id" or exclude="phone,pec".
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function business_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'field'   => '',
				'fields'  => '',
				'exclude' => '',
				'lang'    => '',
			),
			is_array( $atts ) ? $atts : array(),
			'hive_business'
		);
		if ( '' !== $atts['field'] ) {
			return esc_html( $this->identity->value( sanitize_key( $atts['field'] ) ) );
		}
		return $this->identity->render( BusinessIdentity::fields_from( (string) $atts['fields'], (string) $atts['exclude'] ), Languages::normalize_code( (string) $atts['lang'] ) );
	}

	/**
	 * [hive_legal_links group="legal"] or group="assistance"; without a group, every page.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function links_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'group' => '',
				'lang'  => '',
			),
			is_array( $atts ) ? $atts : array(),
			'hive_legal_links'
		);
		return $this->links->render( sanitize_key( $atts['group'] ), Languages::normalize_code( (string) $atts['lang'] ) );
	}

	/**
	 * Server-side render of hive/business-identity.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_identity_block( $attributes ): string {
		$fields = array_values( array_intersect( BusinessIdentity::FIELDS, array_map( 'strval', (array) ( $attributes['fields'] ?? BusinessIdentity::FIELDS ) ) ) );
		$html   = $this->identity->render( $fields, Languages::normalize_code( (string) ( $attributes['lang'] ?? '' ) ) );
		return '' === $html ? '' : '<p ' . get_block_wrapper_attributes() . '>' . $html . '</p>';
	}

	/**
	 * Server-side render of hive/legal-links.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_links_block( $attributes ): string {
		$html = $this->links->render( sanitize_key( (string) ( $attributes['group'] ?? '' ) ), Languages::normalize_code( (string) ( $attributes['lang'] ?? '' ) ) );
		return '' === $html ? '' : '<nav ' . get_block_wrapper_attributes( array( 'aria-label' => esc_attr__( 'Legal information', 'hive-compliance' ) ) ) . '>' . $html . '</nav>';
	}

	/**
	 * Field and language choices for the block inspectors.
	 */
	public function editor_assets(): void {
		$fields = array();
		foreach ( BusinessIdentity::field_labels() as $value => $label ) {
			$fields[] = array(
				'value' => $value,
				'label' => $label,
			);
		}
		$languages = array();
		foreach ( $this->languages->enabled() as $code ) {
			$languages[] = array(
				'value' => $code,
				'label' => $this->languages->label( $code ),
			);
		}
		$data = 'window.hiveComplianceSiteBlocks = ' . wp_json_encode(
			array(
				'fields'    => $fields,
				'languages' => $languages,
			)
		) . ';';
		foreach ( $this->editor_handles as $handle ) {
			wp_add_inline_script( $handle, $data, 'before' );
		}
	}
}
