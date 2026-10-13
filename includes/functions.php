<?php
/**
 * Global template helpers.
 *
 * @package Hive\Compliance
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'hive_fragment' ) ) {
	/**
	 * Renders a fragment (e.g. a consent label) as HTML.
	 *
	 * Returns '' for visitors while the fragment is not published, and always for unknown or
	 * non-fragment IDs, so callers can fall back to their own default text:
	 *
	 *     echo wp_kses_post( hive_fragment( 'frag_newsletter_consent' ) ?: __( 'Subscribe', 'my-theme' ) );
	 *
	 * @param string      $id   Fragment ID from the catalog (frag_*).
	 * @param string|null $lang Language code; null uses the current language.
	 * @return string HTML (already sanitised with wp_kses_post).
	 */
	function hive_fragment( $id, $lang = null ): string {
		return \Hive\Compliance\Plugin::instance()->renderer()->render_fragment(
			sanitize_key( (string) $id ),
			null === $lang ? '' : \Hive\Compliance\I18n\Languages::normalize_code( (string) $lang )
		);
	}
}
