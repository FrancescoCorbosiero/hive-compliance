<?php
/**
 * Store Profile wizard (re-editable).
 *
 * @package Hive\Compliance
 */

namespace Hive\Compliance\Admin;

use Hive\Compliance\I18n\Languages;
use Hive\Compliance\Profile\Markets;
use Hive\Compliance\Profile\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Steps: seller, markets, customers, products, product flags, features, summary.
 * Values WooCommerce already knows are pre-filled with their source.
 */
final class ProfileScreen extends Screen {

	const ACTION_SAVE = 'hive_compliance_save_profile';

	/**
	 * Slug.
	 */
	public function slug(): string {
		return 'profile';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Store Profile', 'hive-compliance' );
	}

	/**
	 * Hooks the save handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
	}

	/**
	 * Steps (slug => label).
	 *
	 * @return array<string, string>
	 */
	public static function steps(): array {
		return array(
			'seller'    => __( 'Seller', 'hive-compliance' ),
			'markets'   => __( 'Markets', 'hive-compliance' ),
			'customers' => __( 'Customers', 'hive-compliance' ),
			'products'  => __( 'Products', 'hive-compliance' ),
			'flags'     => __( 'Product flags', 'hive-compliance' ),
			'features'  => __( 'Features', 'hive-compliance' ),
			'summary'   => __( 'Summary', 'hive-compliance' ),
		);
	}

	/**
	 * Merges one step's answers into the profile (testable without a request).
	 *
	 * @param array<string, mixed> $profile Current profile.
	 * @param string               $step    Step slug.
	 * @param array<string, mixed> $input   Unslashed answers of the step.
	 * @return array<string, mixed>
	 */
	public static function merge_step( array $profile, string $step, array $input ): array {
		switch ( $step ) {
			case 'seller':
				$profile['seller_country']      = (string) ( $input['seller_country'] ?? '' );
				$profile['is_micro_enterprise'] = ! empty( $input['is_micro_enterprise'] );
				break;
			case 'markets':
				$profile['markets'] = array_merge( (array) ( $input['markets_eu'] ?? array() ), (array) ( $input['markets_other'] ?? array() ), (array) ( $input['markets_us'] ?? array() ) );
				break;
			case 'customers':
				$profile['customer_types'] = (array) ( $input['customer_types'] ?? array() );
				break;
			case 'products':
				$profile['product_types'] = (array) ( $input['product_types'] ?? array() );
				break;
			case 'flags':
				$profile['product_flags'] = (array) ( $input['product_flags'] ?? array() );
				break;
			case 'features':
				$profile['features']  = (array) ( $input['features'] ?? array() );
				$profile['completed'] = true;
				break;
		}//end switch
		return $profile;
	}

	/**
	 * Handles the form submission (admin-post.php).
	 */
	public function handle_save(): void {
		Request::require_capability();
		check_admin_referer( self::ACTION_SAVE );

		$step  = sanitize_key( wp_unslash( $_POST['step'] ?? '' ) );
		$input = array();
		foreach ( array( 'markets_eu', 'markets_other', 'markets_us', 'customer_types', 'product_types', 'product_flags', 'features' ) as $key ) {
			$input[ $key ] = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST[ $key ] ?? array() ) );
		}
		$input['seller_country']      = sanitize_text_field( wp_unslash( $_POST['seller_country'] ?? '' ) );
		$input['is_micro_enterprise'] = ! empty( $_POST['is_micro_enterprise'] );

		$profile = $this->plugin->profile();
		$profile->save( self::merge_step( $profile->get(), $step, $input ) );
		Notices::add( 'success', __( 'Store profile saved.', 'hive-compliance' ) );

		$steps = array_keys( self::steps() );
		$index = array_search( $step, $steps, true );
		$next  = false === $index ? 'summary' : ( $steps[ $index + 1 ] ?? 'summary' );
		Request::redirect( Request::page_url( 'profile', array( 'step' => $next ) ) );
	}

	/**
	 * Prints the current step.
	 */
	public function render(): void {
		$steps = self::steps();
		$step  = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'seller'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Step routing.
		$step  = isset( $steps[ $step ] ) ? $step : 'seller';

		$profile  = $this->plugin->profile();
		$values   = $profile->get();
		$detected = $profile->detected();

		echo '<div class="wrap hive-wrap">';
		$this->header( $this->title(), __( 'Your answers decide which documents apply. Edit them any time.', 'hive-compliance' ) );
		if ( ! $profile->is_saved() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Pre-filled from WooCommerce and the active plugins. Check each step and save.', 'hive-compliance' ) . '</p></div>';
		}

		echo '<nav class="hive-steps">';
		foreach ( $steps as $slug => $label ) {
			printf( '<a href="%1$s" class="%2$s">%3$s</a>', esc_url( Request::page_url( 'profile', array( 'step' => $slug ) ) ), $slug === $step ? 'is-current' : '', esc_html( $label ) );
		}
		echo '</nav>';

		if ( 'summary' === $step ) {
			$this->render_summary();
			echo '</div>';
			return;
		}

		$this->form_open( self::ACTION_SAVE );
		printf( '<input type="hidden" name="step" value="%s">', esc_attr( $step ) );
		echo '<div class="hive-step">';
		$method = 'step_' . $step;
		$this->$method( $values, $detected );
		echo '</div>';
		submit_button( 'features' === $step ? __( 'Save and finish', 'hive-compliance' ) : __( 'Save and continue', 'hive-compliance' ) );
		echo '</form></div>';
	}

	/**
	 * Seller step.
	 *
	 * @param array<string, mixed> $values   Profile.
	 * @param array<string, mixed> $detected Detected values.
	 */
	private function step_seller( array $values, array $detected ): void {
		echo '<h2>' . esc_html__( 'Seller', 'hive-compliance' ) . '</h2>';
		printf( '<p><label for="hive-seller-country">%1$s</label><br><input type="text" id="hive-seller-country" name="seller_country" value="%2$s" maxlength="2" class="small-text" pattern="[A-Za-z]{2}"> <span class="description">%3$s</span></p>', esc_html__( 'Seller country (ISO code, e.g. IT)', 'hive-compliance' ), esc_attr( (string) $values['seller_country'] ), esc_html( Markets::country_name( (string) $values['seller_country'] ) ) );
		$this->source_note( $detected['seller_country'] ?? null );
		printf( '<p><label><input type="checkbox" name="is_micro_enterprise" value="1"%1$s> %2$s</label></p>', checked( ! empty( $values['is_micro_enterprise'] ), true, false ), esc_html__( 'Micro-enterprise (fewer than 10 employees and turnover or balance sheet of at most €2 million)', 'hive-compliance' ) );
	}

	/**
	 * Markets step.
	 *
	 * @param array<string, mixed> $values   Profile.
	 * @param array<string, mixed> $detected Detected values.
	 */
	private function step_markets( array $values, array $detected ): void {
		$markets = (array) $values['markets'];
		echo '<h2>' . esc_html__( 'Markets you sell to', 'hive-compliance' ) . '</h2>';
		$this->source_note( $detected['markets'] ?? null );

		echo '<h3>' . esc_html__( 'European Union', 'hive-compliance' ) . '</h3><div class="hive-checkgrid">';
		foreach ( Markets::EU as $code ) {
			$this->checkbox( 'markets_eu[]', $code, Markets::country_name( $code ), in_array( $code, $markets, true ) );
		}
		echo '</div>';

		echo '<h3>' . esc_html__( 'Other markets', 'hive-compliance' ) . '</h3><div class="hive-checkgrid">';
		foreach ( Markets::OTHER as $code ) {
			$this->checkbox( 'markets_other[]', $code, Markets::label( $code ), in_array( $code, $markets, true ) );
		}
		echo '</div>';

		echo '<h3>' . esc_html__( 'United States (by state)', 'hive-compliance' ) . '</h3><div class="hive-checkgrid hive-checkgrid--small">';
		foreach ( Markets::US_STATES as $state ) {
			$this->checkbox( 'markets_us[]', 'US-' . $state, $state, in_array( 'US-' . $state, $markets, true ) );
		}
		echo '</div>';
	}

	/**
	 * Customers step.
	 *
	 * @param array<string, mixed> $values   Profile.
	 * @param array<string, mixed> $detected Detected values.
	 */
	private function step_customers( array $values, array $detected ): void {
		echo '<h2>' . esc_html__( 'Customers', 'hive-compliance' ) . '</h2>';
		$this->options( 'customer_types', $values, $detected );
	}

	/**
	 * Products step.
	 *
	 * @param array<string, mixed> $values   Profile.
	 * @param array<string, mixed> $detected Detected values.
	 */
	private function step_products( array $values, array $detected ): void {
		echo '<h2>' . esc_html__( 'What you sell', 'hive-compliance' ) . '</h2>';
		$this->options( 'product_types', $values, $detected );
	}

	/**
	 * Product flags step.
	 *
	 * @param array<string, mixed> $values   Profile.
	 * @param array<string, mixed> $detected Detected values.
	 */
	private function step_flags( array $values, array $detected ): void {
		echo '<h2>' . esc_html__( 'Product characteristics', 'hive-compliance' ) . '</h2>';
		$this->options( 'product_flags', $values, $detected );
	}

	/**
	 * Features step.
	 *
	 * @param array<string, mixed> $values   Profile.
	 * @param array<string, mixed> $detected Detected values.
	 */
	private function step_features( array $values, array $detected ): void {
		echo '<h2>' . esc_html__( 'Shop features', 'hive-compliance' ) . '</h2>';
		$this->options( 'features', $values, $detected );
	}

	/**
	 * Checkbox list of one profile field, with per-option sources when detected.
	 *
	 * @param string               $field    Field.
	 * @param array<string, mixed> $values   Profile.
	 * @param array<string, mixed> $detected Detected values.
	 */
	private function options( string $field, array $values, array $detected ): void {
		$labels  = Profile::option_labels()[ $field ];
		$sources = (array) ( $detected[ $field ]['sources'] ?? array() );
		if ( isset( $detected[ $field ] ) && ! $sources ) {
			$this->source_note( $detected[ $field ] );
		}
		echo '<div class="hive-checklist-options">';
		foreach ( $labels as $value => $label ) {
			$this->checkbox( $field . '[]', $value, $label, in_array( $value, (array) $values[ $field ], true ), $sources[ $value ] ?? '' );
		}
		echo '</div>';
	}

	/**
	 * One checkbox.
	 *
	 * @param string $name    Input name.
	 * @param string $value   Value.
	 * @param string $label   Label.
	 * @param bool   $checked Checked.
	 * @param string $source  Detection source.
	 */
	private function checkbox( string $name, string $value, string $label, bool $checked, string $source = '' ): void {
		printf(
			'<label class="hive-check"><input type="checkbox" name="%1$s" value="%2$s"%3$s> %4$s%5$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $checked, true, false ),
			esc_html( $label ),
			'' !== $source ? ' <span class="hive-source">' . esc_html( sprintf( /* translators: %s: where the value was detected. */ __( 'detected: %s', 'hive-compliance' ), $source ) ) . '</span>' : ''
		);
	}

	/**
	 * Where a pre-filled value comes from.
	 *
	 * @param array<string, mixed>|null $detected Detected entry.
	 */
	private function source_note( ?array $detected ): void {
		if ( null === $detected ) {
			return;
		}
		/* translators: %s: source of the pre-filled value. */
		echo '<p class="hive-source">' . esc_html( sprintf( __( 'Pre-filled from: %s', 'hive-compliance' ), (string) $detected['source'] ) ) . '</p>';
		if ( ! empty( $detected['note'] ) ) {
			echo '<p class="hive-field-message hive-field-message--warning">' . esc_html( (string) $detected['note'] ) . '</p>';
		}
	}

	/**
	 * Summary: applicable documents and the read-only store facts.
	 */
	private function render_summary(): void {
		$lang       = Languages::locale_to_code( (string) get_user_locale() );
		$applicable = $this->plugin->applicability()->applicable();

		echo '<h2>' . esc_html__( 'Applicable documents', 'hive-compliance' ) . '</h2><div class="hive-cards">';
		foreach ( array( 'core', 'conditional', 'international', 'fragment', 'internal' ) as $group ) {
			$titles = array();
			foreach ( $applicable as $entry ) {
				if ( $entry->group === $group ) {
					$titles[] = $entry->title( $lang );
				}
			}
			printf( '<div class="hive-card"><h3>%1$s <span class="hive-count">%2$d</span></h3><ul>', esc_html( ucfirst( $group ) ), count( $titles ) );
			foreach ( $titles as $title ) {
				echo '<li>' . esc_html( $title ) . '</li>';
			}
			echo '</ul></div>';
		}
		echo '</div>';
		printf( '<p><a class="button button-primary" href="%1$s">%2$s</a></p>', esc_url( Request::page_url() ), esc_html__( 'Go to the dashboard', 'hive-compliance' ) );

		echo '<h2>' . esc_html__( 'Store facts (auto-detected, read-only)', 'hive-compliance' ) . '</h2>';
		echo '<table class="widefat striped hive-facts"><thead><tr><th>' . esc_html__( 'Fact', 'hive-compliance' ) . '</th><th>' . esc_html__( 'Value', 'hive-compliance' ) . '</th><th>' . esc_html__( 'Placeholder', 'hive-compliance' ) . '</th><th>' . esc_html__( 'Source', 'hive-compliance' ) . '</th></tr></thead><tbody>';
		$facts = $this->plugin->store_facts();
		foreach ( $facts->all() as $key => $fact ) {
			$text = $facts->text( $key );
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td><code>{{store.%3$s}}</code></td><td class="hive-meta">%4$s</td></tr>',
				esc_html( $fact['label'] ),
				'' === $text ? '<em>' . esc_html__( 'unknown', 'hive-compliance' ) . '</em>' : esc_html( $text ),
				esc_html( $key ),
				esc_html( $fact['source'] )
			);
		}
		echo '</tbody></table>';
	}
}
