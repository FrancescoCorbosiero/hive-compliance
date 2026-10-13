<?php
/**
 * Business Data screen (business.* and custom.* placeholders).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\Business\BusinessData;
use Hive\Compliance\Business\Check;
use Hive\Compliance\Profile\Markets;

defined( 'ABSPATH' ) || exit;

/**
 * One form for the business data, with inline validation messages. Saving is never blocked.
 */
final class BusinessScreen extends Screen {

	const ACTION_SAVE = 'hive_compliance_save_business';

	/**
	 * Slug.
	 */
	public function slug(): string {
		return 'business';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Business Data', 'hive-compliance' );
	}

	/**
	 * Hooks the save handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
	}

	/**
	 * Repeater script.
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

		$fields = array();
		foreach ( array_keys( BusinessData::fields() ) as $key ) {
			$fields[ $key ] = sanitize_textarea_field( wp_unslash( $_POST['business'][ $key ] ?? '' ) );
		}
		$fields['social'] = $this->rows( wp_unslash( $_POST['business']['social'] ?? array() ), array( 'network', 'url' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised in rows() and BusinessData::save().
		$fields['custom'] = $this->rows( wp_unslash( $_POST['business']['custom'] ?? array() ), array( 'key', 'value' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised in rows() and BusinessData::save().

		$checks = $this->plugin->business()->save( $fields, (string) ( $this->plugin->profile()->get()['seller_country'] ?? '' ) );
		$errors = array_filter( $checks, static fn( Check $check ) => $check->is_error() );
		if ( $errors ) {
			/* translators: %d: number of fields with problems. */
			Notices::add( 'warning', sprintf( _n( 'Saved, but %d field needs attention (see below).', 'Saved, but %d fields need attention (see below).', count( $errors ), 'hive-compliance' ), count( $errors ) ) );
		} else {
			Notices::add( 'success', __( 'Business data saved.', 'hive-compliance' ) );
		}
		Request::redirect( Request::page_url( 'business' ) );
	}

	/**
	 * Prints the form.
	 */
	public function render(): void {
		$business = $this->plugin->business();
		$data     = $business->get();
		$checks   = $business->checks( (string) ( $this->plugin->profile()->get()['seller_country'] ?? '' ) );
		$missing  = array_filter( BusinessData::REQUIRED, static fn( $key ) => '' === trim( (string) $data[ $key ] ) );

		echo '<div class="wrap hive-wrap">';
		$this->header( $this->title(), __( 'Facts about the business, used by the {{business.*}} placeholders. They are exported to the LLM as context and never imported back.', 'hive-compliance' ) );
		$this->form_open( self::ACTION_SAVE );

		$fields = BusinessData::fields();
		foreach ( BusinessData::groups() as $group => $group_label ) {
			echo '<h2>' . esc_html( $group_label ) . '</h2><table class="form-table" role="presentation"><tbody>';
			foreach ( $fields as $key => $field ) {
				if ( $field['group'] === $group ) {
					$this->field_row( $key, $field, (string) $data[ $key ], $checks[ $key ] ?? null, in_array( $key, $missing, true ) );
				}
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Social profiles', 'hive-compliance' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Only this shop\'s own profiles. Available as {{business.social_<network>}}.', 'hive-compliance' ) . '</p>';
		$this->repeater( 'social', (array) $data['social'], array( 'network', 'url' ), $checks );

		echo '<h2>' . esc_html__( 'Custom values', 'hive-compliance' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Your own key/value pairs, available as {{custom.<key>}}. Keys: lowercase letters, digits and _.', 'hive-compliance' ) . '</p>';
		$this->repeater( 'custom', (array) $data['custom'], array( 'key', 'value' ), $checks );

		submit_button( __( 'Save business data', 'hive-compliance' ) );
		echo '</form></div>';
	}

	/**
	 * One field row.
	 *
	 * @param string                $key     Field key.
	 * @param array<string, string> $field   Field definition.
	 * @param string                $value   Current value.
	 * @param Check|null            $check   Validation result.
	 * @param bool                  $missing Whether a required field is empty.
	 */
	private function field_row( string $key, array $field, string $value, ?Check $check, bool $missing ): void {
		$id   = 'hive-business-' . $key;
		$name = 'business[' . $key . ']';
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label>';
		if ( in_array( $key, BusinessData::REQUIRED, true ) ) {
			echo ' <span class="hive-required">*</span>';
		}
		echo '</th><td>';

		if ( 'textarea' === $field['type'] ) {
			printf( '<textarea id="%1$s" name="%2$s" rows="3" class="large-text">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ) );
		} elseif ( 'country' === $field['type'] ) {
			printf( '<select id="%1$s" name="%2$s"><option value="">%3$s</option>', esc_attr( $id ), esc_attr( $name ), esc_html__( '— Select —', 'hive-compliance' ) );
			foreach ( $this->countries() as $code => $label ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $code ), selected( $value, $code, false ), esc_html( $label ) );
			}
			echo '</select>';
		} else {
			$type = 'email' === $field['type'] ? 'email' : ( 'phone' === $field['type'] ? 'tel' : 'text' );
			printf( '<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text%5$s">', esc_attr( $type ), esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), null !== $check && $check->is_error() ? ' hive-invalid' : '' );
		}
		printf( ' <code class="hive-token">{{business.%s}}</code>', esc_html( $key ) );

		if ( null !== $check && Check::OK !== $check->severity ) {
			printf( '<p class="hive-field-message hive-field-message--%1$s">%2$s</p>', esc_attr( $check->severity ), esc_html( $check->message ) );
		} elseif ( $missing ) {
			echo '<p class="hive-field-message hive-field-message--error">' . esc_html__( 'Required for the legal notice.', 'hive-compliance' ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Repeater table (always one empty row, so it works without JavaScript).
	 *
	 * @param string               $name    social|custom.
	 * @param array<int, mixed>    $rows    Stored rows.
	 * @param string[]             $columns Column keys.
	 * @param array<string, Check> $checks  Validation results.
	 */
	private function repeater( string $name, array $rows, array $columns, array $checks ): void {
		$labels = array(
			'network' => __( 'Network (e.g. instagram)', 'hive-compliance' ),
			'url'     => __( 'Profile URL', 'hive-compliance' ),
			'key'     => __( 'Key', 'hive-compliance' ),
			'value'   => __( 'Value', 'hive-compliance' ),
		);
		$rows[] = array_fill_keys( $columns, '' );

		printf( '<table class="widefat striped hive-repeater" data-hive-repeater="%s"><thead><tr>', esc_attr( $name ) );
		foreach ( $columns as $column ) {
			echo '<th>' . esc_html( $labels[ $column ] ) . '</th>';
		}
		echo '<th></th></tr></thead><tbody>';
		foreach ( array_values( $rows ) as $index => $row ) {
			echo '<tr>';
			foreach ( $columns as $column ) {
				printf(
					'<td><input type="%1$s" name="business[%2$s][%3$d][%4$s]" value="%5$s" class="widefat"%6$s></td>',
					'url' === $column ? 'url' : 'text',
					esc_attr( $name ),
					(int) $index,
					esc_attr( $column ),
					esc_attr( (string) ( $row[ $column ] ?? '' ) ),
					'network' === $column ? ' list="hive-networks"' : ''
				);
			}
			$check = $checks[ $name . '_' . $index ] ?? null;
			echo '<td>' . ( null !== $check ? '<span class="hive-field-message hive-field-message--error">' . esc_html( $check->message ) . '</span>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		printf( '<p><button type="button" class="button" data-hive-add-row="%1$s">%2$s</button></p>', esc_attr( $name ), esc_html__( 'Add row', 'hive-compliance' ) );
		if ( 'social' === $name ) {
			echo '<datalist id="hive-networks">';
			foreach ( BusinessData::NETWORKS as $network ) {
				echo '<option value="' . esc_attr( $network ) . '">';
			}
			echo '</datalist>';
		}
	}

	/**
	 * Clean rows from a repeater post.
	 *
	 * @param mixed    $rows    Raw rows.
	 * @param string[] $columns Columns.
	 * @return array<int, array<string, string>>
	 */
	private function rows( $rows, array $columns ): array {
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$clean = array();
			foreach ( $columns as $column ) {
				$clean[ $column ] = sanitize_text_field( (string) ( is_array( $row ) ? ( $row[ $column ] ?? '' ) : '' ) );
			}
			$out[] = $clean;
		}
		return $out;
	}

	/**
	 * Country choices.
	 *
	 * @return array<string, string>
	 */
	private function countries(): array {
		if ( function_exists( 'WC' ) && WC()->countries ) {
			return array_map( static fn( $name ) => html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ), WC()->countries->get_countries() );
		}
		$codes = array_merge( Markets::EU, array( 'GB', 'CH', 'NO', 'SM', 'US', 'CA', 'BR', 'JP', 'AU' ) );
		$out   = array();
		foreach ( $codes as $code ) {
			$out[ $code ] = Markets::country_name( $code );
		}
		asort( $out );
		return $out;
	}
}
