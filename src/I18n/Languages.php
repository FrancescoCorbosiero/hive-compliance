<?php
/**
 * Language list: WPML, Polylang or the plugin's own list.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\I18n;

use Hive\Compliance\Hooks;
use Hive\Compliance\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "which languages exist, which is the default, which is current".
 */
final class Languages {

	const PROVIDER_WPML     = 'wpml';
	const PROVIDER_POLYLANG = 'polylang';
	const PROVIDER_OWN      = 'own';

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The plugin's own language list (BCP-47 code => native name).
	 *
	 * @return array<string, string>
	 */
	public static function own_list(): array {
		$list = array(
			'bg'    => 'Български',
			'cs'    => 'Čeština',
			'da'    => 'Dansk',
			'de'    => 'Deutsch',
			'el'    => 'Ελληνικά',
			'en'    => 'English',
			'es'    => 'Español',
			'et'    => 'Eesti',
			'fi'    => 'Suomi',
			'fr'    => 'Français',
			'ga'    => 'Gaeilge',
			'hr'    => 'Hrvatski',
			'hu'    => 'Magyar',
			'it'    => 'Italiano',
			'ja'    => '日本語',
			'lt'    => 'Lietuvių',
			'lv'    => 'Latviešu',
			'mt'    => 'Malti',
			'nl'    => 'Nederlands',
			'pl'    => 'Polski',
			'pt'    => 'Português',
			'pt-BR' => 'Português (Brasil)',
			'ro'    => 'Română',
			'sk'    => 'Slovenčina',
			'sl'    => 'Slovenščina',
			'sv'    => 'Svenska',
		);

		/** This filter is documented in src/Hooks.php */
		$filtered = apply_filters( Hooks::LANGUAGES, $list );

		$clean = array();
		foreach ( (array) $filtered as $code => $label ) {
			$code = self::normalize_code( (string) $code );
			if ( '' !== $code ) {
				$clean[ $code ] = (string) $label;
			}
		}
		return $clean;
	}

	/**
	 * Normalises a language code to BCP-47 casing (it, pt-BR, zh-Hans). Returns '' when invalid.
	 *
	 * @param string $code Raw code such as "pt_br" or "IT".
	 */
	public static function normalize_code( string $code ): string {
		$code  = str_replace( '_', '-', trim( $code ) );
		$parts = explode( '-', $code );
		if ( ! preg_match( '/^[A-Za-z]{2,3}$/', $parts[0] ) ) {
			return '';
		}
		$out = strtolower( $parts[0] );
		if ( isset( $parts[1] ) ) {
			if ( preg_match( '/^[A-Za-z]{2}$|^\d{3}$/', $parts[1] ) ) {
				$out .= '-' . strtoupper( $parts[1] );
			} elseif ( preg_match( '/^[A-Za-z]{4}$/', $parts[1] ) ) {
				$out .= '-' . ucfirst( strtolower( $parts[1] ) );
			} else {
				return '';
			}
		}
		if ( count( $parts ) > 2 ) {
			return '';
		}
		return $out;
	}

	/**
	 * Converts a WordPress locale (it_IT, de_DE_formal) to its primary language code (it, de).
	 *
	 * @param string $locale WordPress locale.
	 */
	public static function locale_to_code( string $locale ): string {
		$primary = strtolower( (string) strtok( $locale, '_-' ) );
		return preg_match( '/^[a-z]{2,3}$/', $primary ) ? $primary : 'en';
	}

	/**
	 * Primary subtag of a code ("pt-BR" => "pt").
	 *
	 * @param string $code Language code.
	 */
	public static function primary( string $code ): string {
		return strtolower( (string) strtok( $code, '-' ) );
	}

	/**
	 * Which provider supplies the languages.
	 */
	public function provider(): string {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_active_languages' ) ) {
			return self::PROVIDER_WPML;
		}
		if ( function_exists( 'pll_languages_list' ) ) {
			return self::PROVIDER_POLYLANG;
		}
		return self::PROVIDER_OWN;
	}

	/**
	 * Languages that can be enabled (code => label).
	 *
	 * @return array<string, string>
	 */
	public function available(): array {
		$provided = $this->provided_languages();
		return null === $provided ? self::own_list() : $provided;
	}

	/**
	 * Enabled languages (codes), default language first.
	 *
	 * @return string[]
	 */
	public function enabled(): array {
		$provided = $this->provided_languages();
		if ( null !== $provided ) {
			$codes = array_keys( $provided );
		} else {
			$codes = array_values( (array) $this->settings->get( 'enabled_languages' ) );
		}

		$default = $this->default_language();
		$codes   = array_values( array_unique( array_merge( array( $default ), $codes ) ) );
		return $codes;
	}

	/**
	 * Whether a language code is enabled.
	 *
	 * @param string $code Language code.
	 */
	public function is_enabled( string $code ): bool {
		return in_array( $code, $this->enabled(), true );
	}

	/**
	 * Default language code.
	 */
	public function default_language(): string {
		$provider = $this->provider();
		if ( self::PROVIDER_WPML === $provider ) {
			$code = self::normalize_code( (string) apply_filters( 'wpml_default_language', null ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
			if ( '' !== $code ) {
				return $code;
			}
		} elseif ( self::PROVIDER_POLYLANG === $provider && function_exists( 'pll_default_language' ) ) {
			$code = self::normalize_code( (string) pll_default_language() );
			if ( '' !== $code ) {
				return $code;
			}
		}

		$code = self::normalize_code( (string) $this->settings->get( 'default_language' ) );
		return '' !== $code ? $code : 'en';
	}

	/**
	 * Language of the current front-end request (falls back to the default language).
	 */
	public function current(): string {
		$provider = $this->provider();
		$code     = '';
		if ( self::PROVIDER_WPML === $provider ) {
			$code = self::normalize_code( (string) apply_filters( 'wpml_current_language', null ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
		} elseif ( self::PROVIDER_POLYLANG === $provider && function_exists( 'pll_current_language' ) ) {
			$code = self::normalize_code( (string) pll_current_language() );
		}

		if ( '' !== $code && $this->is_enabled( $code ) ) {
			return $code;
		}
		return $this->default_language();
	}

	/**
	 * Human label of a language code.
	 *
	 * @param string $code Language code.
	 */
	public function label( string $code ): string {
		$available = $this->available();
		return $available[ $code ] ?? $code;
	}

	/**
	 * Assigns a WP post to a language in WPML or Polylang (no-op otherwise).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $code    Language code.
	 */
	public function assign_post_language( int $post_id, string $code ): void {
		$provider = $this->provider();
		if ( self::PROVIDER_POLYLANG === $provider && function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $post_id, $this->provider_code( $code ) );
		} elseif ( self::PROVIDER_WPML === $provider ) {
			do_action(
				'wpml_set_element_language_details', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
				array(
					'element_id'    => $post_id,
					'element_type'  => 'post_' . get_post_type( $post_id ),
					'trid'          => false,
					'language_code' => $this->provider_code( $code ),
				)
			);
		}
	}

	/**
	 * Languages supplied by WPML or Polylang, or null when neither is active.
	 *
	 * @return array<string, string>|null
	 */
	private function provided_languages(): ?array {
		$provider = $this->provider();

		if ( self::PROVIDER_WPML === $provider ) {
			$raw = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML hook.
			$out = array();
			foreach ( (array) $raw as $code => $language ) {
				$normalized = self::normalize_code( (string) $code );
				if ( '' !== $normalized ) {
					$out[ $normalized ] = (string) ( $language['native_name'] ?? $code );
				}
			}
			return $out ? $out : null;
		}

		if ( self::PROVIDER_POLYLANG === $provider ) {
			$slugs = (array) pll_languages_list( array( 'fields' => 'slug' ) );
			$names = (array) pll_languages_list( array( 'fields' => 'name' ) );
			$out   = array();
			foreach ( $slugs as $index => $slug ) {
				$normalized = self::normalize_code( (string) $slug );
				if ( '' !== $normalized ) {
					$out[ $normalized ] = (string) ( $names[ $index ] ?? $slug );
				}
			}
			return $out ? $out : null;
		}

		return null;
	}

	/**
	 * The code as the active provider spells it (WPML and Polylang use lowercase slugs).
	 *
	 * @param string $code BCP-47 code.
	 */
	private function provider_code( string $code ): string {
		return strtolower( $code );
	}
}
