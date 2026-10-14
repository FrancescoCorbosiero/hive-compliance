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
	 * One-line description of each step.
	 *
	 * @return array<string, string>
	 */
	private static function intros(): array {
		return array(
			'seller'    => __( 'Where the business is established. This sets the baseline law and the required business data.', 'hive-compliance' ),
			'markets'   => __( 'Where you sell. Each market can add documents (for example the UK, Switzerland or a US state with its own privacy law).', 'hive-compliance' ),
			'customers' => __( 'Who buys from you. Consumer sales bring withdrawal rights and guarantees; business sales bring their own terms.', 'hive-compliance' ),
			'products'  => __( 'What you sell. Digital content, services and subscriptions each need specific clauses.', 'hive-compliance' ),
			'flags'     => __( 'Characteristics that add obligations: labelling, age checks, take-back schemes, customs.', 'hive-compliance' ),
			'features'  => __( 'Shop features that process personal data or make promises to customers. Detected ones are pre-ticked.', 'hive-compliance' ),
			'summary'   => __( 'The documents that apply with these answers, and the store facts detected from WordPress and WooCommerce.', 'hive-compliance' ),
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
		$saved    = $profile->is_saved();

		echo '<div class="wrap hive-wrap">';
		$this->header( $this->title(), __( 'Your answers decide which documents apply. Edit them any time.', 'hive-compliance' ) );
		if ( ! $saved ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Pre-filled from WooCommerce and the active plugins. Check each step and save.', 'hive-compliance' ) . '</p></div>';
		}

		$this->render_stepper( $steps, $step, $values, $saved );

		if ( 'summary' === $step ) {
			$this->render_summary();
			echo '</div>';
			return;
		}

		$keys     = array_keys( $steps );
		$index    = (int) array_search( $step, $keys, true );
		$previous = $index > 0 ? $keys[ $index - 1 ] : '';

		$this->form_open( self::ACTION_SAVE );
		printf( '<input type="hidden" name="step" value="%s">', esc_attr( $step ) );
		$this->panel_open( $steps[ $step ], array( 'meta' => '<span>' . esc_html( sprintf( /* translators: 1: step number, 2: number of steps. */ __( 'Step %1$d of %2$d', 'hive-compliance' ), $index + 1, count( $steps ) - 1 ) ) . '</span>' ) );
		echo '<p class="hive-step-intro">' . esc_html( self::intros()[ $step ] ) . '</p>';
		echo '<div class="hive-step">';
		$method = 'step_' . $step;
		$this->$method( $values, $detected );
		echo '</div>';
		$this->panel_close();

		echo '<div class="hive-wizard-nav">';
		if ( '' !== $previous ) {
			printf( '<a class="button" href="%1$s">%2$s %3$s</a>', esc_url( Request::page_url( 'profile', array( 'step' => $previous ) ) ), $this->icon( 'arrow-left-alt2' ), esc_html( $steps[ $previous ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
		} else {
			echo '<span></span>';
		}
		submit_button( 'features' === $step ? __( 'Save and finish', 'hive-compliance' ) : __( 'Save and continue', 'hive-compliance' ), 'primary', 'submit', false );
		echo '</div>';
		echo '</form></div>';
	}

	/**
	 * The stepper: numbered steps, done ones ticked, the current one highlighted.
	 *
	 * @param array<string, string> $steps   Steps.
	 * @param string                $current Current step.
	 * @param array<string, mixed>  $values  Profile.
	 * @param bool                  $saved   Whether the profile was saved at least once.
	 */
	private function render_stepper( array $steps, string $current, array $values, bool $saved ): void {
		$done = array(
			'seller'    => '' !== (string) $values['seller_country'],
			'markets'   => (bool) $values['markets'],
			'customers' => (bool) $values['customer_types'],
			'products'  => (bool) $values['product_types'],
			'flags'     => $saved,
			'features'  => $saved && ! empty( $values['completed'] ),
			'summary'   => false,
		);
		echo '<nav class="hive-steps" aria-label="' . esc_attr__( 'Profile steps', 'hive-compliance' ) . '">';
		$n = 0;
		foreach ( $steps as $slug => $label ) {
			++$n;
			$is_done = $saved && ! empty( $done[ $slug ] ) && $slug !== $current;
			printf(
				'<a href="%1$s" class="%2$s"%5$s><span class="hive-steps__num">%3$s</span>%4$s</a>',
				esc_url( Request::page_url( 'profile', array( 'step' => $slug ) ) ),
				$slug === $current ? 'is-current' : ( $is_done ? 'is-done' : '' ),
				$is_done ? $this->icon( 'yes' ) : ( 'summary' === $slug ? $this->icon( 'flag' ) : (string) $n ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
				esc_html( $label ),
				$slug === $current ? ' aria-current="step"' : ''
			);
		}
		echo '</nav>';
	}

	/**
	 * Seller step.
	 *
	 * @param array<string, mixed> $values   Profile.
	 * @param array<string, mixed> $detected Detected values.
	 */
	private function step_seller( array $values, array $detected ): void {
		$country   = strtoupper( (string) $values['seller_country'] );
		$countries = BusinessScreen::countries();
		if ( '' !== $country && ! isset( $countries[ $country ] ) ) {
			$countries[ $country ] = Markets::country_name( $country );
		}
		echo '<div class="hive-field"><label class="hive-field__label" for="hive-seller-country">' . esc_html__( 'Seller country', 'hive-compliance' ) . '</label>';
		$select = $this->select(
			'hive-seller-country',
			'seller_country',
			$countries,
			$country,
			array(
				'placeholder' => __( '— Select a country —', 'hive-compliance' ),
				'class'       => 'regular-text',
			)
		);
		echo '<div class=\"hive-combobox--inline-wrap\">' . $select . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select().
		echo '<span class="hive-field__hint">' . esc_html__( 'The country where the business is established (its registered office), not where it ships from.', 'hive-compliance' ) . '</span>';
		$this->source_note( $detected['seller_country'] ?? null, (string) $values['seller_country'] );
		echo '</div>';

		printf(
			'<div class="hive-field"><label class="hive-check"><input type="checkbox" name="is_micro_enterprise" value="1" aria-describedby="hive-micro-definition"%1$s> <span class="hive-check__text"><span>%2$s</span><span class="hive-meta" id="hive-micro-definition">%3$s</span></span></label></div>',
			checked( ! empty( $values['is_micro_enterprise'] ), true, false ),
			esc_html__( 'Micro-enterprise', 'hive-compliance' ),
			esc_html__( 'Definition: fewer than 10 staff, and annual turnover or balance sheet total up to €2 million (Commission Recommendation 2003/361/EC). Check it before answering: the answer changes which documents apply, for example the accessibility statement.', 'hive-compliance' )
		);
	}

	/**
	 * Markets step.
	 *
	 * @param array<string, mixed> $values   Profile.
	 * @param array<string, mixed> $detected Detected values.
	 */
	private function step_markets( array $values, array $detected ): void {
		$markets = (array) $values['markets'];
		$this->source_note( $detected['markets'] ?? null, $markets );

		echo '<h3>' . esc_html__( 'European Union', 'hive-compliance' ) . '</h3>';
		echo $this->filter_input( 'hive-markets-eu', __( 'Find a country', 'hive-compliance' ) ) . $this->select_all( 'hive-markets-eu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helpers.
		echo '<div class="hive-checkgrid" id="hive-markets-eu">';
		foreach ( Markets::EU as $code ) {
			echo $this->choice( 'markets_eu[]', $code, Markets::country_name( $code ), in_array( $code, $markets, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in choice().
		}
		echo '</div>';

		echo '<h3>' . esc_html__( 'Other markets', 'hive-compliance' ) . '</h3>' . $this->select_all( 'hive-markets-other' ) . '<div class="hive-checkgrid" id="hive-markets-other">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select_all().
		foreach ( Markets::OTHER as $code ) {
			echo $this->choice( 'markets_other[]', $code, Markets::label( $code ), in_array( $code, $markets, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in choice().
		}
		echo '</div>';

		echo '<h3>' . esc_html__( 'United States (by state)', 'hive-compliance' ) . '</h3>';
		echo '<p class="hive-meta">' . esc_html__( 'Several states have their own consumer privacy laws. Tick the states you ship to or market in.', 'hive-compliance' ) . '</p>';
		echo $this->filter_input( 'hive-markets-us', __( 'Find a state', 'hive-compliance' ) ) . $this->select_all( 'hive-markets-us' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helpers.
		echo '<div class="hive-checkgrid hive-checkgrid--small" id="hive-markets-us">';
		foreach ( Markets::US_STATES as $state ) {
			echo $this->choice( 'markets_us[]', 'US-' . $state, $state, in_array( 'US-' . $state, $markets, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in choice().
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
		$this->options( 'customer_types', $values, $detected );
	}

	/**
	 * Products step.
	 *
	 * @param array<string, mixed> $values   Profile.
	 * @param array<string, mixed> $detected Detected values.
	 */
	private function step_products( array $values, array $detected ): void {
		$this->options( 'product_types', $values, $detected );
	}

	/**
	 * Product flags step.
	 *
	 * @param array<string, mixed> $values   Profile.
	 * @param array<string, mixed> $detected Detected values.
	 */
	private function step_flags( array $values, array $detected ): void {
		$this->options( 'product_flags', $values, $detected );
	}

	/**
	 * Features step.
	 *
	 * @param array<string, mixed> $values   Profile.
	 * @param array<string, mixed> $detected Detected values.
	 */
	private function step_features( array $values, array $detected ): void {
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
		if ( count( $labels ) > 3 ) {
			echo $this->select_all( 'hive-options-' . $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in select_all().
		}
		echo '<div class="hive-checklist-options" id="hive-options-' . esc_attr( $field ) . '">';
		foreach ( $labels as $value => $label ) {
			$source = (string) ( $sources[ $value ] ?? '' );
			echo $this->choice( $field . '[]', (string) $value, $label, in_array( $value, (array) $values[ $field ], true ), '' !== $source ? '<span class="hive-source">' . esc_html( sprintf( /* translators: %s: where the value was detected. */ __( 'detected: %s', 'hive-compliance' ), $source ) ) . '</span>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in choice().
		}
		echo '</div>';
	}

	/**
	 * Where a pre-filled value comes from. Once the profile is saved, the detected value is shown
	 * only when it differs from the saved answer and was really detected (not a fallback guess).
	 *
	 * @param array<string, mixed>|null $detected Detected entry.
	 * @param string|string[]|null      $value    Current answer (seller country or markets); null skips the comparison.
	 */
	private function source_note( ?array $detected, $value = null ): void {
		if ( null === $detected ) {
			return;
		}
		if ( ! $this->plugin->profile()->is_saved() ) {
			/* translators: %s: source of the pre-filled value. */
			echo '<p class="hive-source">' . $this->icon( 'info-outline' ) . ' ' . esc_html( sprintf( __( 'Pre-filled from: %s', 'hive-compliance' ), (string) $detected['source'] ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
		} elseif ( null !== $value && empty( $detected['fallback'] ) && ! self::same_answer( $value, $detected['value'] ) ) {
			/* translators: 1: where the value was detected, 2: detected value. */
			echo $this->field_message( 'warning', sprintf( __( 'Differs from %1$s: %2$s.', 'hive-compliance' ), (string) $detected['source'], self::describe( $detected['value'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_message().
		}
		if ( ! empty( $detected['note'] ) ) {
			echo $this->field_message( 'warning', (string) $detected['note'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in field_message().
		}
	}

	/**
	 * Whether a saved answer equals a detected value (lists compare without order).
	 *
	 * @param string|string[] $answer   Saved answer.
	 * @param mixed           $detected Detected value.
	 */
	private static function same_answer( $answer, $detected ): bool {
		if ( is_array( $answer ) || is_array( $detected ) ) {
			$answer   = array_map( 'strval', (array) $answer );
			$detected = array_map( 'strval', (array) $detected );
			sort( $answer );
			sort( $detected );
			return $answer === $detected;
		}
		return strtoupper( (string) $answer ) === strtoupper( (string) $detected );
	}

	/**
	 * A detected country or market list as text.
	 *
	 * @param mixed $value Country code or market codes.
	 */
	private static function describe( $value ): string {
		if ( ! is_array( $value ) ) {
			$code = strtoupper( (string) $value );
			if ( '' === $code ) {
				return '—';
			}
			$name = Markets::country_name( $code );
			// WooCommerce names some countries with their code already, e.g. "United States (US)".
			return false !== strpos( $name, $code ) ? $name : $name . ' (' . $code . ')';
		}
		$labels = array_map( array( Markets::class, 'label' ), array_map( 'strval', $value ) );
		if ( count( $labels ) > 6 ) {
			/* translators: 1: first items of a list, 2: number of further items. */
			return sprintf( __( '%1$s and %2$d more', 'hive-compliance' ), implode( ', ', array_slice( $labels, 0, 6 ) ), count( $labels ) - 6 );
		}
		return $labels ? implode( ', ', $labels ) : '—';
	}

	/**
	 * Summary: applicable documents and the read-only store facts.
	 */
	private function render_summary(): void {
		$lang       = Languages::locale_to_code( (string) get_user_locale() );
		$applicable = $this->plugin->applicability()->applicable();
		$groups     = array(
			'core'          => array( __( 'Core', 'hive-compliance' ), 'shield' ),
			'conditional'   => array( __( 'Conditional', 'hive-compliance' ), 'randomize' ),
			'international' => array( __( 'International', 'hive-compliance' ), 'admin-site-alt3' ),
			'fragment'      => array( __( 'Fragments', 'hive-compliance' ), 'editor-insertmore' ),
			'internal'      => array( __( 'Internal', 'hive-compliance' ), 'lock' ),
		);

		echo '<p class="hive-step-intro">' . esc_html( self::intros()['summary'] ) . '</p>';
		echo '<div class="hive-stats">';
		echo $this->stat( __( 'Applicable documents', 'hive-compliance' ), count( $applicable ), 'info', '', Request::page_url() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in stat().
		foreach ( $groups as $group => list( $label ) ) {
			$count = count( array_filter( $applicable, static fn( $entry ) => $entry->group === $group ) );
			echo $this->stat( $label, $count ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in stat().
		}
		echo '</div>';

		echo '<div class="hive-cards">';
		foreach ( $groups as $group => list( $label, $icon ) ) {
			$titles = array();
			foreach ( $applicable as $entry ) {
				if ( $entry->group === $group ) {
					$titles[] = $entry->title( $lang );
				}
			}
			printf(
				'<div class="hive-card"><div class="hive-card__head"><span class="hive-card__icon">%1$s</span><h3>%2$s</h3><span class="hive-card__count">%3$d</span></div><div class="hive-card__body">',
				$this->icon( $icon ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon().
				esc_html( $label ),
				count( $titles )
			);
			if ( ! $titles ) {
				echo '<p class="hive-card__nothing">' . esc_html__( 'None with these answers.', 'hive-compliance' ) . '</p>';
			} else {
				echo '<ul>';
				foreach ( $titles as $title ) {
					echo '<li>' . esc_html( $title ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</div></div>';
		}//end foreach
		echo '</div>';
		printf( '<p><a class="button button-primary" href="%1$s">%2$s</a></p>', esc_url( Request::page_url() ), esc_html__( 'Go to the dashboard', 'hive-compliance' ) );

		$this->panel_open(
			__( 'Store facts', 'hive-compliance' ),
			array(
				'icon'  => 'store',
				'flush' => true,
				'meta'  => '<span>' . esc_html__( 'auto-detected, read-only', 'hive-compliance' ) . '</span>',
			)
		);
		echo '<table class="widefat striped hive-facts"><thead><tr><th>' . esc_html__( 'Fact', 'hive-compliance' ) . '</th><th>' . esc_html__( 'Value', 'hive-compliance' ) . '</th><th>' . esc_html__( 'Placeholder', 'hive-compliance' ) . '</th><th>' . esc_html__( 'Source', 'hive-compliance' ) . '</th></tr></thead><tbody>';
		$facts = $this->plugin->store_facts();
		foreach ( $facts->all() as $key => $fact ) {
			$text = $facts->text( $key );
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td><code class="hive-token">{{store.%3$s}}</code></td><td class="hive-meta">%4$s</td></tr>',
				esc_html( $fact['label'] ),
				'' === $text ? '<em class="hive-meta">' . esc_html__( 'unknown', 'hive-compliance' ) . '</em>' : esc_html( $text ),
				esc_html( $key ),
				esc_html( $fact['source'] )
			);
		}
		echo '</tbody></table>';
		$this->panel_close();
	}
}
