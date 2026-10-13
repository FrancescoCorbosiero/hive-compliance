<?php
/**
 * [hive_document] and [hive_fragment] shortcodes.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Render;

use Hive\Compliance\I18n\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrappers around the Renderer.
 */
final class Shortcodes {

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param Renderer $renderer Renderer.
	 */
	public function __construct( Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Registers the shortcodes.
	 */
	public function register(): void {
		add_shortcode( 'hive_document', array( $this, 'document' ) );
		add_shortcode( 'hive_fragment', array( $this, 'fragment' ) );
	}

	/**
	 * [hive_document id="terms_of_sale" lang="it"]
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function document( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'   => '',
				'lang' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'hive_document'
		);
		return $this->renderer->render_document( sanitize_key( $atts['id'] ), Languages::normalize_code( $atts['lang'] ) );
	}

	/**
	 * [hive_fragment id="frag_newsletter_consent" lang="it"]
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function fragment( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'   => '',
				'lang' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'hive_fragment'
		);
		return $this->renderer->render_fragment( sanitize_key( $atts['id'] ), Languages::normalize_code( $atts['lang'] ) );
	}
}
