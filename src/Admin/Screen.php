<?php
/**
 * Base class of the admin screens.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\Document\Document;
use Hive\Compliance\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Shared output helpers. Screens render with WordPress admin markup and classes.
 */
abstract class Screen {

	/**
	 * Plugin services.
	 *
	 * @var Plugin
	 */
	protected Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin services.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Page slug suffix ('' for the dashboard).
	 */
	abstract public function slug(): string;

	/**
	 * Menu and page title.
	 */
	abstract public function title(): string;

	/**
	 * Prints the page.
	 */
	abstract public function render(): void;

	/**
	 * Hooks form handlers (admin-post.php). Default: none.
	 */
	public function register(): void {}

	/**
	 * Enqueues the screen's assets. Default: none besides the shared stylesheet.
	 */
	public function enqueue(): void {}

	/**
	 * Whether the screen appears in the menu.
	 */
	public function in_menu(): bool {
		return true;
	}

	/**
	 * Opens a form posting to admin-post.php with the action and its nonce.
	 *
	 * @param string $action Action (also the nonce action).
	 * @param array  $attrs  Extra form attributes (name => value).
	 */
	protected function form_open( string $action, array $attrs = array() ): void {
		$extra = '';
		foreach ( $attrs as $name => $value ) {
			$extra .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}
		printf( '<form method="post" action="%s"%s>', esc_url( admin_url( 'admin-post.php' ) ), $extra ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped above.
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
		wp_nonce_field( $action );
	}

	/**
	 * Status badge HTML.
	 *
	 * @param string $status empty|draft|published.
	 */
	protected function status_badge( string $status ): string {
		$labels = array(
			Document::STATUS_EMPTY     => __( 'Empty', 'hive-compliance' ),
			Document::STATUS_DRAFT     => __( 'Draft', 'hive-compliance' ),
			Document::STATUS_PUBLISHED => __( 'Published', 'hive-compliance' ),
		);
		return sprintf( '<span class="hive-badge hive-badge--%1$s">%2$s</span>', esc_attr( $status ), esc_html( $labels[ $status ] ?? $status ) );
	}

	/**
	 * Generic badge HTML.
	 *
	 * @param string $text Text.
	 * @param string $kind ok|warning|error|info|muted.
	 */
	protected function badge( string $text, string $kind = 'info' ): string {
		return sprintf( '<span class="hive-badge hive-badge--%1$s">%2$s</span>', esc_attr( $kind ), esc_html( $text ) );
	}

	/**
	 * Page header.
	 *
	 * @param string $title    Title.
	 * @param string $subtitle Optional text under the title.
	 */
	protected function header( string $title, string $subtitle = '' ): void {
		echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		if ( '' !== $subtitle ) {
			echo '<p class="hive-subtitle">' . esc_html( $subtitle ) . '</p>';
		}
		echo '<hr class="wp-header-end">';
	}
}
