<?php
/**
 * Settings screen: languages, import limit and uninstall behaviour.
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\I18n\Languages;
use Hive\Compliance\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plain WordPress settings form posting to admin-post.php.
 */
final class SettingsScreen extends Screen {

	const ACTION_SAVE = 'hive_compliance_save_settings';

	/**
	 * Slug.
	 */
	public function slug(): string {
		return 'settings';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Settings', 'hive-compliance' );
	}

	/**
	 * Hooks the save handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
	}

	/**
	 * Handles the form submission (admin-post.php).
	 */
	public function handle_save(): void {
		Request::require_capability();
		check_admin_referer( self::ACTION_SAVE );

		$this->plugin->settings()->update(
			array(
				'default_language'         => sanitize_text_field( wp_unslash( $_POST['default_language'] ?? '' ) ),
				'enabled_languages'        => array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['enabled_languages'] ?? array() ) ),
				'remove_data_on_uninstall' => ! empty( $_POST['remove_data_on_uninstall'] ),
				'max_import_kb'            => absint( $_POST['max_import_kb'] ?? 0 ),
				'custom_integrations'      => isset( $_POST['custom_integrations'] ) && is_array( $_POST['custom_integrations'] ) ? map_deep( wp_unslash( $_POST['custom_integrations'] ), 'sanitize_text_field' ) : array(),
			)
		);
		Notices::add( 'success', __( 'Settings saved.', 'hive-compliance' ) );
		Request::redirect( Request::page_url( 'settings' ) );
	}

	/**
	 * Prints the form.
	 */
	public function render(): void {
		$settings  = $this->plugin->settings()->all();
		$languages = $this->plugin->languages();
		$provider  = $languages->provider();

		echo '<div class="wrap hive-wrap">';
		$this->header( $this->title(), __( 'Languages, import limits, uninstall behaviour and the integrations the plugin cannot detect by itself.', 'hive-compliance' ) );
		$this->form_open( self::ACTION_SAVE );

		$this->panel_open(
			__( 'Languages', 'hive-compliance' ),
			array(
				'icon' => 'translation',
				'meta' => '<span>' . esc_html( sprintf( /* translators: %d: number of enabled languages. */ _n( '%d enabled', '%d enabled', count( $languages->enabled() ), 'hive-compliance' ), count( $languages->enabled() ) ) ) . '</span>',
			)
		);
		if ( Languages::PROVIDER_OWN !== $provider ) {
			/* translators: %s: WPML or Polylang. */
			echo '<p>' . esc_html( sprintf( __( 'Languages come from %s. Manage them there; the default language is highlighted.', 'hive-compliance' ), Languages::PROVIDER_WPML === $provider ? 'WPML' : 'Polylang' ) ) . '</p>';
			echo '<p class="hive-badges">';
			foreach ( $languages->enabled() as $code ) {
				echo $this->badge( $code . ' — ' . $languages->label( $code ), $code === $languages->default_language() ? 'ok' : 'muted', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped.
			}
			echo '</p>';
		} else {
			$options = array();
			foreach ( $languages->available() as $code => $label ) {
				$options[ $code ] = $code . ' — ' . $label;
			}
			echo '<div class="hive-field"><label class="hive-field__label" for="hive-default-language">' . esc_html__( 'Default language', 'hive-compliance' ) . '</label>';
			echo '<div class="hive-combobox--inline-wrap">' . $this->select( 'hive-default-language', 'default_language', $options, (string) $settings['default_language'], array( 'class' => 'regular-text' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select().
			echo '<span class="hive-field__hint">' . esc_html__( 'The language of the dashboard status, the skeletons and the links placeholders. It is always enabled.', 'hive-compliance' ) . '</span></div>';

			echo '<div class="hive-field"><span class="hive-field__label" id="hive-enabled-languages-label">' . esc_html__( 'Enabled languages', 'hive-compliance' ) . '</span>';
			echo $this->filter_input( 'hive-enabled-languages', __( 'Find a language', 'hive-compliance' ) ) . $this->select_all( 'hive-enabled-languages' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helpers.
			echo '<fieldset class="hive-checkgrid" id="hive-enabled-languages" aria-labelledby="hive-enabled-languages-label">';
			foreach ( $options as $code => $label ) {
				echo $this->choice( 'enabled_languages[]', (string) $code, $label, in_array( $code, (array) $settings['enabled_languages'], true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in choice().
			}
			echo '</fieldset><span class="hive-field__hint">' . esc_html__( 'Each document can exist once per enabled language.', 'hive-compliance' ) . '</span></div>';
		}//end if
		$this->panel_close();

		$this->panel_open( __( 'Import', 'hive-compliance' ), array( 'icon' => 'upload' ) );
		printf(
			'<div class="hive-field"><label class="hive-field__label" for="hive-max-import">%1$s</label><span><input type="number" id="hive-max-import" name="max_import_kb" min="64" max="20480" value="%2$d" class="small-text"> KB</span><span class="hive-field__hint">%3$s</span></div>',
			esc_html__( 'Largest import file', 'hive-compliance' ),
			(int) $settings['max_import_kb'],
			esc_html__( 'Bundles and JSON backups above this size are refused before they are parsed.', 'hive-compliance' )
		);
		$this->panel_close();

		$this->panel_open( __( 'Uninstall', 'hive-compliance' ), array( 'icon' => 'trash' ) );
		printf(
			'<label class="hive-check"><input type="checkbox" name="remove_data_on_uninstall" value="1"%1$s> <span class="hive-check__text"><span>%2$s</span><span class="hive-meta">%3$s</span></span></label>',
			checked( ! empty( $settings['remove_data_on_uninstall'] ), true, false ),
			esc_html__( 'Remove all documents, revisions and settings when the plugin is deleted', 'hive-compliance' ),
			esc_html__( 'Off by default: data is kept. Bound pages are ordinary pages and are never deleted.', 'hive-compliance' )
		);
		$this->panel_close();

		$this->panel_open(
			__( 'Custom integrations', 'hive-compliance' ),
			array(
				'icon'        => 'admin-plugins',
				'description' => __( 'In-house plugins and services that cannot be detected. They are listed in the export context with the detected integrations.', 'hive-compliance' ),
			)
		);
		$this->integrations( (array) $settings['custom_integrations'] );
		$this->panel_close();

		echo '<div class="hive-actionbar"><div class="hive-actionbar__group">';
		submit_button( __( 'Save settings', 'hive-compliance' ), 'primary', 'submit', false );
		echo '</div></div>';
		echo '</form></div>';
	}

	/**
	 * Repeater of custom integrations (always one empty row, so it works without JavaScript).
	 *
	 * @param array<int, array<string, string>> $rows Stored rows.
	 */
	private function integrations( array $rows ): void {
		$labels = array(
			'name'      => __( 'Name', 'hive-compliance' ),
			'role'      => __( 'Role', 'hive-compliance' ),
			'data'      => __( 'Data sent', 'hive-compliance' ),
			'recipient' => __( 'Recipient', 'hive-compliance' ),
			'transfer'  => __( 'Transfer outside the EU', 'hive-compliance' ),
		);
		echo '<table class="widefat striped hive-repeater" data-hive-repeater="custom_integrations"><thead><tr>';
		foreach ( $labels as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '<th class="hive-repeater__actions"><span class="screen-reader-text">' . esc_html__( 'Actions', 'hive-compliance' ) . '</span></th></tr></thead><tbody>';
		$rows[] = array_fill_keys( Settings::INTEGRATION_FIELDS, '' );
		foreach ( array_values( $rows ) as $index => $row ) {
			echo '<tr>';
			foreach ( array( 'name', 'role', 'data', 'recipient' ) as $field ) {
				printf(
					'<td><input type="text" name="custom_integrations[%1$d][%2$s]" value="%3$s" class="widefat" aria-label="%4$s"></td>',
					(int) $index,
					esc_attr( $field ),
					esc_attr( (string) ( $row[ $field ] ?? '' ) ),
					esc_attr( $labels[ $field ] )
				);
			}
			printf(
				'<td><select name="custom_integrations[%1$d][transfer]" aria-label="%2$s"><option value="no"%3$s>%4$s</option><option value="yes"%5$s>%6$s</option></select></td>',
				(int) $index,
				esc_attr( $labels['transfer'] ),
				selected( 'yes' !== ( $row['transfer'] ?? '' ), true, false ),
				esc_html__( 'No', 'hive-compliance' ),
				selected( 'yes' === ( $row['transfer'] ?? '' ), true, false ),
				esc_html__( 'Yes', 'hive-compliance' )
			);
			printf(
				'<td class="hive-repeater__actions"><button type="button" class="hive-repeater__remove" data-hive-remove-row aria-label="%1$s" title="%1$s" hidden>%2$s</button></td></tr>',
				esc_attr__( 'Remove row', 'hive-compliance' ),
				$this->icon( 'no-alt' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			);
		}//end foreach
		echo '</tbody></table>';
		printf( '<p><button type="button" class="button" data-hive-add-row="custom_integrations">%2$s %1$s</button></p>', esc_html__( 'Add row', 'hive-compliance' ), $this->icon( 'plus-alt2' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
	}
}
