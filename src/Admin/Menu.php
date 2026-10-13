<?php
/**
 * Admin menu: Hive Compliance and its screens.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the menu pages and the shared admin stylesheet.
 */
final class Menu {

	/**
	 * Screens in menu order.
	 *
	 * @var Screen[]
	 */
	private array $screens;

	/**
	 * Constructor.
	 *
	 * @param Screen[] $screens Screens in menu order; the first one is the top-level page.
	 */
	public function __construct( array $screens ) {
		$this->screens = $screens;
	}

	/**
	 * Hooks the menu, assets and form handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		foreach ( $this->screens as $screen ) {
			$screen->register();
		}
	}

	/**
	 * Adds the menu pages.
	 */
	public function add_pages(): void {
		$top = $this->screens[0];
		add_menu_page( __( 'Hive Compliance', 'hive-compliance' ), __( 'Hive Compliance', 'hive-compliance' ), Plugin::CAPABILITY, 'hive-compliance', array( $top, 'render' ), 'dashicons-shield', 58 );
		foreach ( $this->screens as $screen ) {
			$slug = '' === $screen->slug() ? 'hive-compliance' : 'hive-compliance-' . $screen->slug();
			add_submenu_page( $screen->in_menu() ? 'hive-compliance' : '', $screen->title(), $screen->title(), Plugin::CAPABILITY, $slug, array( $screen, 'render' ) );
		}
	}

	/**
	 * Enqueues assets on the plugin's screens only.
	 */
	public function enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		if ( 0 !== strpos( $page, 'hive-compliance' ) ) {
			return;
		}
		wp_enqueue_style( 'hive-compliance-admin', HIVE_COMPLIANCE_URL . 'assets/css/admin.css', array(), HIVE_COMPLIANCE_VERSION );
		wp_enqueue_style( 'hive-compliance-front', HIVE_COMPLIANCE_URL . 'assets/css/front.css', array(), HIVE_COMPLIANCE_VERSION );
		foreach ( $this->screens as $screen ) {
			$slug = '' === $screen->slug() ? 'hive-compliance' : 'hive-compliance-' . $screen->slug();
			if ( $slug === $page ) {
				$screen->enqueue();
			}
		}
	}
}
