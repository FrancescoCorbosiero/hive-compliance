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
		$required = BusinessData::required( $data );
		$missing  = array_filter( $required, static fn( $key ) => '' === trim( (string) $data[ $key ] ) );
		$fields   = BusinessData::fields();
		$icons    = array(
			'identity'     => 'building',
			'registration' => 'id',
			'address'      => 'location',
			'contacts'     => 'email',
			'privacy'      => 'shield',
		);

		echo '<div class="wrap hive-wrap">';
		$this->header(
			$this->title(),
			__( 'Facts about the business, used by the {{business.*}} placeholders. They are exported to the LLM as context and never imported back.', 'hive-compliance' ),
			sprintf( '<a class="button" href="%1$s">%2$s %3$s</a>', esc_url( Request::page_url( 'placeholders' ) ), $this->icon( 'editor-code' ), esc_html__( 'All placeholders', 'hive-compliance' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
		);

		$this->render_completeness( $required, $missing, $fields );

		$this->form_open( self::ACTION_SAVE );

		foreach ( BusinessData::groups() as $group => $group_label ) {
			$group_errors = 0;
			foreach ( $fields as $key => $field ) {
				if ( $field['group'] === $group && ( in_array( $key, $missing, true ) || ( isset( $checks[ $key ] ) && $checks[ $key ]->is_error() ) ) ) {
					++$group_errors;
				}
			}
			$this->panel_open(
				$group_label,
				array(
					'icon' => $icons[ $group ] ?? 'admin-generic',
					'id'   => 'hive-group-' . $group,
					/* translators: %d: number of fields needing attention. */
					'meta' => $group_errors ? $this->badge( sprintf( _n( '%d field needs attention', '%d fields need attention', $group_errors, 'hive-compliance' ), $group_errors ), 'error' ) : $this->badge( __( 'complete', 'hive-compliance' ), 'ok' ),
				)
			);
			echo '<table class="form-table" role="presentation"><tbody>';
			foreach ( $fields as $key => $field ) {
				if ( $field['group'] === $group ) {
					$this->field_row( $key, $field, (string) $data[ $key ], $checks[ $key ] ?? null, in_array( $key, $missing, true ), in_array( $key, $required, true ) );
				}
			}
			echo '</tbody></table>';
			$this->panel_close();
		}//end foreach

		$this->panel_open(
			__( 'Social profiles', 'hive-compliance' ),
			array(
				'icon'        => 'share',
				'description' => __( 'Only this shop\'s own profiles. Available as {{business.social_<network>}}.', 'hive-compliance' ),
			)
		);
		$this->repeater( 'social', (array) $data['social'], array( 'network', 'url' ), $checks );
		$this->panel_close();

		$this->panel_open(
			__( 'Custom values', 'hive-compliance' ),
			array(
				'icon'        => 'tag',
				'description' => __( 'Your own key/value pairs, available as {{custom.<key>}}. Keys: lowercase letters, digits and _.', 'hive-compliance' ),
			)
		);
		$this->repeater( 'custom', (array) $data['custom'], array( 'key', 'value' ), $checks );
		$this->panel_close();

		echo '<div class="hive-actionbar"><div class="hive-actionbar__group">';
		submit_button( __( 'Save business data', 'hive-compliance' ), 'primary', 'submit', false );
		echo '<span class="hive-meta">' . esc_html__( 'Saving never blocks: fields with problems are flagged, not rejected.', 'hive-compliance' ) . '</span>';
		echo '</div></div>';
		echo '</form></div>';
	}

	/**
	 * Completeness bar for the required fields.
	 *
	 * @param string[]                             $required Required field keys.
	 * @param string[]                             $missing  Required fields still empty.
	 * @param array<string, array<string, string>> $fields   Field definitions.
	 */
	private function render_completeness( array $required, array $missing, array $fields ): void {
		$total = count( $required );
		$done  = $total - count( $missing );
		echo '<div class="hive-completeness">';
		/* translators: 1: filled required fields, 2: required fields. */
		echo '<span class="hive-completeness__text"><strong>' . esc_html( sprintf( __( '%1$d of %2$d required fields', 'hive-compliance' ), $done, $total ) ) . '</strong></span>';
		echo '<span class="hive-completeness__bar">' . $this->progress( $done, $total, true ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in progress().
		if ( $missing ) {
			$links = array();
			foreach ( $missing as $key ) {
				$links[] = sprintf( '<a href="#hive-business-%1$s">%2$s</a>', esc_attr( $key ), esc_html( $fields[ $key ]['label'] ?? $key ) );
			}
			echo '<span class="hive-completeness__missing">' . esc_html__( 'Still missing:', 'hive-compliance' ) . ' ' . implode( ', ', $links ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		} else {
			echo '<span class="hive-ok">' . $this->icon( 'yes-alt' ) . ' ' . esc_html__( 'Everything the legal notice needs is filled in.', 'hive-compliance' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
		}
		echo '</div>';
	}

	/**
	 * One field row.
	 *
	 * @param string                $key      Field key.
	 * @param array<string, string> $field    Field definition.
	 * @param string                $value    Current value.
	 * @param Check|null            $check    Validation result.
	 * @param bool                  $missing  Whether a required field is empty.
	 * @param bool                  $required Whether the field is required.
	 */
	private function field_row( string $key, array $field, string $value, ?Check $check, bool $missing, bool $required ): void {
		$id      = 'hive-business-' . $key;
		$name    = 'business[' . $key . ']';
		$invalid = ( null !== $check && $check->is_error() ) || $missing;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label>';
		if ( $required ) {
			echo ' <span class="hive-required" title="' . esc_attr__( 'Required', 'hive-compliance' ) . '">*</span>';
		}
		echo '</th><td>';

		if ( 'textarea' === $field['type'] ) {
			printf( '<textarea id="%1$s" name="%2$s" rows="3" class="large-text%4$s">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ), $invalid ? ' hive-invalid' : '' );
		} elseif ( 'legal_form' === $field['type'] ) {
			$select = $this->select(
				$id,
				$name,
				BusinessData::legal_forms(),
				$value,
				array(
					'placeholder' => __( '— Select —', 'hive-compliance' ),
					'class'       => 'regular-text' . ( $invalid ? ' hive-invalid' : '' ),
				)
			);
			echo $select; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select().
		} elseif ( 'country' === $field['type'] ) {
			$select = $this->select(
				$id,
				$name,
				self::countries(),
				$value,
				array(
					'placeholder' => __( '— Select a country —', 'hive-compliance' ),
					'class'       => 'regular-text' . ( $invalid ? ' hive-invalid' : '' ),
				)
			);
			echo $select; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select().
		} else {
			$type = 'email' === $field['type'] ? 'email' : ( 'phone' === $field['type'] ? 'tel' : 'text' );
			printf( '<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text%5$s">', esc_attr( $type ), esc_attr( $id ), esc_attr( $name ), esc_attr( $value ), $invalid ? ' hive-invalid' : '' );
		}//end if
		printf(
			'<span class="hive-form-row-token"><code class="hive-token">{{business.%1$s}}</code><button type="button" class="button-link hive-copy" data-copy="%2$s" aria-label="%3$s">%4$s</button></span>',
			esc_html( $key ),
			esc_attr( '{{business.' . $key . '}}' ),
			esc_attr__( 'Copy placeholder', 'hive-compliance' ),
			esc_html__( 'Copy', 'hive-compliance' )
		);

		if ( null !== $check && Check::OK !== $check->severity ) {
			echo $this->field_message( $check->severity, $check->message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_message().
		} elseif ( $missing ) {
			echo $this->field_message( 'error', in_array( $key, BusinessData::REQUIRED, true ) ? __( 'Required for the legal notice.', 'hive-compliance' ) : __( 'Required for an impresa individuale.', 'hive-compliance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_message().
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
		echo '<th class="hive-repeater__actions"><span class="screen-reader-text">' . esc_html__( 'Actions', 'hive-compliance' ) . '</span></th></tr></thead><tbody>';
		foreach ( array_values( $rows ) as $index => $row ) {
			$check = $checks[ $name . '_' . $index ] ?? null;
			echo '<tr>';
			foreach ( $columns as $column ) {
				printf(
					'<td><input type="%1$s" name="business[%2$s][%3$d][%4$s]" value="%5$s" class="widefat%7$s"%6$s aria-label="%8$s">%9$s</td>',
					'url' === $column ? 'url' : 'text',
					esc_attr( $name ),
					(int) $index,
					esc_attr( $column ),
					esc_attr( (string) ( $row[ $column ] ?? '' ) ),
					'network' === $column ? ' list="hive-networks"' : '',
					null !== $check && 'url' !== $column && 'value' !== $column ? ' hive-invalid' : '',
					esc_attr( $labels[ $column ] ),
					null !== $check && ( 'url' === $column || 'value' === $column ) ? $this->field_message( 'error', $check->message ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_message().
				);
			}
			printf(
				'<td class="hive-repeater__actions"><button type="button" class="hive-repeater__remove" data-hive-remove-row aria-label="%1$s" title="%1$s" hidden>%2$s</button></td></tr>',
				esc_attr__( 'Remove row', 'hive-compliance' ),
				$this->icon( 'no-alt' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
			);
		}//end foreach
		echo '</tbody></table>';
		printf( '<p><button type="button" class="button" data-hive-add-row="%1$s">%3$s %2$s</button></p>', esc_attr( $name ), esc_html__( 'Add row', 'hive-compliance' ), $this->icon( 'plus-alt2' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
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
	public static function countries(): array {
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
