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
 * Shared output helpers. Screens render with WordPress admin markup and classes plus the
 * small component set of assets/css/admin.css (panels, badges, stat tiles, choice grids,
 * filterable selects). Every helper returns or prints escaped HTML.
 */
abstract class Screen {

	/**
	 * A <select> with at least this many options becomes a filterable combobox (assets/js/admin.js).
	 */
	const COMBOBOX_MIN_OPTIONS = 8;

	/**
	 * A checkbox list with at least this many items gets a filter field.
	 */
	const FILTER_MIN_ITEMS = 12;

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
	 * Enqueues the screen's assets. Default: none besides the shared stylesheet and script.
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
	 * @param string $text  Text.
	 * @param string $kind  ok|warning|error|info|muted.
	 * @param bool   $plain Without the status dot.
	 */
	protected function badge( string $text, string $kind = 'info', bool $plain = false ): string {
		return sprintf( '<span class="hive-badge hive-badge--%1$s%3$s">%2$s</span>', esc_attr( $kind ), esc_html( $text ), $plain ? ' hive-badge--plain' : '' );
	}

	/**
	 * A Dashicon.
	 *
	 * @param string $name Icon name without the "dashicons-" prefix.
	 */
	protected function icon( string $name ): string {
		return sprintf( '<span class="dashicons dashicons-%s" aria-hidden="true"></span>', esc_attr( $name ) );
	}

	/**
	 * "Select all · Select none" for the checkboxes inside an element, with a live count.
	 * Hidden until assets/js/admin.js runs, so it never shows without JavaScript.
	 *
	 * @param string $container_id ID of the element holding the checkboxes.
	 * @param string $activates    Optional ID of a radio button to select when the boxes change.
	 */
	protected function select_all( string $container_id, string $activates = '' ): string {
		return sprintf(
			'<p class="hive-select-all" data-hive-select="%1$s"%2$s hidden><button type="button" class="button-link" data-hive-select-action="all">%3$s</button> · <button type="button" class="button-link" data-hive-select-action="none">%4$s</button> <span class="hive-meta hive-select-count" aria-live="polite"></span></p>',
			esc_attr( $container_id ),
			'' !== $activates ? ' data-hive-activates="' . esc_attr( $activates ) . '"' : '',
			esc_html__( 'Select all', 'hive-compliance' ),
			esc_html__( 'Select none', 'hive-compliance' )
		);
	}

	/**
	 * Search field that narrows a list (choice grid, placeholder picker) as you type.
	 * Works without JavaScript too: it is then an inert field, so it is only printed with it.
	 *
	 * @param string $container_id ID of the element holding the items.
	 * @param string $placeholder  Field placeholder.
	 * @param string $items        CSS selector of the items (default .hive-check).
	 */
	protected function filter_input( string $container_id, string $placeholder, string $items = '' ): string {
		static $n = 0;
		++$n;
		return sprintf(
			'<div class="hive-filter"><label class="screen-reader-text" for="hive-filter-%1$d">%3$s</label><span class="hive-search">%5$s<input type="search" id="hive-filter-%1$d" placeholder="%3$s" data-hive-filter="%2$s"%4$s autocomplete="off"></span><span class="hive-filter__count" data-hive-filter-count aria-live="polite"></span></div>',
			$n,
			esc_attr( $container_id ),
			esc_attr( $placeholder ),
			'' !== $items ? ' data-hive-filter-items="' . esc_attr( $items ) . '"' : '',
			$this->icon( 'search' )
		);
	}

	/**
	 * A <select>. Long lists get the hive-combobox class and become filterable.
	 *
	 * @param string                $id          Element ID.
	 * @param string                $name        Input name.
	 * @param array<string, string> $options     value => label.
	 * @param string                $selected    Selected value.
	 * @param array<string, mixed>  $args        placeholder (first empty option), placeholder_value ('' by default),
	 *                                           class, attrs (name => value), combobox (force on/off).
	 */
	protected function select( string $id, string $name, array $options, string $selected, array $args = array() ): string {
		$combobox = $args['combobox'] ?? ( count( $options ) >= self::COMBOBOX_MIN_OPTIONS );
		$class    = trim( ( $combobox ? 'hive-combobox ' : '' ) . (string) ( $args['class'] ?? '' ) );
		$attrs    = '';
		foreach ( (array) ( $args['attrs'] ?? array() ) as $attr => $value ) {
			$attrs .= ' ' . esc_attr( $attr ) . '="' . esc_attr( (string) $value ) . '"';
		}
		$html = sprintf( '<select id="%1$s" name="%2$s"%3$s%4$s>', esc_attr( $id ), esc_attr( $name ), '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '', $attrs );
		if ( isset( $args['placeholder'] ) ) {
			$html .= sprintf( '<option value="%1$s">%2$s</option>', esc_attr( (string) ( $args['placeholder_value'] ?? '' ) ), esc_html( (string) $args['placeholder'] ) );
		}
		foreach ( $options as $value => $label ) {
			$html .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( $selected, (string) $value, false ), esc_html( (string) $label ) );
		}
		return $html . '</select>';
	}

	/**
	 * Pages offered for binding: ID => "Title (status)".
	 *
	 * @return array<int, string>
	 */
	protected function page_options(): array {
		$pages = get_pages(
			array(
				'post_status' => array( 'publish', 'draft', 'private' ),
				'sort_column' => 'post_title',
			)
		);
		$out   = array();
		foreach ( (array) $pages as $page ) {
			$status                 = 'publish' === $page->post_status ? '' : ' (' . $page->post_status . ')';
			$title                  = get_the_title( $page );
			$out[ (int) $page->ID ] = ( '' !== $title ? $title : __( '(no title)', 'hive-compliance' ) ) . $status;
		}
		return $out;
	}

	/**
	 * Opens a panel (white card with a header).
	 *
	 * @param string               $title Title.
	 * @param array<string, mixed> $args  icon, meta (HTML, already escaped), id, class, description, flush (no body padding), body_class.
	 */
	protected function panel_open( string $title, array $args = array() ): void {
		printf(
			'<section class="hive-panel%1$s"%2$s><header class="hive-panel__head"><h2 class="hive-panel__title">%3$s%4$s</h2>%5$s</header><div class="hive-panel__body%6$s%7$s">',
			isset( $args['class'] ) ? ' ' . esc_attr( (string) $args['class'] ) : '',
			isset( $args['id'] ) ? ' id="' . esc_attr( (string) $args['id'] ) . '"' : '',
			isset( $args['icon'] ) ? $this->icon( (string) $args['icon'] ) : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			wp_kses_post( $title ),
			isset( $args['meta'] ) && '' !== $args['meta'] ? '<div class="hive-panel__meta">' . $args['meta'] . '</div>' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller escapes.
			! empty( $args['flush'] ) ? ' hive-panel__body--flush' : '',
			isset( $args['body_class'] ) ? ' ' . esc_attr( (string) $args['body_class'] ) : ''
		);
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="hive-panel__description">' . esc_html( (string) $args['description'] ) . '</p>';
		}
	}

	/**
	 * Closes a panel.
	 *
	 * @param string $foot Optional footer HTML (already escaped).
	 */
	protected function panel_close( string $foot = '' ): void {
		echo '</div>';
		if ( '' !== $foot ) {
			echo '<footer class="hive-panel__foot">' . $foot . '</footer>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller escapes.
		}
		echo '</section>';
	}

	/**
	 * A stat tile.
	 *
	 * @param string     $label Label.
	 * @param int|string $value Value.
	 * @param string     $kind  ok|warning|error|info|'' (neutral).
	 * @param string     $meta  Optional line under the value.
	 * @param string     $url   Optional link.
	 */
	protected function stat( string $label, $value, string $kind = '', string $meta = '', string $url = '' ): string {
		$inner = sprintf( '<span class="hive-stat__label">%1$s</span><span class="hive-stat__value">%2$s</span>%3$s', esc_html( $label ), esc_html( (string) $value ), '' !== $meta ? '<span class="hive-stat__meta">' . esc_html( $meta ) . '</span>' : '' );
		$class = 'hive-stat' . ( '' !== $kind ? ' hive-stat--' . esc_attr( $kind ) : '' );
		if ( '' !== $url ) {
			return sprintf( '<a class="%1$s" href="%2$s">%3$s</a>', $class, esc_url( $url ), $inner );
		}
		return sprintf( '<div class="%1$s">%2$s</div>', $class, $inner );
	}

	/**
	 * A choice (checkbox or radio inside a bordered label).
	 *
	 * @param string $name    Input name.
	 * @param string $value   Value.
	 * @param string $label   Label.
	 * @param bool   $checked Checked.
	 * @param string $extra   Optional HTML under the label (already escaped).
	 * @param string $type    checkbox|radio.
	 * @param string $attrs   Extra input attributes (already escaped).
	 */
	protected function choice( string $name, string $value, string $label, bool $checked, string $extra = '', string $type = 'checkbox', string $attrs = '' ): string {
		return sprintf(
			'<label class="hive-check" data-hive-search="%6$s"><input type="%5$s" name="%1$s" value="%2$s"%3$s%7$s> <span class="hive-check__text"><span>%4$s</span>%8$s</span></label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $checked, true, false ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value . ' ' . $label ),
			'' !== $attrs ? ' ' . $attrs : '',
			$extra
		);
	}

	/**
	 * One finding (editor checks, import review).
	 *
	 * @param string   $severity error|warning|conflict|info.
	 * @param string   $message  Message.
	 * @param string[] $details  Details shown as code.
	 */
	protected function finding( string $severity, string $message, array $details = array() ): string {
		$icons = array(
			'error'    => 'dismiss',
			'warning'  => 'warning',
			'conflict' => 'randomize',
			'info'     => 'info-outline',
		);
		$html  = sprintf(
			'<li class="hive-finding hive-finding--%1$s"><span class="hive-finding__icon">%2$s</span><div class="hive-finding__body"><span class="hive-finding__severity">%3$s</span>%4$s',
			esc_attr( $severity ),
			$this->icon( $icons[ $severity ] ?? 'info-outline' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			esc_html( $severity ),
			esc_html( $message )
		);
		if ( $details ) {
			$html .= '<ul>';
			foreach ( $details as $detail ) {
				$html .= '<li><code>' . esc_html( (string) $detail ) . '</code></li>';
			}
			$html .= '</ul>';
		}
		return $html . '</div></li>';
	}

	/**
	 * Inline note styled like a finding (one-off messages inside panels).
	 *
	 * @param string $severity error|warning|info.
	 * @param string $message  Message.
	 */
	protected function note( string $severity, string $message ): string {
		$icons = array(
			'error'   => 'dismiss',
			'warning' => 'warning',
			'info'    => 'info-outline',
		);
		return sprintf(
			'<p class="hive-finding hive-finding--%1$s"><span class="hive-finding__icon">%2$s</span><span class="hive-finding__body">%3$s</span></p>',
			esc_attr( $severity ),
			$this->icon( $icons[ $severity ] ?? 'info-outline' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			esc_html( $message )
		);
	}

	/**
	 * Field validation message with an icon.
	 *
	 * @param string $severity error|warning.
	 * @param string $message  Message.
	 */
	protected function field_message( string $severity, string $message ): string {
		return sprintf(
			'<p class="hive-field-message hive-field-message--%1$s">%2$s<span>%3$s</span></p>',
			esc_attr( $severity ),
			$this->icon( 'error' === $severity ? 'warning' : 'info-outline' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			esc_html( $message )
		);
	}

	/**
	 * Progress bar.
	 *
	 * @param int  $done  Done.
	 * @param int  $total Total.
	 * @param bool $wide  Full width.
	 */
	protected function progress( int $done, int $total, bool $wide = false ): string {
		$percent = $total > 0 ? (int) round( 100 * $done / $total ) : 0;
		return sprintf(
			'<span class="hive-progress%3$s%4$s" role="progressbar" aria-valuenow="%1$d" aria-valuemin="0" aria-valuemax="100" title="%1$d%%"><span style="width:%1$d%%"></span></span>',
			$percent,
			$total,
			$wide ? ' hive-progress--wide' : '',
			$total > 0 && $done >= $total ? ' hive-progress--full' : ''
		);
	}

	/**
	 * Page header: title, optional subtitle, optional actions on the right.
	 *
	 * @param string $title    Title.
	 * @param string $subtitle Optional text under the title.
	 * @param string $actions  Optional HTML of the actions (already escaped).
	 */
	protected function header( string $title, string $subtitle = '', string $actions = '' ): void {
		echo '<div class="hive-header"><div class="hive-header__text">';
		echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		if ( '' !== $subtitle ) {
			echo '<p class="hive-subtitle">' . esc_html( $subtitle ) . '</p>';
		}
		echo '</div>';
		if ( '' !== $actions ) {
			echo '<div class="hive-header__actions">' . $actions . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller escapes.
		}
		echo '</div>';
		echo '<hr class="wp-header-end">';
	}
}
