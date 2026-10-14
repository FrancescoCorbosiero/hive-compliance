<?php
/**
 * Maps active plugins and settings to known third-party services.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Integrations;

use Hive\Compliance\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Detects integrations from active plugins (by directory slug) and a few core/WooCommerce settings.
 * "transfer" estimates whether personal data likely leaves the EU: likely | possible | unlikely.
 */
final class Detector {

	/**
	 * Cached result for the request.
	 *
	 * @var array{known: array<int, array<string, string>>, unknown: array<int, array<string, string>>}|null
	 */
	private ?array $detected = null;

	/**
	 * Automattic's tax and shipping-label services.
	 */
	const AUTOMATTIC_SERVICES = array(
		'data'      => 'order and customer addresses, for tax calculation and shipping labels',
		'recipient' => 'Automattic',
	);

	/**
	 * Snippet plugins can hold anything, tracking code included.
	 */
	const REVIEW_SNIPPETS = array(
		'note' => 'may contain tracking code — review manually',
	);

	/**
	 * WP Mail SMTP mailers: provider and likely transfer outside the EU.
	 */
	const MAILERS = array(
		'mail'         => array( 'the web server (PHP mail)', 'unlikely' ),
		'gmail'        => array( 'Google (Gmail / Google Workspace)', 'likely' ),
		'outlook'      => array( 'Microsoft (Outlook / Microsoft 365)', 'likely' ),
		'sendgrid'     => array( 'SendGrid (Twilio)', 'likely' ),
		'sendinblue'   => array( 'Brevo', 'possible' ),
		'brevo'        => array( 'Brevo', 'possible' ),
		'mailgun'      => array( 'Mailgun', 'likely' ),
		'postmark'     => array( 'Postmark', 'likely' ),
		'sparkpost'    => array( 'SparkPost', 'likely' ),
		'amazonses'    => array( 'Amazon SES', 'likely' ),
		'zoho'         => array( 'Zoho Mail', 'possible' ),
		'smtpcom'      => array( 'SMTP.com', 'likely' ),
		'elasticemail' => array( 'Elastic Email', 'likely' ),
		'mailjet'      => array( 'Mailjet', 'possible' ),
		'mailersend'   => array( 'MailerSend', 'possible' ),
		'smtp2go'      => array( 'SMTP2GO', 'possible' ),
		'resend'       => array( 'Resend', 'likely' ),
	);

	/**
	 * Known plugin slugs. Entries may also say what data is sent ("data"), to whom ("recipient")
	 * and add a "note".
	 *
	 * @return array<string, array<string, string>>
	 */
	public function map(): array {
		$map = array(
			// Payments.
			'woocommerce-payments'                         => array( 'WooPayments (Stripe)', 'payments', 'likely' ),
			'woocommerce-gateway-stripe'                   => array( 'Stripe', 'payments', 'likely' ),
			'woo-stripe-payment'                           => array( 'Stripe (Payment Plugins)', 'payments', 'likely' ),
			'woocommerce-paypal-payments'                  => array( 'PayPal', 'payments', 'likely' ),
			'woocommerce-gateway-amazon-payments-advanced' => array( 'Amazon Pay', 'payments', 'likely' ),
			'klarna-payments-for-woocommerce'              => array( 'Klarna', 'payments_bnpl', 'possible' ),
			'klarna-checkout-for-woocommerce'              => array( 'Klarna', 'payments_bnpl', 'possible' ),
			'woocommerce-pay-for-payment'                  => array( 'Pay for Payment for WooCommerce', 'payment_fees', 'unlikely' ),
			// Tax and shipping services.
			'woocommerce-services'                         => array( 'WooCommerce Tax / Shipping & Tax (Automattic)', 'tax_and_shipping', 'likely', self::AUTOMATTIC_SERVICES ),
			'woocommerce-tax'                              => array( 'WooCommerce Tax (Automattic)', 'tax_and_shipping', 'likely', self::AUTOMATTIC_SERVICES ),
			'woocommerce-shipping'                         => array( 'WooCommerce Shipping (Automattic)', 'tax_and_shipping', 'likely', self::AUTOMATTIC_SERVICES ),
			// Google.
			'google-listings-and-ads'                      => array( 'Google for WooCommerce', 'advertising', 'likely' ),
			'woocommerce-google-analytics-integration'     => array( 'Google Analytics for WooCommerce', 'analytics', 'likely' ),
			'google-site-kit'                              => array( 'Site Kit by Google', 'analytics', 'likely' ),
			// Meta.
			'facebook-for-woocommerce'                     => array( 'Meta (Facebook for WooCommerce, Pixel)', 'advertising', 'likely' ),
			'official-facebook-pixel'                      => array( 'Meta Pixel', 'advertising', 'likely' ),
			'pixelyoursite'                                => array( 'PixelYourSite (tracking pixels)', 'advertising', 'likely' ),
			// TikTok.
			'tiktok-for-business'                          => array(
				'TikTok for Business (TikTok Pixel)',
				'advertising',
				'likely',
				array(
					'data'      => 'browsing and purchase events (advertising pixel)',
					'recipient' => 'TikTok',
				),
			),
			// E-mail marketing.
			'mailchimp-for-woocommerce'                    => array( 'Mailchimp', 'email_marketing', 'likely' ),
			'mailchimp-for-wp'                             => array( 'Mailchimp (MC4WP)', 'email_marketing', 'likely' ),
			'mailpoet'                                     => array( 'MailPoet', 'email_marketing', 'possible' ),
			'wordpress-popup'                              => array( 'Hustle (opt-in forms)', 'email_marketing', 'possible' ),
			'klaviyo'                                      => array( 'Klaviyo', 'email_marketing', 'likely' ),
			'mailin'                                       => array( 'Brevo', 'email_marketing', 'possible' ),
			'newsletter'                                   => array( 'Newsletter (self-hosted)', 'email_marketing', 'unlikely' ),
			// Transactional e-mail (the provider is read from the plugin settings).
			'wp-mail-smtp'                                 => array( 'WP Mail SMTP', 'email_transactional', 'possible' ),
			'wp-mail-smtp-pro'                             => array( 'WP Mail SMTP', 'email_transactional', 'possible' ),
			// Consent.
			'complianz-gdpr'                               => array( 'Complianz', 'consent', 'unlikely' ),
			'complianz-gdpr-premium'                       => array( 'Complianz', 'consent', 'unlikely' ),
			'iubenda-cookie-law-solution'                  => array( 'iubenda', 'consent', 'possible' ),
			'cookie-law-info'                              => array( 'CookieYes', 'consent', 'possible' ),
			'cookiebot'                                    => array( 'Cookiebot (Usercentrics)', 'consent', 'possible' ),
			'real-cookie-banner'                           => array( 'Real Cookie Banner', 'consent', 'unlikely' ),
			// Reviews.
			'trustpilot-reviews'                           => array( 'Trustpilot', 'reviews', 'possible' ),
			'customer-reviews-woocommerce'                 => array( 'CusRev (Customer Reviews)', 'reviews', 'possible' ),
			// Analytics.
			'koko-analytics'                               => array( 'Koko Analytics (self-hosted)', 'analytics', 'unlikely' ),
			'matomo'                                       => array( 'Matomo (self-hosted)', 'analytics', 'unlikely' ),
			'jetpack'                                      => array( 'Jetpack (Automattic)', 'analytics', 'likely' ),
			// Cart recovery.
			'woo-cart-abandonment-recovery'                => array( 'Cart Abandonment Recovery', 'cart_recovery', 'unlikely' ),
			'woocommerce-abandoned-cart'                   => array( 'Abandoned Cart Lite', 'cart_recovery', 'unlikely' ),
			// Product and store features.
			'woocommerce-subscriptions'                    => array( 'WooCommerce Subscriptions', 'subscriptions', 'unlikely' ),
			'woocommerce-gift-cards'                       => array( 'WooCommerce Gift Cards', 'gift_cards', 'unlikely' ),
			'pw-woocommerce-gift-cards'                    => array( 'PW Gift Cards', 'gift_cards', 'unlikely' ),
			'yith-woocommerce-gift-cards'                  => array( 'YITH Gift Cards', 'gift_cards', 'unlikely' ),
			'woocommerce-points-and-rewards'               => array( 'WooCommerce Points and Rewards', 'loyalty', 'unlikely' ),
			'yith-woocommerce-points-and-rewards'          => array( 'YITH Points and Rewards', 'loyalty', 'unlikely' ),
			'dokan-lite'                                   => array( 'Dokan (multivendor)', 'marketplace', 'unlikely' ),
			'wc-multivendor-marketplace'                   => array( 'WCFM Marketplace', 'marketplace', 'unlikely' ),
			'wc-vendors'                                   => array( 'WC Vendors', 'marketplace', 'unlikely' ),
			'dc-woocommerce-multi-vendor'                  => array( 'MultiVendorX', 'marketplace', 'unlikely' ),
			// Chat and AI.
			'tidio-live-chat'                              => array( 'Tidio (chat, AI assistant)', 'ai_chatbot', 'likely' ),
			'ai-engine'                                    => array( 'AI Engine (chatbot)', 'ai_chatbot', 'likely' ),
			// Other.
			'akismet'                                      => array( 'Akismet', 'anti_spam', 'likely' ),
			'code-snippets'                                => array( 'Code Snippets', 'custom_code', 'possible', self::REVIEW_SNIPPETS ),
			'code-snippets-pro'                            => array( 'Code Snippets Pro', 'custom_code', 'possible', self::REVIEW_SNIPPETS ),
		);

		$out = array();
		foreach ( $map as $slug => $entry ) {
			$out[ $slug ] = array_merge(
				array(
					'service'  => $entry[0],
					'role'     => $entry[1],
					'transfer' => $entry[2],
				),
				$entry[3] ?? array()
			);
		}

		/** This filter is documented in src/Hooks.php */
		return (array) apply_filters( Hooks::INTEGRATIONS_MAP, $out );
	}

	/**
	 * Slug substrings for families of plugins (checked when no exact slug matches).
	 *
	 * @return array<string, array{service: string, role: string, transfer: string}>
	 */
	private function patterns(): array {
		return array(
			'scalapay'      => array(
				'service'  => 'Scalapay',
				'role'     => 'payments_bnpl',
				'transfer' => 'possible',
			),
			'abandon'       => array(
				'service'  => __( 'Abandoned-cart recovery', 'hive-compliance' ),
				'role'     => 'cart_recovery',
				'transfer' => 'possible',
			),
			'cart-recovery' => array(
				'service'  => __( 'Abandoned-cart recovery', 'hive-compliance' ),
				'role'     => 'cart_recovery',
				'transfer' => 'possible',
			),
		);
	}

	/**
	 * Detected integrations: known services and unknown active plugins (by name).
	 *
	 * @return array{known: array<int, array<string, string>>, unknown: array<int, array<string, string>>}
	 */
	public function detect(): array {
		if ( null !== $this->detected ) {
			return $this->detected;
		}

		$map      = $this->map();
		$patterns = $this->patterns();
		$known    = array();
		$unknown  = array();

		foreach ( $this->active_plugins() as $file ) {
			$slug = $this->slug( $file );
			if ( in_array( $slug, array( 'hive-compliance', 'woocommerce' ), true ) ) {
				continue;
			}
			$info = $this->identify( $slug, $map, $patterns );
			if ( null !== $info && 'email_transactional' === $info['role'] && 0 === strpos( $slug, 'wp-mail-smtp' ) ) {
				$info = $this->mailer( $info );
			}
			if ( null === $info ) {
				$unknown[] = array(
					'slug' => $slug,
					'name' => $this->plugin_name( $file ),
				);
				continue;
			}
			$known[] = array_merge(
				$info,
				array(
					'slug'   => $slug,
					'name'   => $this->plugin_name( $file ),
					'source' => 'plugin',
				)
			);
		}//end foreach

		$known = array_merge( $known, $this->from_settings() );

		$result = array(
			'known'   => $known,
			'unknown' => $unknown,
		);

		/** This filter is documented in src/Hooks.php */
		$this->detected = (array) apply_filters( Hooks::DETECTED_INTEGRATIONS, $result );
		return $this->detected;
	}

	/**
	 * Known service for a plugin slug: exact match first, then slug families.
	 *
	 * @param string                               $slug     Plugin directory slug.
	 * @param array<string, array<string, string>> $map      Exact slugs.
	 * @param array<string, array<string, string>> $patterns Slug substrings.
	 * @return array<string, string>|null
	 */
	private function identify( string $slug, array $map, array $patterns ): ?array {
		if ( isset( $map[ $slug ] ) ) {
			return $map[ $slug ];
		}
		foreach ( $patterns as $needle => $info ) {
			if ( false !== strpos( $slug, $needle ) ) {
				return $info;
			}
		}
		return null;
	}

	/**
	 * WP Mail SMTP: the configured mailer is the real e-mail provider.
	 *
	 * @param array<string, string> $info Map entry.
	 * @return array<string, string>
	 */
	private function mailer( array $info ): array {
		$options = (array) get_option( 'wp_mail_smtp', array() );
		$mailer  = defined( 'WPMS_ON' ) && WPMS_ON && defined( 'WPMS_MAILER' ) ? (string) WPMS_MAILER : (string) ( $options['mail']['mailer'] ?? 'mail' );
		if ( 'smtp' === $mailer ) {
			$host     = sanitize_text_field( (string) ( defined( 'WPMS_ON' ) && WPMS_ON && defined( 'WPMS_SMTP_HOST' ) ? WPMS_SMTP_HOST : ( $options['smtp']['host'] ?? '' ) ) );
			$provider = '' !== $host ? 'SMTP server ' . $host : 'an SMTP server';
			$transfer = 'possible';
		} else {
			list( $provider, $transfer ) = self::MAILERS[ $mailer ] ?? array( $mailer, 'possible' );
		}
		return array_merge(
			$info,
			array(
				'service'   => $info['service'] . ' → ' . $provider,
				'transfer'  => $transfer,
				'data'      => 'e-mails sent by the site (order, account and form e-mails, with the recipients\' addresses)',
				'recipient' => $provider,
			)
		);
	}

	/**
	 * Whether any detected service has the given role.
	 *
	 * @param string $role Role (payments_bnpl, email_marketing, cart_recovery, ...).
	 */
	public function has_role( string $role ): bool {
		foreach ( $this->detect()['known'] as $integration ) {
			if ( ( $integration['role'] ?? '' ) === $role ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Detected services that have a given role.
	 *
	 * @param string $role Role.
	 * @return array<int, array<string, string>>
	 */
	public function with_role( string $role ): array {
		return array_values(
			array_filter(
				$this->detect()['known'],
				static fn( $integration ) => ( $integration['role'] ?? '' ) === $role
			)
		);
	}

	/**
	 * Whether a plugin is active, by directory slug.
	 *
	 * @param string $slug Plugin directory slug.
	 */
	public function is_active( string $slug ): bool {
		return in_array( $slug, array_map( array( $this, 'slug' ), $this->active_plugins() ), true );
	}

	/**
	 * Forgets the cached detection.
	 */
	public function reset(): void {
		$this->detected = null;
	}

	/**
	 * Services detected from WordPress and WooCommerce settings.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function from_settings(): array {
		$found = array();
		if ( get_option( 'show_avatars' ) ) {
			$found[] = array(
				'service'  => 'Gravatar',
				'role'     => 'avatars',
				'transfer' => 'likely',
				'slug'     => 'show_avatars',
				'name'     => __( 'Settings → Discussion → Avatars', 'hive-compliance' ),
				'source'   => 'setting',
			);
		}
		if ( class_exists( 'WooCommerce' ) && 'yes' === get_option( 'woocommerce_feature_order_attribution_enabled' ) ) {
			$found[] = array(
				'service'  => __( 'WooCommerce order attribution (first-party cookies)', 'hive-compliance' ),
				'role'     => 'analytics',
				'transfer' => 'unlikely',
				'slug'     => 'woocommerce_feature_order_attribution_enabled',
				'name'     => __( 'WooCommerce → Settings → Advanced → Features', 'hive-compliance' ),
				'source'   => 'setting',
			);
		}
		if ( class_exists( 'WooCommerce' ) && 'yes' === get_option( 'woocommerce_allow_tracking' ) ) {
			$found[] = array(
				'service'  => __( 'WooCommerce usage tracking', 'hive-compliance' ),
				'role'     => 'analytics',
				'transfer' => 'likely',
				'slug'     => 'woocommerce_allow_tracking',
				'name'     => __( 'WooCommerce → Settings → Advanced → WooCommerce.com', 'hive-compliance' ),
				'source'   => 'setting',
			);
		}
		return $found;
	}

	/**
	 * Active plugin files (site and network).
	 *
	 * @return string[]
	 */
	private function active_plugins(): array {
		$plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_values( array_unique( array_map( 'strval', $plugins ) ) );
	}

	/**
	 * Directory slug of a plugin file ("woocommerce/woocommerce.php" => "woocommerce").
	 *
	 * @param string $file Plugin file relative to the plugins directory.
	 */
	private function slug( string $file ): string {
		$dir = dirname( $file );
		return '.' === $dir ? basename( $file, '.php' ) : $dir;
	}

	/**
	 * Plugin name from its header, or the slug.
	 *
	 * @param string $file Plugin file.
	 */
	private function plugin_name( string $file ): string {
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( ! is_readable( $path ) ) {
			return $this->slug( $file );
		}
		$data = get_file_data( $path, array( 'Name' => 'Plugin Name' ), 'plugin' );
		return '' !== $data['Name'] ? $data['Name'] : $this->slug( $file );
	}
}
