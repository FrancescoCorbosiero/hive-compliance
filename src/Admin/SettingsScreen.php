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
	 * Repeater script for the custom integrations.
	 */
	public function enqueue(): void {
		wp_enqueue_script( 'hive-compliance-repeater', HIVE_COMPLIANCE_URL . 'assets/js/repeater.js', array(), HIVE_COMPLIANCE_VERSION, true );
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
		$this->header( $this->title() );
		$this->form_open( self::ACTION_SAVE );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Languages', 'hive-compliance' ) . '</th><td>';
		if ( Languages::PROVIDER_OWN !== $provider ) {
			/* translators: %s: WPML or Polylang. */
			printf( '<p>%s</p>', esc_html( sprintf( __( 'Languages come from %s.', 'hive-compliance' ), Languages::PROVIDER_WPML === $provider ? 'WPML' : 'Polylang' ) ) );
			echo '<p>';
			foreach ( $languages->enabled() as $code ) {
				echo $this->badge( $code . ' — ' . $languages->label( $code ), $code === $languages->default_language() ? 'ok' : 'muted' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped.
			}
			echo '</p>';
		} else {
			echo '<p><label for="hive-default-language">' . esc_html__( 'Default language', 'hive-compliance' ) . '</label><br><select id="hive-default-language" name="default_language">';
			foreach ( $languages->available() as $code => $label ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $code ), selected( $settings['default_language'], $code, false ), esc_html( $code . ' — ' . $label ) );
			}
			echo '</select></p>' . $this->select_all( 'hive-enabled-languages' ) . '<fieldset class="hive-checkgrid" id="hive-enabled-languages"><legend class="screen-reader-text">' . esc_html__( 'Enabled languages', 'hive-compliance' ) . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select_all().
			foreach ( $languages->available() as $code => $label ) {
				printf( '<label class="hive-check"><input type="checkbox" name="enabled_languages[]" value="%1$s"%2$s> %3$s</label>', esc_attr( $code ), checked( in_array( $code, (array) $settings['enabled_languages'], true ), true, false ), esc_html( $code . ' — ' . $label ) );
			}
			echo '</fieldset><p class="description">' . esc_html__( 'Each document can exist once per enabled language.', 'hive-compliance' ) . '</p>';
		}
		echo '</td></tr>';

		printf(
			'<tr><th scope="row"><label for="hive-max-import">%1$s</label></th><td><input type="number" id="hive-max-import" name="max_import_kb" min="64" max="20480" value="%2$d" class="small-text"> KB</td></tr>',
			esc_html__( 'Largest import file', 'hive-compliance' ),
			(int) $settings['max_import_kb']
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="remove_data_on_uninstall" value="1"%2$s> %3$s</label><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Uninstall', 'hive-compliance' ),
			checked( ! empty( $settings['remove_data_on_uninstall'] ), true, false ),
			esc_html__( 'Remove all documents, revisions and settings when the plugin is deleted', 'hive-compliance' ),
			esc_html__( 'Off by default: data is kept. Bound pages are ordinary pages and are never deleted.', 'hive-compliance' )
		);

		echo '<tr><th scope="row">' . esc_html__( 'Custom integrations', 'hive-compliance' ) . '</th><td>';
		$this->integrations( (array) $settings['custom_integrations'] );
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button();
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
		echo '<p class="description">' . esc_html__( 'In-house plugins and services that cannot be detected. They are listed in the export context with the detected integrations.', 'hive-compliance' ) . '</p>';
		echo '<table class="widefat striped hive-repeater" data-hive-repeater="custom_integrations"><thead><tr>';
		foreach ( $labels as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
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
				'<td><select name="custom_integrations[%1$d][transfer]" aria-label="%2$s"><option value="no"%3$s>%4$s</option><option value="yes"%5$s>%6$s</option></select></td></tr>',
				(int) $index,
				esc_attr( $labels['transfer'] ),
				selected( 'yes' !== ( $row['transfer'] ?? '' ), true, false ),
				esc_html__( 'No', 'hive-compliance' ),
				selected( 'yes' === ( $row['transfer'] ?? '' ), true, false ),
				esc_html__( 'Yes', 'hive-compliance' )
			);
		}//end foreach
		echo '</tbody></table>';
		printf( '<p><button type="button" class="button" data-hive-add-row="custom_integrations">%s</button></p>', esc_html__( 'Add row', 'hive-compliance' ) );
	}
}
