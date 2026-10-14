<?php
/**
 * Integration detection and custom integrations.
 *
 * @package Hive\Compliance
 */

use Hive\Compliance\Export\BundleExporter;

class DetectorTest extends Hive_TestCase {

	/**
	 * Known integrations for the given active plugins, keyed by slug.
	 */
	private function detect( array $plugins ): array {
		update_option( 'active_plugins', $plugins );
		$this->plugin()->detector()->reset();
		return array_column( $this->plugin()->detector()->detect()['known'], null, 'slug' );
	}

	public function test_new_services_are_detected_with_role_and_transfer() {
		$known = $this->detect(
			array(
				'tiktok-for-business/tiktok-for-business.php',
				'woocommerce-services/woocommerce-services.php',
				'code-snippets/code-snippets.php',
			)
		);

		$this->assertSame( 'advertising', $known['tiktok-for-business']['role'] );
		$this->assertSame( 'likely', $known['tiktok-for-business']['transfer'] );
		$this->assertStringContainsString( 'pixel', $known['tiktok-for-business']['data'] );

		$this->assertSame( 'Automattic', $known['woocommerce-services']['recipient'] );
		$this->assertStringContainsString( 'addresses', $known['woocommerce-services']['data'] );
		$this->assertSame( 'likely', $known['woocommerce-services']['transfer'] );

		$this->assertSame( 'may contain tracking code — review manually', $known['code-snippets']['note'] );
	}

	public function test_wp_mail_smtp_reports_the_configured_mailer() {
		update_option( 'wp_mail_smtp', array( 'mail' => array( 'mailer' => 'sendinblue' ) ) );
		$smtp = $this->detect( array( 'wp-mail-smtp/wp_mail_smtp.php' ) )['wp-mail-smtp'];
		$this->assertSame( 'WP Mail SMTP → Brevo', $smtp['service'] );
		$this->assertSame( 'Brevo', $smtp['recipient'] );
		$this->assertSame( 'email_transactional', $smtp['role'] );

		update_option(
			'wp_mail_smtp',
			array(
				'mail' => array( 'mailer' => 'smtp' ),
				'smtp' => array( 'host' => 'smtps.aruba.it' ),
			)
		);
		$this->assertSame( 'WP Mail SMTP → SMTP server smtps.aruba.it', $this->detect( array( 'wp-mail-smtp/wp_mail_smtp.php' ) )['wp-mail-smtp']['service'] );

		update_option( 'wp_mail_smtp', array( 'mail' => array( 'mailer' => 'gmail' ) ) );
		$this->assertSame( 'likely', $this->detect( array( 'wp-mail-smtp/wp_mail_smtp.php' ) )['wp-mail-smtp']['transfer'] );
	}

	public function test_custom_integrations_are_sanitised_and_exported() {
		$this->plugin()->settings()->update(
			array(
				'custom_integrations' => array(
					array(
						'name'      => 'Hive Sync',
						'role'      => 'stock sync',
						'data'      => 'orders, <b>customer</b> e-mail',
						'recipient' => 'Hive ERP (EU)',
						'transfer'  => 'maybe',
					),
					array(
						'name'      => 'Hive Commerce',
						'role'      => 'marketplace feed',
						'data'      => 'products',
						'recipient' => 'US partner',
						'transfer'  => 'yes',
					),
					array(
						'name' => '',
						'role' => 'empty rows are dropped',
					),
				),
			)
		);
		$rows = $this->plugin()->settings()->get( 'custom_integrations' );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'orders, customer e-mail', $rows[0]['data'] );
		$this->assertSame( 'no', $rows[0]['transfer'], 'Anything but "yes" is "no".' );

		update_option( 'active_plugins', array( 'tiktok-for-business/tiktok-for-business.php' ) );
		$this->plugin()->detector()->reset();
		$context = ( new BundleExporter( $this->plugin() ) )->context_data( time() )['integrations'];
		$this->assertSame( 'role: stock sync; data sent: orders, customer e-mail; recipient: Hive ERP (EU); personal data transfer outside the EU: no; source: custom integration (Settings)', $context['Hive Sync'] );
		$this->assertStringContainsString( 'personal data transfer outside the EU: yes', $context['Hive Commerce'] );
		$this->assertStringContainsString( 'data sent: browsing and purchase events', $context['TikTok for Business (TikTok Pixel)'] );
	}

	public function test_settings_screen_offers_the_custom_integrations_repeater() {
		$this->login_as( 'administrator' );
		$this->plugin()->settings()->update(
			array(
				'custom_integrations' => array(
					array(
						'name'     => 'Hive Sync',
						'transfer' => 'yes',
					),
				),
			)
		);
		$html = $this->render_screen( 'settings' );
		$this->assertStringContainsString( 'data-hive-repeater="custom_integrations"', $html );
		$this->assertStringContainsString( 'name="custom_integrations[0][name]" value="Hive Sync"', $html );
		$this->assertStringContainsString( 'name="custom_integrations[1][name]" value=""', $html, 'One empty row to add more.' );
	}
}
