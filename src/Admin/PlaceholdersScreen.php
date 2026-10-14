<?php
/**
 * Placeholders screen: every {{placeholder}}, its value, status, usage and source, with inline editing.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\Business\BusinessData;
use Hive\Compliance\I18n\Languages;
use Hive\Compliance\Placeholders\Inventory;

defined( 'ABSPATH' ) || exit;

/**
 * One place to see and set the values documents refer to. Business values, custom values
 * and page links are edited here; store facts link to the WordPress or WooCommerce setting.
 */
final class PlaceholdersScreen extends Screen {

	const ACTION_SAVE = 'hive_compliance_save_placeholders';

	/**
	 * Screen slug.
	 */
	public function slug(): string {
		return 'placeholders';
	}

	/**
	 * Menu title.
	 */
	public function title(): string {
		return __( 'Placeholders', 'hive-compliance' );
	}

	/**
	 * Hooks the save handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
	}

	/**
	 * Search and filter.
	 */
	public function enqueue(): void {
		wp_enqueue_script( 'hive-compliance-placeholders', HIVE_COMPLIANCE_URL . 'assets/js/placeholders.js', array( 'wp-i18n', 'hive-compliance-admin' ), HIVE_COMPLIANCE_VERSION, true );
	}

	/**
	 * Handles the form (admin-post.php).
	 */
	public function handle_save(): void {
		Request::require_capability();
		check_admin_referer( self::ACTION_SAVE );

		$lang  = Languages::normalize_code( sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) ) );
		$input = array();
		foreach ( array( 'business', 'custom', 'custom_new', 'custom_remove', 'links' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ) {
				$input[ $key ] = map_deep( wp_unslash( $_POST[ $key ] ), 'sanitize_textarea_field' );
			}
		}
		foreach ( $this->save( $input, $lang ) as list( $type, $message ) ) {
			Notices::add( $type, $message );
		}
		Request::redirect( Request::page_url( 'placeholders', array( 'lang' => $lang ) ) );
	}

	/**
	 * Saves business values, custom values and page links.
	 *
	 * @param array<string, mixed> $input business[key], custom[key], custom_new{key, value}, custom_remove[], links[doc_id] => page ID.
	 * @param string               $lang  Language of the page links.
	 * @return array<int, array{0: string, 1: string}> Notices (type, message).
	 */
	public function save( array $input, string $lang ): array {
		$notices  = array();
		$lang     = $this->plugin->languages()->is_enabled( $lang ) ? $lang : $this->plugin->languages()->default_language();
		$business = $this->plugin->business();
		$data     = $business->get();
		$fields   = BusinessData::fields();

		foreach ( (array) ( $input['business'] ?? array() ) as $key => $value ) {
			if ( isset( $fields[ $key ] ) && is_scalar( $value ) ) {
				$data[ $key ] = (string) $value;
			}
		}

		$remove  = array_map( 'strval', (array) ( $input['custom_remove'] ?? array() ) );
		$new_key = trim( (string) ( $input['custom_new']['key'] ?? '' ) );
		if ( isset( $input['custom'] ) || $remove || '' !== $new_key ) {
			$custom   = array();
			$existing = $business->custom();
			foreach ( (array) ( $input['custom'] ?? array() ) as $key => $value ) {
				// An undefined key used by a document is only created once it gets a value.
				$defined = array_key_exists( (string) $key, $existing ) || '' !== trim( (string) $value );
				if ( $defined && ! in_array( (string) $key, $remove, true ) ) {
					$custom[] = array(
						'key'   => (string) $key,
						'value' => (string) $value,
					);
				}
			}
			if ( '' !== $new_key ) {
				$custom[] = array(
					'key'   => $new_key,
					'value' => (string) ( $input['custom_new']['value'] ?? '' ),
				);
			}
			$data['custom'] = $custom;
		}//end if

		$checks = $business->save( $data, $this->plugin->seller_country() );
		foreach ( $checks as $key => $check ) {
			if ( 0 === strpos( (string) $key, 'custom_' ) || ( isset( $fields[ $key ] ) && $check->is_error() ) ) {
				$label     = $fields[ $key ]['label'] ?? __( 'Custom value', 'hive-compliance' );
				$notices[] = array( 'warning', $label . ': ' . $check->message );
			}
		}

		$notices = array_merge( $notices, $this->save_links( (array) ( $input['links'] ?? array() ), $lang ) );
		$this->plugin->placeholders()->reset();
		array_unshift( $notices, array( 'success', __( 'Placeholders saved.', 'hive-compliance' ) ) );
		return $notices;
	}

	/**
	 * Binds, rebinds or unbinds the pages whose dropdown changed.
	 *
	 * @param array<string, mixed> $links Doc ID => page ID (0 = unbind).
	 * @param string               $lang  Language.
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function save_links( array $links, string $lang ): array {
		$notices = array();
		$manager = $this->plugin->page_manager();
		foreach ( $links as $doc_id => $page_id ) {
			$doc_id  = sanitize_key( (string) $doc_id );
			$page_id = absint( $page_id );
			$entry   = $this->plugin->catalog()->get( $doc_id );
			if ( null === $entry || $page_id === $this->plugin->bindings()->get( $doc_id, $lang ) ) {
				continue;
			}
			if ( 0 === $page_id ) {
				$manager->unbind( $doc_id, $lang );
				continue;
			}
			$result = $manager->assign( $doc_id, $lang, $page_id );
			if ( is_wp_error( $result ) ) {
				$notices[] = array( 'error', $entry->title( $lang ) . ': ' . $result->get_error_message() );
			} elseif ( $result ) {
				/* translators: %s: document title. */
				$notices[] = array( 'warning', sprintf( __( '%s: page bound, but it holds more than the Compliance document block. Keep only the block on bound pages.', 'hive-compliance' ), $entry->title( $lang ) ) );
			}
		}
		return $notices;
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		$languages = $this->plugin->languages();
		$lang      = isset( $_GET['lang'] ) ? Languages::normalize_code( sanitize_text_field( wp_unslash( $_GET['lang'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View switch.
		$lang      = $languages->is_enabled( $lang ) ? $lang : $languages->default_language();
		$groups    = ( new Inventory( $this->plugin ) )->rows( $lang );
		$summary   = Inventory::summary( $groups );

		$actions = '';
		if ( count( $languages->enabled() ) > 1 ) {
			$actions = '<nav class="hive-segmented" aria-label="' . esc_attr__( 'Language', 'hive-compliance' ) . '">';
			foreach ( $languages->enabled() as $code ) {
				$actions .= sprintf( '<a href="%1$s"%2$s>%3$s</a>', esc_url( Request::page_url( 'placeholders', array( 'lang' => $code ) ) ), $code === $lang ? ' class="is-current" aria-current="page"' : '', esc_html( $code . ' — ' . $languages->label( $code ) ) );
			}
			$actions .= '</nav>';
		}

		echo '<div class="wrap hive-wrap hive-placeholders-screen">';
		$this->header( $this->title(), __( 'Every {{placeholder}} the documents can use: its current value, whether it needs attention, which documents use it and where it comes from. Business values, custom values and page links can be changed here.', 'hive-compliance' ), $actions );

		echo '<div class="hive-stats">';
		echo $this->stat( __( 'Placeholders', 'hive-compliance' ), $summary['total'], 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in stat().
		echo $this->stat( __( 'With a value', 'hive-compliance' ), $summary['set'], 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in stat().
		echo $this->stat( __( 'Used in documents', 'hive-compliance' ), $summary['used'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in stat().
		echo $this->stat( __( 'Need attention', 'hive-compliance' ), $summary['attention'], $summary['attention'] ? 'warning' : 'ok', $summary['attention'] ? __( 'missing or invalid, and used', 'hive-compliance' ) : __( 'all clear', 'hive-compliance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in stat().
		echo '</div>';

		$filters = array(
			'all'       => __( 'All', 'hive-compliance' ),
			'attention' => __( 'Need attention', 'hive-compliance' ),
			'used'      => __( 'Used in documents', 'hive-compliance' ),
			'editable'  => __( 'Editable here', 'hive-compliance' ),
		);
		$active  = $summary['attention'] ? 'attention' : 'all';
		echo '<div class="hive-toolbar hive-ph-toolbar"><div class="hive-toolbar__group">';
		printf( '<label class="screen-reader-text" for="hive-ph-search">%1$s</label><span class="hive-search">%2$s<input type="search" id="hive-ph-search" placeholder="%1$s" autocomplete="off"></span>', esc_attr__( 'Search placeholders, labels and values', 'hive-compliance' ), $this->icon( 'search' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
		echo '</div><div class="hive-toolbar__group"><div class="hive-segmented" id="hive-ph-filter" role="group" aria-label="' . esc_attr__( 'Show', 'hive-compliance' ) . '">';
		foreach ( $filters as $value => $label ) {
			printf( '<button type="button" data-hive-ph-filter="%1$s"%2$s>%3$s</button>', esc_attr( $value ), $value === $active ? ' class="is-current" aria-pressed="true"' : ' aria-pressed="false"', esc_html( $label ) );
		}
		echo '</div></div></div>';

		$titles = array(
			'business' => array( __( 'Business data', 'hive-compliance' ), __( 'Facts about the business. Also on the Business Data screen, with social profiles.', 'hive-compliance' ), 'building' ),
			'store'    => array( __( 'Store facts', 'hive-compliance' ), __( 'Read-only: detected from WordPress and WooCommerce. Change them where they are set.', 'hive-compliance' ), 'store' ),
			'links'    => array( __( 'Links to document pages', 'hive-compliance' ), __( 'The address of the published page bound to each document. Pick a page to bind it.', 'hive-compliance' ), 'admin-links' ),
			'custom'   => array( __( 'Custom values', 'hive-compliance' ), __( 'Your own values, such as a return address or support hours. Keys use a-z, 0-9 and _.', 'hive-compliance' ), 'tag' ),
			'unknown'  => array( __( 'Unknown placeholders', 'hive-compliance' ), __( 'Used in documents, but no namespace provides them: fix the documents.', 'hive-compliance' ), 'warning' ),
		);
		if ( ! isset( $groups['custom'] ) ) {
			$groups['custom'] = array();
		}

		echo '<nav class="hive-ph-nav" aria-label="' . esc_attr__( 'Namespaces', 'hive-compliance' ) . '">';
		foreach ( $groups as $prefix => $rows ) {
			$attention = count( array_filter( $rows, array( Inventory::class, 'needs_attention' ) ) );
			printf(
				'<a href="#hive-ph-%1$s"><code>%1$s.*</code> <span class="hive-count">%2$d</span>%3$s</a>',
				esc_attr( (string) $prefix ),
				count( $rows ),
				$attention ? ' ' . $this->badge( (string) $attention, 'error', true ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
			);
		}
		echo '</nav>';

		$this->form_open( self::ACTION_SAVE );
		printf( '<input type="hidden" name="lang" value="%s">', esc_attr( $lang ) );

		$pages = $this->page_options();
		foreach ( $groups as $prefix => $rows ) {
			list( $title, $description, $icon ) = $titles[ $prefix ] ?? array( (string) $prefix, '', 'editor-code' );
			$this->panel_open(
				esc_html( $title ) . ' <code>{{' . esc_html( (string) $prefix ) . '.*}}</code>',
				array(
					'id'    => 'hive-ph-' . $prefix,
					'icon'  => $icon,
					'class' => 'hive-ph-group',
					'flush' => true,
					'meta'  => '<span>' . esc_html( $description ) . '</span>',
				)
			);
			echo '<table class="widefat striped hive-ph-table"><thead><tr><th class="hive-ph-col-token">' . esc_html__( 'Placeholder', 'hive-compliance' ) . '</th><th>' . esc_html__( 'Value', 'hive-compliance' ) . '</th><th class="hive-ph-col-status">' . esc_html__( 'Status', 'hive-compliance' ) . '</th><th class="hive-ph-col-used">' . esc_html__( 'Used in', 'hive-compliance' ) . '</th><th class="hive-ph-col-source">' . esc_html__( 'Source', 'hive-compliance' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$this->render_row( $row, $lang, $pages );
			}
			if ( 'custom' === $prefix ) {
				$this->render_new_custom_row();
			}
			echo '</tbody></table>';
			$this->panel_close();
		}//end foreach

		echo '<div class="hive-empty hive-ph-empty">' . $this->icon( 'search' ) . esc_html__( 'No placeholder matches the search and filter.', 'hive-compliance' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().

		echo '<div class="hive-actionbar"><div class="hive-actionbar__group">';
		submit_button( __( 'Save changes', 'hive-compliance' ), 'primary', 'submit', false );
		echo '<span class="hive-meta">' . esc_html__( 'Business values go through the same checks as on Business Data.', 'hive-compliance' ) . '</span>';
		echo '</div></div>';
		echo '</form></div>';
	}

	/**
	 * One placeholder row.
	 *
	 * @param array<string, mixed> $row   Inventory row.
	 * @param string               $lang  Language.
	 * @param array<int, string>   $pages Pages offered for binding (ID => title).
	 */
	private function render_row( array $row, string $lang, array $pages ): void {
		$token  = '{{' . $row['token'] . '}}';
		$search = strtolower( $row['token'] . ' ' . $row['label'] . ' ' . $row['value'] );
		printf(
			'<tr data-search="%1$s" data-attention="%2$d" data-used="%3$d" data-editable="%4$d">',
			esc_attr( $search ),
			Inventory::needs_attention( $row ) ? 1 : 0,
			$row['used_in'] ? 1 : 0,
			'' !== $row['editable'] ? 1 : 0
		);

		printf(
			'<td><code class="hive-token">%1$s</code> <button type="button" class="button-link hive-copy" data-copy="%2$s">%3$s</button>%4$s</td>',
			esc_html( $token ),
			esc_attr( $token ),
			esc_html__( 'Copy', 'hive-compliance' ),
			'' !== $row['label'] ? '<br><span class="hive-meta">' . esc_html( (string) $row['label'] ) . '</span>' : ''
		);

		echo '<td>';
		$this->render_value( $row, $lang, $pages );
		echo '</td>';

		$kinds               = array(
			Inventory::OK      => array( 'ok', __( 'Set', 'hive-compliance' ) ),
			Inventory::MISSING => array( $row['used_in'] ? 'error' : 'muted', __( 'Missing', 'hive-compliance' ) ),
			Inventory::INVALID => array( 'error', __( 'Invalid', 'hive-compliance' ) ),
			Inventory::WARNING => array( 'warning', __( 'Check', 'hive-compliance' ) ),
			Inventory::UNKNOWN => array( 'error', __( 'Unknown', 'hive-compliance' ) ),
		);
		list( $kind, $text ) = $kinds[ $row['status'] ] ?? array( 'muted', (string) $row['status'] );
		echo '<td>' . $this->badge( $text, $kind ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
		if ( '' !== $row['message'] ) {
			echo '<br><span class="hive-meta">' . esc_html( (string) $row['message'] ) . '</span>';
		}
		echo '</td>';

		echo '<td>';
		if ( ! $row['used_in'] ) {
			echo '<span class="hive-dash">—</span>';
		} else {
			$links = array();
			foreach ( array_slice( $row['used_in'], 0, 4, true ) as $doc_id => $title ) {
				$links[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( Request::editor_url( (string) $doc_id, $lang ) ), esc_html( (string) $title ) );
			}
			echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			if ( count( $row['used_in'] ) > 4 ) {
				/* translators: %d: number of further documents. */
				echo ' <span class="hive-meta">' . esc_html( sprintf( __( 'and %d more', 'hive-compliance' ), count( $row['used_in'] ) - 4 ) ) . '</span>';
			}
		}
		echo '</td>';

		echo '<td><span class="hive-meta">' . esc_html( (string) $row['source'] ) . '</span>';
		if ( '' !== $row['edit_url'] ) {
			printf( '<br><a href="%1$s">%2$s</a>', esc_url( (string) $row['edit_url'] ), esc_html( '' === $row['editable'] ? __( 'Change it there', 'hive-compliance' ) : __( 'Open', 'hive-compliance' ) ) );
		}
		echo '</td></tr>';
	}

	/**
	 * The value cell: an input for editable rows, the value otherwise.
	 *
	 * @param array<string, mixed> $row   Inventory row.
	 * @param string               $lang  Language.
	 * @param array<int, string>   $pages Pages offered for binding (ID => title).
	 */
	private function render_value( array $row, string $lang, array $pages ): void {
		$key = (string) $row['key'];
		$id  = 'hive-ph-' . str_replace( '.', '-', (string) $row['token'] );

		if ( 'business' === $row['editable'] ) {
			$field = BusinessData::fields()[ $key ];
			$raw   = $this->plugin->business()->value( $key );
			$name  = 'business[' . $key . ']';
			printf( '<label class="screen-reader-text" for="%1$s">%2$s</label>', esc_attr( $id ), esc_html( (string) $row['label'] ) );
			if ( 'legal_form' === $field['type'] || 'country' === $field['type'] ) {
				$choices = 'legal_form' === $field['type'] ? BusinessData::legal_forms() : BusinessScreen::countries();
				$select  = $this->select( $id, $name, $choices, $raw, array( 'placeholder' => __( '— Select —', 'hive-compliance' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select().
			} elseif ( 'textarea' === $field['type'] ) {
				printf( '<textarea id="%1$s" name="%2$s" rows="2" class="large-text">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $raw ) );
			} else {
				printf( '<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text%4$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $raw ), Inventory::INVALID === $row['status'] ? ' hive-invalid' : '' );
			}
			if ( '' !== $row['value'] && $row['value'] !== $raw ) {
				/* translators: %s: value as it appears in documents. */
				echo '<br><span class="hive-meta">' . esc_html( sprintf( __( 'In documents: %s', 'hive-compliance' ), $row['value'] ) ) . '</span>';
			}
			return;
		}//end if

		if ( 'custom' === $row['editable'] ) {
			$name = 'custom[' . $key . ']';
			printf( '<label class="screen-reader-text" for="%1$s">%2$s</label>', esc_attr( $id ), esc_html( (string) $row['token'] ) );
			printf( '<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( $this->plugin->business()->custom()[ $key ] ?? '' ) );
			if ( Inventory::UNKNOWN !== $row['status'] ) {
				printf( '<br><label class="hive-meta"><input type="checkbox" name="custom_remove[]" value="%1$s"> %2$s</label>', esc_attr( $key ), esc_html__( 'Remove this value on save', 'hive-compliance' ) );
			}
			return;
		}

		if ( 'link' === $row['editable'] ) {
			printf( '<label class="screen-reader-text" for="%1$s">%2$s</label>', esc_attr( $id ), esc_html( (string) $row['label'] ) );
			$select = $this->select(
				$id,
				'links[' . $key . ']',
				$pages,
				(string) (int) $row['page_id'],
				array(
					'placeholder'       => __( '— Not bound —', 'hive-compliance' ),
					'placeholder_value' => '0',
				)
			);
			echo $select; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select().
			if ( '' !== $row['value'] ) {
				printf( '<br><a href="%1$s" target="_blank" rel="noopener" class="hive-meta">%2$s ↗</a>', esc_url( (string) $row['value'] ), esc_html( (string) $row['value'] ) );
			}
			return;
		}

		echo '' !== $row['value'] ? esc_html( (string) $row['value'] ) : '<span class="hive-dash">—</span>';
	}

	/**
	 * Row to add a custom value.
	 */
	private function render_new_custom_row(): void {
		echo '<tr data-search="" data-attention="0" data-used="0" data-editable="1" class="hive-ph-new">';
		printf( '<td><label for="hive-ph-new-key"><strong>%1$s</strong></label><br><code>{{custom.</code><input type="text" id="hive-ph-new-key" name="custom_new[key]" class="hive-ph-new-key" pattern="[a-z0-9_]+" placeholder="return_address"><code>}}</code></td>', esc_html__( 'Add a custom value', 'hive-compliance' ) );
		printf( '<td colspan="4"><label class="screen-reader-text" for="hive-ph-new-value">%1$s</label><input type="text" id="hive-ph-new-value" name="custom_new[value]" class="regular-text" placeholder="%1$s"></td></tr>', esc_attr__( 'Value', 'hive-compliance' ) );
	}
}
